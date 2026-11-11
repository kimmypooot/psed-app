<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\FieldOffice;
use Illuminate\Database\Seeder;

/**
 * Sample appointments for Field Office-Eastern Samar (September - October 2026)
 * so the dashboard and all four reports have something to show.
 */
class DemoAppointmentSeeder extends Seeder
{
    public function run(): void
    {
        $office = FieldOffice::where('name', 'Field Office-Eastern Samar')->first();
        if (! $office) {
            return;
        }

        // [last, first, middle, sex, agency, sector, position, SG, level, status, nature, eligibility, firstTime]
        $rows = [
            ['Hermano', 'Dean Ernest Paul', 'Rosales', 'M', 'Department of Education - Schools Division Office of Borongan City', 'National Government Agency', 'School Principal IV', 22, '2nd', 'Permanent', 'Promotion', 'RA 1080 (Teacher)', false],
            ['Ranola', 'Leonardo', 'Niemes', 'M', 'MG Maydolong', 'Local Government Unit', 'Municipal Government Department Head I (Municipal Civil Registrar)', 24, '2nd', 'Permanent', 'Transfer', 'CS Professional', false],
            ['Campo', 'Raymond', 'Sumook', 'M', 'MG Salcedo', 'Local Government Unit', 'Municipal Government Department Head I (Municipal Accountant)', 26, '2nd', 'Permanent', 'Original', 'RA 1080 (Accountant)', false],
            ['Amadar', 'Emil', 'Borja', 'M', 'MG Balangkayan', 'Local Government Unit', 'Municipal Government Department Head I (Municipal Assessor)', 24, '2nd', 'Permanent', 'Original', 'RA 1080 (Real Estate Appraiser)', true],
            ['Comillo', 'Baby Jane', 'Alidon', 'F', 'MG Balangkayan', 'Local Government Unit', 'Administrative Aide I (Utility Worker I)', 1, '1st', 'Permanent', 'Original', null, false],
            ['Glino', 'Jose', 'Boco', 'M', 'MG Balangkayan', 'Local Government Unit', 'Administrative Aide IV (Budgeting Aide)', 4, '1st', 'Permanent', 'Original', 'Sangguniang Member (First Level) Eligibility', true],
            ['Abletes', 'Winny Rica Mae', 'Germones', 'F', 'Department of Education - Schools Division Office of Borongan City', 'National Government Agency', 'Teacher I (Elementary)', 11, '2nd', 'Substitute', 'Reemployment', 'RA 1080 (Teacher)', false],
            ['Caliso', 'Isijane', 'Ogario', 'F', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Administrative Officer II (Administrative Officer I)', 11, '2nd', 'Permanent', 'Original', 'Honor Graduate Eligibility (PD 907)', true],
            ['Go', 'Queenie Ann', 'Pontica', 'F', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Administrative Officer II (Administrative Officer I)', 11, '2nd', 'Permanent', 'Original', 'CS Professional', true],
            ['Patilla', 'Mark Jensen', 'Robiene', 'M', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Administrative Officer II (Administrative Officer I)', 11, '2nd', 'Permanent', 'Original', 'CS Professional', true],
            ['Maglipas', 'Marlon', 'Gagarino', 'M', 'MG Dolores', 'Local Government Unit', 'Administrative Aide IV (Electrician I)', 4, '1st', 'Permanent', 'Reappointment', 'Electrician Eligibility (MC 11)', true],
            ['Dado', 'Eric', 'Odevilas', 'M', 'Eastern Samar State University', 'State University / College', 'Administrative Aide IV (Clerk II)', 4, '1st', 'Permanent', 'Original', 'CS Subprofessional', true],
            ['Bula', 'Irene', 'Evardone', 'F', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Administrative Assistant II (Disbursing Officer II)', 8, '1st', 'Permanent', 'Original', 'CS Subprofessional', true],
            ['Lustre', 'Feda May', 'Flores', 'F', 'PG Eastern Samar', 'Local Government Unit', 'Administrative Aide IV (Clerk II)', 4, '1st', 'Permanent', 'Original', 'CSE-Preference Rating (Professional)', true],
            ['Cabus', 'Ruel', 'Lacbayen', 'M', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Project Development Officer I', 11, '2nd', 'Permanent', 'Original', 'CS Professional', true],
            ['Gaganao', 'Haidee', 'Opriasa', 'F', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Administrative Aide I', 1, '1st', 'Casual', 'Reemployment', null, false],
            ['Balbuena', 'Jesus', 'Boquiron', 'M', 'Department of Education - Schools Division Office of Borongan City', 'National Government Agency', 'Administrative Aide I', 1, '1st', 'Casual', 'Reappointment', null, false],
            ['Abendanio', 'Norwel', 'Campo', 'M', 'Department of Education - Schools Division Office of Eastern Samar', 'National Government Agency', 'Administrative Aide III (Clerk I)', 3, '1st', 'Casual', 'Reappointment', null, false],
            ['Ambal', 'Ma. Elenita', 'Baldono', 'F', 'Eastern Samar State University', 'State University / College', 'Administrative Aide I (Utility Worker I)', 1, '1st', 'Contractual', 'Original', null, false],
            ['Colandog', 'Jeck', 'Pagaran', 'M', 'Eastern Samar State University', 'State University / College', 'Administrative Aide IV (Driver II)', 4, '1st', 'Casual', 'Reappointment', null, false],
            ['Mercado', 'Anna Liza', 'Tan', 'F', 'PG Eastern Samar', 'Local Government Unit', 'Provincial Government Department Head I', 25, '2nd', 'Permanent', 'Promotion', 'CS Professional', false],
            ['Villarin', 'Gerald', 'Cabrera', 'M', 'MG Oras', 'Local Government Unit', 'Municipal Government Department Head II', 27, '2nd', 'Permanent', 'Original', 'RA 1080 (Civil Engineer)', false],
        ];

        $officers = ['Ryan Jose M. Estoque', 'Jason Thaddeus A. Tan'];

        foreach ($rows as $i => [$last, $first, $mid, $sex, $agency, $sector, $position, $sg, $level, $status, $nature, $elig, $firstTime]) {
            // Spread receipts over September and October 2026.
            $received = $i % 3 === 0 ? now()->setDate(2026, 9, 14 + ($i % 10)) : now()->setDate(2026, 10, 1 + ($i % 24));
            $received = $received->startOfDay();

            // Most are acted on in October; the last few stay pending.
            $pending = $i >= count($rows) - 3;
            $disapproved = $i === 1;
            $action = $pending ? 'Pending' : ($disapproved ? 'Disapproved' : ($i % 2 ? 'Validated' : 'Approved'));
            $actionDate = $pending ? null : now()->setDate(2026, 10, 5 + ($i % 20))->startOfDay();

            Appointment::create([
                'field_office_id' => $office->id,
                'agency' => $agency,
                'sector' => $sector,
                'date_received' => $received,
                'time_received' => sprintf('%02d:%02d:00', 9 + ($i % 7), ($i * 7) % 60),
                'received_by' => $officers[$i % 2],
                'date_issued' => $received->copy()->subDays(5),
                'date_effective' => $received->copy()->subDays(5),
                'last_name' => $last,
                'first_name' => $first,
                'name_extension' => null,
                'middle_name' => $mid,
                'sex' => $sex,
                'position' => $position,
                'salary_grade' => $sg,
                'level' => $level,
                'employment_status' => $status,
                'nature_of_appointment' => $nature,
                'publication_start' => $sg > 4 ? $received->copy()->subDays(40) : null,
                'publication_end' => $sg > 4 ? $received->copy()->subDays(30) : null,
                'publication_mode' => $sg > 4 ? 'CSC Bulletin of Vacant Positions' : null,
                'eligibility_type' => $elig,
                'is_first_time_entrant' => $firstTime,
                'eligibility_exam_date' => $elig ? '03/17/2024' : null,
                'eligibility_exam_place' => $elig ? 'Tacloban City, Leyte' : null,
                'eligibility_issued_date' => $elig ? '2024-06-14' : null,
                'eligibility_issued_place' => $elig ? 'Palo, Leyte' : null,
                'is_pwd' => $i === 4,
                'csc_action' => $action,
                'action_officer' => $pending ? null : $officers[$i % 2],
                'action_date' => $actionDate,
                'release_date' => $actionDate?->copy()->addDays(2),
                'pending_reason' => $pending ? 'Lacks required document (certified true copy of eligibility).' : null,
                'disapproval_reason' => $disapproved ? 'Violation of Section 132, Rule XII, CSC MC No. 8, s. 2025.' : null,
            ]);
        }
    }
}
