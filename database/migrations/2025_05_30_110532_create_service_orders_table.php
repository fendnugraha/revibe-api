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
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->dateTime('date_issued');
            $table->string('invoice', 60)->nullable()->unique();
            $table->string('order_number', 60)->unique();
            $table->string('phone_number', 15);
            $table->string('phone_type', 160);
            $table->string('description', 160);
            $table->enum('status', ['Pending', 'In Progress', 'Finished', 'Completed', 'Canceled', 'Rejected'])->default('Pending');
            $table->foreignId('technician_id')->nullable()->constrained('users'); // atau 'technicians'
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->foreignId('user_id')->constrained()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
