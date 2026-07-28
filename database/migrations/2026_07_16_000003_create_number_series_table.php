<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 20); // invoice | proforma | credit_note
            $table->string('name');
            // formát s tokeny: {YYYY} rok, {YY} zkrácený rok, {NNNN} pořadí s paddingem dle počtu N
            $table->string('format', 40)->default('{YYYY}-{NNNN}');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['organization_id', 'document_type', 'name', 'year'], 'number_series_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_series');
    }
};
