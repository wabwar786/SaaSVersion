$ErrorActionPreference="Stop"
$ProjectRoot=Split-Path -Parent $PSScriptRoot
$Selected=Join-Path $ProjectRoot "runtime\selected_php.txt"
$Ini=Join-Path $ProjectRoot "runtime\config\php.ini"

Add-Type -AssemblyName PresentationFramework -ErrorAction SilentlyContinue

try{
    if(-not (Test-Path $Selected)){throw "Run START_RESTAURANT.bat once first."}
    $PhpExe=(Get-Content $Selected -Raw).Trim()
    if(-not (Test-Path $PhpExe)){throw "Selected PHP runtime is missing."}

    $out=& $PhpExe -c $Ini (Join-Path $ProjectRoot "scripts\ensure_v13_login.php") --force 2>&1
    if($LASTEXITCODE -ne 0){throw ($out|Out-String)}

    [System.Windows.MessageBox]::Show(
      "Administrator login reset successfully.`n`nEmail: admin@urbanspoon.local`nPassword: Admin@123",
      "Restaurant Software",
      "OK",
      "Information"
    )|Out-Null
}catch{
    [System.Windows.MessageBox]::Show(
      "Unable to reset administrator login.`n`n$($_.Exception.Message)",
      "Restaurant Software",
      "OK",
      "Error"
    )|Out-Null
    exit 1
}
