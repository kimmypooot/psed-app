<?php

namespace App\Support;

use App\Enums\ReportType;
use App\Models\Appointment;
use App\Services\ExcelReportService;
use App\Services\SummaryDatabase;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Is this installation fit to hold real appointments? One line per thing worth checking before go-live, each
 * a pass, a warning (works, but not as it should in production) or a failure (do not go live like this).
 * Run it with `php artisan cares:preflight` on the machine that will serve CARES.
 */
class Preflight
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** @return list<array{id: string, status: string, label: string, detail: string}> */
    public function run(): array
    {
        $results = [];
        $add = function (string $id, string $status, string $label, string $detail = '') use (&$results): void {
            $results[] = compact('id', 'status', 'label', 'detail');
        };
        $is = fn (bool $ok, string $fail = self::FAIL) => $ok ? self::PASS : $fail;

        // --- The application -------------------------------------------------------------------
        $add('env', $is(app()->isProduction()), 'APP_ENV is production', 'APP_ENV='.config('app.env'));
        $add('debug', $is(! config('app.debug')), 'APP_DEBUG is off', config('app.debug') ? 'Debug pages show code, settings and queries to anyone who triggers an error.' : '');
        $add('key', $is(filled(config('app.key'))), 'APP_KEY is set');
        $add('https', $is(str_starts_with((string) config('app.url'), 'https://')), 'APP_URL uses https', 'APP_URL='.config('app.url'));
        $add('log_level', $is(! in_array(config('logging.channels.'.config('logging.default').'.level', config('logging.channels.single.level')), ['debug'], true), self::WARN), 'Logging is not at debug level', 'Debug logs record a great deal about every request.');

        // --- Sessions, cache and proxies -------------------------------------------------------
        $add('cookie_secure', $is((bool) config('session.secure')), 'The session cookie is sent over https only');
        $add('session_encrypt', $is((bool) config('session.encrypt')), 'Sessions are encrypted');
        $driver = config('session.driver');
        $add('session_driver', match (true) {
            in_array($driver, ['redis', 'memcached', 'dynamodb'], true) => self::PASS,
            $driver === 'array' => self::FAIL,
            default => self::WARN,
        }, 'Sessions are kept apart from the appointments database', "SESSION_DRIVER={$driver}".($driver === 'database' ? ': every request writes to the same MySQL as the appointments.' : ''));
        $store = config('cache.default');
        $add('cache_store', match (true) {
            in_array($store, ['redis', 'memcached', 'dynamodb'], true) => self::PASS,
            $store === 'array' => self::FAIL,
            default => self::WARN,
        }, 'The cache is kept apart from the appointments database', "CACHE_STORE={$store}");
        $add('proxies', $is(config('app.trusted_proxies') !== '*', self::WARN), 'Trusted proxies are named, not "*"', 'Use "*" only if nothing but the proxy can reach this server.');

        // --- The database ----------------------------------------------------------------------
        $connection = config('database.connections.'.config('database.default'), []);
        $add('db_user', $is(! in_array($connection['username'] ?? null, ['root', null, ''], true)), 'The database user is not root', 'DB_USERNAME='.($connection['username'] ?? ''));
        $add('db_password', $is(filled($connection['password'] ?? null)), 'The database user has a password');
        $directory = config('database.connections.psed_directory');
        if (is_array($directory)) {
            $add('directory_user', $is(! in_array($directory['username'] ?? null, ['root', null, ''], true) && filled($directory['password'] ?? null)), 'The PSED directory connection is not root and has a password', 'PSED_DIRECTORY_DB_USERNAME='.($directory['username'] ?? ''));
        }
        try {
            $ran = app('migration.repository')->getRan();
            $files = array_keys(app('migrator')->getMigrationFiles(database_path('migrations')));
            $pending = array_diff($files, $ran);
            $add('migrations', $is($pending === []), 'Every migration has been run', $pending === [] ? '' : count($pending).' pending: php artisan migrate --force');
        } catch (Throwable) {
            $add('migrations', self::FAIL, 'Every migration has been run', 'The database could not be read.');
        }

        // --- IMIS ------------------------------------------------------------------------------
        foreach (['sso_url' => 'IMIS_SSO_URL', 'portal_url' => 'IMIS_PORTAL_URL', 'api_url' => 'IMIS_API_URL'] as $key => $env) {
            $url = (string) config("services.imis.{$key}");
            $add("imis_{$key}", $is($url !== '' && str_starts_with($url, 'https://')), "{$env} uses https", $url === '' ? 'Not set.' : "{$env}={$url}");
        }
        $token = (string) config('services.imis.api_token');
        $add('imis_token', $is(strlen($token) >= 32), 'IMIS_API_TOKEN is set and long', 'Rotate it if it was ever shared, together with IMIS\'s CARES_API_TOKEN.');

        // --- The data and the files ------------------------------------------------------------
        try {
            $demo = Schema::hasTable('appointments') ? Appointment::where('received_by', 'Demo Encoder')->count() : 0;
            $add('demo_data', $is($demo === 0), 'No demo appointments are left', $demo === 0 ? '' : "{$demo} appointments encoded by \"Demo Encoder\": php artisan appointments:seed-demo 0 --fresh");
        } catch (Throwable) {
            $add('demo_data', self::WARN, 'No demo appointments are left', 'The appointments could not be counted.');
        }

        $missing = [];
        $reports = new ExcelReportService;
        foreach (ReportType::cases() as $type) {
            try {
                $reports->templatePath($type);
            } catch (Throwable) {
                $missing[] = $type->label();
            }
        }
        try {
            (new SummaryDatabase)->templatePath();
        } catch (Throwable) {
            $missing[] = 'Summary Database';
        }
        $add('templates', $is($missing === []), 'The Excel templates are in place', $missing === [] ? '' : 'Missing from '.config('psed.templates_path').': '.implode(', ', $missing));

        $add('writable', $is(is_writable(storage_path()) && is_writable(base_path('bootstrap/cache'))), 'storage and bootstrap/cache are writable');
        $add('assets', $is(is_file(public_path('build/manifest.json'))), 'The front end is built', 'npm run build');
        $add('optimized', $is(app()->configurationIsCached() && app()->routesAreCached(), self::WARN), 'Configuration and routes are cached', 'php artisan optimize');

        return $results;
    }
}
