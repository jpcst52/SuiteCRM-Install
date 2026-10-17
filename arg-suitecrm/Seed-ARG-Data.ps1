<#
.SYNOPSIS
  Fill SuiteCRM with a fictional sales book.

.DESCRIPTION
  Run this after Install-SuiteCRM-Windows11.ps1. It does not need Administrator.
  Customers, contacts, prices, and volumes are fictional. They follow ARG's public
  product lines (ARGuard, Kendex, Kensol, Kenwax, fuels, toll blending) and the
  Bradford, PA refinery. Leadership names are the public officers on amref.com.
  Every email address is a .example mailbox.

    powershell -NoProfile -ExecutionPolicy Bypass -File .\Seed-ARG-Data.ps1
#>
[CmdletBinding()]
param(
    [string]$Distro = 'Ubuntu-24.04',
    [string]$InstallRoot = 'C:\SuiteCRM'
)

$ErrorActionPreference = 'Stop'
$env:WSL_UTF8 = '1'

$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpSource = Join-Path $here 'seed-arg-crm.php'
if (-not (Test-Path $phpSource)) {
    throw "Missing $phpSource. Keep it in the same folder as this script."
}

$credPath = Join-Path $InstallRoot 'credentials.txt'
if (-not (Test-Path $credPath)) {
    throw "Missing $credPath. Run Install-SuiteCRM-Windows11.ps1 first."
}

$adminUser = 'admin'
$demoPassword = 'ArgDemo-ChangeMe-810'
$suiteRoot = '/var/www/suitecrm'
foreach ($line in Get-Content $credPath) {
    if ($line -match '^ADMIN_USER=(.*)$' -and $Matches[1]) { $adminUser = $Matches[1] }
    elseif ($line -match '^DEMO_USER_PASSWORD=(.*)$' -and $Matches[1]) { $demoPassword = $Matches[1] }
    elseif ($line -match '^INSTALL_DIR=(.*)$' -and $Matches[1]) { $suiteRoot = $Matches[1] }
    elseif ($line -match '^DISTRO=(.*)$' -and $Matches[1]) { $Distro = $Matches[1] }
}

Write-Host "Seeding $Distro ($suiteRoot) as a fictional ARG book..."
& wsl.exe -d $Distro -u root -e true
if ($LASTEXITCODE -ne 0) {
    throw "Cannot start WSL distro $Distro. If the PC was just rebooted, run $InstallRoot\Start-SuiteCRM.ps1 once, then try again."
}

$phpText = [System.IO.File]::ReadAllText($phpSource) -replace "`r", ''
$envText = @"
SUITECRM_ROOT=$suiteRoot
ADMIN_USER=$adminUser
DEMO_USER_PASSWORD=$demoPassword
"@
$envText = ($envText -replace "`r", '').Trim() + "`n"

# Copy through a Windows temp file that wsl can read from /mnt/c, which works
# even when the \\wsl$ share is slow to appear.
$tempPhp = Join-Path $env:TEMP 'seed-arg-crm.php'
$tempEnv = Join-Path $env:TEMP 'arg-seed.env'
[System.IO.File]::WriteAllText($tempPhp, $phpText)
[System.IO.File]::WriteAllText($tempEnv, $envText)

$winPhp = ($tempPhp -replace '\\', '/')
$winEnv = ($tempEnv -replace '\\', '/')
# C:\Users\... -> /mnt/c/Users/...
if ($winPhp -match '^([A-Za-z]):/(.*)$') {
    $winPhp = '/mnt/' + $Matches[1].ToLower() + '/' + $Matches[2]
}
if ($winEnv -match '^([A-Za-z]):/(.*)$') {
    $winEnv = '/mnt/' + $Matches[1].ToLower() + '/' + $Matches[2]
}

$remote = @'
set -euo pipefail
install -d -o root -g www-data -m 750 /opt/arg-seed
cp "__ENV__" /opt/arg-seed/seed.env
cp "__PHP__" /opt/arg-seed/seed-arg-crm.php
chown root:www-data /opt/arg-seed/seed.env /opt/arg-seed/seed-arg-crm.php
chmod 640 /opt/arg-seed/seed.env /opt/arg-seed/seed-arg-crm.php
if [[ ! -f __ROOT__/public/legacy/config.php ]]; then
  echo "SuiteCRM is not installed at __ROOT__." >&2
  exit 1
fi
runuser -u www-data -- php /opt/arg-seed/seed-arg-crm.php
'@
$remote = $remote.Replace('__ENV__', $winEnv).Replace('__PHP__', $winPhp).Replace('__ROOT__', $suiteRoot)
$remotePath = Join-Path $env:TEMP 'arg-seed-run.sh'
[System.IO.File]::WriteAllText($remotePath, ($remote -replace "`r", ''))
$winSh = ($remotePath -replace '\\', '/')
if ($winSh -match '^([A-Za-z]):/(.*)$') {
    $winSh = '/mnt/' + $Matches[1].ToLower() + '/' + $Matches[2]
}

& wsl.exe -d $Distro -u root -- bash $winSh
if ($LASTEXITCODE -ne 0) {
    throw "Seeding failed. Re-run this script after SuiteCRM is up. The messages above are the detail."
}

Write-Host ""
Write-Host "Done. In SuiteCRM open Accounts, Opportunities, Quotes, and Cases." -ForegroundColor Green
Write-Host "Sales, service, and leadership demo users share the password in $credPath (DEMO_USER_PASSWORD)."
Write-Host "Sign in as admin with ADMIN_PASSWORD from that same file. Change it after you log in."
