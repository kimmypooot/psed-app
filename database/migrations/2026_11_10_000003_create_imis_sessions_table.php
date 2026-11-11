<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single sign-out (IMIS docs/sso-logout.md). At sign-in IMIS gives a secret
     * `sid` that names its own session. We keep only its SHA-256 hash, tied to
     * the CARES session it started, so that when the person signs out of IMIS
     * (IMIS calls POST /sso/logout with the sid) that session can be ended.
     */
    public function up(): void
    {
        Schema::create('imis_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('sid_hash', 64)->unique();
            $table->string('session_id')->index();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imis_sessions');
    }
};
