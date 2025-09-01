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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique()->nullable();     // SKU / kode produk
            $table->string('name', 160)->unique();                // Nama produk
            $table->decimal('init_cost', 15, 2);                  // HPP awal
            $table->decimal('current_cost', 15, 2);               // HPP
            $table->decimal('price', 15, 2);                      // Harga jual
            $table->foreignId('category_id')->index()->default(1);         // Nama kategori (opsional)

            $table->boolean('is_service')->default(false); // Tambahan: tipe produk

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
