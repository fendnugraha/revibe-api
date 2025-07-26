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

            $table->foreignId('product_id')->constrained('products')->onDelete('restrict');
            $table->decimal('quantity', 10, 2); // jika memungkinkan pakai pecahan
            $table->decimal('price', 15, 2);
            $table->decimal('cost', 15, 2);

            $table->enum('transaction_type', ['Sales', 'Purchase', 'Order', 'Adjustment', 'Reversal']);
            $table->foreignId('contact_id')->constrained('contacts')->onDelete('restrict');
            $table->foreignId('warehouse_id')->constrained('warehouses')->onDelete('restrict');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');

            $table->enum('status', ['Active', 'Cancelled', 'On Delivery', 'Confirmed'])->default('Active'); // 1: active, 0: cancelled
            $table->string('serial_number', 255)->nullable();

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
