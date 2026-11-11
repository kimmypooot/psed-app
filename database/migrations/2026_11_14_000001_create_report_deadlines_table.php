<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a Field Office's monthly report is due. A row with no year and month is the report's usual rule
     * (a day of the following month); a row with a year and month is one month's own date, set when the
     * usual one does not hold (a holiday, an extension).
     */
    public function up(): void
    {
        Schema::create('report_deadlines', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedSmallInteger('year')->nullable();
            $table->unsignedTinyInteger('month')->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();   // the usual rule: this day of the following month
            $table->date('due_date')->nullable();                 // one month's own date
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['type', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_deadlines');
    }
};
