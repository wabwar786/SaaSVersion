$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$BuildId = "AIO-RESTAURANT-V14-STARTUP-SAFE-20260824"
$SelectedTxt = Join-Path $ProjectRoot "runtime\selected_php.txt"
$PhpIni = Join-Path $ProjectRoot "runtime\config\php.ini"
$LogDir = Join-Path $ProjectRoot "storage\logs"
$SessDir = Join-Path $ProjectRoot "storage\sessions"
$OutLog = Join-Path $LogDir "php-server.out.log"
$ErrLog2 = Join-Path $LogDir "php-server.err.log"
$ErrLog = Join-Path $LogDir "php-error.log"
$Port=$null
$BaseUrl=$null

New-Item -ItemType Directory -Force -Path $LogDir  | Out-Null
New-Item -ItemType Directory -Force -Path $SessDir | Out-Null

function Show-Error([string]$Title,[string]$Message) {
    Add-Type -AssemblyName PresentationFramework -ErrorAction SilentlyContinue
    if("System.Windows.MessageBox" -as [type]) {
        [System.Windows.MessageBox]::Show($Message,$Title,'OK','Error') | Out-Null
    } else { Write-Host "";Write-Host $Title;Write-Host $Message;Read-Host "Press Enter" }
}
function Step([string]$Text) {
    Write-Host ""; Write-Host "============================================================"
    Write-Host $Text; Write-Host "============================================================"
}
function Test-PortOpen([int]$P) {
    try {
        $c=New-Object Net.Sockets.TcpClient
        $ar=$c.BeginConnect("127.0.0.1",$P,$null,$null)
        $ok=$ar.AsyncWaitHandle.WaitOne(400)
        if($ok -and $c.Connected){$c.EndConnect($ar);$c.Close();return $true}
        $c.Close();return $false
    } catch { return $false }
}
function Test-BuildId([int]$P) {
    try {
        $client=New-Object Net.Sockets.TcpClient
        $client.Connect("127.0.0.1",$P)
        $stream=$client.GetStream()
        $req="GET /build-id.txt HTTP/1.1`r`nHost: 127.0.0.1`r`nConnection: close`r`n`r`n"
        $bytes=[Text.Encoding]::ASCII.GetBytes($req)
        $stream.Write($bytes,0,$bytes.Length); $stream.Flush()
        $sr=New-Object IO.StreamReader($stream)
        $resp=$sr.ReadToEnd(); $client.Close()
        return ($resp -match [regex]::Escape($BuildId))
    } catch { return $false }
}
function Test-PortFree([int]$P) { return (-not (Test-PortOpen $P)) }

# Stop any previous instance of OUR server (identified by router.php in its command line).
function Stop-OldServers {
    try {
        Get-CimInstance Win32_Process -Filter "Name='php.exe'" -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -and $_.CommandLine -match 'router\.php' } |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
    } catch {}
}

try {
    # A. Resolve PHP.
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot "resolve_php.ps1")
    if($LASTEXITCODE -ne 0 -or -not (Test-Path $SelectedTxt)) { throw "PHP setup did not complete." }
    $PhpExe=(Get-Content $SelectedTxt -Raw).Trim()
    if(-not (Test-Path $PhpExe)){ throw "Selected PHP executable is missing: $PhpExe" }

    Step "PHP ready"
    & $PhpExe -c $PhpIni -r "echo 'PHP '.PHP_VERSION.PHP_EOL;"
    if($LASTEXITCODE -ne 0){ throw "Selected PHP cannot run with the Restaurant configuration." }

    # B. Check MySQL + existing aio_local.
    Step "Checking existing MySQL database"
    $dbOut=& $PhpExe -c $PhpIni (Join-Path $ProjectRoot "scripts\check_existing_database.php") 2>&1
    $dbCode=$LASTEXITCODE
    Write-Host (($dbOut | Out-String).Trim())
    if($dbCode -eq 10) {
        throw "MySQL Server 127.0.0.1:3306 par nahi mila. MySQL Windows service start karein. Aapka aio_local database waise hi rahega."
    }
    if($dbCode -eq 20 -or $dbCode -eq 21) {
        Step "aio_local missing/incomplete - creating required schema"
        $boot=& $PhpExe -c $PhpIni (Join-Path $ProjectRoot "scripts\bootstrap_database.php") 2>&1
        Write-Host ($boot | Out-String)
        if($LASTEXITCODE -ne 0){ throw "Local database setup failed." }
    } elseif($dbCode -ne 0) {
        throw "Existing database check failed."
    } else { Write-Host "Existing aio_local database found. Using it as-is." }

    # C. Master data + sync tables (idempotent).
    #
    # migrate_retail.php + seed_industry_modules.php RETAIL vertical ke liye
    # hain. Dono idempotent hain, is liye har start par chalna mehfooz hai —
    # aur isi se purane offline nodes bina kisi alag installer ke supermarket
    # ke qabil ho jate hain.
    Step "Preparing business master data"
    foreach($script in @("seed_roles.php","seed_suppliers.php","seed_edge_node.php","seed_restaurant_demo.php","ensure_v13_login.php","migrate_sync.php","migrate_platform.php","migrate_bridge.php","migrate_collation.php","migrate_site_defaults.php","migrate_ui_menu.php","migrate_menu_image.php","migrate_branding.php","migrate_qr_orders.php","migrate_devices.php","migrate_shifts.php","migrate_sync_columns.php","migrate_sync_log.php","migrate_security.php","migrate_module_ids.php","migrate_retail.php","seed_industry_modules.php")) {
        $full=Join-Path $ProjectRoot ("scripts\"+$script)
        if(Test-Path $full){ & $PhpExe -c $PhpIni $full; if($LASTEXITCODE -ne 0){ throw "$script failed." } }
    }
    Write-Host "Preparing optional demo database data..."
    $demoOut=& $PhpExe -c $PhpIni (Join-Path $ProjectRoot "scripts\seed_full_demo.php") 2>&1
    Write-Host ($demoOut | Out-String)

    # D. Free stale servers, then pick a port.
    Stop-OldServers
    Start-Sleep -Milliseconds 400
    foreach($p in 8940..8950){ if((Test-PortOpen $p) -and (Test-BuildId $p)){$Port=$p;break} }
    if($null -eq $Port){ foreach($p in 8940..8950){ if(Test-PortFree $p){$Port=$p;break} } }
    if($null -eq $Port){ throw "No free local application port (8940-8950) is available." }
    $BaseUrl="http://127.0.0.1:$Port"

    $running = (Test-PortOpen $Port) -and (Test-BuildId $Port)
    if(-not $running) {
        Step "Starting Restaurant Software"
        $pub=Join-Path $ProjectRoot "public"
        $router=Join-Path $ProjectRoot "public\router.php"
        Remove-Item $OutLog,$ErrLog2 -ErrorAction SilentlyContinue

        # Argument ARRAY (no manual quoting) + direct redirect + PassThru.
        $srvArgs=@("-c",$PhpIni,"-d","display_errors=0","-d","log_errors=1","-d","error_log=$ErrLog","-S","127.0.0.1:$Port","-t",$pub,$router)
        $proc=$null
        try {
            $proc=Start-Process -FilePath $PhpExe -ArgumentList $srvArgs -WorkingDirectory $ProjectRoot -WindowStyle Hidden -RedirectStandardOutput $OutLog -RedirectStandardError $ErrLog2 -PassThru
        } catch {
            throw "Server process launch failed: $($_.Exception.Message)"
        }

        # Immediate-crash check.
        Start-Sleep -Milliseconds 500
        if($proc -and $proc.HasExited){
            $e=(Get-Content $ErrLog2 -ErrorAction SilentlyContinue | Out-String)
            $o=(Get-Content $OutLog  -ErrorAction SilentlyContinue | Out-String)
            throw "Server exited immediately (exit code $($proc.ExitCode)).`r`n`r`n$e`r`n$o`r`nAgar yahan kuch nahi hai to 'RUN_SERVER_DEBUG.bat' chala kar asli error dekhein."
        }

        # Readiness: wait up to 30s for the port (proxy-independent).
        $ready=$false
        for($i=0;$i -lt 60;$i++){
            Start-Sleep -Milliseconds 500
            if($proc -and $proc.HasExited){ break }
            if(Test-PortOpen $Port){
                if(Test-BuildId $Port){ $ready=$true; break }
                if($i -ge 8){ $ready=$true; break }
            }
        }
        if(-not $ready){
            $state = if($proc){ if($proc.HasExited){"exited (code $($proc.ExitCode))"} else {"still running but port not reachable"} } else {"unknown"}
            $srvErr=(Get-Content $ErrLog2 -Tail 20 -ErrorAction SilentlyContinue | Out-String)
            $srvOut=(Get-Content $OutLog  -Tail 20 -ErrorAction SilentlyContinue | Out-String)
            $phpErr=(Get-Content $ErrLog  -Tail 20 -ErrorAction SilentlyContinue | Out-String)
            $msg="Restaurant local server did not start on port $Port.`r`nProcess: $state`r`n"
            if($srvErr.Trim()){ $msg+="`r`n[server stderr]`r`n$srvErr" }
            if($srvOut.Trim()){ $msg+="`r`n[server stdout]`r`n$srvOut" }
            if($phpErr.Trim()){ $msg+="`r`n[php-error.log]`r`n$phpErr" }
            if(-not ($srvErr.Trim() -or $srvOut.Trim() -or $phpErr.Trim())){ $msg+="`r`nNo server output captured. Please run RUN_SERVER_DEBUG.bat to see the live error." }
            throw $msg
        }
    }

    # E. Backend login self-test.
    Step "Checking login backend"
    $loginOut=& $PhpExe -c $PhpIni (Join-Path $ProjectRoot "scripts\login_backend_selftest.php") 2>&1
    $loginText=($loginOut | Out-String).Trim()
    Write-Host $loginText
    if($LASTEXITCODE -ne 0 -or $loginText -notmatch "LOGIN_BACKEND_READY_V11"){ throw "Login backend self-test failed: $loginText" }

    # F. Background cloud-sync loop (only if a cloud URL is configured).
    try {
        $syncOn=(& $PhpExe -c $PhpIni (Join-Path $ProjectRoot "scripts\sync_enabled.php") 2>$null | Out-String).Trim()
        if($syncOn -eq '1'){
            $loopArgs=@("-c",$PhpIni,(Join-Path $ProjectRoot "scripts\sync_loop.php"))
            Start-Process -FilePath $PhpExe -ArgumentList $loopArgs -WorkingDirectory $ProjectRoot -WindowStyle Hidden `
                -RedirectStandardOutput (Join-Path $LogDir "sync-loop.out.log") -RedirectStandardError (Join-Path $LogDir "sync-loop.err.log") | Out-Null
            Write-Host "Cloud sync loop started."
        } else { Write-Host "Cloud sync not configured (local-only mode)." }
    } catch { Write-Host "Sync loop not started: $($_.Exception.Message)" }

    # G. Desktop shortcut.
    try {
        $desktop=[Environment]::GetFolderPath("Desktop")
        $ws=New-Object -ComObject WScript.Shell
        $s=$ws.CreateShortcut((Join-Path $desktop "Restaurant Software.lnk"))
        $s.TargetPath=Join-Path $ProjectRoot "START_RESTAURANT.bat"
        $s.WorkingDirectory=$ProjectRoot
        $s.Description="All-in-One Restaurant Software"; $s.Save()
    } catch {}

    # H. Open approved Login UI.
    $url="$BaseUrl/login.html?build=v14"
    $edge=@("$env:ProgramFiles(x86)\Microsoft\Edge\Application\msedge.exe","$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe")|Where-Object{Test-Path $_}|Select-Object -First 1
    $chrome=@("$env:ProgramFiles\Google\Chrome\Application\chrome.exe","$env:ProgramFiles(x86)\Google\Chrome\Application\chrome.exe")|Where-Object{Test-Path $_}|Select-Object -First 1
    if($edge){Start-Process $edge -ArgumentList @("--kiosk",$url,"--edge-kiosk-type=fullscreen","--no-first-run")}
    elseif($chrome){Start-Process $chrome -ArgumentList @("--kiosk",$url,"--no-first-run")}
    else{Start-Process $url}
}
catch { Show-Error "Restaurant Software Setup" $_.Exception.Message; exit 1 }

# build: V17.1 build 2026-08-25
