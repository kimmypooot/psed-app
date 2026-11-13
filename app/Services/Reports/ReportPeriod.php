<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * The stretch of time a report covers: a year, a quarter, a month, or any range of dates.
 * Dates are inclusive, and an appointment belongs to the period in which it was acted upon.
 */
final class ReportPeriod
{
    public const YEAR = 'year';

    public const QUARTER = 'quarter';

    public const MONTH = 'month';

    public const CUSTOM = 'custom';

    private function __construct(
        public readonly string $kind,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
    ) {}

    public static function year(int $year): self
    {
        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();

        return new self(self::YEAR, $from, $from->endOfYear()->startOfDay(), (string) $year);
    }

    public static function quarter(int $year, int $quarter): self
    {
        if ($quarter < 1 || $quarter > 4) {
            throw new InvalidArgumentException('A quarter is 1 to 4.');
        }

        $from = CarbonImmutable::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay();

        return new self(self::QUARTER, $from, $from->addMonths(2)->endOfMonth()->startOfDay(), "Q{$quarter} {$year}");
    }

    public static function month(int $year, int $month): self
    {
        $from = CarbonImmutable::create($year, $month, 1)->startOfDay();

        return new self(self::MONTH, $from, $from->endOfMonth()->startOfDay(), $from->format('F Y'));
    }

    public static function custom(CarbonImmutable $from, CarbonImmutable $to): self
    {
        if ($to->lt($from)) {
            throw new InvalidArgumentException('The end of the period is before its start.');
        }

        return new self(self::CUSTOM, $from->startOfDay(), $to->startOfDay(), $from->format('M j, Y').' to '.$to->format('M j, Y'));
    }

    /** The calendar months it touches, for a report with a column per month (all in one year: see wholeMonthsInOneYear). */
    public function months(): array
    {
        return range($this->from->month, $this->to->month);
    }

    /** From the first day of a month to the last day of a month, in one year: what a month-column report can show truthfully. */
    public function wholeMonthsInOneYear(): bool
    {
        return $this->from->year === $this->to->year
            && $this->from->day === 1
            && $this->to->isSameDay($this->to->endOfMonth());
    }

    /**
     * The validation rules for a period asked for in a request (kind, year, quarter, month, from, to).
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'kind' => ['required', 'in:'.implode(',', [self::YEAR, self::QUARTER, self::MONTH, self::CUSTOM])],
            'year' => ['required_unless:kind,custom', 'integer', 'between:2020,'.(now()->year + 1)],
            'quarter' => ['required_if:kind,quarter', 'integer', 'between:1,4'],
            'month' => ['required_if:kind,month', 'integer', 'between:1,12'],
            'from' => ['required_if:kind,custom', 'date'],
            'to' => ['required_if:kind,custom', 'date', 'after_or_equal:from', 'before_or_equal:'.now()->addYear()->toDateString()],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'to.after_or_equal' => 'The last day cannot be before the first.',
            'from.required_if' => 'Choose the first day.',
            'to.required_if' => 'Choose the last day.',
        ];
    }

    /**
     * From validated request input: kind, year, quarter, month, from, to.
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        return match ($input['kind'] ?? null) {
            self::YEAR => self::year((int) $input['year']),
            self::QUARTER => self::quarter((int) $input['year'], (int) $input['quarter']),
            self::MONTH => self::month((int) $input['year'], (int) $input['month']),
            self::CUSTOM => self::custom(CarbonImmutable::parse($input['from']), CarbonImmutable::parse($input['to'])),
            default => throw new InvalidArgumentException('Choose a year, quarter, month or range of dates.'),
        };
    }
}
