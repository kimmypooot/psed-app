<?php

namespace App\Http\Middleware;

use App\Support\Navigation;
use App\Support\Security\SessionLimits;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    ...$user->only('id', 'name', 'username', 'email', 'position', 'photo_url'),
                    'role' => $user->role->value,
                    'role_label' => $user->role->label(),
                    'office' => $user->fieldOffice?->name,
                    'sees_all_offices' => $user->seesAllOffices(),
                ] : null,
                // For hiding controls only (useAuthorization); every route
                // checks its policy regardless.
                'permissions' => [],
            ],
            // Drives the idle/absolute timeout warning.
            'sessionLimits' => fn () => $user ? app(SessionLimits::class)->forClient($request->session()) : null,
            'app' => ['version' => config('psed.version')],
            'navigation' => $user ? Navigation::for($user) : [],
            'imisUrl' => config('services.imis.portal_url'),
            'flash' => [
                'error' => fn () => $request->session()->get('error'),
                'status' => fn () => $request->session()->get('status'),
                'agency_sync' => fn () => $request->session()->get('agency_sync'),
            ],
        ];
    }
}
