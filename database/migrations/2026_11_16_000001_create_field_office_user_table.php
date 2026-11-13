<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The other Field Offices a Field Office employee works as, besides their own
     * (`users.field_office_id`): a Director II who heads more than one office. IMIS
     * says which at each sign-in (`headed_offices`) and the sign-in rewrites this
     * table, so an ended designation stops applying the next time the person opens CARES.
     */
    public function up(): void
    {
        Schema::create('field_office_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('field_office_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'field_office_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_office_user');
    }
};
