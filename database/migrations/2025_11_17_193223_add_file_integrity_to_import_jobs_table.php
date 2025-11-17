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
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->bigInteger('file_size')->nullable()->after('file_path');
            $table->string('file_hash', 64)->nullable()->after('file_size');
            $table->timestamp('file_last_modified')->nullable()->after('file_hash');
            $table->json('resumption_metadata')->nullable()->after('last_processed_row');
            
            // Add indexes for performance
            $table->index('file_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table) {
            $table->dropIndex(['file_hash']);
            $table->dropColumn(['file_size', 'file_hash', 'file_last_modified', 'resumption_metadata']);
        });
    }
};