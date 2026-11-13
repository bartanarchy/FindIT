<#
 Creates the Windows Task Scheduler jobs for FindIT backups.
 Run in PowerShell (as the Windows user who runs Laragon/PostgreSQL):

   powershell -ExecutionPolicy Bypass -File scripts\windows\install-scheduler.ps1
   powershell -ExecutionPolicy Bypass -File scripts\windows\install-scheduler.ps1 -DailyAt 21:30
   powershell -ExecutionPolicy Bypass -File scripts\windows\install-scheduler.ps1 -Remove

 Jobs:
   FindIT Backup       every day at -DailyAt AND when you sign in; if the computer was off at the
                       planned time it runs as soon as possible afterwards (StartWhenAvailable).
                       It does not repeat a backup that was already made recently (BACKUP_MIN_INTERVAL_HOURS).
   FindIT Restore Test every week (Sunday) at -TestAt: restores the newest backup into the test database.

 The jobs run php.exe directly. They do not need a browser, Apache or any web page.
#>
param(
    [string]$DailyAt = '20:00',
    [string]$TestAt  = '21:00',
    [string]$PhpExe  = '',
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'
$project = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$names = @('FindIT Backup', 'FindIT Restore Test')

if ($Remove) {
    foreach ($n in $names) {
        if (Get-ScheduledTask -TaskName $n -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $n -Confirm:$false
            Write-Host "Removed: $n"
        }
    }
    return
}

if ($PhpExe -eq '') {
    $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($cmd) { $PhpExe = $cmd.Source }
    else {
        $found = Get-ChildItem 'C:\laragon\bin\php' -Recurse -Filter php.exe -ErrorAction SilentlyContinue |
                 Sort-Object FullName -Descending | Select-Object -First 1
        if ($found) { $PhpExe = $found.FullName }
    }
}
if ($PhpExe -eq '' -or -not (Test-Path $PhpExe)) {
    throw 'php.exe was not found. Run again with -PhpExe "C:\laragon\bin\php\php-8.x\php.exe".'
}

$settings = New-ScheduledTaskSettingsSet `
    -StartWhenAvailable `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Hours 2) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 5)

$user = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal = New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited

# Backup: daily + at logon. --retries waits for PostgreSQL if it has not started yet.
$backupAction = New-ScheduledTaskAction -Execute $PhpExe `
    -Argument 'scripts\backup.php --retries=5 --retry-delay=60' -WorkingDirectory $project
$backupTriggers = @(
    (New-ScheduledTaskTrigger -Daily -At $DailyAt),
    (New-ScheduledTaskTrigger -AtLogOn -User $user)
)
Register-ScheduledTask -TaskName $names[0] -Action $backupAction -Trigger $backupTriggers `
    -Settings $settings -Principal $principal -Force `
    -Description 'FindIT database backup (local + remote copy).' | Out-Null

# Weekly restore test (test database only).
$testAction = New-ScheduledTaskAction -Execute $PhpExe `
    -Argument 'scripts\restore-test.php' -WorkingDirectory $project
$testTrigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Sunday -At $TestAt
Register-ScheduledTask -TaskName $names[1] -Action $testAction -Trigger $testTrigger `
    -Settings $settings -Principal $principal -Force `
    -Description 'FindIT: restore the newest backup into the test database and check it.' | Out-Null

Write-Host ''
Write-Host "Created '$($names[0])' (daily $DailyAt + at sign-in) and '$($names[1])' (Sunday $TestAt)."
Write-Host "PHP: $PhpExe"
Write-Host "Project: $project"
Write-Host 'Try it now:  Start-ScheduledTask -TaskName "FindIT Backup"'
Write-Host 'Then check:  php scripts\backup-check.php   and the Backup page in the admin menu.'