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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued')->index();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('transaction_id');
            $table->unsignedBigInteger('warehouse_id');

            $table->integer('quantity');
            $table->decimal('cost', 15, 2);
            $table->decimal('price', 15, 2);
            $table->boolean('is_initial')->default(false);

            $table->enum('transaction_type', ['Purchase', 'Sales', 'Order', 'Adjustment', 'Return', 'Pending', 'Cancelled'])
                ->default('Pending')
                ->index();

            $table->timestamps();

            // Index manual
            $table->index('product_id');
            $table->index('transaction_id');
            $table->index('warehouse_id');

            // Foreign keys with explicit names
            $table->foreign('product_id', 'fk_stock_movements_product_id')
                ->references('id')->on('products')
                ->onDelete('restrict');

            $table->foreign('transaction_id', 'fk_stock_movements_transaction_id')
                ->references('id')->on('transactions')
                ->onDelete('cascade');

            $table->foreign('warehouse_id', 'fk_stock_movements_warehouse_id')
                ->references('id')->on('warehouses')
                ->onDelete('restrict');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
