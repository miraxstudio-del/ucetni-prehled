<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sloupce audit_logs.meta a bank_transactions.raw byly typu JSON. MariaDB pro
 * JSON přidává kontrolu json_valid() — a zašifrovaná hodnota pochopitelně
 * platný JSON není, takže zápis skončil chybou 4025. Převádíme je na TEXT;
 * strukturu si hlídá aplikace (šifrovaný JSON, viz EncryptsAttributes).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Týká se jen MySQL/MariaDB — SQLite (testy) JSON stejně ukládá jako
        // TEXT a syntaxi MODIFY nezná.
        if (! $this->needsConversion()) {
            return;
        }

        // change() by kontrolu json_valid() nezrušil, proto měníme typ přímo.
        DB::statement('ALTER TABLE `audit_logs` MODIFY `meta` TEXT NULL');
        DB::statement('ALTER TABLE `bank_transactions` MODIFY `raw` TEXT NULL');
    }

    public function down(): void
    {
        if (! $this->needsConversion()) {
            return;
        }

        DB::statement('ALTER TABLE `audit_logs` MODIFY `meta` JSON NULL');
        DB::statement('ALTER TABLE `bank_transactions` MODIFY `raw` JSON NULL');
    }

    private function needsConversion(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            && Schema::hasTable('audit_logs')
            && Schema::hasTable('bank_transactions');
    }
};
