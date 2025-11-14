<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\CacheInvalidationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ImportCleanup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:cleanup 
                            {--days=30 : Number of days to keep completed jobs}
                            {--failed-days=7 : Number of days to keep failed jobs}
                            {--cache-hours=24 : Number of hours for cache cleanup}
                            {--files : Also cleanup uploaded files}
                            {--dry-run : Show what would be cleaned without actually doing it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cleanup old import jobs, errors, cache, and files';

    private CacheInvalidationService $cacheService;

    /**
     * Create a new command instance.
     */
    public function __construct(CacheInvalidationService $cacheService)
    {
        parent::__construct();
        $this->cacheService = $cacheService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🧹 Starting Import System Cleanup');
        $this->newLine();

        $dryRun = $this->option('dry-run');
        $days = (int) $this->option('days');
        $failedDays = (int) $this->option('failed-days');
        $cacheHours = (int) $this->option('cache-hours');
        $cleanupFiles = $this->option('files');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No actual cleanup will be performed');
            $this->newLine();
        }

        $stats = [
            'completed_jobs_cleaned' => 0,
            'failed_jobs_cleaned' => 0,
            'errors_cleaned' => 0,
            'files_cleaned' => 0,
            'cache_entries_cleaned' => 0,
        ];

        // Cleanup completed jobs
        $stats['completed_jobs_cleaned'] = $this->cleanupCompletedJobs($days, $dryRun);

        // Cleanup failed jobs
        $stats['failed_jobs_cleaned'] = $this->cleanupFailedJobs($failedDays, $dryRun);

        // Cleanup orphaned errors
        $stats['errors_cleaned'] = $this->cleanupOrphanedErrors($dryRun);

        // Cleanup cache
        $stats['cache_entries_cleaned'] = $this->cleanupCache($cacheHours, $dryRun);

        // Cleanup files if requested
        if ($cleanupFiles) {
            $stats['files_cleaned'] = $this->cleanupFiles($dryRun);
        }

        // Display summary
        $this->displayCleanupSummary($stats, $dryRun);

        return Command::SUCCESS;
    }

    /**
     * Cleanup completed import jobs older than specified days
     */
    private function cleanupCompletedJobs(int $days, bool $dryRun): int
    {
        $cutoffDate = now()->subDays($days);
        
        $query = ImportJob::where('status', ImportJob::STATUS_COMPLETED)
            ->where('completed_at', '<', $cutoffDate);

        $count = $query->count();
        
        $this->info("📋 Completed Jobs Cleanup (older than {$days} days):");
        $this->line("  Found: {$count} jobs to cleanup");

        if ($count > 0 && !$dryRun) {
            // Get job IDs for cache cleanup
            $jobIds = $query->pluck('id');
            
            // Delete the jobs (cascade will handle related errors)
            $deleted = $query->delete();
            
            // Cleanup cache for deleted jobs
            foreach ($jobIds as $jobId) {
                $this->cacheService->invalidateImportJobCache($jobId);
            }
            
            $this->line("  Deleted: {$deleted} completed jobs");
        }

        return $count;
    }

    /**
     * Cleanup failed import jobs older than specified days
     */
    private function cleanupFailedJobs(int $days, bool $dryRun): int
    {
        $cutoffDate = now()->subDays($days);
        
        $query = ImportJob::where('status', ImportJob::STATUS_FAILED)
            ->where('updated_at', '<', $cutoffDate);

        $count = $query->count();
        
        $this->info("❌ Failed Jobs Cleanup (older than {$days} days):");
        $this->line("  Found: {$count} jobs to cleanup");

        if ($count > 0 && !$dryRun) {
            // Get job IDs for cache cleanup
            $jobIds = $query->pluck('id');
            
            // Delete the jobs
            $deleted = $query->delete();
            
            // Cleanup cache for deleted jobs
            foreach ($jobIds as $jobId) {
                $this->cacheService->invalidateImportJobCache($jobId);
            }
            
            $this->line("  Deleted: {$deleted} failed jobs");
        }

        return $count;
    }

    /**
     * Cleanup orphaned import errors (errors without corresponding jobs)
     */
    private function cleanupOrphanedErrors(bool $dryRun): int
    {
        $query = ImportError::whereNotExists(function ($query) {
            $query->select('id')
                ->from('import_jobs')
                ->whereColumn('import_jobs.id', 'import_errors.import_job_id');
        });

        $count = $query->count();
        
        $this->info("🗑️ Orphaned Errors Cleanup:");
        $this->line("  Found: {$count} orphaned errors to cleanup");

        if ($count > 0 && !$dryRun) {
            $deleted = $query->delete();
            $this->line("  Deleted: {$deleted} orphaned errors");
        }

        return $count;
    }

    /**
     * Cleanup old cache entries
     */
    private function cleanupCache(int $hours, bool $dryRun): int
    {
        $this->info("🗄️ Cache Cleanup (older than {$hours} hours):");
        
        if (!$dryRun) {
            $this->cacheService->scheduledCleanup($hours);
            $this->line("  Cache cleanup completed");
        } else {
            $this->line("  Would perform cache cleanup for entries older than {$hours} hours");
        }

        return 1; // Placeholder count since cache cleanup doesn't return specific numbers
    }

    /**
     * Cleanup uploaded files for deleted jobs
     */
    private function cleanupFiles(bool $dryRun): int
    {
        $this->info("📁 File Cleanup:");
        
        // Get all file paths from existing jobs
        $existingFiles = ImportJob::pluck('file_path')->filter()->toArray();
        
        // Get all files in the upload directory
        $uploadPath = storage_path('app/imports');
        
        if (!is_dir($uploadPath)) {
            $this->line("  Upload directory not found: {$uploadPath}");
            return 0;
        }

        $allFiles = glob($uploadPath . '/*');
        $orphanedFiles = [];

        foreach ($allFiles as $file) {
            if (is_file($file) && !in_array($file, $existingFiles)) {
                // Check if file is older than 7 days
                if (filemtime($file) < strtotime('-7 days')) {
                    $orphanedFiles[] = $file;
                }
            }
        }

        $count = count($orphanedFiles);
        $this->line("  Found: {$count} orphaned files to cleanup");

        if ($count > 0 && !$dryRun) {
            $deleted = 0;
            foreach ($orphanedFiles as $file) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
            $this->line("  Deleted: {$deleted} orphaned files");
        }

        return $count;
    }

    /**
     * Display cleanup summary
     */
    private function displayCleanupSummary(array $stats, bool $dryRun): void
    {
        $this->newLine();
        $this->info('📊 Cleanup Summary:');
        
        $action = $dryRun ? 'Would cleanup' : 'Cleaned up';
        
        $this->table(['Category', 'Count'], [
            ['Completed Jobs', $action . ': ' . number_format($stats['completed_jobs_cleaned'])],
            ['Failed Jobs', $action . ': ' . number_format($stats['failed_jobs_cleaned'])],
            ['Orphaned Errors', $action . ': ' . number_format($stats['errors_cleaned'])],
            ['Cache Entries', $action . ': Cache cleanup performed'],
            ['Orphaned Files', $action . ': ' . number_format($stats['files_cleaned'])],
        ]);

        $total = $stats['completed_jobs_cleaned'] + $stats['failed_jobs_cleaned'] + $stats['errors_cleaned'] + $stats['files_cleaned'];
        
        $this->newLine();
        if ($dryRun) {
            $this->info("Total items that would be cleaned: " . number_format($total));
            $this->line("Run without --dry-run to perform actual cleanup");
        } else {
            $this->info("✅ Cleanup completed! Total items cleaned: " . number_format($total));
        }

        // Recommendations
        $this->newLine();
        $this->info('💡 Recommendations:');
        $this->line('  • Schedule this command to run daily: php artisan import:cleanup');
        $this->line('  • Use --files flag periodically to cleanup orphaned files');
        $this->line('  • Monitor disk space and adjust retention periods as needed');
        $this->line('  • Consider archiving important completed jobs before cleanup');
    }

    /**
     * Get disk usage information
     */
    private function getDiskUsage(): array
    {
        $uploadPath = storage_path('app/imports');
        $logPath = storage_path('logs');
        
        $usage = [
            'uploads' => $this->getDirectorySize($uploadPath),
            'logs' => $this->getDirectorySize($logPath),
        ];

        return $usage;
    }

    /**
     * Get directory size in bytes
     */
    private function getDirectorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }

        return $size;
    }

    /**
     * Format bytes to human readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
