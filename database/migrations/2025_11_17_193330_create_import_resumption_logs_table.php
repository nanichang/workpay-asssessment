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
        Schema::create('import_resumption_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('import_job_id');
            $table->enum('event_type', ['resumption_attempt', 'resumption_success', 'resumption_failure', 'integrity_check', 'lock_renewal']);
            $table->unsignedInteger('attempt_number')->default(1);
            $table->unsignedInteger('resumed_from_row')->nullable();
            $table->text('details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');
            
            // Foreign key constraint
            $table->foreign('import_job_id')->references('id')->on('import_jobs')->onDelete('cascade');
            
            // Indexes for performance and monitoring
            $table->index(['import_job_id', 'event_type']);
            $table->index(['event_type', 'created_at']);
            $table->index('attempt_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_resumption_logs');
    }
};