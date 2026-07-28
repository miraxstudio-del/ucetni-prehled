<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Snapshot údajů dodavatele, odběratele a účtu v okamžiku vystavení faktury.
 *
 * Bez něj se PDF/ISDOC skládá ze ŽIVÝCH dat — přejmenování klienta nebo změna
 * adresy organizace zpětně změní i loni vystavené doklady. Vystavená faktura
 * je přitom právní dokument a měnit se nesmí.
 *
 * TEXT, ne JSON: obsah je šifrovaný (jméno a adresa odběratele jsou citlivé
 * stejně jako v tabulce clients) a MariaDB json_valid() CHECK by ciphertext
 * odmítl — stejný důvod jako u audit_logs.meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->text('snapshot')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('snapshot');
        });
    }
};
