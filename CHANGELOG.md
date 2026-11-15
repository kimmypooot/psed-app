# Changelog

All notable changes to CARES (the PSED appointments system) are recorded here, newest first.
The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow
[Semantic Versioning](https://semver.org/):

- **Patch** (1.0.1): bug fixes only.
- **Minor** (1.1.0): new features that keep what exists working.
- **Major** (2.0.0): a change that breaks something, such as a new MOA form layout or import format.

Add an entry under **Unreleased** with every change; when a version is released, rename that heading to
the version and date and start a new empty **Unreleased** above it.

## [Unreleased]

## [1.0.0] - 2026-11-14

The first release: the system as it stands for go-live.

### Added

- **Appointment Analytics** (Statistics, PSED administrators): counts of what was received and how it was
  decided, by month or year, with the approval rate, average turnaround and pending appointments by age.
  Breakdowns by status of employment, nature of appointment, level, salary grade, sex, sector, eligibility,
  mode of publication, processor, Field Office, agency and position, with filters for year, office and
  Plantilla or Casual/Contractual. Charts are drawn without a chart library.
- **Import review table**: the import dialog now shows every row of the file (up to 2,000), not a sample.
  It has a "#" column that numbers the rows that will be imported, filters (All, Will be imported, Left out,
  With notes), search, paging, status-coloured rows with the same hover colours as the appointments list, and
  a toggle for the Status and Sheet / row columns. A Received by or Action officer value that looks like
  initials gets a note.
- **Dashboard drill-down**: every count in the Field Offices table (received, acted, Field Office backlog,
  PSED backlog) opens the appointments it counts, and the "Acted upon" card is now a link.
- **Date of action filter** on the appointments list (`acted_from`, `acted_to`), with a removable chip.
- Generate Reports for a year, quarter, month or range of dates.
- Summary Database workbook (Integrated CSE Applicants and Absorption) by period, and Summary Agencies to
  pair an agency with its row in the workbook.
- Report submissions from Field Offices to PSED, with a timeliness rating and accept / return review.
- Opening balances that feed the MRAP, visible to Field Offices.
- Audit Trail, with rows that take the hover colour of what was done.
- Office switcher for people who work as more than one Field Office.
- `cares:preflight` go-live checks, and security headers on every response.

### Changed

- **Generated reports print Received by and Action officer as initials** ("Kim Benedick M. Banoyo" becomes
  `KBMB`). A report imported back therefore brings those two fields in as initials.
- **Appointment form layout**: one 12-column grid with the same spacing in every section; the CSC action
  section gives the Action officer more room and the dates less; Received by sits beside Sector; Publication
  and Eligibility fields are sized to their content.
- Appointment form: removed the helper text under Action officer and Received by, and the
  "Saved earlier ... (not linked to IMIS)" choice under Received by.
- Dashboard: the two backlog column headings break onto two lines ("Field Office / Backlog", "PSED / Backlog").
- Wider filter dropdowns on the Appointment Analytics page so labels are not cut off.

### Fixed

- **CSC action fields were always disabled on the edit page**, even for a Field Office editing an appointment
  it processes. The controller sent `can_record_action` but the page read `canRecordAction`; a missing true/false
  prop is treated as `false`.
- Combobox dropdown no longer clipped.
- Better agency suggestions on the appointment form.
