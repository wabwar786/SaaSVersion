<#
  fix_vcruntime.ps1 — "VCRUNTIME140.dll 14.0 is not compatible with this
  PHP build linked with 14.29" ka hal.

  MASLA
  Kuch Windows machines par System32 mein PURANI Visual C++ runtime hoti
  hai (14.0 = VC++ 2015). Hamari PHP 8.2 build ko 14.29+ chahiye
  (VS2019/2022). Aisi soorat mein php.exe chalta hi nahi — aur phir
  diagnostics mein saari extensions "MISSING" aur "Application boot
  FAILED" dikhta hai, jo asli reason chhupa deta hai.

  HAL
  Windows kisi exe ki DLL dependencies first USI FOLDER mein dhoondta hai
  jahan exe hai, phir System32. (vcruntime140.dll "KnownDLL" nahi hai, is
  liye yeh tarteeb lagu hoti hai.) Chunanche new DLL ki copy php.exe ke
  saath rakh dene se System32 ki old copy be-asar ho jati hai — aur
  Windows par kuch install karne ki zaroorat nahi rehti. Yehi hamare
  product ka waada hai: "Nothing is installed on Windows".

  Teen raste, isi tarteeb se:
    1) Package ke apne bundled DLLs  (vendor\vcruntime\)  <- behtareen
    2) PC par kahin present new copy dhoond kar
    3) Warna: clear hidayat ke vc_redist.x64.exe install please

  Exit: 0 = fine ho gaya / first se fine tha, 1 = user ko kuch karna hai
#>
param([string]$PhpExe = '')

$ErrorActionPreference = 'Continue'
$root = Split-Path -Parent $PSScriptRoot
function Say($m, $c = 'Gray') { Write-Host $m -ForegroundColor $c }

if (-not $PhpExe -or -not (Test-Path $PhpExe)) {
  $f = Get-ChildItem -Path (Join-Path $root 'runtime\php') -Filter 'php.exe' -Recurse -ErrorAction SilentlyContinue |
       Select-Object -First 1
  if ($f) { $PhpExe = $f.FullName }
}
if (-not $PhpExe -or -not (Test-Path $PhpExe)) { Say 'php.exe not found.' 'Red'; exit 1 }

$phpDir = Split-Path -Parent $PhpExe
$needed = @('vcruntime140.dll', 'vcruntime140_1.dll', 'msvcp140.dll')

# ---------- 1) kya waqai masla hai? ----------
$out = & $PhpExe -v 2>&1 | Out-String
if ($out -notmatch 'VCRUNTIME140|not compatible with this PHP build') {
  Say 'The Visual C++ runtime is fine.' 'Green'
  exit 0
}

Say ''
Say 'This computer has an old Visual C++ runtime.' 'Yellow'
Say 'PHP ko new chahiye. Theek karne ki koshish...' 'Yellow'

function Get-DllVersion($path) {
  try { return [System.Diagnostics.FileVersionInfo]::GetVersionInfo($path).FileVersion } catch { return '' }
}
function Is-NewEnough($path) {
  $v = Get-DllVersion $path
  if (-not $v) { return $false }
  try { return ([version]($v -replace '[^0-9\.].*$','')) -ge ([version]'14.29') } catch { return $false }
}

# ---------- 2) package ke bundled DLLs ----------
$bundle = Join-Path $root 'vendor\vcruntime'
$copied = 0
if (Test-Path $bundle) {
  foreach ($n in $needed) {
    $src = Join-Path $bundle $n
    if (Test-Path $src) { Copy-Item $src (Join-Path $phpDir $n) -Force -ErrorAction SilentlyContinue; $copied++ }
  }
  if ($copied -gt 0) { Say "  Package ke apne runtime files use kiye ($copied)." 'Gray' }
}

# ---------- 3) PC par kahin nayi copy ----------
if ($copied -eq 0) {
  $hunt = @(
    "$env:SystemRoot\System32",
    "$env:ProgramFiles\Microsoft Visual Studio",
    "${env:ProgramFiles(x86)}\Microsoft Visual Studio",
    "$env:ProgramFiles\Common Files\Microsoft Shared"
  )
  foreach ($n in $needed) {
    foreach ($h in $hunt) {
      if (-not (Test-Path $h)) { continue }
      $hit = Get-ChildItem -Path $h -Filter $n -Recurse -ErrorAction SilentlyContinue |
             Where-Object { Is-NewEnough $_.FullName } | Select-Object -First 1
      if ($hit) {
        Copy-Item $hit.FullName (Join-Path $phpDir $n) -Force -ErrorAction SilentlyContinue
        Say ("  Mila: {0}  ({1})" -f $n, (Get-DllVersion $hit.FullName)) 'Gray'
        $copied++
        break
      }
    }
  }
}

# ---------- 4) dobara check ----------
if ($copied -gt 0) {
  $out2 = & $PhpExe -v 2>&1 | Out-String
  if ($out2 -notmatch 'VCRUNTIME140|not compatible with this PHP build') {
    Say 'Fixed — PHP now runs.' 'Green'
    exit 0
  }
  Say '  The problem remains even after copying.' 'DarkYellow'
}

# ---------- 5) user ko saaf hidayat ----------
Say ''
Say '  WHAT TO DO' 'Yellow'
Say '  Install this once on this computer, then run setup again:' 'Gray'
Say '' 
Say '     Microsoft Visual C++ 2015-2022 Redistributable (x64)' 'White'
Say '     https://aka.ms/vs/17/release/vc_redist.x64.exe' 'White'
Say ''
Say '  (It is a small Microsoft file and installs in about a minute.)' 'DarkGray'
Say ''
Say '  OR — from another computer that already has them, C:\Windows\System32' 'DarkGray'
Say '  copy these three files into this package vendor\vcruntime folder' 'DarkGray'
Say '  then run setup again:' 'DarkGray'
Say '     vcruntime140.dll   vcruntime140_1.dll   msvcp140.dll' 'Gray'
Say ''
exit 1

# build: V64.1 build 2026-08-27
