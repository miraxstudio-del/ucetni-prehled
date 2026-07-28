<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ico', 8)->nullable();
            $table->string('dic', 12)->nullable();
            $table->boolean('vat_payer')->default(false);
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('country', 2)->default('CZ');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            // spisová značka / „Fyzická osoba zapsaná v…" — povinný údaj na dokladech
            $table->string('registration_note')->nullable();
            $table->string('logo_path')->nullable();
            $table->string('stamp_path')->nullable();
            $table->unsignedSmallInteger('default_due_days')->default(14);
            $table->text('invoice_footer')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('ico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
