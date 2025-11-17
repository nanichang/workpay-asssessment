<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Services\FileIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackfillFileIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:backfill-integrity 
                            {--dry-run : Show what would be processed without actually doing it}
                            {--force : Process all jobs, even if they already have integrity data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill file integrity data for existing import jobs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('Backfilling file integrity data for existing import jobs...');

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No data will be actually updated');
        }

        try {
            // Find jobs that need integrity data
            $query = ImportJob::query();
            
            if (!$force) {
                $query->where(function ($q) {
                    $q->whereNull('file_hash')
                      ->orWhereNull('file_size')
                      ->orWhereNull('file_last_modified');
                });
            }

            $jobs = $query->orderBy('created_at', 'desc')->get();

            if ($jobs->isEmpty()) {
                $this->info('No jobs found that need integrity data backfill');
                return Command::SUCCESS;
            }

            $this->info("Found {$jobs->count()} job(s) to process");

            $integrityService = app(FileIntegrityService::class);
            $processed = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($jobs as $job) {
                $this->line("Processing job {$job->id} ({$job->filename})...");

                try {
                    // Get the full file path
                    $filePath = $this->getFullFilePath($job->file_path);
                    
                    if (!file_exists($filePath)) {
                        $this->warn("  File not found: {$filePath}");
                        $skipped++;
                        continue;
                    }

                    if (!$dryRun) {
                        $integrity = $integrityService->calculateFileIntegrity($job, $filePath);
                        $this->info("  ✓ Calculated integrity: size={$integrity['file_size']}, hash=" . substr($integrity['file_hash'], 0, 16) . "...");
                    } else {
                        $this->info("  ✓ Would calculate integrity for file: {$filePath}");
                    }

                    $processed++;

                } catch (\Exception $e) {
                    $this->error("  ✗ Failed to process job {$job->id}: " . $e->getMessage());
                    $errors++;
                }
            }

            $this->newLine();
            $this->info('Backfill Summary:');
            $this->table(
                ['Status', 'Count'],
                [
                    ['Processed', $processed],
                    ['Skipped (file not found)', $skipped],
                    ['Errors', $errors],
                    ['Total', $jobs->count()],
                ]
            );

            if ($dryRun) {
                $this->warn('This was a dry run. Use without --dry-run to actually update the data.');
            } else {
                $this->info('✓ Backfill completed successfully');
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Backfill failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Get the full file path, handling different storage configurations.
     */
    private function getFullFilePath(string $relativePath): string
    {
        // If it's already an absolute path, return as-is
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }

        // Handle Laravel storage paths - check multiple possible locations
        $possiblePaths = [
            storage_path('app/private/' . ltrim($relativePath, '/')),
            storage_path('app/' . ltrim($relativePath, '/')),
            $relativePath, // In case it's already a full path
        ];

        // If the path starts with 'imports/', also try without the prefix
        if (str_starts_with($relativePath, 'imports/')) {
            $filename = basename($relativePath);
            $possiblePaths[] = storage_path('app/private/imports/' . $filename);
            $possiblePaths[] = storage_path('app/imports/' . $filename);
        }

        // Return the first path that exists
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // If no file found, return the most likely path for error reporting
        return storage_path('app/private/' . ltrim($relativePath, '/'));
    }
}