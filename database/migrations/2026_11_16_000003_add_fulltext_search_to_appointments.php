<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The appointment search box matches whole words by their start ("garc" finds Garcia) through
     * a FULLTEXT index instead of scanning every row with LIKE '%...%'. MySQL / MariaDB only: other
     * databases (the SQLite used by the tests) keep the LIKE search.
     */
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->fullText(['last_name', 'first_name', 'middle_name', 'agency', 'position'], 'appointments_search_ft');
        });
    }

    public function down(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropFullText('appointments_search_ft');
        });
    }
};
