param(
    [int]$Port = 8088,
    [switch]$DemoPayment,
    [string]$PhpExecutable = ''
)
$ErrorActionPreference = 'Stop'
$projectDirectory = Split-Path $PSScriptRoot -Parent
if (-not $PhpExecutable) {
    $bundledPhp = Join-Path $projectDirectory '..\..\Extensions\php\php7.3.4nts\php.exe'
    if (Test-Path -LiteralPath $bundledPhp) { $PhpExecutable = (Resolve-Path -LiteralPath $bundledPhp).Path }
    else { $PhpExecutable = (Get-Command php -ErrorAction Stop).Source }
}
$sessionDirectory = Join-Path ([System.IO.Path]::GetTempPath()) 'huimeng-shop-sessions'
New-Item -ItemType Directory -Path $sessionDirectory -Force | Out-Null
$env:SHOP_DEMO_PAYMENT = if ($DemoPayment) { '1' } else { '0' }
Set-Location -LiteralPath $projectDirectory
Write-Host "Local shop: http://127.0.0.1:$Port/index.php"
if ($DemoPayment) { Write-Host 'Demo payment enabled: no real money will be charged.' }
& $PhpExecutable -d "session.save_path=$sessionDirectory" -S "127.0.0.1:$Port" scripts/router.php
