<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "qualification_standard" (22 characters) does not fit the 20 the column
     * was created with. MySQL refuses the row; SQLite, which the tests use,
     * does not check lengths, so this was only visible on MySQL.
     */
    public function up(): void
    {
        Schema::table('reference_documents', function (Blueprint $table) {
            $table->string('type', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('reference_documents', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }
};
