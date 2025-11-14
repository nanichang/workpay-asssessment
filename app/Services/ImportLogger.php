<?php

namespace App\Services;

use App\Models\ImportJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class ImportLogger
{
    /**
     * Get log configuration from config
     */
    private function getLogConfig(): array
    {
        return config('import.monitoring.log_levels', [
            'job_start' => 'info',
            'job_complete' => 'info',
            'job_failed' => 'error',
            'validation_errors' => 'warning',
            'performance' => 'debug',
        ]);
    }

    /**
     * Check if performance logging is enabled
     */
    private function isPerformanceLoggingEnabled(): bool
    {
        return config('import.monitoring.performance_logging', true);
    }

    /**
     * Log import job start
     *
     * @param ImportJob $importJob
     * @param array $context
     * @return void
     */
    public function logJobStart(ImportJob $importJob, array $context = []): void
    {
        $level = $this->getLogConfig()['job_start'];
        
        $logData = array_merge([
            'event' => 'import_job_started',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'total_rows' => $importJob->total_rows,
            'file_size_bytes' => file_exists($importJob->file_path) ? filesize($importJob->file_path) : 0,
            'started_at' => $importJob->started_at?->toISOString(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ], $context);

        Log::log($level, "Import job started: {$importJob->filename}", $logData);

        // Store start metrics for performance tracking
        if ($this->isPerformanceLoggingEnabled()) {
            $this->storePerformanceMetric($importJob->id, 'start', $logData);
        }
    }

    /**
     * Log import job completion
     *
     * @param ImportJob $importJob
     * @param array $context
     * @return void
     */
    public function logJobComplete(ImportJob $importJob, array $context = []): void
    {
        $level = $this->getLogConfig()['job_complete'];
        
        $duration = $importJob->started_at ? $importJob->started_at->diffInSeconds($importJob->completed_at) : 0;
        $processingRate = $duration > 0 ? round($importJob->processed_rows / $duration, 2) : 0;

        $logData = array_merge([
            'event' => 'import_job_completed',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'total_rows' => $importJob->total_rows,
            'processed_rows' => $importJob->processed_rows,
            'successful_rows' => $importJob->successful_rows,
            'error_rows' => $importJob->error_rows,
            'duration_seconds' => $duration,
            'processing_rate_rows_per_second' => $processingRate,
            'success_rate_percent' => $importJob->total_rows > 0 ? round(($importJob->successful_rows / $importJob->total_rows) * 100, 2) : 0,
            'completed_at' => $importJob->completed_at?->toISOString(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ], $context);

        Log::log($level, "Import job completed: {$importJob->filename}", $logData);

        // Store completion metrics
        if ($this->isPerformanceLoggingEnabled()) {
            $this->storePerformanceMetric($importJob->id, 'complete', $logData);
        }
    }

    /**
     * Log import job failure
     *
     * @param ImportJob $importJob
     * @param \Throwable $exception
     * @param array $context
     * @return void
     */
    public function logJobFailure(ImportJob $importJob, \Throwable $exception, array $context = []): void
    {
        $level = $this->getLogConfig()['job_failed'];
        
        $duration = $importJob->started_at ? $importJob->started_at->diffInSeconds(now()) : 0;

        $logData = array_merge([
            'event' => 'import_job_failed',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'total_rows' => $importJob->total_rows,
            'processed_rows' => $importJob->processed_rows,
            'successful_rows' => $importJob->successful_rows,
            'error_rows' => $importJob->error_rows,
            'duration_seconds' => $duration,
            'exception_class' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'exception_file' => $exception->getFile(),
            'exception_line' => $exception->getLine(),
            'failed_at' => now()->toISOString(),
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ], $context);

        Log::log($level, "Import job failed: {$importJob->filename} - {$exception->getMessage()}", $logData);

        // Store failure metrics
        if ($this->isPerformanceLoggingEnabled()) {
            $this->storePerformanceMetric($importJob->id, 'failed', $logData);
        }
    }

    /**
     * Log validation errors
     *
     * @param ImportJob $importJob
     * @param int $rowNumber
     * @param array $errors
     * @param array $rowData
     * @return void
     */
    public function logValidationErrors(ImportJob $importJob, int $rowNumber, array $errors, array $rowData = []): void
    {
        $level = $this->getLogConfig()['validation_errors'];
        
        $logData = [
            'event' => 'validation_errors',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'row_number' => $rowNumber,
            'error_count' => count($errors),
            'errors' => $errors,
            'row_data' => $this->sanitizeRowData($rowData),
        ];

        Log::log($level, "Validation errors in row {$rowNumber} of {$importJob->filename}", $logData);
    }

    /**
     * Log performance metrics
     *
     * @param ImportJob $importJob
     * @param array $metrics
     * @return void
     */
    public function logPerformanceMetrics(ImportJob $importJob, array $metrics): void
    {
        if (!$this->isPerformanceLoggingEnabled()) {
            return;
        }

        $level = $this->getLogConfig()['performance'];
        
        $logData = array_merge([
            'event' => 'performance_metrics',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'timestamp' => now()->toISOString(),
        ], $metrics);

        Log::log($level, "Performance metrics for {$importJob->filename}", $logData);

        // Store performance metrics for analysis
        $this->storePerformanceMetric($importJob->id, 'performance', $logData);
    }

    /**
     * Log chunk processing progress
     *
     * @param ImportJob $importJob
     * @param int $chunkNumber
     * @param int $chunkSize
     * @param float $chunkProcessingTime
     * @param array $chunkStats
     * @return void
     */
    public function logChunkProgress(ImportJob $importJob, int $chunkNumber, int $chunkSize, float $chunkProcessingTime, array $chunkStats = []): void
    {
        if (!$this->isPerformanceLoggingEnabled()) {
            return;
        }

        $level = $this->getLogConfig()['performance'];
        
        $logData = array_merge([
            'event' => 'chunk_processed',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'chunk_number' => $chunkNumber,
            'chunk_size' => $chunkSize,
            'processing_time_seconds' => round($chunkProcessingTime, 3),
            'rows_per_second' => $chunkProcessingTime > 0 ? round($chunkSize / $chunkProcessingTime, 2) : 0,
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
        ], $chunkStats);

        Log::log($level, "Processed chunk {$chunkNumber} for {$importJob->filename}", $logData);
    }

    /**
     * Log duplicate detection results
     *
     * @param ImportJob $importJob
     * @param array $duplicateStats
     * @return void
     */
    public function logDuplicateDetection(ImportJob $importJob, array $duplicateStats): void
    {
        $level = $this->getLogConfig()['validation_errors'];
        
        $logData = array_merge([
            'event' => 'duplicate_detection',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
        ], $duplicateStats);

        Log::log($level, "Duplicate detection results for {$importJob->filename}", $logData);
    }

    /**
     * Log file processing start
     *
     * @param string $filePath
     * @param array $fileMetadata
     * @return void
     */
    public function logFileProcessingStart(string $filePath, array $fileMetadata): void
    {
        $level = $this->getLogConfig()['job_start'];
        
        $logData = array_merge([
            'event' => 'file_processing_start',
            'file_path' => $filePath,
            'file_size_bytes' => $fileMetadata['file_size'] ?? 0,
            'file_type' => $fileMetadata['file_type'] ?? 'unknown',
            'estimated_rows' => $fileMetadata['total_rows'] ?? 0,
        ], $fileMetadata);

        Log::log($level, "Starting file processing: " . basename($filePath), $logData);
    }

    /**
     * Log memory usage warnings
     *
     * @param ImportJob $importJob
     * @param int $currentMemoryMB
     * @param int $memoryLimitMB
     * @return void
     */
    public function logMemoryWarning(ImportJob $importJob, int $currentMemoryMB, int $memoryLimitMB): void
    {
        $logData = [
            'event' => 'memory_warning',
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'current_memory_mb' => $currentMemoryMB,
            'memory_limit_mb' => $memoryLimitMB,
            'memory_usage_percent' => round(($currentMemoryMB / $memoryLimitMB) * 100, 2),
            'processed_rows' => $importJob->processed_rows,
        ];

        Log::warning("High memory usage detected for {$importJob->filename}", $logData);
    }

    /**
     * Store performance metric for analysis
     *
     * @param string $importJobId
     * @param string $metricType
     * @param array $data
     * @return void
     */
    private function storePerformanceMetric(string $importJobId, string $metricType, array $data): void
    {
        if (!config('import.monitoring.metrics.enabled', true)) {
            return;
        }

        $cacheKey = "import:metrics:{$importJobId}:{$metricType}";
        $ttl = 86400; // 24 hours

        Cache::put($cacheKey, $data, $ttl);
    }

    /**
     * Get performance metrics for an import job
     *
     * @param string $importJobId
     * @return array
     */
    public function getPerformanceMetrics(string $importJobId): array
    {
        $metrics = [];
        $metricTypes = ['start', 'complete', 'failed', 'performance'];

        foreach ($metricTypes as $type) {
            $cacheKey = "import:metrics:{$importJobId}:{$type}";
            $data = Cache::get($cacheKey);
            
            if ($data) {
                $metrics[$type] = $data;
            }
        }

        return $metrics;
    }

    /**
     * Sanitize row data for logging (remove sensitive information)
     *
     * @param array $rowData
     * @return array
     */
    private function sanitizeRowData(array $rowData): array
    {
        $sanitized = $rowData;

        // Mask email addresses for privacy
        if (isset($sanitized['email'])) {
            $email = $sanitized['email'];
            if (str_contains($email, '@')) {
                $parts = explode('@', $email);
                $sanitized['email'] = substr($parts[0], 0, 2) . '***@' . $parts[1];
            }
        }

        // Mask salary information
        if (isset($sanitized['salary'])) {
            $sanitized['salary'] = '***';
        }

        return $sanitized;
    }

    /**
     * Generate import summary report
     *
     * @param ImportJob $importJob
     * @return array
     */
    public function generateImportSummary(ImportJob $importJob): array
    {
        $duration = $importJob->started_at && $importJob->completed_at 
            ? $importJob->started_at->diffInSeconds($importJob->completed_at) 
            : 0;

        $summary = [
            'import_job_id' => $importJob->id,
            'filename' => $importJob->filename,
            'status' => $importJob->status,
            'total_rows' => $importJob->total_rows,
            'processed_rows' => $importJob->processed_rows,
            'successful_rows' => $importJob->successful_rows,
            'error_rows' => $importJob->error_rows,
            'skipped_rows' => $importJob->processed_rows - $importJob->successful_rows - $importJob->error_rows,
            'success_rate_percent' => $importJob->total_rows > 0 ? round(($importJob->successful_rows / $importJob->total_rows) * 100, 2) : 0,
            'duration_seconds' => $duration,
            'processing_rate_rows_per_second' => $duration > 0 ? round($importJob->processed_rows / $duration, 2) : 0,
            'started_at' => $importJob->started_at?->toISOString(),
            'completed_at' => $importJob->completed_at?->toISOString(),
        ];

        // Add performance metrics if available
        $performanceMetrics = $this->getPerformanceMetrics($importJob->id);
        if (!empty($performanceMetrics)) {
            $summary['performance_metrics'] = $performanceMetrics;
        }

        return $summary;
    }

    /**
     * Log import summary
     *
     * @param ImportJob $importJob
     * @return void
     */
    public function logImportSummary(ImportJob $importJob): void
    {
        $summary = $this->generateImportSummary($importJob);
        
        $level = $importJob->isCompleted() ? 'info' : 'warning';
        
        Log::log($level, "Import summary for {$importJob->filename}", $summary);
    }
}