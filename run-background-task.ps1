param(
    [Parameter(Mandatory = $true)]
    [string]$PhpExecutable,
    [Parameter(Mandatory = $true)]
    [ValidateSet('Scheduler', 'Queue')]
    [string]$Task
)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
if ($Task -eq 'Scheduler') {
    & $PhpExecutable artisan schedule:run *>> storage\logs\schedule.log
} else {
    & $PhpExecutable artisan queue:work --stop-when-empty --max-time=50 --sleep=3 --tries=3 --timeout=60 *>> storage\logs\queue.log
}
exit $LASTEXITCODE
