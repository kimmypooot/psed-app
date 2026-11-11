<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** An agency belongs to the jurisdiction of one Field Office. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_values', function (Blueprint $table) {
            $table->foreignId('field_office_id')->nullable()->after('value')->constrained('field_offices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reference_values', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_office_id');
        });
    }
};
