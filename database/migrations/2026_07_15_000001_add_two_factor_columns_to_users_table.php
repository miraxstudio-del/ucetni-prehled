<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // TOTP secret a záložní kódy jsou šifrované (encrypted cast, AES přes APP_KEY)
            $table->text('totp_secret')->nullable()->after('remember_token');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->text('totp_recovery_codes')->nullable()->after('totp_confirmed_at');
            // ochrana proti opakovanému použití téhož TOTP kódu (verifyKeyNewer)
            $table->unsignedBigInteger('totp_timestamp')->nullable()->after('totp_recovery_codes');
            $table->timestamp('last_login_at')->nullable()->after('totp_timestamp');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'totp_secret',
                'totp_confirmed_at',
                'totp_recovery_codes',
                'totp_timestamp',
                'last_login_at',
            ]);
        });
    }
};
