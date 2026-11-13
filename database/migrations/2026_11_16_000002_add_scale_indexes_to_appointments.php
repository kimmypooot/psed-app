<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the queries the dashboard, list and reports actually run, so they
     * stay fast at hundreds of thousands of rows. Every Field Office filter starts with the office.
     */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // Backlog / queue counts: office + who processes + decision.
            $table->index(['field_office_id', 'processed_by', 'csc_action'], 'appointments_office_processor_action_idx');
            // "Acted between" and first-time-entrant counts.
            $table->index(['field_office_id', 'csc_action', 'action_date'], 'appointments_office_action_date_idx');
            // Cross-office (PSED) date range and ordering.
            $table->index(['date_received', 'id'], 'appointments_received_id_idx');
            $table->index(['processed_by', 'csc_action'], 'appointments_processor_action_idx');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_office_processor_action_idx');
            $table->dropIndex('appointments_office_action_date_idx');
            $table->dropIndex('appointments_received_id_idx');
            $table->dropIndex('appointments_processor_action_idx');
        });
    }
};
