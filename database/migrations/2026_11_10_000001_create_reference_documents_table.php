<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The reference page: the issuances, memorandums and policies that guide
     * the processing of appointments. (Not called "references", which is a
     * reserved word in MySQL.)
     */
    public function up(): void
    {
        Schema::create('reference_documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('type', 20);                 // qualification_standard | issuance | memorandum | policy | other
            $table->string('reference_no')->nullable(); // e.g. "CSC MC No. 8, s. 2025"
            $table->date('issued_on')->nullable();
            $table->text('summary')->nullable();        // what it covers / when it applies
            $table->string('url', 2048)->nullable();    // an external link, instead of or besides a file

            // The uploaded document, on the private disk and served only to signed-in staff.
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();    // the name it was uploaded with
            $table->string('file_mime', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['type', 'issued_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_documents');
    }
};
