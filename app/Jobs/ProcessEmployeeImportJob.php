<?php

namespace App\Jobs;

use App\Models\ImportJob;
use App\Models\ImportResumptionLog;
use App\Services\FileProcessorService;
use App\Services\ProgressTracker;
use App\Services\DynamicLockManager;
use App\Services\FileIntegrityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessEmployeeImportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The import job to process.
     */
    public ImportJob $importJob;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     *
     * @var int
     */
    public int $timeout = 3600; // 1 hour

    /**
     * Indicate if the job should be marked as failed on timeout.
     *
     * @var bool
     */
    public bool $failOnTimeout = true;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public array $backoff = [30, 60, 120]; // 30s, 1m, 2m

    /**
     * Create a new job instance.
     */
    public function __construct(ImportJob $importJob)
    {
        $this->importJob = $importJob;
        
        // Set queue name based on file size for prioritization
        $this->onQueue($this->determineQueue());
        
        Log::info("ProcessEmployeeImportJob created for import {$importJob->id}");
    }

    /**
     * Execute the job.
     */
    public function handle(FileProcessorService $fileProcessor, ProgressTracker $progressTracker): void
    {
        Log::info("Starting ProcessEmployeeImportJob for import {$this->importJob->id} (attempt {$this->attempts()})");

        try {
            // Prevent duplicate processing by checking job state
            if (!$this->shouldProcessJob()) {
                Log::info("Skipping job processing for import {$this->importJob->id} - already completed or being processed");
                return;
            }

            // Validate job consistency before processing
            if (!$this->validateJobConsistency()) {
                throw new \RuntimeException("Job consistency validation failed for import {$this->importJob->id}");
            }

            // Acquire processing lock to prevent concurrent execution
            if (!$this->acquireProcessingLock()) {
                Log::warning("Could not acquire processing lock for import {$this->importJob->id} - another process may be handling it");
                $this->release(30); // Retry in 30 seconds
                return;
            }

            try {
                // Check if job can be resumed or needs to start fresh
                if ($this->canResumeProcessing($fileProcessor)) {
                    Log::info("Resuming import processing from row {$this->importJob->last_processed_row} for job {$this->importJob->id}");
                    
                    // Log successful resumption
                    ImportResumptionLog::logResumptionSuccess(
                        $this->importJob->id,
                        $this->importJob->last_processed_row,
                        [
                            'attempt_number' => $this->attempts(),
                            'total_rows' => $this->importJob->total_rows,
                            'processed_rows' => $this->importJob->processed_rows,
                        ]
                    );
                } else {
                    Log::info("Starting fresh import processing for job {$this->importJob->id}");
                    $this->resetJobProgress();
                }

                // Log resumption attempt
                ImportResumptionLog::logResumptionAttempt(
                    $this->importJob->id,
                    $this->attempts(),
                    $this->importJob->last_processed_row,
                    'Processing job execution started'
                );

                // Ensure job is marked as processing
                if (!$this->importJob->isProcessing()) {
                    $this->importJob->markAsStarted();
                }

                // Process the import using FileProcessorService
                $fileProcessor->processImport($this->importJob);

                Log::info("ProcessEmployeeImportJob completed successfully for import {$this->importJob->id}");

            } finally {
                // Always release the processing lock
                $this->releaseProcessingLock();
            }

        } catch (Throwable $exception) {
            Log::error("ProcessEmployeeImportJob failed for import {$this->importJob->id}: " . $exception->getMessage(), [
                'exception' => $exception,
                'import_job_id' => $this->importJob->id,
                'attempt' => $this->attempts(),
                'max_attempts' => $this->tries,
            ]);

            // Handle retry logic
            $this->handleJobFailure($exception, $progressTracker);

            throw $exception;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error("ProcessEmployeeImportJob permanently failed for import {$this->importJob->id}", [
            'exception' => $exception,
            'import_job_id' => $this->importJob->id,
            'total_attempts' => $this->attempts(),
        ]);

        // Release any processing locks
        $this->releaseProcessingLock();

        // Refresh the model to ensure we have the latest state
        $this->importJob->refresh();

        // Ensure the import job is marked as failed (idempotent operation)
        if (!$this->importJob->hasFailed()) {
            $this->importJob->markAsFailed();
        }

        // Clear any cached progress data
        app(ProgressTracker::class)->clearProgressCache($this->importJob->id);

        // Log final statistics
        Log::info("Final import statistics for failed job {$this->importJob->id}", [
            'total_rows' => $this->importJob->total_rows,
            'processed_rows' => $this->importJob->processed_rows,
            'successful_rows' => $this->importJob->successful_rows,
            'error_rows' => $this->importJob->error_rows,
            'last_processed_row' => $this->importJob->last_processed_row,
        ]);
    }

    /**
     * Determine if processing can be resumed from the last checkpoint.
     */
    private function canResumeProcessing(FileProcessorService $fileProcessor): bool
    {
        return $fileProcessor->canResumeProcessing($this->importJob);
    }

    /**
     * Check if the job should be processed (idempotency check).
     */
    private function shouldProcessJob(): bool
    {
        // Refresh the model to get the latest state
        $this->importJob->refresh();

        // Don't process if already completed
        if ($this->importJob->isCompleted()) {
            Log::info("Import job {$this->importJob->id} is already completed");
            return false;
        }

        // Don't process if already failed and this is not a retry
        if ($this->importJob->hasFailed() && $this->attempts() === 1) {
            Log::info("Import job {$this->importJob->id} has already failed");
            return false;
        }

        return true;
    }

    /**
     * Acquire a processing lock to prevent concurrent execution.
     */
    private function acquireProcessingLock(): bool
    {
        $lockManager = app(DynamicLockManager::class);
        $result = $lockManager->acquireProcessingLock($this->importJob);
        
        return $result['acquired'];
    }

    /**
     * Release the processing lock.
     */
    private function releaseProcessingLock(): void
    {
        $lockManager = app(DynamicLockManager::class);
        $lockManager->releaseProcessingLock($this->importJob);
    }

    /**
     * Reset job progress for fresh start (used when resumption is not possible).
     */
    private function resetJobProgress(): void
    {
        $this->importJob->update([
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0,
        ]);

        Log::info("Reset progress for import job {$this->importJob->id}");
    }

    /**
     * Handle job failure with appropriate retry logic.
     */
    private function handleJobFailure(Throwable $exception, ProgressTracker $progressTracker): void
    {
        $isLastAttempt = $this->attempts() >= $this->tries;
        
        if ($isLastAttempt) {
            Log::error("Final attempt failed for import {$this->importJob->id}. Marking as permanently failed.");
            $this->importJob->markAsFailed();
            $progressTracker->markFailed($this->importJob, $exception->getMessage());
        } else {
            Log::warning("Attempt {$this->attempts()} failed for import {$this->importJob->id}. Will retry.");
            
            // Update job status to indicate retry is pending
            $this->importJob->update([
                'status' => ImportJob::STATUS_PENDING, // Reset to pending for retry
            ]);
        }
    }

    /**
     * Determine if the exception is retryable.
     */
    private function isRetryableException(Throwable $exception): bool
    {
        // Define which exceptions should trigger retries
        $retryableExceptions = [
            \Illuminate\Database\QueryException::class,
            \PDOException::class,
            \RuntimeException::class,
            \PhpOffice\PhpSpreadsheet\Exception::class,
        ];

        foreach ($retryableExceptions as $retryableClass) {
            if ($exception instanceof $retryableClass) {
                return true;
            }
        }

        // Also retry on temporary file system issues
        if (str_contains($exception->getMessage(), 'No such file or directory') ||
            str_contains($exception->getMessage(), 'Permission denied') ||
            str_contains($exception->getMessage(), 'Connection refused')) {
            return true;
        }

        return false;
    }

    /**
     * Create a checkpoint for the current processing state.
     */
    public function createCheckpoint(): void
    {
        // This method can be called by the FileProcessorService during processing
        // to create intermediate checkpoints for better resumption
        $this->importJob->touch(); // Update the updated_at timestamp
        
        Log::debug("Checkpoint created for import job {$this->importJob->id} at row {$this->importJob->last_processed_row}");
    }

    /**
     * Validate that the job data is still consistent before processing.
     */
    private function validateJobConsistency(): bool
    {
        try {
            $integrityService = app(FileIntegrityService::class);
            $result = $integrityService->verifyFileIntegrity($this->importJob);
            
            if (!$result['valid']) {
                Log::error("File integrity validation failed for job {$this->importJob->id}", $result['errors']);
                
                // Log resumption failure
                ImportResumptionLog::logResumptionFailure(
                    $this->importJob->id,
                    'File integrity validation failed: ' . implode(', ', $result['errors'])
                );
                
                return false;
            }
            
            Log::info("File integrity validated successfully for job {$this->importJob->id}");
            return true;
            
        } catch (\Exception $e) {
            Log::error("Job consistency validation failed for job {$this->importJob->id}: " . $e->getMessage());
            
            ImportResumptionLog::logResumptionFailure(
                $this->importJob->id,
                'Job consistency validation exception: ' . $e->getMessage()
            );
            
            return false;
        }
    }

    /**
     * Determine the appropriate queue for this job based on file size.
     */
    private function determineQueue(): string
    {
        // Use different queues for different file sizes to allow prioritization
        $totalRows = $this->importJob->total_rows;

        if ($totalRows === 0) {
            return 'imports'; // Default queue
        }

        if ($totalRows < 1000) {
            return 'imports-small'; // High priority for small files
        }

        if ($totalRows < 10000) {
            return 'imports-medium'; // Medium priority
        }

        return 'imports-large'; // Lower priority for large files
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'import',
            'employee-import',
            "import-job:{$this->importJob->id}",
            "file:{$this->importJob->filename}",
        ];
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return $this->backoff;
    }

    /**
     * Determine if the job should be retried based on the exception.
     */
    public function retryUntil(): \DateTime
    {
        // Allow retries for up to 2 hours from the first attempt
        return now()->addHours(2);
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [
            // Add rate limiting if needed
            // new RateLimited('imports'),
        ];
    }
}