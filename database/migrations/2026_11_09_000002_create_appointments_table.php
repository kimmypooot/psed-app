<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_office_id')->constrained()->cascadeOnDelete();

            // --- Receipt -------------------------------------------------
            $table->string('agency');
            $table->string('sector')->nullable();
            $table->date('date_received');
            $table->time('time_received')->nullable();
            $table->string('received_by')->nullable();
            $table->date('date_issued')->nullable();      // date of issuance
            $table->date('date_effective')->nullable();   // effectivity / assumption

            // --- Appointee -----------------------------------------------
            $table->string('last_name');
            $table->string('first_name');
            $table->string('name_extension', 20)->nullable();
            $table->string('middle_name')->nullable();
            $table->char('sex', 1);                        // M | F

            // --- Position ------------------------------------------------
            $table->string('position');
            $table->unsignedTinyInteger('salary_grade');
            $table->string('level', 10)->nullable();       // 1st | 2nd | 3rd
            $table->string('employment_status');
            $table->string('nature_of_appointment');

            // --- Publication (Plantilla only) ----------------------------
            $table->date('publication_start')->nullable();
            $table->date('publication_end')->nullable();
            $table->string('publication_mode')->nullable();

            // --- Eligibility ---------------------------------------------
            $table->string('eligibility_type')->nullable();
            // "Used for the first time?" -> the first-time entrant flag that
            // feeds the Absorption Report and IPMR 5.1 / 5.2.
            $table->boolean('is_first_time_entrant')->default(false);
            $table->string('eligibility_exam_date')->nullable();   // may be a range
            $table->string('eligibility_exam_place')->nullable();
            $table->date('eligibility_issued_date')->nullable();
            $table->string('eligibility_issued_place')->nullable();
            $table->date('eligibility_validity')->nullable();

            // --- Other information ---------------------------------------
            $table->boolean('is_pwd')->default(false);
            $table->boolean('is_indigenous')->default(false);
            $table->boolean('is_solo_parent')->default(false);

            // --- CSC action ----------------------------------------------
            $table->string('csc_action', 20)->nullable();  // Approved|Disapproved|Invalidated|Pending|Validated
            $table->string('action_officer')->nullable();
            $table->date('action_date')->nullable();
            $table->date('release_date')->nullable();
            $table->text('pending_reason')->nullable();
            $table->text('disapproval_reason')->nullable();

            // --- Routing (computed by AppointmentRouter) -----------------
            $table->string('processed_by', 20)->default('field_office'); // field_office | psed

            $table->timestamps();

            $table->index(['field_office_id', 'date_received']);
            $table->index(['field_office_id', 'action_date']);
            $table->index('salary_grade');
            $table->index('agency');
            $table->index(['last_name', 'first_name']);
        });

        Schema::create('reference_values', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);     // sector, agency, position, eligibility_type, ...
            $table->string('value');
            $table->timestamps();

            $table->unique(['type', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_values');
        Schema::dropIfExists('appointments');
    }
};
