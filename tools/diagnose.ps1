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
  $ver = (& $php.FullName -c "$ini" -r "echo PHP_VERSION;" 2>&1 | Out-String).Trim()
  KV 'PHP version' $ver

  # V64.1 — VCRUNTIME ka masla yahan PAKRA jata hai.
  # Pehle yeh report bas "MISSING" x4 aur "Application boot FAILED"
  # dikhati thi, jis se lagta tha ke package kharab hai. Asli wajah yeh
  # hoti hai ke php.exe chal hi nahi raha - purani Visual C++ runtime.
  if ($ver -match 'VCRUNTIME140|not compatible with this PHP build') {
    Write-Host ''
    Write-Host '  >>> ASLI MASLA MIL GAYA' -ForegroundColor Yellow
    Write-Host '  Is computer par PURANI Visual C++ runtime hai; PHP ko nayi chahiye.' -ForegroundColor Yellow
    Write-Host '  Isi liye php.exe chal nahi raha - neeche wali saari "MISSING" lines' -ForegroundColor Gray
    Write-Host '  isi ka nateeja hain, package bilkul theek hai.' -ForegroundColor Gray
    Write-Host ''
    Write-Host '  HAL: yeh install karein, phir INSTALL_OFFLINE.bat dobara chalayein:' -ForegroundColor White
    Write-Host '       https://aka.ms/vs/17/release/vc_redist.x64.exe' -ForegroundColor White
    Write-Host ''
  }

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
