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
        Schema::create('chart_of_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('acc_code', 30)->unique()->index();
            $table->string('acc_name', 100)->index();
            $table->foreignId('account_id')->constrained()->onDelete('cascade'); // e.g., 'Cash', 'Bank', etc.
            $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->onDelete('set null');
            $table->bigInteger('st_balance')->default(0);
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_primary_cash')->default(false); // ✅ akun kas utama?
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chart_of_accounts');
    }
};
