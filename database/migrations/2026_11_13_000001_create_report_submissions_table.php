<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Field Office's monthly report as submitted to PSED. Each upload is a row, so a resubmission keeps
     * the earlier file; the newest row of an office, report type and month is the one that counts.
     */
    public function up(): void
    {
        Schema::create('report_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('field_office_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status', 20)->default('submitted');   // submitted, accepted, returned

            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->text('note')->nullable();

            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->useCurrent();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['field_office_id', 'type', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_submissions');
    }
};
