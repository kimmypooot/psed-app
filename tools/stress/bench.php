<?php

// Usage (after: DB_DATABASE=psed_stress php artisan migrate; php artisan stress:seed 100000): DB_DATABASE=psed_stress php tools/stress/bench.php
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

chdir(dirname(__DIR__, 2));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (! str_contains(DB::getDatabaseName(), 'stress')) {
    exit("Refusing: not a stress database.\n");
}

$office = FieldOffice::first();
$admin = User::firstOrCreate(['imis_user_id' => 900001], ['username' => 'stress-admin', 'name' => 'Stress Admin', 'role' => UserRole::Admin]);
$fo = User::firstOrCreate(['imis_user_id' => 900002], ['username' => 'stress-fo', 'name' => 'Stress FO', 'role' => UserRole::FieldOffice, 'field_office_id' => $office->id]);

function hit(string $label, User $user, string $uri, int $runs = 5): void
{
    global $app;
    $times = [];
    $queries = 0;

    for ($i = 0; $i < $runs; $i++) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        Auth::guard('web')->setUser($user);
        $start = hrtime(true);
        $response = $app->make(Kernel::class)->handle(Request::create($uri, 'GET'));
        $times[] = (hrtime(true) - $start) / 1e6;
        $queries = count(DB::getQueryLog());
        $status = $response->getStatusCode();
    }

    sort($times);
    printf("%-48s %3d  first %7.1f ms   median %7.1f ms   %3d queries\n", $label, $status, $times[0] === min($times) ? $times[0] : $times[0], $times[intdiv($runs, 2)], $queries);
}

echo 'Rows: '.number_format(Appointment::count())."\n\n";

echo "== Pages (cold = first run before cache, then median of warm runs) ==\n";
Cache::flush();
hit('Dashboard (PSED, all offices)', $admin, '/dashboard');
Cache::flush();
hit('Dashboard (Field Office)', $fo, '/dashboard');
hit('Appointments list (PSED)', $admin, '/appointments');
hit('Appointments list page 500 (PSED)', $admin, '/appointments?page=500');
hit('Appointments list, search "Santos1"', $admin, '/appointments?search=Santos1');
hit('Appointments list, search "Reyes" (many hits)', $admin, '/appointments?search=Reyes');
hit('Appointments list, date range', $admin, '/appointments?date_from=2026-01-01&date_to=2026-03-31');
hit('Appointments list, status=pending', $admin, '/appointments?status=pending');
hit('Appointments list, agency filter', $admin, '/appointments?agency=Agency+number+12');
hit('Appointments list (Field Office)', $fo, '/appointments');
hit('Appointments list, FO + sort by SG', $fo, '/appointments?sort=salary_grade&dir=desc');

echo "\n== Dashboard: old logic vs new (same numbers, admin scope) ==\n";
$today = CarbonImmutable::today();
$start = $today->startOfMonth();
$end = $today->endOfMonth()->startOfDay();
$t = hrtime(true);
$scope = fn () => Appointment::query();
$received = $scope()->whereBetween('date_received', [$today->startOfYear()->toDateString(), $today->endOfYear()->toDateString()])->pluck('date_received');
$acted = $scope()->acted()->whereBetween('action_date', [$today->startOfYear()->toDateString(), $today->endOfYear()->toDateString()])->pluck('action_date');
$trend = collect(range(1, 12))->map(fn ($m) => [$received->filter(fn ($d) => $d->month === $m)->count(), $acted->filter(fn ($d) => $d->month === $m)->count()]);
foreach (FieldOffice::all() as $o) {
    Appointment::where('field_office_id', $o->id)->receivedBetween($start, $end)->count();
    Appointment::where('field_office_id', $o->id)->actedBetween($start, $end)->count();
    Appointment::where('field_office_id', $o->id)->where('processed_by', 'psed')->unacted()->count();
    Appointment::where('field_office_id', $o->id)->where('processed_by', 'field_office')->unacted()->count();
}
printf("old trend + office table (PHP counting, N x 4 counts): %.1f ms, %d rows hydrated for the trend\n", (hrtime(true) - $t) / 1e6, $received->count() + $acted->count());

echo "\n== EXPLAIN of the hot list queries ==\n";
foreach ([
    'office + sort' => "SELECT id FROM appointments WHERE field_office_id = {$office->id} ORDER BY date_received DESC, id DESC LIMIT 15",
    'all offices + sort' => 'SELECT id FROM appointments ORDER BY date_received DESC, id DESC LIMIT 15',
    'date range' => "SELECT id FROM appointments WHERE date_received >= '2026-01-01' AND date_received <= '2026-03-31' ORDER BY date_received DESC LIMIT 15",
    'backlog count' => "SELECT COUNT(*) FROM appointments WHERE field_office_id = {$office->id} AND processed_by = 'psed' AND (action_date IS NULL OR csc_action IS NULL OR csc_action = 'Pending')",
    'search contains' => "SELECT id FROM appointments WHERE (last_name LIKE '%Reyes%' OR first_name LIKE '%Reyes%' OR agency LIKE '%Reyes%') ORDER BY date_received DESC LIMIT 15",
] as $name => $sql) {
    $plan = DB::select('EXPLAIN '.$sql)[0];
    printf("%-20s type=%-6s key=%-40s rows=%s %s\n", $name, $plan->type, $plan->key ?? 'NONE', number_format($plan->rows), $plan->Extra ?? '');
}
