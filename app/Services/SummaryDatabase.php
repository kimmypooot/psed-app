<?php

namespace App\Services;

use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use App\Models\SummaryAgencyAlias;
use App\Services\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * The Integrated CSE Applicants and Absorption Database Summary: four sheets (K-12 graduates, JOCOSC6,
 * SKOE, special groups) listing every Field Office's agencies, filled in from the appointments acted upon
 * in a period.
 *
 * What CARES knows is how many appointees were hired and how: which eligibility they used, whether it was
 * used for the first time, their status, and whether they are K-12 graduates, PWD, indigenous or solo
 * parents. What it does not hold is who applied for or was granted an eligibility, nor the Category III / IV
 * of a position, nor Job Order and Contract of Service (they are not appointments): those cells are left
 * for PSED to type, and the template's own formulas then work out the rates.
 *
 * An appointment is "absorbed" when it was approved or validated in the period and the appointee used
 * the eligibility for the first time (the rule of the Absorption Report).
 */
class SummaryDatabase
{
    /** sheet => [column => metric], the cells CARES fills in each agency's row. */
    private const SHEETS = [
        'K-12 Absorption Summary' => [
            'D' => 'k12_cse_pr', 'E' => 'k12_skoe', 'F' => 'k12_professional', 'G' => 'k12_subprofessional',
            'H' => 'k12_other', 'I' => 'k12_non_permanent', 'J' => 'k12_permanent',
        ],
        'JOCOSC6 Summary' => ['AD' => 'abs_cse_pr', 'AE' => 'abs_cs_exam', 'AF' => 'abs_other_spel'],
        // E to G (the grantees by SK position) are for PSED: the template adds them up to the grantees, so they are not appointees.
        'SKOE Absorption Summary' => ['H' => 'skoe_permanent'],
        'Special Groups Summary' => [
            'D' => 'pwd_absorbed', 'E' => 'pwd_non_permanent', 'H' => 'ip_absorbed', 'I' => 'ip_non_permanent',
            'L' => 'solo_absorbed', 'M' => 'solo_non_permanent',
        ],
    ];

    /** Two names are the same agency when this alike once tidied (percent). */
    private const CLOSE_ENOUGH = 85;

    /**
     * @return array{path: string, name: string, report: array<string, mixed>}
     */
    public function generate(ReportPeriod $period): array
    {
        (new ExcelReportService)->allowEnoughMemory();

        $book = IOFactory::load($this->templatePath());
        $report = $this->fill($book, $period);

        $path = tempnam(sys_get_temp_dir(), 'cares_').'.xlsx';
        $writer = IOFactory::createWriter($book, 'Xlsx');
        $writer->setPreCalculateFormulas(true);
        $writer->save($path);
        $book->disconnectWorksheets();

        return ['path' => $path, 'name' => "Integrated CSE Applicants and Absorption Database Summary - {$period->label}.xlsx", 'report' => $report];
    }

    /**
     * What a download for the period would hold, without making one: the totals, and the agencies that
     * could not be matched to a row of the workbook (they are counted in their office's total instead).
     *
     * @return array<string, mixed>
     */
    public function check(ReportPeriod $period): array
    {
        $book = IOFactory::load($this->templatePath());
        $report = $this->fill($book, $period);
        $book->disconnectWorksheets();

        return $report;
    }

    public function templatePath(): string
    {
        $matches = array_values(array_filter(
            glob(config('psed.templates_path').DIRECTORY_SEPARATOR.config('psed.summary_database_template')) ?: [],
            fn (string $p) => ! str_starts_with(basename($p), '~$'),
        ));

        if ($matches === []) {
            throw new RuntimeException(sprintf(
                'The Summary Database template was not found. Place the workbook matching "%s" in %s.',
                config('psed.summary_database_template'),
                config('psed.templates_path'),
            ));
        }

        usort($matches, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $matches[0];
    }

    // ------------------------------------------------------------------------------ the figures

    /**
     * Counts per office and agency for the period.
     *
     * @return array<int, array<string, array<string, int>>> field office id => agency => metric => count
     */
    private function figures(ReportPeriod $period): array
    {
        $approved = config('psed.approved_actions');
        $skoe = config('psed.skoe.eligibility');
        $marks = implode(',', array_fill(0, count($approved), '?'));

        // Each metric is [condition, bindings]; the rows are already only those approved in the period.
        $first = 'is_first_time_entrant = 1';
        $permanent = "employment_status = 'Permanent'";
        $pr = "eligibility_type LIKE 'CSE-Preference Rating%'";
        $professional = "eligibility_type = 'CS Professional'";
        $subprofessional = "eligibility_type = 'CS Subprofessional'";
        $named = "eligibility_type IS NOT NULL AND eligibility_type != 'N/A'";

        $metrics = [
            'approved' => ['1 = 1', []],
            // K-12 graduates employed, by the eligibility they used for the first time.
            'k12_cse_pr' => ["is_k12_graduate = 1 AND {$first} AND {$pr}", []],
            'k12_skoe' => ["is_k12_graduate = 1 AND {$first} AND eligibility_type = ?", [$skoe]],
            'k12_professional' => ["is_k12_graduate = 1 AND {$first} AND {$professional}", []],
            'k12_subprofessional' => ["is_k12_graduate = 1 AND {$first} AND {$subprofessional}", []],
            'k12_total' => ['is_k12_graduate = 1', []],
            'k12_non_permanent' => ["is_k12_graduate = 1 AND NOT ({$permanent})", []],
            'k12_permanent' => ["is_k12_graduate = 1 AND {$permanent}", []],
            // Absorbed as permanent employees, by what they used (JOCOSC6).
            'abs_cse_pr' => ["{$first} AND {$permanent} AND {$pr}", []],
            'abs_cs_exam' => ["{$first} AND {$permanent} AND ({$professional} OR {$subprofessional})", []],
            'abs_other_spel' => ["{$first} AND {$permanent} AND {$named} AND NOT ({$pr}) AND NOT ({$professional}) AND NOT ({$subprofessional})", []],
            // SKOE: absorbed as permanent employees.
            'skoe_permanent' => ["eligibility_type = ? AND {$first} AND {$permanent}", [$skoe]],
            // Special groups: absorbed (permanent, first-time) and employed in non-permanent positions.
            'pwd_absorbed' => ["is_pwd = 1 AND {$first} AND {$permanent}", []],
            'pwd_non_permanent' => ["is_pwd = 1 AND NOT ({$permanent})", []],
            'ip_absorbed' => ["is_indigenous = 1 AND {$first} AND {$permanent}", []],
            'ip_non_permanent' => ["is_indigenous = 1 AND NOT ({$permanent})", []],
            'solo_absorbed' => ["is_solo_parent = 1 AND {$first} AND {$permanent}", []],
            'solo_non_permanent' => ["is_solo_parent = 1 AND NOT ({$permanent})", []],
        ];

        $select = [];
        $bindings = [];
        foreach ($metrics as $name => [$condition, $values]) {
            $select[] = "COALESCE(SUM(CASE WHEN {$condition} THEN 1 ELSE 0 END), 0) AS {$name}";
            array_push($bindings, ...$values);
        }

        $rows = DB::table('appointments')
            ->whereIn('csc_action', $approved)
            ->where('action_date', '>=', $period->from->toDateString())->where('action_date', '<', $period->to->addDay()->toDateString())
            ->selectRaw('field_office_id, agency, '.implode(', ', $select), $bindings)
            ->groupBy('field_office_id', 'agency')
            ->get();

        $figures = [];
        foreach ($rows as $row) {
            $figures[$row->field_office_id][$row->agency] = collect($metrics)->keys()->mapWithKeys(fn ($m) => [$m => (int) $row->{$m}])->all();
            // K-12 graduates who got in by another way (or by an eligibility not used for the first time): the rest of those employed.
            $m = &$figures[$row->field_office_id][$row->agency];
            $m['k12_other'] = max(0, $m['k12_total'] - $m['k12_cse_pr'] - $m['k12_skoe'] - $m['k12_professional'] - $m['k12_subprofessional']);
            unset($m);
        }

        return $figures;
    }

    // ------------------------------------------------------------------------------ the workbook

    /**
     * @return array<string, mixed> what was written, for the check
     */
    private function fill(Spreadsheet $book, ReportPeriod $period): array
    {
        $figures = $this->figures($period);
        $offices = FieldOffice::orderBy('id')->get();
        $aliases = $this->aliases();
        $report = ['period' => $period->label, 'appointments' => 0, 'sheets' => [], 'unmatched' => []];

        $report['appointments'] = (int) DB::table('appointments')->whereIn('csc_action', config('psed.approved_actions'))
            ->where('action_date', '>=', $period->from->toDateString())->where('action_date', '<', $period->to->addDay()->toDateString())->count();

        foreach (self::SHEETS as $title => $columns) {
            $sheet = $book->getSheetByName($title);
            if ($sheet === null) {
                throw new RuntimeException("The template has no \"{$title}\" sheet.");
            }

            $sheet->setCellValue('A1', rtrim((string) $sheet->getCell('A1')->getValue()).' | '.$period->label);
            $totals = array_fill_keys($columns, 0);

            foreach ($this->blocks($sheet) as $block) {
                $office = $offices->first(fn (FieldOffice $o) => $this->officeMatches($o->name, $block['name']));
                if ($office === null) {
                    continue;
                }

                $rowOf = $this->rowIndex($block['agencies']);
                $other = array_fill_keys($columns, 0);
                $otherAgencies = [];

                foreach ($figures[$office->id] ?? [] as $agency => $metrics) {
                    // PSED's own pairing wins over the automatic one; "no row of its own" is a decision too.
                    $paired = array_key_exists($agency, $aliases[$office->id] ?? []);
                    $row = $paired
                        ? (($aliases[$office->id][$agency] ?? null) !== null ? ($rowOf[$this->tidy($aliases[$office->id][$agency])] ?? $this->match($agency, $rowOf)) : null)
                        : $this->match($agency, $rowOf);

                    foreach ($columns as $col => $metric) {
                        $value = $metrics[$metric];
                        $totals[$metric] += $value;

                        if ($value === 0) {
                            continue;
                        }

                        if ($row === null) {
                            $other[$metric] += $value;
                        } elseif (! $sheet->getCell($col.$row)->isFormula()) {
                            $sheet->setCellValue($col.$row, $value);
                        }
                    }

                    if ($row === null && array_sum(array_intersect_key($metrics, array_flip($columns))) > 0) {
                        $otherAgencies[$agency] = $metrics['approved'];
                    }
                }

                // Agencies the workbook does not list still count: in their office's own row, with a note saying so.
                if ($otherAgencies !== []) {
                    foreach ($columns as $col => $metric) {
                        if ($other[$metric] > 0) {
                            $cell = $sheet->getCell($col.$block['row']);
                            $current = $cell->getValue();
                            $sheet->setCellValue($col.$block['row'], is_string($current) && str_starts_with($current, '=') ? $current.'+'.$other[$metric] : (int) $current + $other[$metric]);
                        }
                    }

                    $sheet->getComment('B'.$block['row'])->getText()->createTextRun(
                        'Includes appointments of agencies that are not listed on this sheet: '.collect($otherAgencies)->map(fn ($n, $a) => "{$a} ({$n})")->implode('; ')
                    );

                    // An agency PSED has said has no row of its own is counted here, but is no longer flagged.
                    foreach ($otherAgencies as $agency => $n) {
                        if (! array_key_exists($agency, $aliases[$office->id] ?? [])) {
                            $report['unmatched'][$office->name][$agency] = $n;
                        }
                    }
                }
            }

            $report['sheets'][$title] = $totals;
        }

        // The same agency can be left over on several sheets: list it once.
        $report['unmatched'] = collect($report['unmatched'])->map(fn (array $agencies, string $office) => collect($agencies)
            ->map(fn ($n, $agency) => ['agency' => $agency, 'appointments' => $n])->values()->all())->all();

        return $report;
    }

    /**
     * The Field Office blocks of a sheet: a row marked "-" with the office's name, then its agencies.
     *
     * @return list<array{name: string, row: int, agencies: array<int, string>}>
     */
    private function blocks(Worksheet $sheet): array
    {
        $blocks = [];
        $current = null;

        for ($i = 6, $last = $sheet->getHighestDataRow('B'); $i <= $last; $i++) {
            $name = trim((string) $sheet->getCell("B{$i}")->getValue());
            if ($name === '') {
                continue;
            }

            if (trim((string) $sheet->getCell("A{$i}")->getValue()) === '-') {
                $blocks[] = ['name' => $name, 'row' => $i, 'agencies' => []];
                $current = array_key_last($blocks);
            } elseif ($current !== null) {
                $blocks[$current]['agencies'][$i] = $name;
            }
        }

        return $blocks;
    }

    /** "Field Office-Eastern Samar" is the workbook's "Eastern Samar", and "Samar" is not "Eastern Samar". */
    private function officeMatches(string $officeName, string $blockName): bool
    {
        return $this->tidy(preg_replace('/^(field|satellite) office[\s-]*/i', '', $officeName)) === $this->tidy($blockName);
    }

    /**
     * @param  array<int, string>  $agencies  row => name
     * @return array<string, int> tidied name => row
     */
    private function rowIndex(array $agencies): array
    {
        $index = [];
        foreach ($agencies as $row => $name) {
            $index[$this->tidy($name)] = $row;
        }

        return $index;
    }

    /** The row of the workbook's agency that this one is, or null. */
    private function match(string $agency, array $rowOf): ?int
    {
        $name = $this->tidy($agency);

        if (isset($rowOf[$name])) {
            return $rowOf[$name];
        }

        [$row, $score] = $this->closest($name, $rowOf);

        return $score >= self::CLOSE_ENOUGH ? $row : null;
    }

    /**
     * The row whose name is most like this (already tidied) one, and how alike (percent).
     *
     * @return array{0: ?int, 1: float}
     */
    private function closest(string $name, array $rowOf): array
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($rowOf as $candidate => $row) {
            similar_text($name, (string) $candidate, $score);
            if ($score > $bestScore) {
                [$best, $bestScore] = [$row, $score];
            }
        }

        return [$best, $bestScore];
    }

    /**
     * What PSED has paired by hand: office id => CARES agency name => the workbook's agency, or null for "no row of its own".
     *
     * @return array<int, array<string, ?string>>
     */
    private function aliases(): array
    {
        return SummaryAgencyAlias::all()->groupBy('field_office_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn (SummaryAgencyAlias $a) => [$a->agency => $a->template_agency])->all())->all();
    }

    /**
     * Every Field Office's agencies against the workbook's rows, for the screen where PSED pairs the ones that
     * do not match: how each is matched (`auto`, `mapped`, `none` or `unmatched`), to which row, and the closest
     * row to suggest for an unmatched one.
     *
     * @return list<array{id: int, name: string, rows: list<string>, agencies: list<array<string, mixed>>}>
     */
    public function review(): array
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly('K-12 Absorption Summary');
        $book = $reader->load($this->templatePath());
        $blocks = $this->blocks($book->getSheetByName('K-12 Absorption Summary'));
        $book->disconnectWorksheets();

        $aliases = $this->aliases();
        $result = [];

        foreach (FieldOffice::orderBy('name')->get() as $office) {
            $block = collect($blocks)->first(fn (array $b) => $this->officeMatches($office->name, $b['name']));
            if ($block === null) {
                continue;
            }

            $rowOf = $this->rowIndex($block['agencies']);
            $agencies = ReferenceValue::where('type', 'agency')->where('field_office_id', $office->id)->pluck('value')
                ->merge(DB::table('appointments')->where('field_office_id', $office->id)->distinct()->pluck('agency'))
                ->unique()->sort()->values();

            $items = [];
            foreach ($agencies as $agency) {
                $paired = array_key_exists($agency, $aliases[$office->id] ?? []);
                $template = $paired ? $aliases[$office->id][$agency] : null;
                $row = $paired ? null : $this->match($agency, $rowOf);
                [$nearRow, $score] = $paired || $row ? [null, 0.0] : $this->closest($this->tidy($agency), $rowOf);

                $items[] = [
                    'agency' => $agency,
                    'status' => $paired ? ($template === null ? 'none' : 'mapped') : ($row ? 'auto' : 'unmatched'),
                    'template' => $paired ? $template : ($row ? $block['agencies'][$row] : null),
                    'suggestion' => $nearRow !== null && $score >= 60 ? $block['agencies'][$nearRow] : null,
                ];
            }

            $result[] = ['id' => $office->id, 'name' => $office->name, 'rows' => array_values($block['agencies']), 'agencies' => $items];
        }

        return $result;
    }

    /** Lower case, without the bracketed part or "Province of", so the two ways of writing a name meet. */
    private function tidy(string $name): string
    {
        $name = strtolower(preg_replace('/\([^)]*\)/', ' ', $name));
        $name = str_replace(['province of', 'formerly', 'the '], ' ', $name);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $name)));
    }
}
