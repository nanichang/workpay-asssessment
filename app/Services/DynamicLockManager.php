<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\ImportResumptionLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DynamicLockManager
{
    /**
     * Default lock timeout in seconds
     */
    private const DEFAULT_TIMEOUT = 3600; // 1 hour

    /**
     * Lock renewal interval in seconds
     */
    private const RENEWAL_INTERVAL = 300; // 5 minutes

    /**
     * Maximum lock timeout in seconds
     */
    private const MAX_TIMEOUT = 14400; // 4 hours

    /**
     * Acquire a processing lock with dynamic timeout based on job characteristics.
     *
     * @param ImportJob $job
     * @return array
     */
    public function acquireProcessingLock(ImportJob $job): array
    {
        $lockKey = $this->getLockKey($job->id);
        $timeout = $this->calculateLockTimeout($job);
        
        $acquired = Cache::lock($lockKey, $timeout)->get();
        
        $result = [
            'acquired' => $acquired,
            'lock_key' => $lockKey,
            'timeout' => $timeout,
            'expires_at' => now()->addSeconds($timeout),
        ];

        if ($acquired) {
            // Store lock metadata for renewal
            $this->storeLockMetadata($job->id, $result);
            
            ImportResumptionLog::logLockRenewal($job->id, true, [
                'action' => 'acquire',
                'timeout' => $timeout,
                'expires_at' => $result['expires_at']->toISOString(),
            ]);
            
            Log::info("Processing lock acquired for job {$job->id} with {$timeout}s timeout");
        } else {
            ImportResumptionLog::logLockRenewal($job->id, false, [
                'action' => 'acquire_failed',
                'timeout' => $timeout,
            ]);
            
            Log::warning("Failed to acquire processing lock for job {$job->id}");
        }

        return $result;
    }

    /**
     * Renew an existing processing lock.
     *
     * @param ImportJob $job
     * @return array
     */
    public function renewProcessingLock(ImportJob $job): array
    {
        $lockKey = $this->getLockKey($job->id);
        $metadata = $this->getLockMetadata($job->id);
        
        if (!$metadata) {
            return [
                'renewed' => false,
                'error' => 'No lock metadata found',
            ];
        }

        // Calculate new timeout (may be different based on current progress)
        $newTimeout = $this->calculateLockTimeout($job);
        
        // Try to renew the lock
        $lock = Cache::lock($lockKey, $newTimeout);
        $renewed = $lock->get();
        
        $result = [
            'renewed' => $renewed,
            'lock_key' => $lockKey,
            'timeout' => $newTimeout,
            'expires_at' => $renewed ? now()->addSeconds($newTimeout) : null,
        ];

        if ($renewed) {
            // Update lock metadata
            $this->storeLockMetadata($job->id, $result);
            
            ImportResumptionLog::logLockRenewal($job->id, true, [
                'action' => 'renew',
                'timeout' => $newTimeout,
                'expires_at' => $result['expires_at']->toISOString(),
            ]);
            
            Log::info("Processing lock renewed for job {$job->id} with {$newTimeout}s timeout");
        } else {
            ImportResumptionLog::logLockRenewal($job->id, false, [
                'action' => 'renew_failed',
                'timeout' => $newTimeout,
            ]);
            
            Log::warning("Failed to renew processing lock for job {$job->id}");
        }

        return $result;
    }

    /**
     * Release a processing lock.
     *
     * @param ImportJob $job
     * @return bool
     */
    public function releaseProcessingLock(ImportJob $job): bool
    {
        $lockKey = $this->getLockKey($job->id);
        
        try {
            Cache::lock($lockKey)->release();
            $this->clearLockMetadata($job->id);
            
            ImportResumptionLog::logLockRenewal($job->id, true, [
                'action' => 'release',
            ]);
            
            Log::info("Processing lock released for job {$job->id}");
            return true;
            
        } catch (\Exception $e) {
            ImportResumptionLog::logLockRenewal($job->id, false, [
                'action' => 'release_failed',
                'error' => $e->getMessage(),
            ]);
            
            Log::error("Failed to release processing lock for job {$job->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a lock needs renewal.
     *
     * @param ImportJob $job
     * @return bool
     */
    public function needsRenewal(ImportJob $job): bool
    {
        $metadata = $this->getLockMetadata($job->id);
        
        if (!$metadata || !isset($metadata['expires_at'])) {
            return false;
        }

        $expiresAt = \Carbon\Carbon::parse($metadata['expires_at']);
        $renewalThreshold = $expiresAt->subSeconds(self::RENEWAL_INTERVAL);
        
        return now()->gte($renewalThreshold);
    }

    /**
     * Get lock status for a job.
     *
     * @param ImportJob $job
     * @return array
     */
    public function getLockStatus(ImportJob $job): array
    {
        $lockKey = $this->getLockKey($job->id);
        $metadata = $this->getLockMetadata($job->id);
        
        $status = [
            'lock_key' => $lockKey,
            'has_metadata' => $metadata !== null,
            'is_locked' => false,
            'expires_at' => null,
            'time_remaining' => null,
            'needs_renewal' => false,
        ];

        if ($metadata && isset($metadata['expires_at'])) {
            $expiresAt = \Carbon\Carbon::parse($metadata['expires_at']);
            $status['expires_at'] = $expiresAt->toISOString();
            $status['time_remaining'] = max(0, $expiresAt->diffInSeconds(now()));
            $status['is_locked'] = $status['time_remaining'] > 0;
            $status['needs_renewal'] = $this->needsRenewal($job);
        }

        return $status;
    }

    /**
     * Calculate appropriate lock timeout based on job characteristics.
     *
     * @param ImportJob $job
     * @return int
     */
    private function calculateLockTimeout(ImportJob $job): int
    {
        $baseTimeout = self::DEFAULT_TIMEOUT;
        
        // Adjust based on file size
        if ($job->total_rows > 0) {
            if ($job->total_rows > 50000) {
                $baseTimeout = 7200; // 2 hours for very large files
            } elseif ($job->total_rows > 10000) {
                $baseTimeout = 3600; // 1 hour for large files
            } elseif ($job->total_rows > 1000) {
                $baseTimeout = 1800; // 30 minutes for medium files
            } else {
                $baseTimeout = 900; // 15 minutes for small files
            }
        }

        // Adjust based on current progress and processing rate
        if ($job->processed_rows > 0 && $job->started_at) {
            $elapsedMinutes = $job->started_at->diffInMinutes(now());
            if ($elapsedMinutes > 0) {
                $rowsPerMinute = $job->processed_rows / $elapsedMinutes;
                $remainingRows = $job->total_rows - $job->processed_rows;
                
                if ($rowsPerMinute > 0) {
                    $estimatedMinutesRemaining = $remainingRows / $rowsPerMinute;
                    $estimatedTimeout = (int) ($estimatedMinutesRemaining * 60 * 1.5); // 50% buffer
                    
                    // Use the larger of base timeout or estimated timeout
                    $baseTimeout = max($baseTimeout, $estimatedTimeout);
                }
            }
        }

        // Adjust based on error rate (more errors = more time needed)
        if ($job->processed_rows > 0) {
            $errorRate = $job->error_rows / $job->processed_rows;
            if ($errorRate > 0.1) { // More than 10% errors
                $baseTimeout = (int) ($baseTimeout * 1.3); // 30% more time
            }
        }

        // Ensure timeout is within reasonable bounds
        return min(max($baseTimeout, 300), self::MAX_TIMEOUT); // Min 5 minutes, max 4 hours
    }

    /**
     * Get the cache key for a job's lock.
     *
     * @param string $jobId
     * @return string
     */
    private function getLockKey(string $jobId): string
    {
        return "import_processing:{$jobId}";
    }

    /**
     * Get the cache key for lock metadata.
     *
     * @param string $jobId
     * @return string
     */
    private function getLockMetadataKey(string $jobId): string
    {
        return "import_lock_meta:{$jobId}";
    }

    /**
     * Store lock metadata for renewal tracking.
     *
     * @param string $jobId
     * @param array $metadata
     * @return void
     */
    private function storeLockMetadata(string $jobId, array $metadata): void
    {
        $key = $this->getLockMetadataKey($jobId);
        $ttl = $metadata['timeout'] + 300; // Store metadata slightly longer than lock
        
        Cache::put($key, $metadata, $ttl);
    }

    /**
     * Get lock metadata.
     *
     * @param string $jobId
     * @return array|null
     */
    private function getLockMetadata(string $jobId): ?array
    {
        $key = $this->getLockMetadataKey($jobId);
        return Cache::get($key);
    }

    /**
     * Clear lock metadata.
     *
     * @param string $jobId
     * @return void
     */
    private function clearLockMetadata(string $jobId): void
    {
        $key = $this->getLockMetadataKey($jobId);
        Cache::forget($key);
    }

    /**
     * Get lock statistics for monitoring.
     *
     * @param \Carbon\Carbon|null $since
     * @return array
     */
    public function getLockStatistics(?\Carbon\Carbon $since = null): array
    {
        $logs = ImportResumptionLog::byEventType(ImportResumptionLog::EVENT_LOCK_RENEWAL);
        
        if ($since) {
            $logs->where('created_at', '>=', $since);
        }

        $allLogs = $logs->get();
        
        $acquires = $allLogs->where('metadata.action', 'acquire');
        $renewals = $allLogs->where('metadata.action', 'renew');
        $releases = $allLogs->where('metadata.action', 'release');
        
        $successfulAcquires = $acquires->where('metadata.successful', true);
        $successfulRenewals = $renewals->where('metadata.successful', true);
        $successfulReleases = $releases->where('metadata.successful', true);

        return [
            'total_lock_operations' => $allLogs->count(),
            'lock_acquires' => $acquires->count(),
            'lock_renewals' => $renewals->count(),
            'lock_releases' => $releases->count(),
            'successful_acquires' => $successfulAcquires->count(),
            'successful_renewals' => $successfulRenewals->count(),
            'successful_releases' => $successfulReleases->count(),
            'acquire_success_rate' => $acquires->count() > 0 
                ? round(($successfulAcquires->count() / $acquires->count()) * 100, 2)
                : 0,
            'renewal_success_rate' => $renewals->count() > 0 
                ? round(($successfulRenewals->count() / $renewals->count()) * 100, 2)
                : 0,
        ];
    }

    /**
     * Clean up expired lock metadata.
     *
     * @return int Number of cleaned up locks
     */
    public function cleanupExpiredLocks(): int
    {
        // This would require a more sophisticated implementation
        // For now, we rely on cache TTL to handle cleanup
        Log::info("Lock cleanup completed (handled by cache TTL)");
        return 0;
    }
}