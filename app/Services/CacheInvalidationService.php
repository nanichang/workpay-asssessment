<?php

namespace App\Services;

use App\Models\ImportJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CacheInvalidationService
{
    private ProgressTracker $progressTracker;
    private ValidationCache $validationCache;
    private FileMetadataCache $fileMetadataCache;

    public function __construct(
        ProgressTracker $progressTracker,
        ValidationCache $validationCache,
        FileMetadataCache $fileMetadataCache
    ) {
        $this->progressTracker = $progressTracker;
        $this->validationCache = $validationCache;
        $this->fileMetadataCache = $fileMetadataCache;
    }

    /**
     * Invalidate all cache related to an import job
     *
     * @param string $importJobId
     * @return void
     */
    public function invalidateImportJobCache(string $importJobId): void
    {
        Log::info("Invalidating all cache for import job: {$importJobId}");

        // Clear progress cache
        $this->progressTracker->clearProgressCache($importJobId);

        // Clear validation cache for this import
        $this->validationCache->clearImportValidationCache($importJobId);

        // Clear file metadata cache
        $this->fileMetadataCache->clearImportMetadataCache($importJobId);

        Log::info("Cache invalidation completed for import job: {$importJobId}");
    }

    /**
     * Invalidate cache when import job is completed
     *
     * @param ImportJob $importJob
     * @return void
     */
    public function invalidateOnCompletion(ImportJob $importJob): void
    {
        Log::info("Invalidating cache on completion for import job: {$importJob->id}");

        // Keep progress cache for a while for reporting purposes
        // but clear processing-specific cache
        $this->fileMetadataCache->clearProcessingCheckpoint($importJob->id);

        // Clear validation cache as it's no longer needed
        $this->validationCache->clearImportValidationCache($importJob->id);

        Log::info("Completion cache invalidation done for import job: {$importJob->id}");
    }

    /**
     * Invalidate cache when import job fails
     *
     * @param ImportJob $importJob
     * @return void
     */
    public function invalidateOnFailure(ImportJob $importJob): void
    {
        Log::info("Invalidating cache on failure for import job: {$importJob->id}");

        // Clear all cache except progress (for debugging purposes)
        $this->validationCache->clearImportValidationCache($importJob->id);
        $this->fileMetadataCache->clearProcessingCheckpoint($importJob->id);

        Log::info("Failure cache invalidation done for import job: {$importJob->id}");
    }

    /**
     * Invalidate cache when file is re-uploaded
     *
     * @param string $filePath
     * @return void
     */
    public function invalidateFileCache(string $filePath): void
    {
        Log::info("Invalidating file cache for: {$filePath}");

        // Clear file metadata cache
        $this->fileMetadataCache->clearFileMetadata($filePath);

        Log::info("File cache invalidation done for: {$filePath}");
    }

    /**
     * Scheduled cache cleanup for old entries
     *
     * @param int $olderThanHours
     * @return void
     */
    public function scheduledCleanup(int $olderThanHours = 24): void
    {
        Log::info("Starting scheduled cache cleanup for entries older than {$olderThanHours} hours");

        // Find completed import jobs older than specified hours
        $cutoffTime = now()->subHours($olderThanHours);
        
        $oldCompletedJobs = ImportJob::where('status', ImportJob::STATUS_COMPLETED)
            ->where('completed_at', '<', $cutoffTime)
            ->pluck('id');

        foreach ($oldCompletedJobs as $jobId) {
            $this->invalidateImportJobCache($jobId);
        }

        // Find failed import jobs older than specified hours
        $oldFailedJobs = ImportJob::where('status', ImportJob::STATUS_FAILED)
            ->where('updated_at', '<', $cutoffTime)
            ->pluck('id');

        foreach ($oldFailedJobs as $jobId) {
            $this->invalidateImportJobCache($jobId);
        }

        Log::info("Scheduled cache cleanup completed. Cleaned {$oldCompletedJobs->count()} completed and {$oldFailedJobs->count()} failed job caches");
    }

    /**
     * Warm up cache with common validation data
     *
     * @return void
     */
    public function warmUpCache(): void
    {
        Log::info("Starting cache warm-up process");

        // Warm up validation cache
        $this->validationCache->warmUpCache();

        Log::info("Cache warm-up process completed");
    }

    /**
     * Get cache statistics across all cache types
     *
     * @return array
     */
    public function getCacheStatistics(): array
    {
        return [
            'progress_cache' => $this->progressTracker->getProcessingStatistics(),
            'validation_cache' => $this->validationCache->getValidationCacheStats(),
            'metadata_cache' => $this->fileMetadataCache->getMetadataCacheStats(),
            'last_checked' => now()->toISOString(),
        ];
    }

    /**
     * Clear all import-related cache (use with caution)
     *
     * @return void
     */
    public function clearAllImportCache(): void
    {
        Log::warning("Clearing ALL import-related cache - this will impact performance");

        // This is a nuclear option - clear everything
        $this->validationCache->clearAllValidationCache();

        Log::warning("All import cache cleared");
    }

    /**
     * Test cache connectivity and performance
     *
     * @return array
     */
    public function testCachePerformance(): array
    {
        $results = [];

        // Test Redis connectivity
        try {
            $start = microtime(true);
            Cache::store('redis')->put('test_key', 'test_value', 60);
            $value = Cache::store('redis')->get('test_key');
            Cache::store('redis')->forget('test_key');
            $end = microtime(true);

            $results['redis'] = [
                'status' => $value === 'test_value' ? 'OK' : 'FAILED',
                'response_time_ms' => round(($end - $start) * 1000, 2),
            ];
        } catch (\Exception $e) {
            $results['redis'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
            ];
        }

        // Test default cache
        try {
            $start = microtime(true);
            Cache::put('test_key_default', 'test_value', 60);
            $value = Cache::get('test_key_default');
            Cache::forget('test_key_default');
            $end = microtime(true);

            $results['default'] = [
                'status' => $value === 'test_value' ? 'OK' : 'FAILED',
                'response_time_ms' => round(($end - $start) * 1000, 2),
            ];
        } catch (\Exception $e) {
            $results['default'] = [
                'status' => 'ERROR',
                'error' => $e->getMessage(),
            ];
        }

        return $results;
    }
}