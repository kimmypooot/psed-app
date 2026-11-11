<?php

namespace App\Console\Commands;

use App\Enums\ReportType;
use App\Models\FieldOffice;
use App\Services\ExcelReportService;
use Illuminate\Console\Command;

class GenerateReport extends Command
{
    protected $signature = 'cares:report
        {type : ipmr, mrap, moa or absorption}
        {--office= : Field Office name or IMIS code, or "all" for the consolidated report (default: Field Office-Eastern Samar)}
        {--year= : Year (default: current)}
        {--month= : Month 1-12 (default: current)}
        {--out= : Folder to save into (default: storage/app/reports)}';

    protected $description = 'Generate a monthly report from its Excel template, without the browser.';

    public function handle(ExcelReportService $service): int
    {
        $type = ReportType::tryFrom(strtolower($this->argument('type')));
        if (! $type) {
            $this->error('Type must be one of: '.implode(', ', array_column(ReportType::cases(), 'value')));

            return self::FAILURE;
        }

        $needle = $this->option('office') ?: 'Field Office-Eastern Samar';
        $office = strtolower($needle) === 'all'
            ? null
            : FieldOffice::where('name', $needle)->orWhere('imis_code', $needle)->first();
        if ($office === null && strtolower($needle) !== 'all') {
            $this->error("No Field Office matches \"{$needle}\".");

            return self::FAILURE;
        }

        $year = (int) ($this->option('year') ?: now()->year);
        $month = (int) ($this->option('month') ?: now()->month);

        $file = $service->generate($type, $office, $year, $month);

        $dir = $this->option('out') ?: storage_path('app/reports');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = rtrim($dir, '\\/').DIRECTORY_SEPARATOR.$file['name'];
        rename($file['path'], $target);

        $this->info("Saved {$target}");

        return self::SUCCESS;
    }
}
