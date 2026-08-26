# ============================================================
# diagnose.ps1 - Collects environment details for support.
# Nothing is changed; it only reports.
# ============================================================
$ProgressPreference = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
function Line { Write-Host ("=" * 60) -ForegroundColor DarkGray }
function KV($k,$v,$c='White'){ Write-Host ("  {0,-22}: {1}" -f $k,$v) -ForegroundColor $c }

Line; Write-Host '  SMARTPOS - DIAGNOSTICS' -ForegroundColor Green; Line
KV 'Folder' $root
KV 'Windows' ([Environment]::OSVersion.VersionString)
KV 'PowerShell' $PSVersionTable.PSVersion

$sealed = Join-Path $root 'runtime\app.sealed'
KV 'Sealed package' $(if (Test-Path $sealed) { "OK ($([math]::Round((Get-Item $sealed).Length/1KB)) KB)" } else { 'MISSING' }) `
   $(if (Test-Path $sealed) {'Green'} else {'Red'})

$php = Get-ChildItem -Path (Join-Path $root 'runtime\php') -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
if ($php) {
  $ini = Join-Path (Split-Path $php.FullName) 'php.ini'
  KV 'Private PHP' $php.FullName 'Green'
  KV 'PHP version' (& $php.FullName -c "$ini" -r "echo PHP_VERSION;" 2>&1)
  $ext = & $php.FullName -c "$ini" -r "echo implode(',', get_loaded_extensions());" 2>&1
  foreach ($e in @('openssl','mbstring','pdo_mysql','zlib')) {
    $has = ("$ext" -match "(?i)\b$e\b")
    KV "  ext: $e" $(if ($has) {'loaded'} else {'MISSING'}) $(if ($has) {'Green'} else {'Red'})
  }
  $rootFwd = $root -replace '\\','/'
  $boot = & $php.FullName -c "$ini" -r "require '$rootFwd/runtime/boot.php'; SealedApp::boot('$rootFwd'); echo 'APP_OK';" 2>&1
  KV 'Application boot' $(if ("$boot" -match 'APP_OK') {'OK'} else {"FAILED"}) $(if ("$boot" -match 'APP_OK') {'Green'} else {'Red'})
  if ("$boot" -notmatch 'APP_OK') { "$boot".Split("`n") | Select-Object -First 5 | ForEach-Object { Write-Host "      $_" -ForegroundColor Red } }
} else { KV 'Private PHP' 'MISSING - run INSTALL_OFFLINE.bat' 'Red' }

$dbUp = $false
try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1',3307); $c.Close(); $dbUp = $true } catch {}
KV 'Database (3307)' $(if ($dbUp) {'running'} else {'not running'}) $(if ($dbUp) {'Green'} else {'Yellow'})

foreach ($f in @('storage\logs\server.err.log','storage\logs\server.out.log')) {
  $p = Join-Path $root $f
  if (Test-Path $p) {
    Write-Host ''; Write-Host "  --- $f (last 10 lines) ---" -ForegroundColor DarkGray
    Get-Content $p -Tail 10 | ForEach-Object { Write-Host "      $_" -ForegroundColor Gray }
  }
}
Write-Host ''
Write-Host '  Send this output to support@wabwar.com if the problem continues.' -ForegroundColor Cyan
Write-Host ''
Read-Host 'Press Enter to close'
