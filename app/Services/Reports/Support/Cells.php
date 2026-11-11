<?php

namespace App\Services\Reports\Support;

use Carbon\CarbonInterface;
use PhpOffice\PhpSpreadsheet\Shared\Date;

/** Value formatting shared by the hydrators, following how the CSC templates write things. */
final class Cells
{
    public const NA = 'N/A';

    /** The templates write "N/A" rather than leaving a cell empty. */
    public static function text(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? self::NA : (string) $value;
    }

    /** A real Excel date (the template's cell format shows it as mm/dd/yyyy), or "N/A". */
    public static function date(?CarbonInterface $date): float|string
    {
        return $date ? Date::PHPToExcel($date->copy()->startOfDay()) : self::NA;
    }

    /** "07-Oct-26", as the Absorption report writes dates (text). */
    public static function shortDate(?CarbonInterface $date): string
    {
        return $date ? $date->format('d-M-y') : self::NA;
    }

    /** The MOA keeps the time received as text, "14:30:00". */
    public static function time(?string $time): string
    {
        return $time ? substr($time, 0, 8) : self::NA;
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    /** "mm/dd/yyyy to mm/dd/yyyy", as typed in the MOA's publication column. */
    public static function dateRange(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if ($from && $to) {
            return $from->format('m/d/Y').' to '.$to->format('m/d/Y');
        }

        $only = $from ?? $to;

        return $only ? $only->format('m/d/Y') : self::NA;
    }
}
