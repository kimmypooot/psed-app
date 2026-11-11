<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Who gets in is decided by the person's IMIS unit: PSED employees are the
 * administrators, Field and Satellite Office employees work as their own
 * office, everyone else is refused. There are no per-person grants.
 */
class ImisSsoTest extends TestCase
{
    use RefreshDatabase;

    private const VALIDATE_URL = 'http://imis.test/api/v1/sso/validate';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.imis.sso_url' => self::VALIDATE_URL,
            'services.imis.audience' => 'CARES.test',
        ]);
    }

    /**
     * What IMIS answers for a valid token. By default: a PSED employee.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeImis(array $overrides = []): void
    {
        Http::fake([self::VALIDATE_URL => Http::response([
            'success' => true,
            'user' => [
                'id' => 42,
                'username' => 'jamorden',
                'fname' => 'Juan',
                'lname' => 'Amorden',
                'email' => 'juan@example.com',
                'role_code' => null,           // no per-person grant is needed or used
                'office_code' => 'PSED',
                'office_type' => 'regional_support_unit',
                'position' => 'Human Resource Specialist I',
                'photo_url' => 'https://imis.test/photos/abc.jpg',
                ...$overrides,
            ],
        ])]);
    }

    private function fieldOffice(string $imisCode = 'ESFO'): FieldOffice
    {
        return FieldOffice::factory()->create(['imis_code' => $imisCode]);
    }

    public function test_the_sso_page_shows_the_splash_with_the_token(): void
    {
        $this->get('/sso?sso_token=abc123')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/ImisSso')->where('token', 'abc123'));
    }

    public function test_a_psed_employee_is_signed_in_as_an_administrator(): void
    {
        $this->fakeImis();

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $user = User::sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull(session('auth.started_at'), 'The sign-in starts the session limit clock.');
        $this->assertSame(42, $user->imis_user_id);
        $this->assertSame('jamorden', $user->username);
        $this->assertSame('Juan Amorden', $user->name);
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertNull($user->field_office_id);
        $this->assertSame('Human Resource Specialist I', $user->position);
        $this->assertSame('https://imis.test/photos/abc.jpg', $user->photo_url);

        Http::assertSent(fn (Request $request) => $request['token'] === 'abc123' && $request['audience'] === 'CARES.test');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'login']);
    }

    public function test_a_field_office_employee_works_as_their_own_office(): void
    {
        $office = $this->fieldOffice('ESFO');
        $this->fakeImis(['office_code' => 'ESFO', 'office_type' => 'field_office']);

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $user = User::sole();
        $this->assertSame(UserRole::FieldOffice, $user->role);
        $this->assertSame($office->id, $user->field_office_id);
    }

    public function test_a_satellite_office_employee_works_as_their_own_office(): void
    {
        $office = $this->fieldOffice('WLSO');
        $this->fakeImis(['office_code' => 'WLSO', 'office_type' => 'satellite_office']);

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $this->assertSame($office->id, User::sole()->field_office_id);
    }

    public function test_no_grant_is_needed_and_a_stray_role_code_changes_nothing(): void
    {
        $this->fieldOffice('ESFO');

        // Someone in a Field Office is not promoted by a role_code of "admin"...
        $this->fakeImis(['office_code' => 'ESFO', 'office_type' => 'field_office', 'role_code' => 'admin']);
        $this->post('/sso', ['sso_token' => 'abc123']);
        $this->assertSame(UserRole::FieldOffice, User::sole()->role);
    }

    public function test_a_person_who_moves_unit_gets_the_role_of_their_new_unit(): void
    {
        $office = $this->fieldOffice('ESFO');
        $user = User::factory()->fieldOffice($office->id)->create(['imis_user_id' => 42, 'username' => 'jamorden']);

        // Now reported as a PSED employee.
        $this->fakeImis();
        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertNull($user->field_office_id, 'no longer tied to the old office');
        $this->assertSame(1, User::count());
    }

    public function test_employees_of_other_units_are_refused(): void
    {
        $this->fieldOffice('ESFO');
        $this->fakeImis(['office_code' => 'HRMD', 'office_type' => 'regional_support_unit']);

        $this->post('/sso', ['sso_token' => 'abc123'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'CARES is for PSED and Field Office staff'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_someone_with_no_unit_is_refused(): void
    {
        $this->fakeImis(['office_code' => null, 'office_type' => null]);

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_a_field_office_not_set_up_in_cares_is_refused_with_that_reason(): void
    {
        $this->fakeImis(['office_code' => 'NOPE', 'office_type' => 'field_office']);

        $this->post('/sso', ['sso_token' => 'abc123'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'not set up in CARES as a Field Office'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    public function test_the_psed_unit_code_can_be_configured(): void
    {
        config(['psed.imis.psed_unit_code' => 'PSED-X']);
        $this->fakeImis(['office_code' => 'PSED-X']);

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/dashboard');

        $this->assertSame(UserRole::Admin, User::sole()->role);
    }

    public function test_a_token_imis_rejects_is_refused(): void
    {
        Http::fake([self::VALIDATE_URL => Http::response(['success' => false], 422)]);

        $this->post('/sso', ['sso_token' => 'used'])->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_an_unreachable_imis_is_refused(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->post('/sso', ['sso_token' => 'abc123'])
            ->assertRedirect('/login')
            ->assertSessionHas('error', 'IMIS could not be reached. Please try again in a moment.');

        $this->assertGuest();
    }

    public function test_a_username_held_by_another_imis_user_is_refused(): void
    {
        User::factory()->create(['imis_user_id' => 7, 'username' => 'jamorden']);
        $this->fakeImis();

        $this->post('/sso', ['sso_token' => 'abc123'])->assertRedirect('/login')->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_sso_is_off_when_no_imis_url_is_set(): void
    {
        config(['services.imis.sso_url' => null]);

        $this->get('/sso?sso_token=abc123')->assertRedirect('/login');
    }

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');

        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Auth/SignIn'));
    }

    public function test_signing_out_ends_the_session_and_is_audited(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->assertTrue(AuditLog::where('user_id', $user->id)->where('action', 'logout')->exists());
    }
}
