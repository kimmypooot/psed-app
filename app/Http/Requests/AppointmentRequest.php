<?php

namespace App\Http\Requests;

use App\Support\AppointmentRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppointmentRequest extends FormRequest
{
    /** The columns that record the CSC's decision, handled by whoever the flow chart routes the appointment to. */
    public const ACTION_FIELDS = ['csc_action', 'action_officer', 'action_officer_imis_id', 'action_date', 'release_date', 'pending_reason', 'disapproval_reason'];

    public function authorize(): bool
    {
        return true; // the controller authorizes through AppointmentPolicy
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'field_office_id' => [$this->user()->seesAllOffices() ? 'required' : 'nullable', 'integer', 'exists:field_offices,id'],
            // An IMIS employee's id (or 'keep' for what is saved); the name is looked up, never taken from the browser.
            // Who received it: likewise an IMIS employee's id (or 'keep').
            'received_by_imis_id' => ['nullable', 'regex:/^(keep|\d+)$/'],
            'action_officer_imis_id' => ['nullable', 'regex:/^(keep|\d+)$/'],
            ...AppointmentRules::fields(),
            // On the form the position is required once the SKOE is chosen (an imported file may leave it for later).
            'skoe_position' => ['nullable', Rule::in(config('psed.skoe.positions')), Rule::requiredIf(fn () => $this->input('eligibility_type') === config('psed.skoe.eligibility'))],
        ];
    }

    public function messages(): array
    {
        return AppointmentRules::messages();
    }
}
