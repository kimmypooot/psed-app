<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_offices', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // as printed in the reports: "Field Office-Eastern Samar"
            // The IMIS organizational unit code (ESFO, SFO, LFO-I ...). One code, shared with IMIS: it
            // scopes Field Office staff at sign-in and is the code printed on the reports.
            $table->string('imis_code', 20)->unique();
            $table->string('location')->nullable();    // e.g. "Borongan City" (MRAP header)
            // director_ii | oic_sg22 | none  -- drives appointment routing (flow chart, step 4)
            $table->string('head_type', 20)->default('none');

            // Signatories printed at the bottom of the reports.
            $table->string('prepared_by_name')->nullable();
            $table->string('prepared_by_title')->nullable();
            $table->string('approved_by_name')->nullable();
            $table->string('approved_by_title')->nullable();

            $table->timestamps();
        });

        // Field Office staff are scoped to one office; PSED staff have none.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('field_office_id')->nullable()->after('role')
                ->constrained('field_offices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_office_id');
        });
        Schema::dropIfExists('field_offices');
    }
};
