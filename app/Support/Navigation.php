<?php

namespace App\Support;

use App\Models\User;

/** The sidebar for a signed-in user (shape: see SidebarNav.vue). */
class Navigation
{
    /** @return list<array<string, mixed>> */
    public static function for(User $user): array
    {
        $items = [
            ['label' => 'Dashboard', 'href' => '/dashboard', 'icon' => 'home'],
            ['heading' => 'Appointments'],
            // The MOA's two sheets, the same appointments one kind at a time. `fixed`: always shown open, nothing to toggle.
            ['group' => 'Appointments', 'icon' => 'clipboard', 'fixed' => true, 'items' => [
                ['label' => 'Plantilla', 'href' => '/appointments/plantilla'],
                ['label' => 'Casual / Contractual', 'href' => '/appointments/casual-contractual'],
            ]],
            ['label' => 'Encode Appointment', 'href' => '/appointments/create', 'icon' => 'plus'],
            ['heading' => 'Reports'],
            ['label' => 'Generate Reports', 'href' => '/reports', 'icon' => 'documentText'],
            $user->seesAllOffices()
                ? ['label' => 'Report Submissions', 'href' => '/reports/submissions', 'icon' => 'archive']
                : ['label' => 'Submit Reports', 'href' => '/reports/submit', 'icon' => 'upload'],
            ...($user->isAdmin() ? [['label' => 'Summary Database', 'href' => '/reports/summary-database', 'icon' => 'database']] : []),
            ['heading' => 'Guide'],
            ['label' => 'Qualification Standards', 'href' => '/qualification-standards', 'icon' => 'archive'],
        ];

        if ($user->isAdmin()) {
            $items[] = ['heading' => 'Statistics'];
            $items[] = ['label' => 'PWD, IP and Solo Parents', 'href' => '/statistics/special-groups', 'icon' => 'chartBar'];
            $items[] = ['heading' => 'Settings'];
            $items[] = ['label' => 'Field Offices', 'href' => '/settings/field-offices', 'icon' => 'building'];
            $items[] = ['label' => 'Agencies', 'href' => '/settings/agencies', 'icon' => 'archive'];
            $items[] = ['label' => 'Opening Balances', 'href' => '/settings/opening-balances', 'icon' => 'chartBar'];
            $items[] = ['label' => 'Report Deadlines', 'href' => '/settings/report-deadlines', 'icon' => 'calendar'];
            $items[] = ['label' => 'Positions', 'href' => '/settings/positions', 'icon' => 'clipboard'];
            $items[] = ['label' => 'Audit Trail', 'href' => '/audit-trail', 'icon' => 'activity'];
        }

        return $items;
    }
}
