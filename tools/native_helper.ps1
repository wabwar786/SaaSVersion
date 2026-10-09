# ============================================================
# native_helper.ps1 - Run php.exe / mysqld.exe / powershell.exe and get
# ALL of their output (stdout + stderr) back as plain text, without any
# chance of killing the calling script.
#
# Dot-source it:   . (Join-Path $PSScriptRoot 'native_helper.ps1')
#
# ------------------------------------------------------------
# WHY THIS EXISTS - the bug it fixes (live until V141)
# ------------------------------------------------------------
# In Windows PowerShell 5.1 - which is what `powershell.exe` is on every
# customer machine - this innocent looking line is a trap:
#
#     $ErrorActionPreference = 'Stop'      # top of the script
#     ...
#     $out = & php.exe -v 2>&1             # capture stdout + stderr
#
# The moment the native command writes ANYTHING to stderr, PowerShell
# turns it into a TERMINATING error (NativeCommandError) because of that
# 'Stop'. The script dies on the capture line itself.
#
# What that did on a real customer PC: php.exe wrote
#
#     'C:\Windows\SYSTEM32\VCRUNTIME140.dll' 14.0 is not compatible
#      with this PHP build linked with 14.29
#
# ... and setup died at install_offline.ps1 line 96. Every bit of
# handling written directly BELOW that line - the VCRUNTIME auto-repair
# (fix_vcruntime.ps1) and the ASLR / OPcache fallback - never ran once.
# All the customer saw was a wall of PowerShell RemoteException text and
# "Setup did not complete". The handlers were not wrong; they were
# unreachable.
#
# PowerShell 7.3+ has its own version of the same trap:
# $PSNativeCommandUseErrorActionPreference makes a non-zero EXIT CODE
# terminating under 'Stop'.
#
# Invoke-Native closes both doors:
#   - $ErrorActionPreference = 'Continue'  inside the call
#       -> 5.1 cannot raise a terminating NativeCommandError
#   - $PSNativeCommandUseErrorActionPreference = $false (when it exists)
#       -> PS 7.3+ cannot turn a non-zero exit into a throw
#   - ErrorRecord objects are flattened to strings
#       -> `-match 'VCRUNTIME140'` actually matches the text
#   - try/catch around everything
#       -> even an unexpected throw comes back as text, never escapes
#
# Both assignments are LOCAL to this function, so the caller's own
# preferences are never changed.
#
# The command's exit code is left in $global:NativeExitCode.
# ============================================================

function Invoke-Native {
  param(
    [Parameter(Mandatory = $true)][string] $Exe,
    [string[]] $Arguments = @()
  )

  # Local shadows only - the caller's preferences stay as they were.
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
  } catch {
    # Should not happen any more, but if it ever does the caller gets the
    # message as TEXT and keeps running - never a dead script.
    $global:NativeExitCode = 1
    $text = ($_ | Out-String)
  }

  return $text
}

# Same thing for a .ps1 run in its own powershell.exe (the way
# install_offline.ps1 calls resolve_php.ps1 / resolve_mariadb.ps1).
function Invoke-NativeScript {
  param(
    [Parameter(Mandatory = $true)][string] $ScriptPath,
    [string[]] $Arguments = @()
  )
  $exe = 'powershell.exe'
  # On PowerShell 7 `powershell.exe` may not exist (Linux/dev boxes, or a
  # machine with only pwsh). Fall back so the script still works there.
  if (-not (Get-Command $exe -ErrorAction SilentlyContinue)) {
    $exe = (Get-Process -Id $PID).Path
    if (-not $exe) { $exe = 'pwsh' }
  }
  $a = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $ScriptPath) + $Arguments
  return (Invoke-Native -Exe $exe -Arguments $a)
}

# build: V142 build 2026-10-09
