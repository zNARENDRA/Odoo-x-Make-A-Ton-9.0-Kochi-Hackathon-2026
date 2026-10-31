param(
    [int]$Port = 8000,
    [switch]$NoWorkers
)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$localRuntime = Join-Path $env:LOCALAPPDATA 'SubscriptionManagement\php\php.exe'
$phpExecutable = if (Test-Path -LiteralPath $localRuntime) { $localRuntime } else { (Get-Command php -ErrorAction Stop).Source }
& $phpExecutable -r 'exit(extension_loaded("pdo_pgsql") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) { throw 'Enable pdo_pgsql in your PHP installation before starting the app.' }
$pgRoot = Join-Path $env:LOCALAPPDATA 'SubscriptionManagement'
$pgControlPath = Join-Path $pgRoot 'postgres\pgsql\bin\pg_ctl.exe'
$pgDataPath = Join-Path $pgRoot 'pgdata'
if ((Test-Path -LiteralPath $pgControlPath) -and (Test-Path -LiteralPath $pgDataPath)) {
    & $pgControlPath -D $pgDataPath status *> $null
    if ($LASTEXITCODE -ne 0) {
        $databaseProcess = Start-Process -FilePath $pgControlPath -ArgumentList @('-D', ('"' + $pgDataPath + '"'), '-l', ('"' + (Join-Path $pgRoot 'postgres.log') + '"'), '-w', 'start') -WindowStyle Hidden -PassThru
        if (-not $databaseProcess.WaitForExit(20000) -or $databaseProcess.ExitCode -ne 0) { throw 'Could not start local PostgreSQL. Check the postgres.log file in the local runtime directory.' }
    }
}
if (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue) { throw "Port $Port is already in use." }
if (-not (Test-Path -LiteralPath 'public\build\manifest.json')) {
    & npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'The frontend build failed.' }
}
$childProcesses = @()
try {
    if (-not $NoWorkers) {
        $childProcesses += Start-Process -FilePath $phpExecutable -ArgumentList @('artisan','queue:work','--sleep=3','--tries=3','--timeout=60') -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput 'storage\logs\queue.log' -RedirectStandardError 'storage\logs\queue-error.log' -PassThru
        $childProcesses += Start-Process -FilePath $phpExecutable -ArgumentList @('artisan','schedule:work') -WorkingDirectory $PSScriptRoot -WindowStyle Hidden -RedirectStandardOutput 'storage\logs\schedule.log' -RedirectStandardError 'storage\logs\schedule-error.log' -PassThru
    }
    Write-Host "Brightloop is starting at http://localhost:$Port. Press Ctrl+C to stop."
    & $phpExecutable artisan serve --host=127.0.0.1 --port=$Port --no-reload
} finally {
    foreach ($childProcess in $childProcesses) {
        if (-not $childProcess.HasExited) { Stop-Process -Id $childProcess.Id -ErrorAction SilentlyContinue }
    }
}
