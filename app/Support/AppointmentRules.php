<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The rules an appointment must satisfy, whether it is encoded on the form
 * (AppointmentRequest) or read from an imported file (AppointmentImporter), so
 * the two can never disagree about what a valid appointment is.
 */
final class AppointmentRules
{
    /** @return array<string, list<mixed>> every encoded field; the Field Office is handled by each caller */
    public static function fields(): array
    {
        $final = implode(',', config('psed.final_actions'));
        $rejected = implode(',', config('psed.rejected_actions'));

        return [
            'agency' => ['required', 'string', 'max:255'],
            'sector' => ['nullable', 'string', 'max:255'],
            'date_received' => ['required', 'date'],
            'time_received' => ['nullable', 'date_format:H:i'],
            'received_by' => ['nullable', 'string', 'max:255'],
            'date_issued' => ['nullable', 'date'],
            'date_effective' => ['nullable', 'date'],

            'last_name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'name_extension' => ['nullable', 'string', 'max:20'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'sex' => ['required', Rule::in(['M', 'F'])],

            'position' => ['required', 'string', 'max:255'],
            'salary_grade' => ['required', 'integer', 'between:1,33'],
            'level' => ['nullable', Rule::in(['1st', '2nd', '3rd'])],
            'employment_status' => ['required', 'string', 'max:255'],
            'nature_of_appointment' => ['required', 'string', 'max:255'],

            'publication_start' => ['nullable', 'date'],
            'publication_end' => ['nullable', 'date', 'after_or_equal:publication_start'],
            'publication_mode' => ['nullable', 'string', 'max:255'],

            'eligibility_type' => ['nullable', 'string', 'max:255'],
            // Which SK position the SKOE was granted for. Asked on the form; a file may leave it for later.
            'skoe_position' => ['nullable', Rule::in(config('psed.skoe.positions'))],
            'is_first_time_entrant' => ['boolean'],
            'eligibility_exam_date' => ['nullable', 'string', 'max:60'],
            'eligibility_exam_place' => ['nullable', 'string', 'max:255'],
            'eligibility_issued_date' => ['nullable', 'date'],
            'eligibility_issued_place' => ['nullable', 'string', 'max:255'],
            'eligibility_validity' => ['nullable', 'date'],

            'is_pwd' => ['boolean'],
            'is_indigenous' => ['boolean'],
            'is_solo_parent' => ['boolean'],
            'is_k12_graduate' => ['boolean'],

            'csc_action' => ['nullable', Rule::in(['Approved', 'Disapproved', 'Invalidated', 'Pending', 'Validated'])],
            'action_officer' => ['nullable', 'string', 'max:255'],
            'action_date' => ['nullable', 'date', "required_if:csc_action,{$final}"],
            'release_date' => ['nullable', 'date', 'after_or_equal:action_date'],
            'pending_reason' => ['nullable', 'string', 'max:1000'],
            'disapproval_reason' => ['nullable', 'string', 'max:1000', "required_if:csc_action,{$rejected}"],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'skoe_position.required' => 'Choose whether the appointee was an SK Official, SK Secretary or SK Treasurer.',
            'skoe_position.in' => 'The SK position must be SK Official, SK Secretary or SK Treasurer.',
            'action_date.required_if' => 'Enter the date the appointment was acted upon.',
            'disapproval_reason.required_if' => 'State the reason for the disapproval or invalidation.',
            'publication_end.after_or_equal' => 'The end of the publication cannot be before its start.',
            'release_date.after_or_equal' => 'The release cannot be before the action date.',
        ];
    }
}
