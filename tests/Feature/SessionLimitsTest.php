<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Security\SessionLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.lifetime' => 120, 'session.absolute_lifetime' => 480, 'session.idle_warning' => 5]);
    }

    public function test_the_limits_are_shared_with_the_page_as_durations(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('sessionLimits.idle_seconds', 7200)
                ->where('sessionLimits.warning_seconds', 300)
                ->where('sessionLimits.absolute_remaining', fn ($seconds) => $seconds > 28790 && $seconds <= 28800));
    }

    public function test_a_visitor_has_no_limits(): void
    {
        $this->get('/login')->assertInertia(fn ($page) => $page->where('sessionLimits', null));
    }

    public function test_the_heartbeat_answers_with_the_limits_and_needs_a_sign_in(): void
    {
        $this->postJson('/session/heartbeat')->assertUnauthorized();

        $this->actingAs(User::factory()->create())->postJson('/session/heartbeat')
            ->assertOk()
            ->assertJsonPath('alive', true)
            ->assertJsonPath('limits.idle_seconds', 7200)
            ->assertJsonPath('limits.warning_seconds', 300);
    }

    public function test_a_sign_in_past_the_absolute_limit_is_ended(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['auth.started_at' => now()->subMinutes(481)->getTimestamp()]);

        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('action', 'session.limit_reached')->where('user_id', $user->id)->exists());
    }

    public function test_an_ajax_request_past_the_absolute_limit_gets_a_401_with_the_reason(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['auth.started_at' => now()->subMinutes(481)->getTimestamp()]);

        $this->postJson('/session/heartbeat')->assertUnauthorized()->assertJson(['alive' => false, 'reason' => 'limit']);
        $this->assertGuest();
    }

    public function test_a_sign_in_within_the_limit_is_left_alone(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['auth.started_at' => now()->subMinutes(479)->getTimestamp()]);

        $this->get('/dashboard')->assertOk();
        $this->assertAuthenticated();
    }

    public function test_a_session_without_a_start_begins_counting_instead_of_ending(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();

        $this->assertTrue(app(SessionLimits::class)->started(session()->driver()));
    }

    public function test_the_idle_reason_explains_the_sign_out_on_the_login_page(): void
    {
        $this->get('/login?reason=idle')->assertInertia(fn ($page) => $page->where('flash.status', 'You were signed out after a period of inactivity.'));
        $this->get('/login')->assertInertia(fn ($page) => $page->where('flash.status', null));
    }
}
