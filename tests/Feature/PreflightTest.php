<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\FieldOffice;
use App\Support\Preflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The go-live checks (`php artisan cares:preflight`) say pass, warn or fail for the right reasons. */
class PreflightTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> check id => status */
    private function statuses(): array
    {
        return collect((new Preflight)->run())->pluck('status', 'id')->all();
    }

    public function test_debug_and_plain_http_are_failures_in_production(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://cares.example']);
        $bad = $this->statuses();
        $this->assertSame(Preflight::FAIL, $bad['debug']);
        $this->assertSame(Preflight::FAIL, $bad['https']);

        config(['app.debug' => false, 'app.url' => 'https://cares.example']);
        $good = $this->statuses();
        $this->assertSame(Preflight::PASS, $good['debug']);
        $this->assertSame(Preflight::PASS, $good['https']);
    }

    public function test_sessions_and_cache_in_the_appointments_database_are_a_warning_and_array_is_a_failure(): void
    {
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        $this->assertSame([Preflight::WARN, Preflight::WARN], [$this->statuses()['session_driver'], $this->statuses()['cache_store']]);

        config(['session.driver' => 'redis', 'cache.default' => 'redis']);
        $this->assertSame([Preflight::PASS, Preflight::PASS], [$this->statuses()['session_driver'], $this->statuses()['cache_store']]);

        config(['session.driver' => 'array', 'cache.default' => 'array']);
        $this->assertSame([Preflight::FAIL, Preflight::FAIL], [$this->statuses()['session_driver'], $this->statuses()['cache_store']]);
    }

    public function test_the_cookie_and_session_settings_are_checked(): void
    {
        config(['session.secure' => false, 'session.encrypt' => false]);
        $bad = $this->statuses();
        $this->assertSame([Preflight::FAIL, Preflight::FAIL], [$bad['cookie_secure'], $bad['session_encrypt']]);

        config(['session.secure' => true, 'session.encrypt' => true]);
        $good = $this->statuses();
        $this->assertSame([Preflight::PASS, Preflight::PASS], [$good['cookie_secure'], $good['session_encrypt']]);
    }

    public function test_imis_must_be_reached_over_https_with_a_long_token(): void
    {
        config(['services.imis.sso_url' => 'http://imis.example/sso', 'services.imis.portal_url' => null, 'services.imis.api_url' => 'https://imis.example', 'services.imis.api_token' => 'short']);
        $bad = $this->statuses();
        $this->assertSame([Preflight::FAIL, Preflight::FAIL, Preflight::PASS, Preflight::FAIL], [$bad['imis_sso_url'], $bad['imis_portal_url'], $bad['imis_api_url'], $bad['imis_token']]);

        config(['services.imis.sso_url' => 'https://imis.example/sso', 'services.imis.portal_url' => 'https://imis.example', 'services.imis.api_token' => str_repeat('a', 48)]);
        $good = $this->statuses();
        $this->assertSame([Preflight::PASS, Preflight::PASS, Preflight::PASS, Preflight::PASS], [$good['imis_sso_url'], $good['imis_portal_url'], $good['imis_api_url'], $good['imis_token']]);
    }

    public function test_demo_appointments_left_in_the_database_are_a_failure(): void
    {
        $this->assertSame(Preflight::PASS, $this->statuses()['demo_data']);

        Appointment::factory()->create(['field_office_id' => FieldOffice::factory()->create()->id, 'received_by' => 'Demo Encoder']);

        $this->assertSame(Preflight::FAIL, $this->statuses()['demo_data']);
        $detail = collect((new Preflight)->run())->firstWhere('id', 'demo_data')['detail'];
        $this->assertStringContainsString('1 appointments', $detail);
    }

    public function test_the_wildcard_proxy_is_a_warning(): void
    {
        config(['app.trusted_proxies' => '*']);
        $this->assertSame(Preflight::WARN, $this->statuses()['proxies']);

        config(['app.trusted_proxies' => '10.0.0.1']);
        $this->assertSame(Preflight::PASS, $this->statuses()['proxies']);
    }

    public function test_the_command_fails_when_a_check_fails_and_names_it(): void
    {
        config(['app.debug' => true]);

        $this->artisan('cares:preflight')->expectsOutputToContain('APP_DEBUG is off')->assertExitCode(1);
    }
}
