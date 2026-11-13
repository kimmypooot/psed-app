<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Browser-side hardening on every response (SecurityHeaders), and the strict policy that production adds. */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function page(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::factory()->admin()->create())->get('/dashboard');
    }

    public function test_every_page_is_protected_against_framing_sniffing_and_caching(): void
    {
        $response = $this->page()->assertOk();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Opener-Policy'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), 'appointee data is not kept by the browser or a proxy');
    }

    public function test_the_error_pages_carry_them_too(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())->get('/appointments/999999/edit')->assertNotFound();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
    }

    public function test_development_leaves_out_the_policy_and_hsts_that_would_block_the_dev_server(): void
    {
        $response = $this->page();

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_production_adds_https_only_and_a_strict_script_policy_tied_to_the_pages_own_nonce(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $response = $this->page()->assertOk();

        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Strict-Transport-Security'));

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringNotContainsString("'unsafe-inline'", explode('style-src', $policy)[0], 'no inline scripts, except by nonce');

        // The one inline script on the page carries the nonce the policy allows, and no other does.
        preg_match("/script-src 'self' 'nonce-([^']+)'/", $policy, $allowed);
        $this->assertNotEmpty($allowed, 'the policy names a nonce');
        $this->assertStringContainsString('nonce="'.$allowed[1].'"', $response->getContent());
    }

    public function test_each_request_gets_its_own_nonce(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $user = User::factory()->admin()->create();

        $first = $this->actingAs($user)->get('/dashboard')->headers->get('Content-Security-Policy');
        $second = $this->actingAs($user)->get('/dashboard')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }
}
