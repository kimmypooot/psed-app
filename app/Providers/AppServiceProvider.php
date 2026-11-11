<?php

namespace App\Providers;

use App\Enums\Processor;
use App\Models\FieldOffice;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Which Field Office is headed by whom, and who signs the reports:
        // the settings that drive routing are for PSED administrators only.
        Gate::define('manage-offices', fn (User $user) => $user->isAdmin());

        // The PWD / indigenous / solo parent counts cover every Field Office: PSED administrators only.
        Gate::define('view-special-group-report', fn (User $user) => $user->isAdmin());

        // The audit trail shows who changed what, personal data included: PSED administrators only.
        Gate::define('view-audit-trail', fn (User $user) => $user->isAdmin());

        // The Qualification Standards page (standards, issuances, memorandums, policies) is kept by PSED:
        // staff and administrators add and change it, Field Offices only read it.
        Gate::define('manage-references', fn (User $user) => $user->seesAllOffices());

        // Field Offices submit their monthly reports to PSED, which consolidates them: only a Field Office
        // employee submits (for their own office), only PSED reviews.
        Gate::define('submit-reports', fn (User $user) => ! $user->seesAllOffices() && $user->field_office_id !== null);
        Gate::define('review-reports', fn (User $user) => $user->seesAllOffices());

        // Recording the CSC's decision (approve, disapprove, ...) belongs to
        // whoever the flow chart routes the appointment to: PSED for
        // appointments at or above the office's SG threshold, the Field
        // Office for the rest. PSED staff can always act, as they consolidate.
        Gate::define('record-action', function (User $user, FieldOffice $office, Processor $processor) {
            if ($user->seesAllOffices()) {
                return true;
            }

            return $processor === Processor::FieldOffice && $user->field_office_id === $office->id;
        });
    }
}
