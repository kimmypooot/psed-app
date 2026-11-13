<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How PSED paired a CARES agency with a row of the Summary Database workbook when the names did not match by
     * themselves. A null `template_agency` is a decision too: the agency has no row of its own and is counted in
     * its Field Office's row.
     */
    public function up(): void
    {
        Schema::create('summary_agency_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_office_id')->constrained()->cascadeOnDelete();
            $table->string('agency');                         // as CARES spells it
            $table->string('template_agency')->nullable();     // as the workbook spells it
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['field_office_id', 'agency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('summary_agency_aliases');
    }
};
