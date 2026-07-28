<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Šifrování citlivých dat v databázi.
 *
 * - organizations.data_key = datový klíč organizace (zašifrovaný hlavním klíčem z .env)
 * - citlivé sloupce se rozšiřují na TEXT (ciphertext je delší než plaintext)
 * - *_index sloupce = slepé indexy (HMAC) pro vyhledávání a přihlášení
 *
 * Pozor: původní indexy nad šifrovanými sloupci se musí zrušit — nad TEXT
 * nelze indexovat bez délky a hledat se stejně bude přes slepé indexy.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1a) Composite indexy začínající organization_id podpírají cizí klíč —
        //     než je zahodíme, musí FK dostat vlastní index, jinak MariaDB
        //     odmítne DROP (chyba 1553).
        Schema::table('clients', fn (Blueprint $table) => $table->index('organization_id', 'clients_org_idx'));
        Schema::table('invitations', fn (Blueprint $table) => $table->index('organization_id', 'invitations_org_idx'));

        // 1b) Zrušit indexy nad sloupci, které se budou šifrovat
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['email']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropIndex(['ico']));
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'name']);
            $table->dropIndex(['organization_id', 'ico']);
        });
        Schema::table('invitations', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'email']));

        // 2) Datový klíč organizace (zašifrovaný hlavním klíčem z .env)
        Schema::table('organizations', function (Blueprint $table) {
            $table->text('data_key')->nullable()->after('id');

            foreach (['name', 'ico', 'dic', 'street', 'city', 'zip', 'email', 'phone', 'website', 'registration_note'] as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        // 3) Rozšíření sloupců na TEXT + slepé indexy
        Schema::table('users', function (Blueprint $table) {
            $table->text('name')->change();
            $table->text('email')->change();
            $table->string('email_index', 64)->nullable()->after('email');
        });

        Schema::table('clients', function (Blueprint $table) {
            foreach (['name', 'ico', 'dic', 'street', 'city', 'zip', 'email', 'phone'] as $column) {
                $table->text($column)->nullable()->change();
            }
            $table->string('ico_index', 64)->nullable()->after('ico');
            $table->string('email_index', 64)->nullable()->after('email');
            $table->string('name_index', 64)->nullable()->after('name');
        });

        Schema::table('invoice_items', fn (Blueprint $table) => $table->text('description')->change());

        Schema::table('bank_accounts', function (Blueprint $table) {
            foreach (['name', 'account_prefix', 'account_number', 'iban'] as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        Schema::table('bank_transactions', function (Blueprint $table) {
            foreach (['counterparty_account', 'counterparty_name', 'message'] as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        Schema::table('email_log', function (Blueprint $table) {
            $table->text('to')->change();
            $table->text('subject')->change();
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->text('email')->change();
            $table->string('email_index', 64)->nullable()->after('email');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->text('ip')->nullable()->change();
            $table->text('user_agent')->nullable()->change();
        });

        // 4) Nové indexy nad slepými indexy
        Schema::table('users', fn (Blueprint $table) => $table->unique('email_index'));
        Schema::table('clients', function (Blueprint $table) {
            $table->index(['organization_id', 'ico_index']);
            $table->index(['organization_id', 'name_index']);
            $table->index(['organization_id', 'email_index']);
        });
        Schema::table('invitations', fn (Blueprint $table) => $table->index(['organization_id', 'email_index']));
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email_index']);
            $table->dropColumn('email_index');
            $table->string('email')->change();
            $table->string('name')->change();
            $table->unique('email');
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'ico_index']);
            $table->dropIndex(['organization_id', 'name_index']);
            $table->dropIndex(['organization_id', 'email_index']);
            $table->dropColumn(['ico_index', 'name_index', 'email_index']);
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'email_index']);
            $table->dropColumn('email_index');
        });

        Schema::table('clients', fn (Blueprint $table) => $table->dropIndex('clients_org_idx'));
        Schema::table('invitations', fn (Blueprint $table) => $table->dropIndex('invitations_org_idx'));

        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('data_key'));
    }
};
