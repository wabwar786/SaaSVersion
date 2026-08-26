# ============================================================
# start_offline.ps1 - Starts the offline software:
#   1) local database
#   2) private PHP web server
#   3) opens the browser
# Nothing is installed on Windows.
# ============================================================
$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Line { Write-Host ("=" * 60) -ForegroundColor DarkGray }
function Say($m,$c='Gray'){ Write-Host $m -ForegroundColor $c }

$product='SmartPOS'; $company='Wabwar Software House'; $version='1.0.0'
$phone='+92 300 0000000'; $website='https://wabwar.com'; $email='support@wabwar.com'
$bizName='SmartPOS'; $branch=''
if (Test-Path "$root\runtime\app.info") {
  try {
    $i = Get-Content "$root\runtime\app.info" -Raw | ConvertFrom-Json
    if ($i.name){$bizName=$i.name}; if ($i.branch){$branch=$i.branch}
    if ($i.product){$product=$i.product}; if ($i.company){$company=$i.company}
    if ($i.version){$version=$i.version}; if ($i.phone){$phone=$i.phone}
    if ($i.website){$website=$i.website}; if ($i.email){$email=$i.email}
  } catch {}
}

Write-Host ''
Line
Write-Host "  $bizName" -ForegroundColor Green
Line
Write-Host "  Product        : $product" -ForegroundColor White
if ($branch) { Write-Host "  Branch         : $branch" -ForegroundColor White }
Write-Host "  Company        : $company" -ForegroundColor White
Write-Host "  Version        : $version" -ForegroundColor White
Write-Host "  Contact number : $phone"   -ForegroundColor White
Write-Host "  Website        : $website" -ForegroundColor White
Write-Host "  Email          : $email"   -ForegroundColor White
Line
Write-Host ''

Say 'Starting local database...' 'Cyan'
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1"
if ($LASTEXITCODE -ne 0) { Say 'The local database did not start.' 'Red'; exit 1 }

# Private PHP only - never the one installed on the PC.
$php = Get-ChildItem -Path (Join-Path $root 'runtime\php') -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
       Select-Object -First 1
if (-not $php) { Say 'PHP was not found. Please run INSTALL_OFFLINE.bat first.' 'Red'; exit 1 }
$phpExe = $php.FullName
$phpIni = Join-Path (Split-Path $phpExe) 'php.ini'

$port = 0
for ($p = 8080; $p -lt 8120; $p++) {
  try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1',$p); $c.Close() }
  catch { $port = $p; break }
}
if ($port -eq 0) { Say 'No free port available (8080-8119).' 'Red'; exit 1 }

Say "Starting the software on http://localhost:$port ..." 'Cyan'
$srv = Start-Process -FilePath $phpExe `
        -ArgumentList "-c","$phpIni","-S","127.0.0.1:$port","-t","public","public/router.php" `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2
Start-Process "http://localhost:$port/login.html"

Write-Host ''
Say "The software is running. Keep this window open." 'Green'
Say "Closing this window will stop the software." 'DarkGray'
try { Wait-Process -Id $srv.Id } finally {
  Stop-Process -Id $srv.Id -ErrorAction SilentlyContinue
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1" -StopServer | Out-Null
}
