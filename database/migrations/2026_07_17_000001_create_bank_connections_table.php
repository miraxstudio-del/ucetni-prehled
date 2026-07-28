<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // zatím jen 'fio'
            $table->text('api_token');      // šifrováno (encrypted cast, AES přes APP_KEY)
            $table->timestamp('last_sync_at')->nullable();
            $table->string('status', 15)->default('active'); // active | error
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_connections');
    }
};
