# CARES: Centralized Appointment Attestation and Reporting via Electronic System

CSC Regional Office VIII. Laravel 13, Vue 3, Inertia 3, Tailwind 4, MySQL.

A separate app under the IMIS hub. **Staff sign in only through IMIS** (`Auth\ImisSsoController`, same contract as ETTS, ERIS, ROOMS): no passwords, no registration. Users are stubs linked by `imis_user_id`.

## What it does

1. Field Office encodes the appointments received from client agencies.
2. Each one is routed on save (`App\Services\AppointmentRouter`, step 4 of the flow chart):

| Field Office is headed by | PSED processes | Field Office processes |
|---|---|---|
| Director II (SG 26) | SG 26 and above | up to SG 25 |
| Caretaker / OIC (SG 22) | SG 22 and above | below SG 22 |
| Neither (default) | SG 21 and above (consolidated) | below SG 21 |

3. The processor records the CSC action (Approved, Disapproved, Invalidated, Pending, Validated). A Field Office cannot record it on PSED-routed appointments.
4. Monthly IPMR, MRAP, MOA and Absorption reports are produced by **template hydration**.

## Excel template hydration

`App\Services\ExcelReportService` opens the CSC's own `.xlsx` from `storage/app/templates/` with `IOFactory::load()`, writes data into cells, and saves a copy. The workbook is never built from scratch. Merged cells, headers, validations and formulas survive.

- **MOA, Absorption:** list reports. Sample rows are cleared, rows are written with the first data row's style, and `insertNewRowBefore()` pushes the footer/signatures down when data outgrows the template.
- **IPMR, MRAP:** fixed grids. Only the selected month's column inputs are written; the template's formulas recalculate.
- **Consolidated reports:** PSED staff and administrators can choose **All Field Offices** for any of the four reports. MOA and Absorption list every office's appointments (each row names its office); IPMR and MRAP add up all offices and fill every month from January to the selected one, since the template's earlier months hold a single office's figures. Signature lines are left blank. Field Office staff only ever get their own office.
- The one office code is the IMIS unit code (ESFO, SFO ...): it matches staff at sign-in and is printed on the reports.
- Templates are found by pattern (`config/psed.php`), newest file wins.
- Try one without the browser: `php artisan cares:report moa --year=2026 --month=10` (add `--office=all` for the consolidated one).

Which appointments go in a month: received in it, or acted upon in it (the CSC's October files include September receipts acted in October).

## Importing appointments

**Appointments > Import** brings appointments in from the **MOA form** (the Monitoring of Appointments layout: the *Plantilla* and *CasualCon* sheets, data from row 10). A MOA report downloaded from CARES imports back exactly; a blank form can be downloaded from the dialog.

It is two steps, so nothing is saved by surprise: the file is **checked first** (every row converted and validated with the same rules as the encoding form, nothing stored), a summary shows what is ready, what is a duplicate and what has errors (with the spreadsheet row and the reason), and only on confirmation are the good rows imported.

- Duplicates (same office, name, position and date received, already in CARES or repeated in the file) are left out.
- Field Office staff import into their own office; PSED chooses one office or reads column A of each row (by office name or IMIS code).
- Appointments are routed on import like encoded ones. The CSC action is imported only where the user may record it (a Field Office cannot record it on appointments PSED processes).
- Limits: `.xlsx`, 10 MB, 2,000 appointments. The import is recorded in the Audit Trail.
## Setup

```
composer install && npm install
cp .env.example .env && php artisan key:generate
# create the database, set DB_* in .env
php artisan migrate --seed       # reference lists from the MOA template, field offices, demo appointments (local only)
npm run build                    # Poppins is self-hosted (resources/fonts), so no network is needed
php artisan test
```

## Connecting to IMIS (done in IMIS, not here)

Access is decided by the person's **IMIS unit**, not by per-person grants:

| IMIS unit of the person | In CARES |
|---|---|
| PSED (`IMIS_PSED_UNIT_CODE`, default `PSED`) | Administrator: every office, settings, Qualification Standards |
| A Field or Satellite Office (ESFO, SFO, BFO, LFO-I, LFO-II, SLFO, NSFO, WLSO) | Field Office user: that office only |
| Anything else | Refused with an explanation |

The office codes are the IMIS unit codes and are also the codes printed on the reports. CARES is registered in IMIS as a system **without** "requires access", so no one needs a role granted; CARES makes the decision from the unit IMIS reports.

1. Add this app's `host:port` (local: `localhost:8110`) to IMIS `SSO_HUB_HOSTS`.
2. In IMIS, list the system under **Applications**, URL `http://localhost:8110/sso`, under the PSED unit.
3. `.env`: `IMIS_SSO_URL`, `IMIS_PORTAL_URL`, `APP_URL` (the audience is derived from it).

Then set each office's head (Director II, OIC SG 22, default) and the report signatories under **Settings > Field Offices**. Changing the head re-routes that office's existing appointments.
## A Director II who heads more than one Field Office

IMIS lists the other offices a person heads (`headed_offices`, by office code) at each sign-in; CARES keeps them in `field_office_user` and rewrites them every time. Such a person chooses which office they are working as after signing in, and can switch from the header (`ApplyActiveOffice`). The choice is checked against that list on every request and is never saved to the account.

## Opening balances (the MRAP's Beginning Balance and section G)

The balance a Field Office carries into a year is its appointments still unacted on 31 December of the year before. A system that starts in 2027 holds nothing earlier, so PSED enters each office's balance under **Settings > Opening Balances**. Where PSED sets none, the reports use what the appointments in the system show (`OpeningBalances`), which is what later years use.

## Production checklist

Run `php artisan cares:preflight` on the server that will serve CARES. It checks what can be checked below (debug off, https, secure and encrypted sessions, sessions and cache away from the appointments database, a non-root database user with a password, IMIS over https with a long token, no demo data left, no pending migrations, the Excel templates in place, assets built, config cached) and exits with an error if anything fails; `--strict` makes warnings fail too.

- `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`. Sessions are encrypted and the cookie is secure-only; HTTPS is forced and the security headers (including a strict content-security policy) are on in production.
- Sessions, cache and queue off the database that holds the appointments (Redis if the host has it). Set `TRUSTED_PROXIES` when behind a proxy.
- Rotate `IMIS_API_TOKEN` if it was ever shared; keep `.env` out of the repository.
- `php artisan migrate --force`, then `php artisan optimize` and `npm run build`.
- `composer audit` and `npm audit`; a limited-privilege database user; backups that have been restored at least once.
- Remove demo data: `php artisan appointments:seed-demo 0 --fresh` (demo rows are the ones received by "Demo Encoder").

## Stress testing

Never against real data. Create a throwaway database whose name contains `stress`, then:

```
DB_DATABASE=psed_stress php artisan migrate
DB_DATABASE=psed_stress php artisan stress:seed 100000
DB_DATABASE=psed_stress php tools/stress/bench.php          # page timings, EXPLAIN of the hot queries
tools/stress/run-load.sh 25                                  # 25 simulated users at once
```

## Summary Database

**Reports > Summary Database** (PSED administrators) fills the CSC's *Integrated CSE Applicants and Absorption Database Summary* workbook (K-12, JOCOSC6, SKOE and special groups, per Field Office and agency) from the appointments approved in a year, quarter, month or range of dates. Place the blank workbook in `storage/app/templates` (`Integrated CSE*Summary*.xlsx`).

"Absorbed" is an approved or validated appointment whose eligibility is used for the first time (as in the Absorption Report); "regular plantilla" is Permanent status. Agency names are matched to the workbook's rows after tidying ("Province of" and bracketed parts dropped); one that matches no row is counted in its office's own row, with a note. CARES cannot know who applied for or was granted an eligibility, the Category III / IV of a position, or Job Order and Contract of Service, so those cells (and the SKOE position columns, which the template adds up to the grantees) are left for PSED to type, and the template works out the rates.

## Not built yet

- Import of the legacy appointments (`../psed/appt_fo.php`).
