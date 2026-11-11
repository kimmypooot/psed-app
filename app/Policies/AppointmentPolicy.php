<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

/**
 * PSED staff and administrators work on every Field Office's appointments;
 * Field Office staff only on their own office's.
 */
class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->seesAllOffices() || $user->field_office_id !== null;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->seesAllOffices() || $user->field_office_id === $appointment->field_office_id;
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment);
    }

    /** A Field Office can only withdraw an entry nobody has acted on yet. */
    public function delete(User $user, Appointment $appointment): bool
    {
        if ($user->seesAllOffices()) {
            return true;
        }

        return $this->view($user, $appointment) && ! $appointment->isActed();
    }
}
