<?php

// One simulated user (run several at once with tools/stress/run-load.sh): prints its request timings in ms as JSON.
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

chdir(dirname(__DIR__, 2));
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
if (! str_contains(DB::getDatabaseName(), 'stress')) {
    exit("Refusing: not a stress database.\n");
}

$user = User::where('imis_user_id', 900001)->first();
$pages = ['/dashboard', '/appointments', '/appointments?status=pending', '/appointments?date_from=2026-01-01&date_to=2026-06-30', '/appointments?page=3'];
$times = [];

for ($i = 0; $i < (int) ($argv[1] ?? 30); $i++) {
    Auth::guard('web')->setUser($user);
    $request = Request::create($pages[$i % count($pages)], 'GET');
    $start = hrtime(true);
    $response = $app->make(Kernel::class)->handle($request);
    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, 'status '.$response->getStatusCode()."\n");
    }
    $times[] = (hrtime(true) - $start) / 1e6;
}

echo json_encode($times);
