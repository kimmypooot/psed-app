<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The consolidated (all offices) monthly reports count by action date with no office to lead the
     * other indexes; without this they scanned every appointment for each of their ~50 counts.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(['action_date', 'csc_action'], 'appointments_action_date_action_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_action_date_action_idx');
        });
    }
};
