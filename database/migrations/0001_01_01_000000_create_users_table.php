<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff are managed only in IMIS: a row here is a stub linked by
     * `imis_user_id`, created and refreshed at each sign-in from IMIS. There
     * are no passwords; nobody signs in to this system any other way.
     * (`field_office_id` is added with the field_offices table.)
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('imis_user_id')->unique();
            $table->string('username')->unique();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('position')->nullable();
            $table->string('role');
            $table->string('photo_url')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
