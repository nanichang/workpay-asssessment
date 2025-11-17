<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Models\ImportProcessedRecord;
use App\Models\ImportResumptionLog;
use App\Services\FileIntegrityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanupImportData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:cleanup 
                            {--days=30 : Number of days to keep completed import data}
                            {--dry-run : Show what would be cleaned up without actually doing it}
                            {--force : Force cleanup without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old import data and resumption logs';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $cutoffDate = now()->subDays($days);
        
        $this->info("Cleaning up import data older than {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})");

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No data will be actually deleted');
        }

        try {
            // Find jobs to clean up
            $jobsToCleanup = ImportJob::where('created_at', '<', $cutoffDate)
                ->whereIn('status', [ImportJob::STATUS_COMPLETED, ImportJob::STATUS_FAILED])
                ->get();

            if ($jobsToCleanup->isEmpty()) {
                $this->info('No import jobs found for cleanup');
                return Command::SUCCESS;
            }

            $this->displayCleanupSummary($jobsToCleanup, $cutoffDate);

            // Confirm cleanup unless forced
            if (!$force && !$dryRun) {
                if (!$this->confirm('Do you want to proceed with the cleanup?')) {
                    $this->info('Cleanup cancelled');
                    return Command::SUCCESS;
                }
            }

            // Perform cleanup
            $cleanupStats = $this->performCleanup($jobsToCleanup, $dryRun);
            
            $this->displayCleanupResults($cleanupStats, $dryRun);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error("Cleanup failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Display what will be cleaned up.
     */
    private function displayCleanupSummary($jobs, $cutoffDate): void
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Jobs to cleanup', $jobs->count()],
                ['Completed jobs', $jobs->where('status', ImportJob::STATUS_COMPLETED)->count()],
                ['Failed jobs', $jobs->where('status', ImportJob::STATUS_FAILED)->count()],
                ['Cutoff date', $cutoffDate->format('Y-m-d H:i:s')],
            ]
        );

        // Show breakdown by status
        $this->newLine();
        $this->line('Jobs by status:');
        foreach ($jobs->groupBy('status') as $status => $statusJobs) {
            $this->line("  {$status}: {$statusJobs->count()} jobs");
        }
    }

    /**
     * Perform the actual cleanup.
     */
    private function performCleanup($jobs, bool $dryRun): array
    {
        $stats = [
            'jobs_cleaned' => 0,
            'processed_records_cleaned' => 0,
            'resumption_logs_cleaned' => 0,
            'errors_cleaned' => 0,
            'integrity_data_cleaned' => 0,
        ];

        $integrityService = app(FileIntegrityService::class);

        foreach ($jobs as $job) {
            $this->line("Processing job {$job->id} ({$job->filename})...");

            if (!$dryRun) {
                DB::transaction(function () use ($job, &$stats, $integrityService) {
                    // Clean up processed records
                    $processedCount = ImportProcessedRecord::byImportJob($job->id)->count();
                    ImportProcessedRecord::byImportJob($job->id)->delete();
                    $stats['processed_records_cleaned'] += $processedCount;

                    // Clean up resumption logs
                    $logsCount = ImportResumptionLog::byImportJob($job->id)->count();
                    ImportResumptionLog::byImportJob($job->id)->delete();
                    $stats['resumption_logs_cleaned'] += $logsCount;

                    // Clean up import errors (cascade delete should handle this, but let's be explicit)
                    $errorsCount = $job->importErrors()->count();
                    $job->importErrors()->delete();
                    $stats['errors_cleaned'] += $errorsCount;

                    // Clean up integrity data
                    $integrityService->cleanupIntegrityData($job);
                    $stats['integrity_data_cleaned']++;

                    // Delete the job itself
                    $job->delete();
                    $stats['jobs_cleaned']++;
                });
            } else {
                // Dry run - just count what would be deleted
                $stats['jobs_cleaned']++;
                $stats['processed_records_cleaned'] += ImportProcessedRecord::byImportJob($job->id)->count();
                $stats['resumption_logs_cleaned'] += ImportResumptionLog::byImportJob($job->id)->count();
                $stats['errors_cleaned'] += $job->importErrors()->count();
                $stats['integrity_data_cleaned']++;
            }
        }

        return $stats;
    }

    /**
     * Display cleanup results.
     */
    private function displayCleanupResults(array $stats, bool $dryRun): void
    {
        $this->newLine();
        $action = $dryRun ? 'Would be cleaned' : 'Cleaned up';
        
        $this->info("Cleanup Results:");
        $this->table(
            ['Item', 'Count'],
            [
                ['Import jobs', $stats['jobs_cleaned']],
                ['Processed records', $stats['processed_records_cleaned']],
                ['Resumption logs', $stats['resumption_logs_cleaned']],
                ['Import errors', $stats['errors_cleaned']],
                ['Integrity data entries', $stats['integrity_data_cleaned']],
            ]
        );

        if ($dryRun) {
            $this->warn('This was a dry run. Use --force to actually perform the cleanup.');
        } else {
            $this->info('✓ Cleanup completed successfully');
        }
    }
}