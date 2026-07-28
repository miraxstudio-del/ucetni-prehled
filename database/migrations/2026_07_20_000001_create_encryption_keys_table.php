<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Systémové datové klíče (zatím jen 'global' pro záznamy, které nepatří
 * žádné organizaci — uživatelé, audit log).
 *
 * Proč tabulka a ne odvození z hlavního klíče: aby šlo hlavní klíč vyměnit
 * (rotace) bez přešifrování všech dat. Při rotaci se jen přebalí tyhle
 * zabalené klíče — samotná data zůstanou beze změny.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('encryption_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->text('wrapped_key'); // datový klíč zašifrovaný hlavním klíčem
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('encryption_keys');
    }
};
