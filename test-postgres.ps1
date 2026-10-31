$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$localRuntime = Join-Path $env:LOCALAPPDATA 'SubscriptionManagement\php\php.exe'
$phpExecutable = if (Test-Path -LiteralPath $localRuntime) { $localRuntime } else { (Get-Command php -ErrorAction Stop).Source }
if (Select-String -LiteralPath '.env' -Pattern '^DB_URL=.+') { throw 'Use a dedicated .env.testing PostgreSQL connection instead of DB_URL before running these tests.' }
$previousConnection = $env:DB_CONNECTION
$previousDatabase = $env:DB_DATABASE
try {
    $env:DB_CONNECTION = 'pgsql'
    $env:DB_DATABASE = 'subscription_management_test'
    & $phpExecutable artisan test --compact
    if ($LASTEXITCODE -ne 0) { throw 'PostgreSQL tests failed.' }
} finally {
    $env:DB_CONNECTION = $previousConnection
    $env:DB_DATABASE = $previousDatabase
}
