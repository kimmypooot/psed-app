<?php

namespace App\Console\Commands;

use App\Services\AgencyList;
use Illuminate\Console\Command;
use RuntimeException;

class SyncAgencies extends Command
{
    protected $signature = 'agencies:sync {--dry-run : Show what would change without changing anything}';

    protected $description = 'Bring the agency list in from the PSED Directory of Government Agencies';

    public function handle(AgencyList $list): int
    {
        try {
            $agencies = $list->fromDirectory();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $result = $list->sync($agencies, $dry);

        if (! $dry) {
            $list->saveJson($agencies);
        }

        $this->info(($dry ? 'Dry run. ' : '').count($agencies).' agencies in the directory: '
            ."{$result['added']} added, {$result['moved']} moved to another office, {$result['removed']} removed, {$result['unchanged']} unchanged.");

        if ($result['no_office']) {
            $this->warn(count($result['no_office']).' agencies have a field office that matches none here, so they are suggested to no one:');
            foreach ($result['no_office'] as $name) {
                $this->line("  - {$name}");
            }
        }

        if ($result['orphaned']->isNotEmpty()) {
            $this->warn('Saved appointments naming an agency that is not in the list (rename them or correct the directory):');
            foreach ($result['orphaned'] as $name => $total) {
                $this->line("  - {$name} ({$total})");
            }
        }

        return self::SUCCESS;
    }
}
