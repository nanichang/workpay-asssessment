<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\ImportResumptionLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FileIntegrityService
{
    /**
     * Calculate and store file integrity metadata for an import job.
     *
     * @param ImportJob $job
     * @param string $filePath
     * @return array
     */
    public function calculateFileIntegrity(ImportJob $job, string $filePath): array
    {
        try {
            if (!file_exists($filePath)) {
                throw new \RuntimeException("File not found: {$filePath}");
            }

            $fileSize = filesize($filePath);
            $fileHash = hash_file('sha256', $filePath);
            $lastModified = filemtime($filePath);

            $integrity = [
                'file_size' => $fileSize,
                'file_hash' => $fileHash,
                'file_last_modified' => date('Y-m-d H:i:s', $lastModified),
            ];

            // Update the job with integrity data
            $job->update($integrity);

            Log::info("File integrity calculated for job {$job->id}", $integrity);

            return $integrity;

        } catch (\Exception $e) {
            Log::error("Failed to calculate file integrity for job {$job->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Verify file integrity before resumption.
     *
     * @param ImportJob $job
     * @return array
     */
    public function verifyFileIntegrity(ImportJob $job): array
    {
        $result = [
            'valid' => false,
            'errors' => [],
            'details' => [],
        ];

        try {
            // Check if we have integrity data - if not, try to calculate it for legacy jobs
            if (!$job->file_hash || !$job->file_size) {
                $filePath = $this->getFullFilePath($job->file_path);
                
                if (file_exists($filePath)) {
                    Log::info("Legacy job {$job->id} missing integrity data - calculating now");
                    
                    try {
                        $this->calculateFileIntegrity($job, $filePath);
                        $job->refresh(); // Reload the model with new integrity data
                        
                        $result['valid'] = true;
                        $result['details'][] = 'File integrity calculated for legacy job';
                        $this->logIntegrityCheck($job, true, 'Legacy job integrity calculated successfully');
                        
                        return $result;
                        
                    } catch (\Exception $e) {
                        $result['errors'][] = 'Failed to calculate integrity for legacy job: ' . $e->getMessage();
                        $this->logIntegrityCheck($job, false, 'Failed to calculate legacy job integrity: ' . $e->getMessage());
                        return $result;
                    }
                } else {
                    $result['errors'][] = 'No file integrity data available and file not found';
                    $this->logIntegrityCheck($job, false, 'Missing integrity metadata and file not found');
                    return $result;
                }
            }

            // Get the full file path
            $filePath = $this->getFullFilePath($job->file_path);
            
            // Check if file exists
            if (!file_exists($filePath)) {
                $result['errors'][] = 'Import file no longer exists';
                $this->logIntegrityCheck($job, false, "File not found: {$filePath}");
                return $result;
            }

            // Check file size
            $currentSize = filesize($filePath);
            if ($currentSize !== $job->file_size) {
                $result['errors'][] = "File size mismatch. Expected: {$job->file_size}, Found: {$currentSize}";
                $this->logIntegrityCheck($job, false, "Size mismatch: expected {$job->file_size}, found {$currentSize}");
                return $result;
            }

            // Check file hash
            $currentHash = hash_file('sha256', $filePath);
            if ($currentHash !== $job->file_hash) {
                $result['errors'][] = 'File content has been modified (hash mismatch)';
                $this->logIntegrityCheck($job, false, "Hash mismatch: expected {$job->file_hash}, found {$currentHash}");
                return $result;
            }

            // Check file modification time (optional warning)
            $currentModTime = filemtime($filePath);
            $originalModTime = $job->file_last_modified ? $job->file_last_modified->timestamp : null;
            
            if ($originalModTime && $currentModTime !== $originalModTime) {
                $result['details'][] = 'File modification time changed (but content is intact)';
            }

            // All checks passed
            $result['valid'] = true;
            $result['details'][] = 'File integrity verified successfully';
            
            $this->logIntegrityCheck($job, true, 'All integrity checks passed', [
                'file_size' => $currentSize,
                'file_hash' => $currentHash,
                'mod_time_changed' => $originalModTime && $currentModTime !== $originalModTime,
            ]);

            Log::info("File integrity verified for job {$job->id}");

        } catch (\Exception $e) {
            $result['errors'][] = 'Integrity check failed: ' . $e->getMessage();
            $this->logIntegrityCheck($job, false, "Exception during integrity check: " . $e->getMessage());
            Log::error("File integrity verification failed for job {$job->id}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Get the full file path, handling different storage configurations.
     *
     * @param string $relativePath
     * @return string
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

    /**
     * Check if a file can be safely resumed from a specific row.
     *
     * @param ImportJob $job
     * @param int $resumeFromRow
     * @return array
     */
    public function validateResumptionPoint(ImportJob $job, int $resumeFromRow): array
    {
        $result = [
            'valid' => false,
            'errors' => [],
            'details' => [],
        ];

        try {
            // First verify file integrity
            $integrityResult = $this->verifyFileIntegrity($job);
            if (!$integrityResult['valid']) {
                $result['errors'] = array_merge($result['errors'], $integrityResult['errors']);
                return $result;
            }

            // Validate resumption point
            if ($resumeFromRow < 0) {
                $result['errors'][] = 'Invalid resumption point: row number cannot be negative';
                return $result;
            }

            if ($resumeFromRow > $job->total_rows) {
                $result['errors'][] = "Invalid resumption point: row {$resumeFromRow} exceeds total rows {$job->total_rows}";
                return $result;
            }

            // Check if resumption point makes sense with current progress
            if ($resumeFromRow < $job->last_processed_row) {
                $result['details'][] = "Resumption point {$resumeFromRow} is before last processed row {$job->last_processed_row}. Will reprocess some rows.";
            }

            $result['valid'] = true;
            $result['details'][] = "Resumption point {$resumeFromRow} is valid";

            Log::info("Resumption point validated for job {$job->id}: row {$resumeFromRow}");

        } catch (\Exception $e) {
            $result['errors'][] = 'Resumption validation failed: ' . $e->getMessage();
            Log::error("Resumption point validation failed for job {$job->id}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Create a backup of resumption state.
     *
     * @param ImportJob $job
     * @return array
     */
    public function createResumptionBackup(ImportJob $job): array
    {
        $backup = [
            'job_id' => $job->id,
            'last_processed_row' => $job->last_processed_row,
            'processed_rows' => $job->processed_rows,
            'successful_rows' => $job->successful_rows,
            'error_rows' => $job->error_rows,
            'status' => $job->status,
            'backup_created_at' => now()->toISOString(),
        ];

        // Store backup in resumption_metadata
        $metadata = $job->resumption_metadata ?? [];
        $metadata['backup'] = $backup;
        
        $job->update(['resumption_metadata' => $metadata]);

        Log::info("Resumption backup created for job {$job->id}");

        return $backup;
    }

    /**
     * Restore from resumption backup if needed.
     *
     * @param ImportJob $job
     * @return bool
     */
    public function restoreFromBackup(ImportJob $job): bool
    {
        try {
            $metadata = $job->resumption_metadata ?? [];
            
            if (!isset($metadata['backup'])) {
                Log::info("No backup found for job {$job->id}");
                return false;
            }

            $backup = $metadata['backup'];
            
            $job->update([
                'last_processed_row' => $backup['last_processed_row'],
                'processed_rows' => $backup['processed_rows'],
                'successful_rows' => $backup['successful_rows'],
                'error_rows' => $backup['error_rows'],
                'status' => ImportJob::STATUS_PENDING, // Reset to pending for retry
            ]);

            Log::info("Restored job {$job->id} from backup to row {$backup['last_processed_row']}");

            return true;

        } catch (\Exception $e) {
            Log::error("Failed to restore job {$job->id} from backup: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Clean up integrity data and backups for completed jobs.
     *
     * @param ImportJob $job
     * @return void
     */
    public function cleanupIntegrityData(ImportJob $job): void
    {
        try {
            // Clear resumption metadata for completed jobs
            if ($job->isCompleted() || $job->hasFailed()) {
                $job->update(['resumption_metadata' => null]);
                Log::info("Cleaned up integrity data for completed job {$job->id}");
            }

        } catch (\Exception $e) {
            Log::error("Failed to cleanup integrity data for job {$job->id}: " . $e->getMessage());
        }
    }

    /**
     * Get integrity statistics for monitoring.
     *
     * @param \Carbon\Carbon|null $since
     * @return array
     */
    public function getIntegrityStatistics(?\Carbon\Carbon $since = null): array
    {
        $logs = ImportResumptionLog::byEventType(ImportResumptionLog::EVENT_INTEGRITY_CHECK);
        
        if ($since) {
            $logs->where('created_at', '>=', $since);
        }

        $allLogs = $logs->get();
        $passedLogs = $allLogs->where('metadata.passed', true);
        $failedLogs = $allLogs->where('metadata.passed', false);

        return [
            'total_checks' => $allLogs->count(),
            'passed_checks' => $passedLogs->count(),
            'failed_checks' => $failedLogs->count(),
            'success_rate' => $allLogs->count() > 0 
                ? round(($passedLogs->count() / $allLogs->count()) * 100, 2)
                : 0,
            'common_failures' => $this->getCommonIntegrityFailures($failedLogs),
        ];
    }

    /**
     * Get common integrity failure reasons.
     *
     * @param \Illuminate\Support\Collection $failedLogs
     * @return array
     */
    private function getCommonIntegrityFailures($failedLogs): array
    {
        $failures = [];
        
        foreach ($failedLogs as $log) {
            $reason = $log->details;
            if (!isset($failures[$reason])) {
                $failures[$reason] = 0;
            }
            $failures[$reason]++;
        }

        arsort($failures);
        return array_slice($failures, 0, 5, true); // Top 5 failure reasons
    }

    /**
     * Log an integrity check result.
     *
     * @param ImportJob $job
     * @param bool $passed
     * @param string $details
     * @param array $metadata
     * @return void
     */
    private function logIntegrityCheck(ImportJob $job, bool $passed, string $details, array $metadata = []): void
    {
        ImportResumptionLog::logIntegrityCheck($job->id, $passed, $details, $metadata);
    }
}