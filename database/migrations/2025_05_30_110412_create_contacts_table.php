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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->enum('type', ['Supplier', 'Customer', 'General'])->default('General');
            $table->string('phone_number', 15)->nullable()->unique();
            $table->string('address', 160)->nullable();
            $table->string('description', 255)->default('General Contact');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
