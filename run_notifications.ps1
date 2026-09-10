# run_notifications.ps1
# Executes the overdue book notification script via PHP CLI.
# Designed to be called by run_notifications.bat from Windows Task Scheduler.

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpScript = Join-Path $scriptDir "send_due_date_notifications.php"

# Try XAMPP PHP first, then fallback to C:\php
$phpPaths = @(
    "C:\xampp\php\php.exe",
    "C:\php\php.exe",
    "php.exe"
)

$phpExe = $null
foreach ($path in $phpPaths) {
    if (Test-Path $path) {
        $phpExe = $path
        break
    }
}

if (-not $phpExe) {
    Write-Error "PHP executable not found. Searched: $($phpPaths -join ', ')"
    exit 1
}

# Log start
$logFile = Join-Path $scriptDir "notification_log.txt"
$timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"$timestamp - Starting notification check" | Out-File -FilePath $logFile -Append -Encoding utf8

# Run the notification script
& $phpExe $phpScript 2>&1 | Out-File -FilePath $logFile -Append -Encoding utf8

$exitCode = $LASTEXITCODE
$endTimestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
"$endTimestamp - Finished (exit code: $exitCode)" | Out-File -FilePath $logFile -Append -Encoding utf8

exit $exitCode
