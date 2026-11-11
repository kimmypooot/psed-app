<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2A338F">

    <title inertia>{{ config('app.name', 'CARES') }}</title>
    <meta name="description" content="Centralized Appointment Attestation and Reporting via Electronic System of the Civil Service Commission Regional Office VIII.">

    <link rel="icon" href="/favicon.ico" sizes="any">

    {{-- The boot screens' styles (resources/css/boot.css, which app.css also
         imports for the Vue splash) are inlined, as in IMIS, so a screen paints
         at once instead of waiting for app.css. The @layer line is Tailwind's own
         order, set first so the inlined rules sit in the same layers. Comments
         and whitespace are stripped. --}}
    <style>@layer theme, base, components, utilities; {!! preg_replace(['!/\*.*?\*/!s', '/\s+/'], ['', ' '], file_get_contents(resource_path('css/boot.css'))) !!}</style>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-surface-muted font-sans text-text antialiased">
    @if (($page['component'] ?? null) === 'Auth/ImisSso')
        {{-- Arriving from IMIS: IMIS has just shown its light "Opening <system>"
             splash, so the same card is drawn here from the first paint and
             the page (ImisSso.vue, through TransitionSplash) takes over without
             a flash. app.js removes this at once (data-instant). --}}
        <div id="boot-preloader" data-instant class="imis-tr" role="status" aria-live="polite" aria-busy="true">
            <div class="imis-tr__card">
                <div class="imis-tr__rings" aria-hidden="true">
                    <svg class="imis-tr__ring-outer" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="10" class="imis-tr__track" stroke-width="2.5" />
                        <circle cx="12" cy="12" r="10" class="imis-tr__arc--navy" stroke-width="2.5" stroke-linecap="round" stroke-dasharray="62.832" stroke-dashoffset="20" />
                    </svg>
                    <svg class="imis-tr__ring-inner" viewBox="0 0 24 24" fill="none">
                        <circle cx="12" cy="12" r="8" class="imis-tr__track" stroke-width="1.5" />
                        <circle cx="12" cy="12" r="8" class="imis-tr__arc--red" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="50.265" stroke-dashoffset="15" />
                    </svg>
                    <img src="/images/brand/CSC-IMIS.png" class="imis-tr__logo" alt="" draggable="false">
                </div>
                <div class="imis-tr__msg"><span class="imis-tr__lead">Opening</span> Centralized Appointment Attestation and Reporting via Electronic System</div>
                <div class="imis-tr__sub">Signing you in from IMIS</div>
                <div class="imis-tr__dots" aria-hidden="true"><span class="is-active"></span><span></span><span></span></div>
            </div>
        </div>
    @else
        {{-- Boot preloader: the IMIS loading screen. Removed by app.js once
             the first page renders. --}}
        <div id="boot-preloader" class="imis-preloader" role="status" aria-live="polite" aria-busy="true" aria-label="CARES is loading. Please wait.">
            <div class="imis-preloader__logo-wrap" aria-hidden="true">
                <img src="/images/brand/CSC-IMIS.png" class="imis-preloader__logo" alt="" draggable="false">
            </div>
            <div aria-hidden="true">
                <span class="imis-preloader__agency-main">Civil Service Commission</span>
                <span class="imis-preloader__agency-sub">Regional Office VIII</span>
                <span class="imis-preloader__product">Centralized Appointment Attestation and Reporting via Electronic System</span>
            </div>
            <div class="imis-preloader__divider" aria-hidden="true"></div>
            <p class="imis-preloader__label" aria-hidden="true">
                <span>Loading</span>
                <span class="imis-preloader__dots"><span></span><span></span><span></span></span>
            </p>
            <div class="imis-preloader__bar-wrap" aria-hidden="true">
                <div class="imis-preloader__bar"></div>
            </div>
        </div>
        {{-- When the screen started, for app.js to hold it long enough to read
             as deliberate (and to skip it if the page was ready first). --}}
        <script>window.__bootAt = performance.now();</script>
    @endif
    @inertia
</body>
</html>
