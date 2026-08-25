$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$RuntimeRoot = Join-Path $ProjectRoot "runtime"
$PrivateRoot = Join-Path $RuntimeRoot "private\php"
$PrivatePhp  = Join-Path $PrivateRoot "php.exe"
$ConfigRoot  = Join-Path $RuntimeRoot "config"
$SelectedTxt = Join-Path $RuntimeRoot "selected_php.txt"
$SelectedIni = Join-Path $ConfigRoot "php.ini"
$PackageZip  = Join-Path $RuntimeRoot "php-runtime.zip"
$OfflineZip  = Join-Path $ProjectRoot "php-runtime.zip"

New-Item -ItemType Directory -Force -Path $RuntimeRoot | Out-Null
New-Item -ItemType Directory -Force -Path $ConfigRoot | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $ProjectRoot "storage\logs") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $ProjectRoot "storage\sessions") | Out-Null

function Step([string]$Text) {
    Write-Host ""
    Write-Host "============================================================"
    Write-Host $Text
    Write-Host "============================================================"
}

function Get-Version([string]$Exe) {
    try {
        $o = & $Exe -n -r "echo PHP_VERSION;" 2>$null
        if ($LASTEXITCODE -ne 0) { return $null }
        return [version](($o | Out-String).Trim())
    } catch { return $null }
}

function Make-AppIni([string]$Exe) {
    $PhpDir = Split-Path -Parent $Exe
    $ExtDir = Join-Path $PhpDir "ext"
    if (-not (Test-Path $ExtDir)) {
        return $false
    }

    $log = (Join-Path $ProjectRoot "storage\logs\php-error.log").Replace('\','/')
    $ses = (Join-Path $ProjectRoot "storage\sessions").Replace('\','/')
    $ext = $ExtDir.Replace('\','/')

    $iniText = @"
[PHP]
engine=On
short_open_tag=Off
expose_php=Off
display_errors=Off
display_startup_errors=Off
log_errors=On
error_log="$log"
memory_limit=256M
max_execution_time=120
max_input_time=120
post_max_size=32M
upload_max_filesize=25M
date.timezone=Asia/Karachi
extension_dir="$ext"
extension=mysqli
extension=pdo_mysql
extension=mbstring
extension=openssl
extension=curl
extension=fileinfo

[Session]
session.name=AIO_RESTAURANT_V14_SESSION
session.save_path="$ses"
session.use_strict_mode=1
session.use_only_cookies=1
session.cookie_httponly=1
session.cookie_samesite=Lax
session.gc_maxlifetime=28800
"@
    Set-Content -Path $SelectedIni -Value $iniText -Encoding ASCII

    try {
        $mods = & $Exe -c $SelectedIni -m 2>&1
        if ($LASTEXITCODE -ne 0) { return $false }
        foreach($required in @("PDO","pdo_mysql","mysqli","mbstring","openssl")) {
            if ($mods -notcontains $required) { return $false }
        }
        return $true
    } catch { return $false }
}

function Test-Candidate([string]$Exe) {
    if ([string]::IsNullOrWhiteSpace($Exe) -or -not (Test-Path $Exe)) { return $false }
    $ver = Get-Version $Exe
    if ($null -eq $ver -or $ver -lt [version]"8.1.0") { return $false }
    if (-not (Make-AppIni $Exe)) { return $false }
    Set-Content -Path $SelectedTxt -Value $Exe -Encoding ASCII
    Write-Host "Using PHP:"
    Write-Host $Exe
    Write-Host "Version: $ver"
    return $true
}

function Existing-PhpCandidates {
    $list = New-Object System.Collections.Generic.List[string]

    try {
        $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
        if ($cmd -and $cmd.Source) { $list.Add($cmd.Source) }
    } catch {}

    if ($env:PHP_HOME) { $list.Add((Join-Path $env:PHP_HOME "php.exe")) }

    foreach($p in @(
        "C:\php\php.exe",
        "C:\xampp\php\php.exe",
        "C:\xampp8\php\php.exe",
        "C:\Program Files\PHP\php.exe",
        "C:\Program Files (x86)\PHP\php.exe",
        "C:\tools\php\php.exe"
    )) { $list.Add($p) }

    try {
        $parent = Split-Path $ProjectRoot -Parent
        Get-ChildItem -Path $parent -Directory -ErrorAction SilentlyContinue |
          Where-Object { $_.Name -like "RestaurantSoftware*" } |
          ForEach-Object {
            $sibling = Join-Path $_.FullName "runtime\private\php\php.exe"
            if (Test-Path $sibling) { $list.Add($sibling) }
          }
    } catch {}

    foreach($pattern in @(
        "C:\laragon\bin\php\*\php.exe",
        "C:\wamp64\bin\php\*\php.exe",
        "C:\wamp\bin\php\*\php.exe"
    )) {
        try {
            Get-ChildItem -Path $pattern -ErrorAction SilentlyContinue |
                Sort-Object FullName -Descending |
                ForEach-Object { $list.Add($_.FullName) }
        } catch {}
    }

    return $list | Select-Object -Unique
}

function Is-Zip([string]$Path) {
    if (-not (Test-Path $Path)) { return $false }
    try {
        $fs=[IO.File]::OpenRead($Path)
        try {
            if($fs.Length -lt 1000000){ return $false }
            $a=$fs.ReadByte();$b=$fs.ReadByte()
            return ($a -eq 0x50 -and $b -eq 0x4B)
        } finally { $fs.Dispose() }
    } catch { return $false }
}

function Download-WithFallback([string]$Url,[string]$Dest) {
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    } catch {}

    if(Test-Path $Dest){Remove-Item $Dest -Force -ErrorAction SilentlyContinue}

    # 1. BITS (works well on older Windows)
    try {
        Import-Module BitsTransfer -ErrorAction Stop
        Start-BitsTransfer -Source $Url -Destination $Dest -ErrorAction Stop
        if(Is-Zip $Dest){ return $true }
    } catch {}

    # 2. bitsadmin fallback for Windows 7
    try {
        $bits = Get-Command bitsadmin.exe -ErrorAction SilentlyContinue
        if($bits) {
            if(Test-Path $Dest){Remove-Item $Dest -Force -ErrorAction SilentlyContinue}
            & $bits.Source /transfer "RestaurantPhpRuntime" /download /priority normal $Url $Dest | Out-Null
            if(Is-Zip $Dest){ return $true }
        }
    } catch {}

    # 3. certutil
    try {
        $cert = Get-Command certutil.exe -ErrorAction SilentlyContinue
        if($cert) {
            if(Test-Path $Dest){Remove-Item $Dest -Force -ErrorAction SilentlyContinue}
            & $cert.Source -urlcache -split -f $Url $Dest | Out-Null
            if(Is-Zip $Dest){ return $true }
        }
    } catch {}

    # 4. PowerShell web request
    try {
        if(Test-Path $Dest){Remove-Item $Dest -Force -ErrorAction SilentlyContinue}
        Invoke-WebRequest -Uri $Url -OutFile $Dest -UseBasicParsing -TimeoutSec 240
        if(Is-Zip $Dest){ return $true }
    } catch {}

    # 5. WebClient
    try {
        if(Test-Path $Dest){Remove-Item $Dest -Force -ErrorAction SilentlyContinue}
        $wc=New-Object System.Net.WebClient
        $wc.Headers.Add("User-Agent","Mozilla/5.0")
        $wc.DownloadFile($Url,$Dest)
        $wc.Dispose()
        if(Is-Zip $Dest){ return $true }
    } catch {}

    # 6. curl if available
    try {
        $curl=Get-Command curl.exe -ErrorAction SilentlyContinue
        if($curl) {
            if(Test-Path $Dest){Remove-Item $Dest -Force -ErrorAction SilentlyContinue}
            & $curl.Source -L --fail --retry 3 --connect-timeout 30 -o $Dest $Url
            if(Is-Zip $Dest){ return $true }
        }
    } catch {}

    return $false
}

function Install-PrivatePhp {
    Step "No suitable PHP found - preparing private Restaurant PHP"

    # If developer/customer has already placed an offline PHP zip next
    # to START_RESTAURANT.bat, use it without internet.
    if (Is-Zip $OfflineZip) {
        Write-Host "Using bundled php-runtime.zip"
        Copy-Item $OfflineZip $PackageZip -Force
    } else {
        # PHP 8.2 remains compatible with Windows 7 / Server 2008 R2.
        $urls = @(
          "https://downloads.php.net/~windows/releases/latest/php-8.2-nts-Win32-vs16-x64-latest.zip",
          "https://downloads.php.net/~windows/releases/php-8.2.29-nts-Win32-vs16-x64.zip"
        )
        $ok=$false
        foreach($u in $urls) {
            Write-Host "Downloading private PHP runtime..."
            Write-Host $u
            if(Download-WithFallback $u $PackageZip){$ok=$true;break}
        }
        if(-not $ok) {
            throw @"
No installed PHP 8.1+ was found and the private PHP package could not be downloaded.

This PC's MySQL/Workbench installation is NOT the problem; Workbench does not include PHP.

For a fully offline installation, place the official PHP 8.2 NTS x64 ZIP beside
START_RESTAURANT.bat and rename it:

php-runtime.zip

Then run START_RESTAURANT.bat again.
"@
        }
    }

    if(Test-Path $PrivateRoot){Remove-Item $PrivateRoot -Recurse -Force}
    New-Item -ItemType Directory -Force -Path $PrivateRoot | Out-Null
    Expand-Archive -Path $PackageZip -DestinationPath $PrivateRoot -Force
    Remove-Item $PackageZip -Force -ErrorAction SilentlyContinue

    if(-not (Test-Path $PrivatePhp)){ throw "Private PHP extracted, but php.exe was not found." }

    # PHP needs Visual C++ runtime. Try it first; if php starts, nothing else is installed.
    if(Test-Candidate $PrivatePhp){ return $true }

    Step "Preparing Microsoft Visual C++ Runtime"
    $vc = Join-Path $RuntimeRoot "vc_redist.x64.exe"
    $vcUrl="https://aka.ms/vs/17/release/vc_redist.x64.exe"

    $gotVc=$false
    try {
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Start-BitsTransfer -Source $vcUrl -Destination $vc -ErrorAction Stop
        if((Test-Path $vc) -and ((Get-Item $vc).Length -gt 1000000)){$gotVc=$true}
    } catch {}
    if(-not $gotVc) {
        try {
            Invoke-WebRequest -Uri $vcUrl -OutFile $vc -UseBasicParsing -TimeoutSec 180
            if((Test-Path $vc) -and ((Get-Item $vc).Length -gt 1000000)){$gotVc=$true}
        } catch {}
    }

    if($gotVc) {
        try {
            Start-Process -FilePath $vc -ArgumentList "/install","/quiet","/norestart" -Wait -Verb RunAs
            Start-Sleep -Seconds 2
        } catch {}
    }

    if(-not (Test-Candidate $PrivatePhp)) {
        throw "Private PHP was prepared but cannot start. Install/update Microsoft Visual C++ 2015-2022 x64 Runtime and run again."
    }
    return $true
}

Step "Checking for existing PHP"

$found=$false
foreach($candidate in (Existing-PhpCandidates)) {
    if(Test-Candidate $candidate){$found=$true;break}
}

if(-not $found -and (Test-Path $PrivatePhp)) {
    if(Test-Candidate $PrivatePhp){$found=$true}
}

if(-not $found) {
    if(Install-PrivatePhp){$found=$true}
}

if(-not $found -or -not (Test-Path $SelectedTxt)) {
    throw "No working PHP runtime could be prepared."
}

Write-Host ""
Write-Host "PHP_READY"
Write-Host (Get-Content $SelectedTxt -Raw).Trim()
