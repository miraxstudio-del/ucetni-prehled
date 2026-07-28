<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nastavení daňového roku pro OSVČ — jeden řádek na organizaci a rok.
 *
 * Zákonné částky (slevy, sazby, rozhodné částky) tu NEJSOU: ty žijí
 * v config/tax.php i se zdrojem. Tady je jen to, co si volí poplatník.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');

            // vedlejší = rodičovská, zaměstnání, důchod… (bez minimálního VZ)
            $table->boolean('secondary_activity')->default(true);
            // stát je plátcem zdravotního pojistného (péče o dítě)
            $table->boolean('state_health_payer')->default(true);

            // null = skutečné výdaje, jinak sazba paušálu v %
            $table->unsignedTinyInteger('pausal_percent')->nullable()->default(60);
            $table->decimal('actual_expenses', 14, 2)->default(0);

            $table->unsignedTinyInteger('children')->default(0);
            $table->boolean('claim_taxpayer_credit')->default(true);
            $table->boolean('claim_children')->default(true);
            $table->boolean('claim_spouse_credit')->default(false);

            $table->decimal('social_advances_paid', 14, 2)->default(0);
            $table->decimal('health_advances_paid', 14, 2)->default(0);

            // Uzavřený rok: příjmy se berou odsud (z podaného přiznání),
            // ne ze součtu faktur v evidenci.
            $table->decimal('income_override', 14, 2)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_profiles');
    }
};
