<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side hardening for every response: no framing (clickjacking), no MIME
 * sniffing, no referrer leaks, no powerful browser features, HTTPS only in
 * production, and pages that hold personal data are never stored by the browser
 * or a shared proxy cache.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Vite tags and our one inline script carry this per-request nonce, so CSP can ban every other inline script.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        // Appointee data must not linger in a browser or proxy cache (back button after sign-out, shared PCs).
        if (! $headers->has('Cache-Control') || str_contains((string) $headers->get('Cache-Control'), 'no-cache')) {
            $headers->set('Cache-Control', 'no-store, private');
        }

        // A dev server (Vite) serves scripts and websockets from another origin, so CSP and HSTS are production-only.
        if (app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
            $headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",   // IMIS profile photos
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        return $response;
    }
}
