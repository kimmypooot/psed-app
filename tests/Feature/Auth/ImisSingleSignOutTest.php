<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Auth\SessionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Signing out of IMIS ends the CARES session that IMIS's sign-in started
 * (IMIS docs/sso-logout.md). These tests use the real database session driver,
 * so a session genuinely exists, and genuinely stops existing.
 */
class ImisSingleSignOutTest extends TestCase
{
    use RefreshDatabase;

    private const VALIDATE_URL = 'http://imis.test/api/v1/sso/validate';

    private string $sid;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'services.imis.sso_url' => self::VALIDATE_URL,
            'services.imis.audience' => 'CARES.test',
        ]);
        $this->app['session']->forgetDrivers();

        $this->sid = str_repeat('a1', 32); // IMIS's reference: 64 characters
    }

    private function fakeImis(?string $sid): void
    {
        Http::fake([self::VALIDATE_URL => Http::response([
            'success' => true,
            'user' => [
                'id' => 42, 'username' => 'jamorden', 'fname' => 'Juan', 'lname' => 'Amorden',
                'office_code' => 'PSED', 'office_type' => 'regional_support_unit', 'sid' => $sid,
            ],
        ])]);
    }

    private function signIn(?string $sid): void
    {
        $this->fakeImis($sid);
        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');
    }

    public function test_signing_in_ties_the_session_to_imis_and_keeps_only_a_hash_of_the_reference(): void
    {
        $this->signIn($this->sid);

        $link = DB::table('imis_sessions')->sole();
        $this->assertSame(hash('sha256', $this->sid), $link->sid_hash);
        $this->assertDatabaseMissing('imis_sessions', ['sid_hash' => $this->sid]);
        $this->assertDatabaseHas('sessions', ['id' => $link->session_id]);
    }

    public function test_nothing_is_kept_when_imis_sent_no_reference(): void
    {
        $this->signIn(null);

        $this->assertSame(0, DB::table('imis_sessions')->count());
    }

    public function test_imis_signing_out_ends_the_cares_session(): void
    {
        $this->signIn($this->sid);
        $sessionId = DB::table('imis_sessions')->value('session_id');
        $this->assertDatabaseHas('sessions', ['id' => $sessionId]);

        $this->postJson('/sso/logout', ['sid' => $this->sid])->assertOk()->assertExactJson(['success' => true]);

        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertSame(0, DB::table('imis_sessions')->count());
    }

    public function test_an_unknown_or_malformed_reference_is_not_an_error_and_ends_nothing(): void
    {
        $this->signIn($this->sid);
        $sessionId = DB::table('imis_sessions')->value('session_id');

        $this->postJson('/sso/logout', ['sid' => str_repeat('b2', 32)])->assertOk()->assertExactJson(['success' => true]);
        $this->postJson('/sso/logout', ['sid' => 'short'])->assertOk();
        $this->postJson('/sso/logout', [])->assertOk();
        $this->postJson('/sso/logout', ['sid' => ['not', 'a', 'string']])->assertOk();

        $this->assertDatabaseHas('sessions', ['id' => $sessionId]); // someone else's sign-out must not end this session
        $this->assertSame(1, DB::table('imis_sessions')->count());
    }

    public function test_the_same_reference_twice_is_harmless(): void
    {
        $this->signIn($this->sid);

        $this->postJson('/sso/logout', ['sid' => $this->sid])->assertOk();
        $this->postJson('/sso/logout', ['sid' => $this->sid])->assertOk();
    }

    public function test_signing_out_of_cares_removes_the_link_to_imis(): void
    {
        $user = User::factory()->create();
        DB::table('imis_sessions')->insert(['sid_hash' => hash('sha256', $this->sid), 'session_id' => 'this-session', 'created_at' => now()]);
        DB::table('imis_sessions')->insert(['sid_hash' => hash('sha256', 'other'), 'session_id' => 'another-session', 'created_at' => now()]);

        // The request the browser sends to /logout, carrying that session.
        $store = $this->app['session']->driver();
        $store->setId(str_repeat('s', 40));
        $store->start();
        DB::table('imis_sessions')->where('session_id', 'this-session')->update(['session_id' => $store->getId()]);

        $request = Request::create('/logout', 'POST');
        $request->setLaravelSession($store);
        $request->setUserResolver(fn () => $user);

        $response = $this->app->make(SessionController::class)->destroy($request);

        $this->assertSame(route('login'), $response->getTargetUrl());
        $this->assertDatabaseMissing('imis_sessions', ['sid_hash' => hash('sha256', $this->sid)]);
        $this->assertDatabaseHas('imis_sessions', ['session_id' => 'another-session']); // other people's links stay
    }

    public function test_old_links_are_cleared_at_the_next_sign_in(): void
    {
        DB::table('imis_sessions')->insert(['sid_hash' => hash('sha256', 'stale'), 'session_id' => 'gone', 'created_at' => now()->subDays(3)]);

        $this->signIn($this->sid);

        $this->assertDatabaseMissing('imis_sessions', ['session_id' => 'gone']);
        $this->assertSame(1, DB::table('imis_sessions')->count());
    }

    public function test_the_endpoint_needs_no_csrf_token_and_no_sign_in(): void
    {
        $route = $this->app['router']->getRoutes()->getByName('sso.logout');

        $this->assertContains(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, $route->excludedMiddleware());
        $this->assertNotContains('auth', $route->gatherMiddleware());
    }
}
