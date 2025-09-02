<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued')->index();
            $table->string('invoice', 60)->index();

            $table->enum('transaction_type', ['Sales', 'Purchase', 'Order', 'Adjustment', 'Reversal']);
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('restrict');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');

            $table->enum('status', ['Cancelled', 'On Delivery', 'Confirmed', 'Void'])->default('Confirmed')->index(); // 1: active, 0: cancelled
            $table->string('serial_number', 255)->nullable();
            $table->enum('payment_method', ['Cash/Bank Transfer', 'Credit', 'Unpaid'])->default('Unpaid');
            $table->timestamps();
            // Optional index gabungan
            $table->index(['warehouse_id', 'date_issued']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
