<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            // Nepovinné datum, kdy skončila vedlejší činnost (např. konec
            // rodičovské) a od dalšího dne se stala hlavní. Prázdné = celý
            // rok se řídí jen příznakem secondary_activity jako dřív.
            $table->date('secondary_activity_until')->nullable()->after('secondary_activity');
        });
    }

    public function down(): void
    {
        Schema::table('tax_profiles', function (Blueprint $table) {
            $table->dropColumn('secondary_activity_until');
        });
    }
};
