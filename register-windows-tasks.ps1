param(
    [string]$PhpExecutable = (Join-Path $env:LOCALAPPDATA 'SubscriptionManagement\php\php.exe'),
    [string]$TaskPrefix = 'Brightloop'
)
$ErrorActionPreference = 'Stop'
if (-not (Test-Path -LiteralPath $PhpExecutable)) { $PhpExecutable = (Get-Command php -ErrorAction Stop).Source }
$startTime = (Get-Date).AddMinutes(1)
$trigger = New-ScheduledTaskTrigger -Once -At $startTime -RepetitionInterval (New-TimeSpan -Minutes 1)
$principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Minutes 10) -Hidden
$powershellExecutable = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$runnerPath = Join-Path $PSScriptRoot 'run-background-task.ps1'
$runnerArguments = '-NoProfile -WindowStyle Hidden -File "{0}" -PhpExecutable "{1}" -Task ' -f $runnerPath, $PhpExecutable
$schedulerAction = New-ScheduledTaskAction -Execute $powershellExecutable -Argument ($runnerArguments + 'Scheduler') -WorkingDirectory $PSScriptRoot
$queueAction = New-ScheduledTaskAction -Execute $powershellExecutable -Argument ($runnerArguments + 'Queue') -WorkingDirectory $PSScriptRoot
foreach ($taskName in @("$TaskPrefix-Scheduler", "$TaskPrefix-Queue")) {
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) { throw "Task $taskName already exists. Choose a different TaskPrefix or update the existing task manually." }
}
Register-ScheduledTask -TaskName "$TaskPrefix-Scheduler" -Action $schedulerAction -Trigger $trigger -Principal $principal -Settings $settings -Description 'Run Laravel subscription billing checks every minute while signed in.' | Out-Null
Register-ScheduledTask -TaskName "$TaskPrefix-Queue" -Action $queueAction -Trigger $trigger -Principal $principal -Settings $settings -Description 'Process queued Laravel invoice emails while signed in.' | Out-Null
Write-Host "Registered $TaskPrefix-Scheduler and $TaskPrefix-Queue for the current Windows user."
Write-Host 'PostgreSQL must be running. These tasks run while this user is signed in. Use dev.ps1 -NoWorkers when these tasks are enabled.'
