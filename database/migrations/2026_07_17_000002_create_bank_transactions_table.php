<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
            // ID pohybu z banky / hash GPC věty — ochrana proti duplicitnímu importu
            $table->string('external_id', 64);
            $table->date('booked_on');
            $table->decimal('amount', 12, 2);          // záporná = výdaj
            $table->string('currency', 3)->default('CZK');
            $table->string('counterparty_account', 40)->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('variable_symbol', 10)->nullable();
            $table->string('constant_symbol', 10)->nullable();
            $table->string('specific_symbol', 10)->nullable();
            $table->string('message', 255)->nullable();
            $table->string('import_source', 10);       // fio_api | gpc | manual
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['bank_account_id', 'external_id']);
            $table->index(['organization_id', 'booked_on']);
            $table->index(['organization_id', 'variable_symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
