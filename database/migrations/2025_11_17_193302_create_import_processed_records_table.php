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
        Schema::create('import_processed_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_job_id');
            $table->string('employee_number', 50);
            $table->string('email', 255);
            $table->unsignedInteger('row_number');
            $table->enum('status', ['processed', 'skipped', 'error'])->default('processed');
            $table->timestamp('processed_at');
            
            // Foreign key constraint
            $table->foreign('import_job_id')->references('id')->on('import_jobs')->onDelete('cascade');
            
            // Indexes for performance
            $table->index(['import_job_id', 'employee_number']);
            $table->index(['import_job_id', 'email']);
            $table->index(['import_job_id', 'row_number']);
            $table->index(['import_job_id', 'status']);
            
            // Unique constraint to prevent duplicates within same import
            $table->unique(['import_job_id', 'employee_number'], 'unique_job_employee_number');
            $table->unique(['import_job_id', 'email'], 'unique_job_email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_processed_records');
    }
};