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
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_number', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 255)->unique();
            $table->string('department', 100)->nullable();
            $table->decimal('salary', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('country_code', 2)->nullable();
            $table->date('start_date')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['employee_number', 'email'], 'idx_employee_lookup');
            $table->index('currency', 'idx_currency');
            $table->index('country_code', 'idx_country');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
