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
## Not built yet

- IMIS single sign-out (`/sso/logout`), as in `new-imis/docs/sso-logout.md`.
- The MRAP's G.1 to G.4 rows (unacted as of 31 Dec 2025) are left as the office keeps them in the template.
- Import of the legacy appointments (`../psed/appt_fo.php`).
