<?php

namespace App\Enums;

use App\Models\FieldOffice;
use App\Services\Reports\ReportContext;

/** The four monthly reports of the Field Office. */
enum ReportType: string
{
    case Ipmr = 'ipmr';
    case Mrap = 'mrap';
    case Moa = 'moa';
    case Absorption = 'absorption';

    public function label(): string
    {
        return match ($this) {
            self::Ipmr => 'IPMR',
            self::Mrap => 'MRAP',
            self::Moa => 'MOA',
            self::Absorption => 'Absorption Report',
        };
    }

    public function fullName(): string
    {
        return match ($this) {
            self::Ipmr => 'Integrated Performance Monitoring Report',
            self::Mrap => 'Monthly Report on Appointments Processed',
            self::Moa => 'Monitoring of Appointments',
            self::Absorption => 'Monthly Report on Absorption Rate (first-time entrants to government service)',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Ipmr => 'Fills the month\'s column of the 2026 IPMR: first-time eligibles (5.1/5.2), appointments received (6.2.1) and acted upon (6a.1, 6b.1).',
            self::Mrap => 'Fills the month\'s column of the Enhanced MRAP: received, ready to act, acted, unacted and disapproved appointments.',
            self::Moa => 'Lists every appointment received or acted upon in the month, on the Plantilla and CasualCon sheets.',
            self::Absorption => 'Lists the first-time entrants whose appointments were approved or validated in the month, with the eligibility breakdown.',
        };
    }

    /** The file name offered for download, after the CSC naming of each report. */
    public function downloadName(?FieldOffice $office, int $year, int $month): string
    {
        $monthName = date('F', mktime(0, 0, 0, $month, 1, $year));

        return match ($this) {
            self::Absorption => sprintf('AbsorptionRate_%d_%02d_%s.xlsx', $year, $month, $office ? str_replace(' ', '-', $office->name) : 'Regional-Office-VIII'),
            default => sprintf('%s %s - %s %d.xlsx', $office?->imis_code ?? ReportContext::REGION_CODE, strtoupper($this->value), $monthName, $year),
        };
    }
}
