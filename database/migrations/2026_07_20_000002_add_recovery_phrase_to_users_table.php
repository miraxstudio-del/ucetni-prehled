<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Obnovovací fráze účtu (12 slov).
 *
 * Záchrana pro případ, že uživatel ztratí heslo A ZÁROVEŇ přístup k e-mailu —
 * standardní obnova hesla přes e-mail by pak nefungovala a účet by byl navždy
 * nedostupný.
 *
 * V databázi je jen Argon2id hash — ze samotné databáze frázi odvodit nelze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('recovery_phrase_hash')->nullable()->after('totp_recovery_codes');
            $table->timestamp('recovery_phrase_created_at')->nullable()->after('recovery_phrase_hash');
            $table->timestamp('recovery_phrase_used_at')->nullable()->after('recovery_phrase_created_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'recovery_phrase_hash',
                'recovery_phrase_created_at',
                'recovery_phrase_used_at',
            ]);
        });
    }
};
