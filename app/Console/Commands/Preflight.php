<?php

namespace App\Console\Commands;

use App\Support\Preflight as Checks;
use Illuminate\Console\Command;

/**
 * Checks this installation against what going live needs. Exits with an error if anything fails, so it can
 * gate a deployment; `--strict` makes warnings fail too.
 */
class Preflight extends Command
{
    protected $signature = 'cares:preflight {--strict : Treat warnings as failures}';

    protected $description = 'Check that this installation is fit to hold real appointments';

    public function handle(): int
    {
        $results = (new Checks)->run();
        $mark = [Checks::PASS => 'PASS', Checks::WARN => 'WARN', Checks::FAIL => 'FAIL'];

        $this->table(['', 'Check', 'Detail'], array_map(
            fn (array $r) => [$mark[$r['status']], $r['label'], $r['status'] === Checks::PASS ? '' : $r['detail']],
            $results,
        ));

        $failed = count(array_filter($results, fn ($r) => $r['status'] === Checks::FAIL));
        $warned = count(array_filter($results, fn ($r) => $r['status'] === Checks::WARN));

        if ($failed === 0 && ($warned === 0 || ! $this->option('strict'))) {
            $this->info($warned ? "Ready, with {$warned} warning".($warned === 1 ? '' : 's').'.' : 'Ready to go live.');

            return self::SUCCESS;
        }

        $this->error("{$failed} check".($failed === 1 ? '' : 's').' failed'.($warned ? ", {$warned} warning".($warned === 1 ? '' : 's') : '').'. Fix these before going live.');

        return self::FAILURE;
    }
}
