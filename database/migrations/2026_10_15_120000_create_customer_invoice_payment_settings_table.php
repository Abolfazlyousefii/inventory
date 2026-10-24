<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_invoice_payment_settings', function (Blueprint $table): void {
            $table->id();
            // Sensitive bank and identity fields are encrypted using the model's encrypted cast.
            $table->text('name')->nullable();
            $table->text('national_id')->nullable();
            $table->text('iban')->nullable();
            $table->text('account')->nullable();
            $table->text('card')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_invoice_payment_settings');
    }
};
