<?php

namespace App\Services;

use App\Models\FieldOffice;
use App\Models\ReferenceValue;
use Illuminate\Support\Collection;

/** The dropdown lists of the appointment form (the CSC's own wording, from the MOA template's RefData). */
class LookupService
{
    /** Types sent to the form up front. Positions (~2,000) are searched on demand instead. */
    private const LISTS = [
        'sector', 'agency', 'employment_status', 'nature_of_appointment',
        'eligibility_type', 'publication_mode',
    ];

    /** @return array<string, list<string>> */
    public function formOptions(): array
    {
        $values = ReferenceValue::whereIn('type', self::LISTS)->orderBy('value')->get()->groupBy('type');

        $options = [];
        foreach (self::LISTS as $type) {
            $options[$type] = $values->get($type, collect())->pluck('value')->values()->all();
        }

        // Agencies are suggested by the jurisdiction of the form's Field Office.
        $options['agency_by_office'] = ReferenceValue::where('type', 'agency')->whereNotNull('field_office_id')->orderBy('value')->get()
            ->groupBy('field_office_id')->map(fn ($g) => $g->pluck('value')->values()->all())->all();

        // The eligibility that asks for an SK position, and the positions to choose from.
        $options['skoe_eligibility'] = config('psed.skoe.eligibility');
        $options['skoe_position'] = config('psed.skoe.positions');

        $options['level'] = ['1st', '2nd', '3rd'];
        $options['csc_action'] = ['Approved', 'Disapproved', 'Invalidated', 'Pending', 'Validated'];

        return $options;
    }

    /** @return list<string> The agencies within a Field Office's jurisdiction (all of them when no office is given). */
    public function agenciesFor(?int $officeId): array
    {
        return ReferenceValue::where('type', 'agency')
            ->when($officeId, fn ($q) => $q->where('field_office_id', $officeId))
            ->orderBy('value')->pluck('value')->all();
    }

    /** @return list<string> */
    public function searchPositions(string $term, int $limit = 15): array
    {
        $term = trim($term);

        return ReferenceValue::where('type', 'position')
            ->when($term !== '', fn ($q) => $q->where('value', 'like', '%'.$term.'%'))
            ->orderByRaw('value like ? desc', [$term.'%'])
            ->orderBy('value')
            ->limit($limit)
            ->pluck('value')
            ->all();
    }

    /** @return Collection<int, array{id: int, name: string, head_type: string}> */
    public function offices(): Collection
    {
        return FieldOffice::orderBy('name')->get()->map(fn (FieldOffice $o) => [
            'id' => $o->id,
            'name' => $o->name,
            'head_type' => $o->head_type->value,
            'head_label' => $o->head_type->label(),
            'psed_from_sg' => $o->head_type->psedThreshold(),
        ]);
    }
}
