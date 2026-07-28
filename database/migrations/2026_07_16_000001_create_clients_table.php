<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('ico', 8)->nullable();
            $table->string('dic', 12)->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('country', 2)->default('CZ');
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('due_days')->nullable(); // přepis výchozí splatnosti organizace
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'name']);
            $table->index(['organization_id', 'ico']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
