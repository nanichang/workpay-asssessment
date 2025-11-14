<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FileMetadataCache
{
    /**
     * Get cache configuration from config
     */
    private function getCacheConfig(): array
    {
        return config('import.cache', [
            'store' => 'redis',
            'ttl' => ['file_metadata' => 7200],
            'prefixes' => ['metadata' => 'import:metadata:']
        ]);
    }

    /**
     * Get cache store instance
     */
    private function getCacheStore(): \Illuminate\Contracts\Cache\Store
    {
        $config = $this->getCacheConfig();
        return Cache::store($config['store']);
    }

    /**
     * Get cache prefix for metadata
     */
    private function getCachePrefix(): string
    {
        $config = $this->getCacheConfig();
        return $config['prefixes']['metadata'];
    }

    /**
     * Get cache TTL for metadata
     */
    private function getCacheTTL(): int
    {
        $config = $this->getCacheConfig();
        return $config['ttl']['file_metadata'];
    }

    /**
     * Cache file metadata
     *
     * @param string $filePath
     * @param array $metadata
     * @return void
     */
    public function cacheFileMetadata(string $filePath, array $metadata): void
    {
        $cacheKey = $this->generateFileCacheKey($filePath);
        
        $cacheData = [
            'file_path' => $filePath,
            'file_size' => $metadata['file_size'] ?? 0,
            'file_type' => $metadata['file_type'] ?? 'unknown',
            'total_rows' => $metadata['total_rows'] ?? 0,
            'headers' => $metadata['headers'] ?? [],
            'encoding' => $metadata['encoding'] ?? 'UTF-8',
            'delimiter' => $metadata['delimiter'] ?? ',',
            'has_header_row' => $metadata['has_header_row'] ?? true,
            'estimated_processing_time' => $metadata['estimated_processing_time'] ?? null,
            'file_hash' => $metadata['file_hash'] ?? null,
            'cached_at' => now()->toISOString(),
        ];

        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL());
        
        Log::debug("Cached file metadata for: {$filePath}");
    }

    /**
     * Get cached file metadata
     *
     * @param string $filePath
     * @return array|null
     */
    public function getCachedFileMetadata(string $filePath): ?array
    {
        $cacheKey = $this->generateFileCacheKey($filePath);
        $cached = $this->getCacheStore()->get($cacheKey);
        
        if ($cached) {
            Log::debug("Retrieved cached file metadata for: {$filePath}");
            
            // Verify file hasn't changed since caching
            if ($this->isFileMetadataValid($filePath, $cached)) {
                return $cached;
            } else {
                Log::info("File metadata cache invalid for: {$filePath} - file may have changed");
                $this->clearFileMetadata($filePath);
                return null;
            }
        }
        
        return null;
    }

    /**
     * Cache file processing checkpoint
     *
     * @param string $importJobId
     * @param array $checkpoint
     * @return void
     */
    public function cacheProcessingCheckpoint(string $importJobId, array $checkpoint): void
    {
        $cacheKey = $this->getCachePrefix() . 'checkpoint:' . $importJobId;
        
        $cacheData = [
            'import_job_id' => $importJobId,
            'last_processed_row' => $checkpoint['last_processed_row'] ?? 0,
            'current_position' => $checkpoint['current_position'] ?? 0,
            'batch_number' => $checkpoint['batch_number'] ?? 0,
            'processed_rows' => $checkpoint['processed_rows'] ?? 0,
            'successful_rows' => $checkpoint['successful_rows'] ?? 0,
            'error_rows' => $checkpoint['error_rows'] ?? 0,
            'processing_state' => $checkpoint['processing_state'] ?? 'active',
            'memory_usage' => $checkpoint['memory_usage'] ?? 0,
            'cached_at' => now()->toISOString(),
        ];

        // Use shorter TTL for checkpoints as they change frequently
        $checkpointTTL = min($this->getCacheTTL(), 1800); // Max 30 minutes
        $this->getCacheStore()->put($cacheKey, $cacheData, $checkpointTTL);
        
        Log::debug("Cached processing checkpoint for import job: {$importJobId}");
    }

    /**
     * Get cached processing checkpoint
     *
     * @param string $importJobId
     * @return array|null
     */
    public function getCachedProcessingCheckpoint(string $importJobId): ?array
    {
        $cacheKey = $this->getCachePrefix() . 'checkpoint:' . $importJobId;
        $cached = $this->getCacheStore()->get($cacheKey);
        
        if ($cached) {
            Log::debug("Retrieved cached processing checkpoint for import job: {$importJobId}");
        }
        
        return $cached;
    }

    /**
     * Cache file headers validation result
     *
     * @param string $filePath
     * @param array $headers
     * @param bool $isValid
     * @param array $validationErrors
     * @return void
     */
    public function cacheHeadersValidation(string $filePath, array $headers, bool $isValid, array $validationErrors = []): void
    {
        $cacheKey = $this->getCachePrefix() . 'headers:' . md5($filePath);
        
        $cacheData = [
            'file_path' => $filePath,
            'headers' => $headers,
            'is_valid' => $isValid,
            'validation_errors' => $validationErrors,
            'expected_headers' => $this->getExpectedHeaders(),
            'cached_at' => now()->toISOString(),
        ];

        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL());
        
        Log::debug("Cached headers validation for: {$filePath}");
    }

    /**
     * Get cached headers validation result
     *
     * @param string $filePath
     * @return array|null
     */
    public function getCachedHeadersValidation(string $filePath): ?array
    {
        $cacheKey = $this->getCachePrefix() . 'headers:' . md5($filePath);
        return $this->getCacheStore()->get($cacheKey);
    }

    /**
     * Cache file chunk information
     *
     * @param string $importJobId
     * @param array $chunkInfo
     * @return void
     */
    public function cacheChunkInfo(string $importJobId, array $chunkInfo): void
    {
        $cacheKey = $this->getCachePrefix() . 'chunks:' . $importJobId;
        
        $cacheData = [
            'import_job_id' => $importJobId,
            'total_chunks' => $chunkInfo['total_chunks'] ?? 0,
            'chunk_size' => $chunkInfo['chunk_size'] ?? 0,
            'completed_chunks' => $chunkInfo['completed_chunks'] ?? 0,
            'current_chunk' => $chunkInfo['current_chunk'] ?? 0,
            'chunk_boundaries' => $chunkInfo['chunk_boundaries'] ?? [],
            'cached_at' => now()->toISOString(),
        ];

        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL());
        
        Log::debug("Cached chunk info for import job: {$importJobId}");
    }

    /**
     * Get cached chunk information
     *
     * @param string $importJobId
     * @return array|null
     */
    public function getCachedChunkInfo(string $importJobId): ?array
    {
        $cacheKey = $this->getCachePrefix() . 'chunks:' . $importJobId;
        return $this->getCacheStore()->get($cacheKey);
    }

    /**
     * Clear file metadata cache
     *
     * @param string $filePath
     * @return void
     */
    public function clearFileMetadata(string $filePath): void
    {
        $cacheKey = $this->generateFileCacheKey($filePath);
        $this->getCacheStore()->forget($cacheKey);
        
        Log::debug("Cleared file metadata cache for: {$filePath}");
    }

    /**
     * Clear processing checkpoint cache
     *
     * @param string $importJobId
     * @return void
     */
    public function clearProcessingCheckpoint(string $importJobId): void
    {
        $checkpointKey = $this->getCachePrefix() . 'checkpoint:' . $importJobId;
        $chunksKey = $this->getCachePrefix() . 'chunks:' . $importJobId;
        
        $this->getCacheStore()->forget($checkpointKey);
        $this->getCacheStore()->forget($chunksKey);
        
        Log::debug("Cleared processing checkpoint cache for import job: {$importJobId}");
    }

    /**
     * Clear all metadata cache for an import job
     *
     * @param string $importJobId
     * @return void
     */
    public function clearImportMetadataCache(string $importJobId): void
    {
        $this->clearProcessingCheckpoint($importJobId);
        
        Log::info("Cleared all metadata cache for import job: {$importJobId}");
    }

    /**
     * Get metadata cache statistics
     *
     * @return array
     */
    public function getMetadataCacheStats(): array
    {
        return [
            'cache_store' => $this->getCacheConfig()['store'],
            'cache_prefix' => $this->getCachePrefix(),
            'cache_ttl' => $this->getCacheTTL(),
            'last_checked' => now()->toISOString(),
        ];
    }

    /**
     * Generate cache key for file metadata
     *
     * @param string $filePath
     * @return string
     */
    private function generateFileCacheKey(string $filePath): string
    {
        return $this->getCachePrefix() . 'file:' . md5($filePath);
    }

    /**
     * Check if cached file metadata is still valid
     *
     * @param string $filePath
     * @param array $cachedMetadata
     * @return bool
     */
    private function isFileMetadataValid(string $filePath, array $cachedMetadata): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        // Check if file size has changed
        $currentSize = filesize($filePath);
        $cachedSize = $cachedMetadata['file_size'] ?? 0;
        
        if ($currentSize !== $cachedSize) {
            return false;
        }

        // Check if file modification time is newer than cache time
        $fileModTime = filemtime($filePath);
        $cacheTime = isset($cachedMetadata['cached_at']) 
            ? strtotime($cachedMetadata['cached_at']) 
            : 0;
        
        if ($fileModTime > $cacheTime) {
            return false;
        }

        return true;
    }

    /**
     * Get expected headers for employee CSV files
     *
     * @return array
     */
    private function getExpectedHeaders(): array
    {
        return [
            'employee_number',
            'first_name',
            'last_name',
            'email',
            'department',
            'salary',
            'currency',
            'country_code',
            'start_date'
        ];
    }

    /**
     * Estimate processing time based on file metadata
     *
     * @param array $metadata
     * @return int Estimated seconds
     */
    public function estimateProcessingTime(array $metadata): int
    {
        $totalRows = $metadata['total_rows'] ?? 0;
        $fileType = $metadata['file_type'] ?? 'csv';
        
        // Base processing rate (rows per second)
        $baseRate = $fileType === 'csv' ? 50 : 30; // Excel is slower
        
        // Adjust for file size
        $fileSize = $metadata['file_size'] ?? 0;
        if ($fileSize > 10 * 1024 * 1024) { // > 10MB
            $baseRate *= 0.8; // 20% slower for large files
        }
        
        $estimatedSeconds = max(1, ceil($totalRows / $baseRate));
        
        // Add overhead for validation and database operations
        $estimatedSeconds = ceil($estimatedSeconds * 1.5);
        
        return $estimatedSeconds;
    }
}