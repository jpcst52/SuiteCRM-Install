# SuiteCRM 8.10 on Windows 11, with an American Refining Group demo book

Two scripts:

1. `Install-SuiteCRM-Windows11.ps1` installs SuiteCRM.
2. `Seed-ARG-Data.ps1` loads a fictional sales book shaped like American Refining Group.

## Why it uses WSL2

SuiteCRM 8.10.2 (the current 8.10 security release) is supported on Linux with PHP 8.2, 8.3, or 8.4, Apache 2.4, and MariaDB 10.6 / 10.11 / 11.4 / 11.8 or MySQL 8.0 / 8.4. Native Windows is not on that matrix. The installer turns on WSL2, installs Ubuntu 24.04, and inside it:

- Apache 2.4, document root pointed at SuiteCRM `public/`
- MariaDB 10.11 (Ubuntu 24.04), listening on 127.0.0.1 only
- PHP 8.3 with the extensions SuiteCRM asks for
- SuiteCRM **8.10.2** from the official GitHub release
- the scheduler and the 8.10 background worker (`messenger:consume internal-async`)

Windows `http://localhost/` is forwarded into that Linux VM. If something on Windows already owns port 80, the site is `http://localhost:8080/` instead.

## Run it

Right-click **Install-SuiteCRM-Windows11.bat** and approve the administrator prompt, or from an elevated PowerShell:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\Install-SuiteCRM-Windows11.ps1
```

The first run can take a while (WSL, then a ~120 MB download, then the installer). If Windows says a reboot is required, reboot and run the same script again. It will not reinstall SuiteCRM if it is already there.

Then:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\Seed-ARG-Data.ps1
```

Or double-click `Seed-ARG-Data.bat`. Seeding does not need administrator.

Passwords are written to `C:\SuiteCRM\credentials.txt`. That file is the admin login. Every other demo user uses `DEMO_USER_PASSWORD` from the same file (default `ArgDemo-ChangeMe-810`). Change the admin password after you sign in. Do not put this machine on the public internet.

After a reboot, run `C:\SuiteCRM\Start-SuiteCRM.ps1` if the site does not come up on its own. A logon task named **SuiteCRM Start** is created to do that, and **SuiteCRM Jobs** runs the scheduler every minute.

## What the demo data is

It is **not** an export of ARG's CRM and it is **not** a price list.

Included, from public information:

- Bradford refinery context: 77 North Kendall Avenue, Bradford, PA 16701, phone (814) 368-1200, about 11,000 barrels per day, light sweet paraffinic crude from Pennsylvania, Ohio, New York, and West Virginia
- Product families ARG actually sells: ARGuard lubricants, Kendex base oils (including the published hydrotreated H-series and ARGold bright stock), Kendex 0834 heavy resin and extracts, Kensol solvents, Kenwax waxes, agricultural oils, defoamer oils, fuels, quench oil, shock fluid, camping fuel, and toll blending
- Public leadership as CRM users (Ryan Krogmeier, Brian Zolkos, Jeff Brewer, Michael Kozminski, Pete Griffin, Joel Simpson, Richard Smith, Heidi Boser, Ryan Best). Their logins are `@arg.example` and are **not** real mailboxes

Made up, on purpose:

- Every customer, prospect, crude gatherer, and logistics partner
- Every contact and lead
- The sales, customer-service, and logistics people other than the public officers
- Every price, gallon, pound, opportunity amount, quote, and contract

Each of those records is labeled fictional in the description. Competitor rows for Calumet, HF Sinclair, and Ergon are public-company reference cards only.

Open **Accounts**, **Opportunities**, **Quotes**, **Cases**, **Leads**, and **Campaigns** after seeding. Re-running the seed skips records that already exist.

## Useful options

```powershell
# Exact package. 8.10.2 is the right default. 8.10.0 and 8.10.1 also exist.
.\Install-SuiteCRM-Windows11.ps1 -Version 8.10.2

# Install and seed in one step
.\Install-SuiteCRM-Windows11.ps1 -Seed
```

Logs inside Ubuntu: `/var/log/suitecrm-install.log` and `/var/log/apache2/suitecrm-error.log`.

## Files

| File | Role |
|---|---|
| `Install-SuiteCRM-Windows11.ps1` | Windows setup |
| `setup-suitecrm.sh` | Ubuntu setup, called by the installer |
| `Seed-ARG-Data.ps1` | Loads the demo book |
| `seed-arg-crm.php` | SuiteCRM writer, called by the seed script |
