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
        Schema::create('import_errors', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_job_id');
            $table->unsignedInteger('row_number');
            $table->string('error_type', 50);
            $table->text('error_message');
            $table->json('row_data')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('import_job_id')->references('id')->on('import_jobs')->onDelete('cascade');

            // Indexes for performance
            $table->index(['import_job_id', 'error_type'], 'idx_import_errors');
            $table->index('row_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_errors');
    }
};
