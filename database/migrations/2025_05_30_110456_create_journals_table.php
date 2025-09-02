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
        Schema::create('journals', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued')->index();
            $table->string('invoice', 60)->index();
            $table->string('description', 160)->nullable();

            // Metadata (boleh disesuaikan)
            $table->string('finance_type', 30)->nullable();
            $table->integer('payment_nth')->nullable()->default(0);
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->foreignId('warehouse_id')->constrained()->onDelete('restrict');
            $table->string('serial_number', 255)->nullable();
            $table->enum('status', ['Active', 'Deleted'])->default('Active')->index(); // 1: active, 0: cancelled
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journals');
    }
};
