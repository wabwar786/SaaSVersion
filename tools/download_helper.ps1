# ============================================================
# download_helper.ps1 - Shared file downloader with a percentage
# progress display. Dot-source this file to use Get-FileWithProgress.
# ============================================================
function Get-FileWithProgress {
  param(
    [Parameter(Mandatory=$true)][string]$Url,
    [Parameter(Mandatory=$true)][string]$Destination,
    [string]$Label = 'Downloading'
  )
  [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
  $req = [Net.HttpWebRequest]::Create($Url)
  $req.Timeout          = 60000
  $req.ReadWriteTimeout = 600000
  $req.UserAgent        = 'SmartPOS-Setup'
  $res = $req.GetResponse()
  $total = $res.ContentLength
  $in    = $res.GetResponseStream()
  $out   = [IO.File]::Create($Destination)
  try {
    $buf = New-Object byte[] 262144
    $done = 0L; $lastPct = -1; $sw = [Diagnostics.Stopwatch]::StartNew()
    while (($read = $in.Read($buf, 0, $buf.Length)) -gt 0) {
      $out.Write($buf, 0, $read)
      $done += $read
      if ($total -gt 0) {
        $pct = [int](($done * 100) / $total)
        if ($pct -ne $lastPct -and ($pct % 2 -eq 0 -or $pct -eq 100)) {
          $mbD = [math]::Round($done/1MB,1); $mbT = [math]::Round($total/1MB,1)
          $spd = if ($sw.Elapsed.TotalSeconds -gt 0) { [math]::Round(($done/1MB)/$sw.Elapsed.TotalSeconds,1) } else { 0 }
          Write-Host ("`r      $Label : {0,3}%  ({1} / {2} MB at {3} MB/s)   " -f $pct,$mbD,$mbT,$spd) -NoNewline -ForegroundColor Gray
          $lastPct = $pct
        }
      } else {
        $mbD = [math]::Round($done/1MB,1)
        Write-Host ("`r      $Label : $mbD MB   ") -NoNewline -ForegroundColor Gray
      }
    }
    Write-Host ("`r      $Label : 100%  complete.                          ") -ForegroundColor Green
  } finally {
    $out.Close(); $in.Close(); $res.Close()
  }
}
