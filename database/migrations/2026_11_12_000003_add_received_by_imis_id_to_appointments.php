<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Like the action officer, who received the appointment is an IMIS employee; the name stays on the row. */
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedBigInteger('received_by_imis_id')->nullable()->after('received_by')->index();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['received_by_imis_id']);
            $table->dropColumn('received_by_imis_id');
        });
    }
};
