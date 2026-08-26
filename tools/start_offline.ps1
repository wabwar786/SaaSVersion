# ============================================================
# start_offline.ps1 — offline software chalata hai:
#   1) portable MariaDB start (agar band ho)
#   2) private PHP se local web server
#   3) browser khol deta hai
# Kuch bhi system par install nahi hota.
# ============================================================
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Say($m,$c='Gray'){ Write-Host $m -ForegroundColor $c }

Say "Local database start ho raha hai..." 'Cyan'
& powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1"
if ($LASTEXITCODE -ne 0) { Say "Database start nahi hua." 'Red'; exit 1 }

# PHP dhoondo (private runtime pehle)
$php = Get-ChildItem -Path "$root\runtime" -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue | Select-Object -First 1
$phpExe = if ($php) { $php.FullName } else { (Get-Command php -ErrorAction SilentlyContinue).Source }
if (-not $phpExe) {
  Say "PHP nahi mila. Pehle INSTALL_OFFLINE.bat chalayein." 'Red'; exit 1
}

# free port
$port = 8080
for ($p = 8080; $p -lt 8120; $p++) {
  try { $c = New-Object Net.Sockets.TcpClient; $c.Connect('127.0.0.1',$p); $c.Close() }
  catch { $port = $p; break }
}

Say "Software start ho raha hai (http://localhost:$port) ..." 'Cyan'
$srv = Start-Process -FilePath $phpExe `
        -ArgumentList "-S","127.0.0.1:$port","-t","public","public/router.php" `
        -WorkingDirectory $root -WindowStyle Hidden -PassThru
Start-Sleep -Seconds 2
Start-Process "http://localhost:$port/login.html"

Say ""
Say "Software chal raha hai. Yeh window band karne se software band ho jayega." 'Green'
Say "Band karne ke liye is window mein Ctrl+C dabayein ya window close karein." 'DarkGray'
try { Wait-Process -Id $srv.Id } finally {
  Stop-Process -Id $srv.Id -ErrorAction SilentlyContinue
  & powershell.exe -NoProfile -ExecutionPolicy Bypass -File "$root\tools\resolve_mariadb.ps1" -StopServer | Out-Null
}
