<#
  update.ps1 — offline software ka POORA update, ek jagah.

  ============================================================
  YEH KYUN BANI (asal masle jo customer par lage)
  ============================================================
  1) `INSTALL_UPDATE.bat` sirf PAANCH migrations chalati thi, cloud 30+.
     Yani naya CODE aa jata tha magar SCHEMA nahi — software update ho
     kar toot jata aur wajah kahin nazar na aati. Ab `run_migrations.php`
     wahi poori fehrist chalati hai jo cloud par chalti hai
     (`scripts/migration_order.php` — ek hi source).

  2) `INSTALL_UPDATE.bat` KHUD zip ke andar hai, aur extract loop usay
     chalte hue replace kar deta tha. cmd.exe batch file ko line-by-line
     parhta hai, is liye khud ko badalna bigaar paida kar sakta hai. Ab
     saara kaam is .ps1 mein hai — PowerShell poori script pehle memory
     mein parh leta hai, phir chalata hai, is liye replace ho jaye to
     bhi mehfooz hai. `.bat` sirf teen line ka stub reh gaya hai jo kabhi
     nahi badlega.

  3) Do alag qadam thay (GET_UPDATE phir INSTALL_UPDATE). Ab
     `-Mode all` dono ek click mein kar deta hai.

  ============================================================
  Modes
  ============================================================
    -Mode check     sirf dekho ke naya build hai ya nahi
    -Mode download  naya ho to le aao (install nahi)
    -Mode install   jo download ho chuka hai wo lagao
    -Mode all       check + download + install (UPDATE.bat isay bulati hai)

  AAP KA DATA MEHFOOZ REHTA HAI: sirf program ki files badalti hain.
  data\, config\, storage\, backup\, updates\ aur runtime ka PHP/MariaDB
  ko haath nahi lagta.
#>
param([ValidateSet('check', 'download', 'install', 'all')][string]$Mode = 'all')

$ErrorActionPreference = 'Stop'
$ProgressPreference    = 'SilentlyContinue'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# ============================================================
# V167 — UPDATE APNA LOG KHUD LIKHTI HAI.
#
# MASLA: update ke doran koi paigham ya error aata tha aur window
# mein itni tezi se guzar jata ke customer usay likh hi nahi sakta
# tha. Phir poochne par bhi koi nahi bata sakta ke hua kya tha.
#
# AB: shuru se aakhir tak sab kuch ek file mein bhi jata hai:
#     storage\logs\update-<tareekh>.log
#
# `storage\` ko update KABHI replace nahi karta (keep list mein hai),
# is liye yeh log update ke baad bhi salamat rehta hai. Aakhir mein
# us ka poora pata screen par likh diya jata hai.
#
# Transcript har PowerShell host par nahi chalti (kuch purane
# Windows par nahi), is liye nakaami par kaam rukta nahi — sirf log
# nahi banta.
# ============================================================
$logDir = Join-Path $root 'storage\logs'
try { New-Item -ItemType Directory -Force -Path $logDir | Out-Null } catch { }
$updateLog = Join-Path $logDir ('update-' + (Get-Date -Format 'yyyyMMdd_HHmmss') + '.log')
$logOn = $false
try {
  Start-Transcript -Path $updateLog -Force -ErrorAction Stop | Out-Null
  $logOn = $true
} catch { $logOn = $false }

# ------------------------------------------------------------
# V143 ka guard — helper file na mile to bhi chalte raho.
# ------------------------------------------------------------
$__nh = Join-Path $PSScriptRoot 'native_helper.ps1'
if (Test-Path $__nh) { . $__nh }
if (-not (Get-Command Invoke-Native -ErrorAction SilentlyContinue)) {
  function Invoke-Native {
    param([Parameter(Mandatory = $true)][string] $Exe, [string[]] $Arguments = @())
    $ErrorActionPreference = 'Continue'
    if (Test-Path variable:PSNativeCommandUseErrorActionPreference) {
      $PSNativeCommandUseErrorActionPreference = $false
    }
    $global:NativeExitCode = 0
    $text = ''
    try {
      $raw = & $Exe @Arguments 2>&1
      $global:NativeExitCode = if ($null -ne $LASTEXITCODE) { $LASTEXITCODE } else { 0 }
      $lines = @()
      foreach ($item in $raw) {
        if ($null -eq $item) { continue }
        if ($item -is [System.Management.Automation.ErrorRecord]) { $lines += $item.ToString() }
        else { $lines += [string]$item }
      }
      $text = ($lines -join "`r`n")
    } catch { $global:NativeExitCode = 1; $text = ($_ | Out-String) }
    return $text
  }
}

function Line { Write-Host ('=' * 60) -ForegroundColor DarkGray }
function Step($n, $m) { Write-Host "[$n/5] $m" -ForegroundColor Cyan }
function Info($m) { Write-Host "      $m" -ForegroundColor Gray }
function Good($m) { Write-Host "      $m" -ForegroundColor Green }
function Warn($m) { Write-Host "      $m" -ForegroundColor Yellow }
function Bad($m)  { Write-Host "      $m" -ForegroundColor Red }

$product = 'SmartPOS'
if (Test-Path "$root\runtime\app.info") {
  try { $i = Get-Content "$root\runtime\app.info" -Raw | ConvertFrom-Json
        if ($i.product) { $product = $i.product } } catch {}
}

Write-Host ''
Line
Write-Host "       $product - UPDATE" -ForegroundColor Green
Line
# V167 — pata SHURU mein bhi, kyunke agar beech mein kuch toot jaye to
# aakhir wali line kabhi chhapti hi nahi.
if ($logOn) { Info "Log: $updateLog" } else { Warn 'This run is not being logged (transcript unavailable).' }
Write-Host ''

# ---------- PHP ----------
$phpExe = $null
$probe = Get-ChildItem -Path (Join-Path $root 'runtime\php') -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
         Select-Object -First 1
if ($probe) { $phpExe = $probe.FullName }
if (-not $phpExe) {
  Bad 'PHP runtime not found. Run INSTALL_OFFLINE.bat first.'
  exit 1
}
$phpIni = Join-Path (Split-Path $phpExe) 'php.ini'
$updDir = Join-Path $root 'updates'
New-Item -ItemType Directory -Force -Path $updDir | Out-Null

function Php([string[]]$a) {
  $args = @()
  if (Test-Path $phpIni) { $args += @('-c', $phpIni) }
  return (Invoke-Native -Exe $phpExe -Arguments ($args + $a))
}

$installedBuild = ''
if (Test-Path "$root\VERSION") { $installedBuild = (Get-Content "$root\VERSION" -Raw).Trim() }

# =====================================================================
# 1) CHECK  +  2) DOWNLOAD
# =====================================================================
$didDownload = $false
if ($Mode -in @('check', 'download', 'all')) {
  Step 1 'Checking for a new build...'
  Info "Installed : $installedBuild"

  Remove-Item (Join-Path $updDir 'available.txt') -Force -ErrorAction SilentlyContinue
  Remove-Item (Join-Path $updDir 'offline.txt')   -Force -ErrorAction SilentlyContinue

  $wantDl = ($Mode -ne 'check')
  $out = Php(@('scripts/self_update.php') + $(if ($wantDl) { @('--download') } else { @() }))

  foreach ($l in ($out -split "`r?`n")) {
    $t = $l.Trim()
    if ($t -match '^available:\s*(.+)$') { Info "On portal : $($Matches[1])" }
  }

  if ($out -match 'UPDATE_OFFLINE') {
    Bad 'Could not reach the portal - check the internet connection.'
    Bad 'This does NOT mean you already have the latest build.'
    exit 1
  }
  if ($out -match 'UPDATE_NONE') {
    Good 'Already up to date. Nothing to do.'
    Write-Host ''
    exit 0
  }
  if ($out -match 'UPDATE_SKIPPED') {
    Warn 'This copy is the cloud server, not an offline install.'
    exit 0
  }
  if ($out -match 'UPDATE_FAILED') {
    Bad 'The download did not complete:'
    foreach ($l in ($out -split "`r?`n")) {
      if ($l -match 'UPDATE_FAILED') { Bad ('  ' + $l.Trim()) }
    }
    exit 1
  }

  if ($wantDl) {
    Step 2 'Downloading the complete package...'
    if ($out -match 'UPDATE_DOWNLOADED\s+(\S+)\s*\(([^)]+)\)') {
      Good ("Downloaded: " + $Matches[1] + "  (" + $Matches[2] + ")")
      $didDownload = $true
    } elseif (Test-Path (Join-Path $updDir 'ready.txt')) {
      Good 'Downloaded.'
      $didDownload = $true
    } else {
      Bad 'The portal did not return a package.'
      Bad 'Check that this computer is still linked to the business.'
      exit 1
    }
  } else {
    if ($out -match 'UPDATE_AVAILABLE\s+(.+)') {
      Warn ('A new build is available: ' + $Matches[1].Trim())
      Info 'Run UPDATE.bat to download and install it.'
    }
    Write-Host ''
    exit 0
  }

  if ($Mode -eq 'download') {
    Write-Host ''
    Info 'Downloaded only. Run INSTALL_UPDATE.bat to apply it.'
    Write-Host ''
    exit 0
  }
}

# =====================================================================
# 3) INSTALL
# =====================================================================
$readyFile = Join-Path $updDir 'ready.txt'
if (-not (Test-Path $readyFile)) {
  if ($Mode -eq 'install') {
    Info 'No update is waiting. Run UPDATE.bat (or GET_UPDATE.bat) first.'
    Write-Host ''
    exit 0
  }
  Bad 'Nothing to install.'
  exit 1
}
$pkgName = (Get-Content $readyFile -Raw).Trim()
$pkg = Join-Path $updDir $pkgName
if (-not (Test-Path $pkg)) {
  Bad "Update file not found: updates\$pkgName"
  Remove-Item $readyFile -Force -ErrorAction SilentlyContinue
  exit 1
}

Write-Host ''
Info "Package : $pkgName"
Info 'The software must be closed. Close its window if it is open.'
Write-Host ''

# ---------- 3a) backup ----------
Step 3 'Backing up the current files...'
$stamp = Get-Date -Format 'yyyyMMdd_HHmm'
$bkDir = Join-Path $root "backup\before-$stamp"
$bk = Php(@('-r', @"
`$d='backup/before-$stamp';@mkdir(`$d,0775,true);
foreach(['public','src','approved_ui','scripts','tools'] as `$f){
 if(is_dir(`$f)){`$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(`$f,FilesystemIterator::SKIP_DOTS));
 foreach(`$i as `$x){if(!`$x->isFile())continue;`$t=`$d.'/'.`$x->getPathname();@mkdir(dirname(`$t),0775,true);@copy(`$x->getPathname(),`$t);}}}
foreach(['runtime/app.sealed','runtime/app.key','runtime/boot.php','runtime/app.info','VERSION'] as `$x){
 if(is_file(`$x)){@mkdir(dirname(`$d.'/'.`$x),0775,true);@copy(`$x,`$d.'/'.`$x);}}
echo 'BACKUP_OK';
"@))
if ($bk -match 'BACKUP_OK') { Good "Saved to backup\before-$stamp" }
else { Warn 'Backup could not be completed - continuing anyway.' }

# ---------- 3b) extract ----------
Step 4 'Applying the new files...'
# `keep` = jin folders ko chhoona NAHI. Customer ka data, uski settings,
# aur PHP/MariaDB jo pehle se install hain.
$ex = Php(@('-r', @"
`$z=new ZipArchive();
if(`$z->open('updates/$pkgName')!==true){echo 'OPEN_FAILED';exit(1);}
`$keep=['data/','config/','storage/','backup/','updates/','runtime/php/','runtime/mariadb/','runtime/data/'];
`$n=0;`$skip=0;
for(`$i=0;`$i<`$z->numFiles;`$i++){
  `$e=`$z->getNameIndex(`$i);
  if(substr(`$e,-1)==='/')continue;
  `$s=false; foreach(`$keep as `$k){if(strpos(`$e,`$k)===0)`$s=true;}
  if(`$s){`$skip++;continue;}
  `$z->extractTo('.', `$e); `$n++;
}
`$z->close();
echo 'EXTRACTED '.`$n.' kept '.`$skip;
"@))
if ($ex -match 'OPEN_FAILED') {
  Bad 'The update package could not be opened (download may be damaged).'
  Bad 'Delete updates\ and run UPDATE.bat again.'
  exit 1
}
if ($ex -match 'EXTRACTED\s+(\d+)\s+kept\s+(\d+)') {
  Good ($Matches[1] + ' files updated, ' + $Matches[2] + ' of your own files left untouched')
} else {
  Bad 'The files could not be applied:'
  foreach ($l in ($ex -split "`r?`n")) { if ($l.Trim()) { Bad ('  ' + $l.Trim()) } }
  Bad "Your previous files are safe in backup\before-$stamp"
  exit 1
}

# purani cache — warna naya code purani compiled copy ke sath chalta hai
Php(@('-r', @"
`$d='runtime/.cache';
if(is_dir(`$d)){`$i=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(`$d,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach(`$i as `$x){`$x->isDir()?@rmdir(`$x->getPathname()):@unlink(`$x->getPathname());}@rmdir(`$d);}
echo 'CACHE_CLEARED';
"@)) | Out-Null

# ---------- 3c) migrations ----------
Step 5 'Updating the database...'
# YEH WOH QADAM HAI JO PEHLE ADHOORA THA: sirf 5 migrations chalti thin,
# cloud par 30+. Ab wahi ek fehrist dono taraf chalti hai.
$runner = Join-Path $root 'scripts\run_migrations.php'
$mig = ''
if (Test-Path $runner) {
  $mig = Php(@('scripts/run_migrations.php', '--node', '--stage=all', '--quiet'))
  foreach ($l in ($mig -split "`r?`n")) {
    $t = $l.Trim()
    if ($t -match '^(FAIL|!!)') { Bad $t }
  }
  # `stage=...` darmiyan mein aata hai - regex usay chhor kar aage parhta hai,
  # taake runner ka output thora badle to yeh line khamoshi se na toote.
  if ($mig -match 'MIGRATIONS_DONE.*?ok=(\d+)\s+skipped=(\d+)\s+failed=(\d+)') {
    $okN = $Matches[1]; $skN = $Matches[2]; $faN = [int]$Matches[3]
    if ($faN -eq 0) { Good "$okN steps applied, $skN skipped." }
    else {
      Bad "$faN step(s) failed - the software may not work correctly."
      Bad "Previous files are in backup\before-$stamp"
      Bad 'Please contact support@wabwar.pk with this message.'
      exit 1
    }
  } else {
    Warn 'The database step did not report a result. Check DIAGNOSE.bat.'
  }
} else {
  # Purana seal (run_migrations.php us mein nahi hai) — purane tareeqe par
  # chalo, taake update phir bhi mukammal ho. Agla update naya runner le
  # aayega aur yeh shaakh dobara nahi chalegi.
  Warn 'This package has no migration runner - using the older list.'
  foreach ($m in @('install_schema','migrate_sync_columns','migrate_delete_support',
                   'migrate_print_rule_default','migrate_fiscal','migrate_security',
                   'migrate_error_log','migrate_licence_key','migrate_retail',
                   'seed_industry_modules','seed_platform_modules','migrate_module_ids')) {
    if (Test-Path (Join-Path $root "scripts\$m.php")) { Php(@("scripts/$m.php")) | Out-Null }
  }
  Good 'Database updated (older list).'
}

# ---------- done ----------
Remove-Item $readyFile -Force -ErrorAction SilentlyContinue
Remove-Item (Join-Path $updDir 'available.txt') -Force -ErrorAction SilentlyContinue
Remove-Item $pkg -Force -ErrorAction SilentlyContinue

$newBuild = ''
if (Test-Path "$root\VERSION") { $newBuild = (Get-Content "$root\VERSION" -Raw).Trim() }

Write-Host ''
Line
Good 'Update complete.'
if ($newBuild -ne '' -and $newBuild -ne $installedBuild) {
  Info "$installedBuild  ->  $newBuild"
} elseif ($newBuild -ne '') {
  Info "Build: $newBuild"
}
Info 'Open the software with START_OFFLINE.bat'
Info "Previous files are kept in backup\before-$stamp"
if ($logOn) {
  Info "A full log of this update is saved at:"
  Info "  $updateLog"
  Info 'Agar koi masla ho to yehi file bhej dein — sab kuch is mein hai.'
}
Line
Write-Host ''
try { if ($logOn) { Stop-Transcript | Out-Null } } catch { }
exit 0

# build: V167 build 2026-10-25
