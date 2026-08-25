$ErrorActionPreference="Stop"
$Build="AIO-RESTAURANT-V13-WORKABLE-RESET-20260824"
$port=$null
foreach($p in 8920..8930){
  try{
    $r=Invoke-WebRequest -Uri "http://127.0.0.1:$p/build-id.txt?t=$([DateTime]::UtcNow.Ticks)" -UseBasicParsing -TimeoutSec 1
    if($r.Content.Trim() -eq $Build){$port=$p;break}
  }catch{}
}
if($null -eq $port){
  Add-Type -AssemblyName PresentationFramework -ErrorAction SilentlyContinue
  [System.Windows.MessageBox]::Show(
    "Restaurant Software is not running. Start it first, then run RESET_DEMO_UI.bat.",
    "Restaurant Software","OK","Warning"
  )|Out-Null
  exit 1
}
Start-Process "http://127.0.0.1:$port/reset-demo-ui.php"

# build: V17.1 build 2026-08-25
