#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Set up SuiteCRM 8.10.2 on Windows 11.

.DESCRIPTION
  SuiteCRM 8.10 is supported on Linux, Unix, and macOS (PHP 8.2-8.4, Apache 2.4,
  MariaDB 10.6/10.11/11.4/11.8 or MySQL 8.0/8.4). It is not a native Windows app.
  This script installs Ubuntu 24.04 under WSL2, then Apache, MariaDB 10.11, PHP 8.3,
  and the official SuiteCRM 8.10.2 package. Windows localhost is forwarded into WSL.

  Run from an elevated PowerShell:
    powershell -NoProfile -ExecutionPolicy Bypass -File .\Install-SuiteCRM-Windows11.ps1

  After it finishes, run Seed-ARG-Data.ps1 to load the American Refining Group demo data.
#>
[CmdletBinding()]
param(
    [string]$Distro = 'Ubuntu-24.04',
    [string]$Version = '8.10.2',
    [string]$SiteHost = 'localhost',
    [int]$Port = 0,
    [string]$AdminUser = 'admin',
    [string]$InstallRoot = 'C:\SuiteCRM',
    [switch]$Seed
)

$ErrorActionPreference = 'Stop'
$env:WSL_UTF8 = '1'

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "== $Message" -ForegroundColor Cyan
}

function New-Token([int]$Length = 16) {
    $chars = [char[]]((48..57) + (65..90) + (97..122))
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $bytes = New-Object byte[] ($Length)
    $rng.GetBytes($bytes)
    $out = New-Object char[] ($Length)
    for ($i = 0; $i -lt $Length; $i++) {
        $out[$i] = $chars[$bytes[$i] % $chars.Length]
    }
    return -join $out
}

function New-SuitePassword {
    # Upper, lower, and a digit so SuiteCRM's default password rules pass.
    return ('Aa9' + (New-Token 16))
}

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script from an elevated PowerShell window (Run as administrator).'
}

$build = [int](Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Windows NT\CurrentVersion').CurrentBuild
if ($build -lt 22000) {
    Write-Warning "This script is written for Windows 11 (build 22000+). Detected build $build. Continuing anyway."
}

$drive = Get-PSDrive -Name (($InstallRoot.Substring(0, 1)))
if ($drive.Free -lt 8GB) {
    throw "Need at least 8 GB free on $($drive.Name):. SuiteCRM plus WSL uses several gigabytes."
}

Write-Step 'Enabling WSL2 Windows features'
$reboot = $false
foreach ($feature in @('Microsoft-Windows-Subsystem-Linux', 'VirtualMachinePlatform')) {
    $state = (Get-WindowsOptionalFeature -Online -FeatureName $feature).State
    if ($state -ne 'Enabled') {
        Write-Host "Enabling $feature"
        Enable-WindowsOptionalFeature -Online -FeatureName $feature -All -NoRestart | Out-Null
        $reboot = $true
    }
}
if ($reboot) {
    Write-Host ''
    Write-Host 'Windows features were enabled. Reboot, then run this script again.' -ForegroundColor Yellow
    exit 3010
}

Write-Step 'Updating WSL'
& wsl.exe --update
if ($LASTEXITCODE -ne 0) {
    Write-Warning 'wsl --update returned a non-zero exit code. Continuing if WSL is already current.'
}
& wsl.exe --set-default-version 2 | Out-Null

function Get-WslDistros {
    $raw = (& wsl.exe -l -q 2>$null | Out-String)
    if (-not $raw) { return @() }
    $raw = $raw -replace [char]0, ''
    return @($raw -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -and $_ -notmatch 'Windows Subsystem' })
}

Write-Step "Installing $Distro if it is not already installed"
$distros = Get-WslDistros
if ($distros -notcontains $Distro) {
    Write-Host "Installing $Distro (this downloads a few hundred MB)..."
    & wsl.exe --install -d $Distro --no-launch
    if ($LASTEXITCODE -ne 0) {
        Write-Warning 'Retrying without --no-launch.'
        & wsl.exe --install -d $Distro
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Could not install $Distro. Run 'wsl --list --online' and confirm the distro name. If a reboot is pending, reboot and run this script again."
    }
}

Write-Step 'Starting the distro'
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    & wsl.exe -d $Distro -u root -e true
    if ($LASTEXITCODE -eq 0) { $ready = $true; break }
    Start-Sleep -Seconds 3
}
if (-not $ready) {
    throw @"
Ubuntu did not start as root.
If a window asked you to create a UNIX username, finish that prompt (any username is fine), close it, and run this script again.
"@
}

& wsl.exe --set-default $Distro | Out-Null

if ($Port -eq 0) {
    $busy = Get-NetTCPConnection -LocalPort 80 -State Listen -ErrorAction SilentlyContinue
    if ($busy) {
        $Port = 8080
        Write-Warning "Something on Windows is already listening on port 80. SuiteCRM will use http://${SiteHost}:8080/ instead."
    } else {
        $Port = 80
    }
}
if ($Port -eq 80) {
    $SiteUrl = "http://${SiteHost}/"
} else {
    $SiteUrl = "http://${SiteHost}:${Port}/"
}

New-Item -ItemType Directory -Force -Path $InstallRoot | Out-Null
$credPath = Join-Path $InstallRoot 'credentials.txt'
$adminPassword = $null
$dbPassword = $null
$linuxPassword = $null
if (Test-Path $credPath) {
    Write-Host "Reusing passwords from $credPath"
    foreach ($line in Get-Content $credPath) {
        if ($line -match '^ADMIN_PASSWORD=(.*)$') { $adminPassword = $Matches[1] }
        elseif ($line -match '^DB_PASSWORD=(.*)$') { $dbPassword = $Matches[1] }
        elseif ($line -match '^LINUX_PASSWORD=(.*)$') { $linuxPassword = $Matches[1] }
    }
}
if (-not $adminPassword) { $adminPassword = New-SuitePassword }
if (-not $dbPassword) { $dbPassword = New-SuitePassword }
if (-not $linuxPassword) { $linuxPassword = New-SuitePassword }
$demoPassword = 'ArgDemo-ChangeMe-810'

$envFile = @"
VERSION=$Version
SITE_URL=$SiteUrl
HTTP_PORT=$Port
ADMIN_USER=$AdminUser
ADMIN_PASSWORD=$adminPassword
DB_PASSWORD=$dbPassword
DB_NAME=suitecrm
DB_USER=suitecrm
LINUX_USER=crmadmin
LINUX_PASSWORD=$linuxPassword
DEMO_USER_PASSWORD=$demoPassword
DISTRO=$Distro
INSTALL_DIR=/var/www/suitecrm
"@

Write-Step 'Copying the Linux installer into WSL'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$shSource = Join-Path $here 'setup-suitecrm.sh'
if (-not (Test-Path $shSource)) {
    throw "Missing $shSource. Keep setup-suitecrm.sh in the same folder as this script."
}

# Wake the distro so the \\wsl$ share exists, then copy with LF line endings.
& wsl.exe -d $Distro -u root -e true | Out-Null
$wslRoot = "\\wsl$\$Distro\root"
if (-not (Test-Path $wslRoot)) {
    throw "Cannot reach $wslRoot. Start the distro with: wsl -d $Distro"
}
$shText = [System.IO.File]::ReadAllText($shSource) -replace "`r", ''
[System.IO.File]::WriteAllText((Join-Path $wslRoot 'setup-suitecrm.sh'), $shText)
$envText = ($envFile -replace "`r", '')
[System.IO.File]::WriteAllText((Join-Path $wslRoot 'suitecrm-setup.env'), $envText.Trim() + "`n")
& wsl.exe -d $Distro -u root -- chmod 700 /root/setup-suitecrm.sh
& wsl.exe -d $Distro -u root -- chmod 600 /root/suitecrm-setup.env

Write-Step 'Installing Apache, MariaDB, PHP 8.3, and SuiteCRM (several minutes)'
& wsl.exe -d $Distro -u root -- bash /root/setup-suitecrm.sh
if ($LASTEXITCODE -ne 0) {
    throw "The Linux installer failed. Details are inside WSL at /var/log/suitecrm-install.log"
}

Write-Step 'Restarting WSL so systemd can own Apache, MariaDB, and cron'
& wsl.exe --shutdown
Start-Sleep -Seconds 3
& wsl.exe -d $Distro -u root -e true
$healthy = $false
for ($i = 1; $i -le 40; $i++) {
    & wsl.exe -d $Distro -u root -- systemctl is-active apache2 2>$null | Out-Null
    if ($LASTEXITCODE -eq 0) { $healthy = $true; break }
    Start-Sleep -Seconds 3
}
if (-not $healthy) {
    Write-Warning 'systemd did not report apache2 as active. Starting services the sysv way.'
    & wsl.exe -d $Distro -u root -- bash -lc "service mariadb start || service mysql start; service apache2 start; service cron start || true"
}

try {
    $probe = Invoke-WebRequest -Uri $SiteUrl -UseBasicParsing -TimeoutSec 30 -MaximumRedirection 5
    Write-Host "Windows can reach SuiteCRM (HTTP $([int]$probe.StatusCode))."
} catch {
    Write-Warning "SuiteCRM is installed, but Windows could not open $SiteUrl yet. After a minute, run $InstallRoot\Start-SuiteCRM.ps1. $($_.Exception.Message)"
}

$wslCred = "\\wsl$\$Distro\root\suitecrm-credentials.txt"
$copied = $false
for ($i = 1; $i -le 10; $i++) {
    if (Test-Path $wslCred) {
        Copy-Item -Force $wslCred $credPath
        $copied = $true
        break
    }
    Start-Sleep -Seconds 2
    & wsl.exe -d $Distro -u root -e true | Out-Null
}
if (-not $copied) {
    throw "Installer finished but $wslCred is missing."
}
icacls $credPath /inheritance:r /grant:r "${env:USERNAME}:(R,W)" | Out-Null

$jobs = Join-Path $InstallRoot 'run-jobs.cmd'
@"
@echo off
wsl.exe -d $Distro -u root -- runuser -u www-data -- php /var/www/suitecrm/bin/console schedulers:run
wsl.exe -d $Distro -u root -- runuser -u www-data -- php /var/www/suitecrm/bin/console messenger:consume internal-async --time-limit=50 --memory-limit=256M
"@ | Set-Content -Encoding ASCII -Path $jobs

$start = Join-Path $InstallRoot 'Start-SuiteCRM.ps1'
@"
# Starts the WSL services after a reboot and opens SuiteCRM.
`$ErrorActionPreference = 'Continue'
& wsl.exe -d $Distro -u root -- bash -lc "systemctl start mariadb apache2 cron 2>/dev/null || true; service mariadb start 2>/dev/null || service mysql start; service apache2 start; service cron start 2>/dev/null || true"
Start-Sleep -Seconds 2
Start-Process '$SiteUrl'
"@ | Set-Content -Encoding ASCII -Path $start

Write-Step 'Registering scheduled tasks'
try {
    $wake = Join-Path $InstallRoot 'wake-wsl.cmd'
    @"
@echo off
wsl.exe -d $Distro -u root -- bash -lc "systemctl start mariadb apache2 cron 2>/dev/null || true; service mariadb start 2>/dev/null || service mysql start; service apache2 start; service cron start 2>/dev/null || true"
"@ | Set-Content -Encoding ASCII -Path $wake

    $userId = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $principalTask = New-ScheduledTaskPrincipal -UserId $userId -LogonType Interactive -RunLevel Limited
    $logon = New-ScheduledTaskTrigger -AtLogOn -User $userId
    Register-ScheduledTask -TaskName 'SuiteCRM Start' -Action (New-ScheduledTaskAction -Execute $wake) -Trigger $logon -Principal $principalTask -Force | Out-Null

    $begin = (Get-Date).AddMinutes(1)
    $repeat = New-ScheduledTaskTrigger -Once -At $begin -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 9999)
    Register-ScheduledTask -TaskName 'SuiteCRM Jobs' -Action (New-ScheduledTaskAction -Execute $jobs) -Trigger $repeat -Principal $principalTask -Force | Out-Null
    Write-Host 'Scheduled tasks SuiteCRM Start and SuiteCRM Jobs are registered.'
} catch {
    Write-Warning "Could not register scheduled tasks: $($_.Exception.Message)"
    Write-Warning "SuiteCRM still runs. After each reboot, run $start"
}

Write-Step 'Done'
Write-Host "SuiteCRM ${Version} is installed."
Write-Host "Open:            $SiteUrl"
Write-Host "Admin user:      $AdminUser"
Write-Host "Passwords file:  $credPath"
Write-Host "After reboot:    $start"
Write-Host ""
Write-Host "Change the admin password after you log in. Do not expose this machine to the internet."
Write-Host "Next, load the American Refining Group demo data:"
Write-Host "    powershell -NoProfile -ExecutionPolicy Bypass -File `"$((Join-Path $here 'Seed-ARG-Data.ps1'))`""

if ($Seed) {
    $seedScript = Join-Path $here 'Seed-ARG-Data.ps1'
    if (-not (Test-Path $seedScript)) { throw "Missing $seedScript" }
    & powershell -NoProfile -ExecutionPolicy Bypass -File $seedScript -Distro $Distro
}
