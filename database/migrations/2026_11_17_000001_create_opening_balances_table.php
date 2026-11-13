<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The balance a Field Office carries into a year: how many appointments were still unacted on
     * 31 December of the year before. PSED, who consolidates the reports, sets it per office; where it
     * is not set the reports work it out from the appointments this system holds.
     */
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_office_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');            // the year it opens; the balance is as of 31 December of year - 1
            $table->unsignedInteger('unacted');
            $table->string('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['field_office_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
