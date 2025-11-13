<?php

namespace App\Services;

use App\Models\ImportJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ProgressTracker
{
    private const CACHE_PREFIX = 'import_progress:';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Update progress for an import job
     *
     * @param ImportJob $job
     * @param int $processedRows
     * @return void
     */
    public function updateProgress(ImportJob $job, int $processedRows): void
    {
        $progressData = $this->calculateProgress($job, $processedRows);
        
        // Update the database
        $job->update([
            'processed_rows' => $processedRows,
            'last_processed_row' => $processedRows,
        ]);

        // Cache the progress data for fast API access
        $this->cacheProgressData($job->id, $progressData);

        Log::debug("Progress updated for job {$job->id}: {$progressData['percentage']}% complete");
    }

    /**
     * Mark a row as processed and update counters
     *
     * @param ImportJob $job
     * @param bool $success
     * @param int $rowNumber
     * @return void
     */
    public function markRowProcessed(ImportJob $job, bool $success, int $rowNumber): void
    {
        // Update database counters
        $job->incrementProcessedRows($success, $rowNumber);

        // Calculate and cache updated progress
        $progressData = $this->calculateProgress($job);
        $this->cacheProgressData($job->id, $progressData);
    }

    /**
     * Get current progress for an import job
     *
     * @param string $importId
     * @return array
     */
    public function getProgress(string $importId): array
    {
        // Try to get from cache first
        $cachedProgress = $this->getCachedProgressData($importId);
        if ($cachedProgress) {
            return $cachedProgress;
        }

        // Fallback to database
        $job = ImportJob::find($importId);
        if (!$job) {
            return $this->getDefaultProgressData();
        }

        $progressData = $this->calculateProgress($job);
        $this->cacheProgressData($importId, $progressData);

        return $progressData;
    }

    /**
     * Calculate progress metrics for an import job
     *
     * @param ImportJob $job
     * @param int|null $overrideProcessedRows
     * @return array
     */
    private function calculateProgress(ImportJob $job, ?int $overrideProcessedRows = null): array
    {
        $processedRows = $overrideProcessedRows ?? $job->processed_rows;
        $totalRows = $job->total_rows;
        
        $percentage = $totalRows > 0 ? round(($processedRows / $totalRows) * 100, 2) : 0;
        
        $estimatedCompletion = $this->calculateEstimatedCompletion($job, $processedRows);
        $processingRate = $this->calculateProcessingRate($job, $processedRows);

        return [
            'job_id' => $job->id,
            'filename' => $job->filename,
            'status' => $job->status,
            'total_rows' => $totalRows,
            'processed_rows' => $processedRows,
            'successful_rows' => $job->successful_rows,
            'error_rows' => $job->error_rows,
            'percentage' => $percentage,
            'estimated_completion' => $estimatedCompletion,
            'processing_rate' => $processingRate,
            'started_at' => $job->started_at?->toISOString(),
            'last_updated' => now()->toISOString(),
            'is_completed' => $job->isCompleted(),
            'has_errors' => $job->error_rows > 0,
        ];
    }

    /**
     * Calculate estimated completion time
     *
     * @param ImportJob $job
     * @param int $processedRows
     * @return string|null
     */
    private function calculateEstimatedCompletion(ImportJob $job, int $processedRows): ?string
    {
        if (!$job->started_at || $processedRows === 0 || $job->isCompleted()) {
            return null;
        }

        $elapsedSeconds = $job->started_at->diffInSeconds(now());
        if ($elapsedSeconds === 0) {
            return null;
        }

        $remainingRows = $job->total_rows - $processedRows;
        $rowsPerSecond = $processedRows / $elapsedSeconds;

        if ($rowsPerSecond === 0) {
            return null;
        }

        $estimatedSecondsRemaining = $remainingRows / $rowsPerSecond;
        $estimatedCompletion = now()->addSeconds($estimatedSecondsRemaining);

        return $estimatedCompletion->toISOString();
    }

    /**
     * Calculate processing rate (rows per minute)
     *
     * @param ImportJob $job
     * @param int $processedRows
     * @return float
     */
    private function calculateProcessingRate(ImportJob $job, int $processedRows): float
    {
        if (!$job->started_at || $processedRows === 0) {
            return 0.0;
        }

        $elapsedMinutes = $job->started_at->diffInMinutes(now());
        if ($elapsedMinutes === 0) {
            // Use seconds for very recent jobs
            $elapsedSeconds = $job->started_at->diffInSeconds(now());
            return $elapsedSeconds > 0 ? round(($processedRows / $elapsedSeconds) * 60, 2) : 0.0;
        }

        return round($processedRows / $elapsedMinutes, 2);
    }

    /**
     * Cache progress data for fast access
     *
     * @param string $jobId
     * @param array $progressData
     * @return void
     */
    private function cacheProgressData(string $jobId, array $progressData): void
    {
        $cacheKey = self::CACHE_PREFIX . $jobId;
        Cache::put($cacheKey, $progressData, self::CACHE_TTL);
    }

    /**
     * Get cached progress data
     *
     * @param string $jobId
     * @return array|null
     */
    private function getCachedProgressData(string $jobId): ?array
    {
        $cacheKey = self::CACHE_PREFIX . $jobId;
        return Cache::get($cacheKey);
    }

    /**
     * Clear cached progress data
     *
     * @param string $jobId
     * @return void
     */
    public function clearProgressCache(string $jobId): void
    {
        $cacheKey = self::CACHE_PREFIX . $jobId;
        Cache::forget($cacheKey);
    }

    /**
     * Get default progress data structure
     *
     * @return array
     */
    private function getDefaultProgressData(): array
    {
        return [
            'job_id' => null,
            'filename' => null,
            'status' => 'not_found',
            'total_rows' => 0,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'percentage' => 0,
            'estimated_completion' => null,
            'processing_rate' => 0.0,
            'started_at' => null,
            'last_updated' => now()->toISOString(),
            'is_completed' => false,
            'has_errors' => false,
        ];
    }

    /**
     * Get progress summary for multiple jobs
     *
     * @param array $jobIds
     * @return array
     */
    public function getMultipleProgress(array $jobIds): array
    {
        $results = [];
        
        foreach ($jobIds as $jobId) {
            $results[$jobId] = $this->getProgress($jobId);
        }

        return $results;
    }

    /**
     * Get recent import jobs with their progress
     *
     * @param int $limit
     * @return array
     */
    public function getRecentImportsProgress(int $limit = 10): array
    {
        $recentJobs = ImportJob::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        $results = [];
        foreach ($recentJobs as $job) {
            $results[] = $this->calculateProgress($job);
        }

        return $results;
    }

    /**
     * Update progress with batch statistics
     *
     * @param ImportJob $job
     * @param array $batchStats
     * @return void
     */
    public function updateBatchProgress(ImportJob $job, array $batchStats): void
    {
        $progressData = $this->calculateProgress($job);
        
        // Add batch-specific information
        $progressData['current_batch'] = $batchStats['batch_number'] ?? null;
        $progressData['batch_size'] = $batchStats['batch_size'] ?? null;
        $progressData['batches_completed'] = $batchStats['batches_completed'] ?? null;
        $progressData['total_batches'] = $batchStats['total_batches'] ?? null;

        $this->cacheProgressData($job->id, $progressData);
    }

    /**
     * Mark import as completed and update final progress
     *
     * @param ImportJob $job
     * @return void
     */
    public function markCompleted(ImportJob $job): void
    {
        $progressData = $this->calculateProgress($job);
        $progressData['percentage'] = 100.0;
        $progressData['is_completed'] = true;
        $progressData['estimated_completion'] = null;
        $progressData['completed_at'] = now()->toISOString();

        $this->cacheProgressData($job->id, $progressData);

        Log::info("Import job {$job->id} marked as completed with final progress data");
    }

    /**
     * Mark import as failed and update progress
     *
     * @param ImportJob $job
     * @param string $errorMessage
     * @return void
     */
    public function markFailed(ImportJob $job, string $errorMessage): void
    {
        $progressData = $this->calculateProgress($job);
        $progressData['status'] = 'failed';
        $progressData['error_message'] = $errorMessage;
        $progressData['failed_at'] = now()->toISOString();

        $this->cacheProgressData($job->id, $progressData);

        Log::error("Import job {$job->id} marked as failed: {$errorMessage}");
    }

    /**
     * Get processing statistics for dashboard
     *
     * @return array
     */
    public function getProcessingStatistics(): array
    {
        $stats = [
            'active_imports' => ImportJob::byStatus(ImportJob::STATUS_PROCESSING)->count(),
            'pending_imports' => ImportJob::byStatus(ImportJob::STATUS_PENDING)->count(),
            'completed_today' => ImportJob::byStatus(ImportJob::STATUS_COMPLETED)
                ->whereDate('completed_at', today())
                ->count(),
            'failed_today' => ImportJob::byStatus(ImportJob::STATUS_FAILED)
                ->whereDate('updated_at', today())
                ->count(),
        ];

        // Calculate total rows processed today
        $todayProcessed = ImportJob::whereDate('updated_at', today())
            ->sum('successful_rows');
        
        $stats['rows_processed_today'] = $todayProcessed;

        return $stats;
    }
}