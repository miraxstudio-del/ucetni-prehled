<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 10)->default('issued');   // issued | received
            $table->string('type', 15)->default('invoice');       // invoice | proforma | credit_note
            $table->string('status', 15)->default('draft');       // draft | issued | sent | paid | cancelled
            $table->foreignId('number_series_id')->nullable()->constrained('number_series')->nullOnDelete();
            $table->string('number', 30)->nullable();              // přiděluje se při vystavení
            $table->string('variable_symbol', 10)->nullable();
            $table->date('issue_date');
            $table->date('duzp')->nullable();                      // datum uskutečnění zdanitelného plnění
            $table->date('due_date');
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('CZK');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('note')->nullable();                      // text na faktuře
            $table->foreignId('corrected_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'number']);
            $table->index(['organization_id', 'direction', 'status']);
            $table->index(['organization_id', 'due_date']);
            $table->index(['organization_id', 'variable_symbol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
