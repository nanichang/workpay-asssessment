<?php

namespace App\Services;

use App\Models\ImportJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ImportMonitoringService
{
    private ImportLogger $logger;

    public function __construct(ImportLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Get monitoring configuration
     */
    private function getMonitoringConfig(): array
    {
        return config('import.monitoring', [
            'metrics' => ['enabled' => true, 'batch_size' => 100],
            'error_tracking' => ['enabled' => true, 'max_errors_per_job' => 1000],
        ]);
    }

    /**
     * Collect and store system metrics
     *
     * @return array
     */
    public function collectSystemMetrics(): array
    {
        $metrics = [
            'timestamp' => now()->toISOString(),
            'memory' => $this->getMemoryMetrics(),
            'database' => $this->getDatabaseMetrics(),
            'queue' => $this->getQueueMetrics(),
            'cache' => $this->getCacheMetrics(),
            'import_jobs' => $this->getImportJobMetrics(),
        ];

        // Store metrics in cache for dashboard access
        $this->storeSystemMetrics($metrics);

        return $metrics;
    }

    /**
     * Get memory usage metrics
     *
     * @return array
     */
    private function getMemoryMetrics(): array
    {
        return [
            'current_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'limit_mb' => $this->getMemoryLimitMB(),
            'usage_percent' => $this->getMemoryUsagePercent(),
        ];
    }

    /**
     * Get database performance metrics
     *
     * @return array
     */
    private function getDatabaseMetrics(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1');
            $responseTime = (microtime(true) - $start) * 1000;

            // Get connection info
            $connectionName = DB::getDefaultConnection();
            $config = config("database.connections.{$connectionName}");

            return [
                'connection' => $connectionName,
                'driver' => $config['driver'] ?? 'unknown',
                'response_time_ms' => round($responseTime, 2),
                'status' => 'connected',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'response_time_ms' => null,
            ];
        }
    }

    /**
     * Get queue system metrics
     *
     * @return array
     */
    private function getQueueMetrics(): array
    {
        try {
            // Get job counts from database queue
            $pendingJobs = DB::table('jobs')->count();
            $failedJobs = DB::table('failed_jobs')->count();

            // Get import-specific job counts
            $importJobs = [
                'pending' => ImportJob::where('status', ImportJob::STATUS_PENDING)->count(),
                'processing' => ImportJob::where('status', ImportJob::STATUS_PROCESSING)->count(),
                'completed' => ImportJob::where('status', ImportJob::STATUS_COMPLETED)->count(),
                'failed' => ImportJob::where('status', ImportJob::STATUS_FAILED)->count(),
            ];

            return [
                'total_pending_jobs' => $pendingJobs,
                'total_failed_jobs' => $failedJobs,
                'import_jobs' => $importJobs,
                'status' => 'operational',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get cache system metrics
     *
     * @return array
     */
    private function getCacheMetrics(): array
    {
        try {
            $start = microtime(true);
            Cache::put('monitoring_test', 'test_value', 60);
            $value = Cache::get('monitoring_test');
            Cache::forget('monitoring_test');
            $responseTime = (microtime(true) - $start) * 1000;

            return [
                'default_store' => config('cache.default'),
                'response_time_ms' => round($responseTime, 2),
                'status' => $value === 'test_value' ? 'operational' : 'degraded',
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
                'response_time_ms' => null,
            ];
        }
    }

    /**
     * Get import job performance metrics
     *
     * @return array
     */
    private function getImportJobMetrics(): array
    {
        try {
            $today = now()->startOfDay();
            
            return [
                'today' => [
                    'total_started' => ImportJob::whereDate('created_at', $today)->count(),
                    'completed' => ImportJob::whereDate('completed_at', $today)->count(),
                    'failed' => ImportJob::where('status', ImportJob::STATUS_FAILED)
                        ->whereDate('updated_at', $today)->count(),
                    'rows_processed' => ImportJob::whereDate('updated_at', $today)->sum('successful_rows'),
                ],
                'active' => [
                    'processing' => ImportJob::where('status', ImportJob::STATUS_PROCESSING)->count(),
                    'pending' => ImportJob::where('status', ImportJob::STATUS_PENDING)->count(),
                ],
                'performance' => $this->calculatePerformanceMetrics(),
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate performance metrics for recent imports
     *
     * @return array
     */
    private function calculatePerformanceMetrics(): array
    {
        $recentCompleted = ImportJob::where('status', ImportJob::STATUS_COMPLETED)
            ->where('completed_at', '>=', now()->subHours(24))
            ->get();

        if ($recentCompleted->isEmpty()) {
            return [
                'avg_processing_time_seconds' => 0,
                'avg_rows_per_second' => 0,
                'avg_success_rate_percent' => 0,
                'sample_size' => 0,
            ];
        }

        $totalProcessingTime = 0;
        $totalRowsPerSecond = 0;
        $totalSuccessRate = 0;
        $validSamples = 0;

        foreach ($recentCompleted as $job) {
            if ($job->started_at && $job->completed_at) {
                $duration = $job->started_at->diffInSeconds($job->completed_at);
                $totalProcessingTime += $duration;
                
                if ($duration > 0) {
                    $totalRowsPerSecond += $job->processed_rows / $duration;
                }
                
                if ($job->total_rows > 0) {
                    $totalSuccessRate += ($job->successful_rows / $job->total_rows) * 100;
                }
                
                $validSamples++;
            }
        }

        return [
            'avg_processing_time_seconds' => $validSamples > 0 ? round($totalProcessingTime / $validSamples, 2) : 0,
            'avg_rows_per_second' => $validSamples > 0 ? round($totalRowsPerSecond / $validSamples, 2) : 0,
            'avg_success_rate_percent' => $validSamples > 0 ? round($totalSuccessRate / $validSamples, 2) : 0,
            'sample_size' => $validSamples,
        ];
    }

    /**
     * Monitor import job health
     *
     * @param ImportJob $importJob
     * @return array
     */
    public function monitorJobHealth(ImportJob $importJob): array
    {
        $health = [
            'job_id' => $importJob->id,
            'status' => $importJob->status,
            'health_score' => 100,
            'issues' => [],
            'recommendations' => [],
        ];

        // Check processing time
        if ($importJob->started_at) {
            $processingTime = $importJob->started_at->diffInSeconds(now());
            $expectedTime = $this->estimateProcessingTime($importJob);
            
            if ($processingTime > $expectedTime * 2) {
                $health['health_score'] -= 30;
                $health['issues'][] = 'Processing time exceeds expected duration';
                $health['recommendations'][] = 'Consider checking system resources or file complexity';
            }
        }

        // Check error rate
        if ($importJob->processed_rows > 0) {
            $errorRate = ($importJob->error_rows / $importJob->processed_rows) * 100;
            
            if ($errorRate > 10) {
                $health['health_score'] -= 25;
                $health['issues'][] = "High error rate: {$errorRate}%";
                $health['recommendations'][] = 'Review data quality and validation rules';
            }
        }

        // Check memory usage
        $memoryUsage = $this->getMemoryUsagePercent();
        if ($memoryUsage > 80) {
            $health['health_score'] -= 20;
            $health['issues'][] = "High memory usage: {$memoryUsage}%";
            $health['recommendations'][] = 'Consider reducing chunk size or increasing memory limit';
        }

        // Check if job is stuck
        if ($importJob->status === ImportJob::STATUS_PROCESSING && $importJob->updated_at->diffInMinutes(now()) > 30) {
            $health['health_score'] -= 40;
            $health['issues'][] = 'Job appears to be stuck (no updates for 30+ minutes)';
            $health['recommendations'][] = 'Check queue workers and consider restarting the job';
        }

        return $health;
    }

    /**
     * Generate system health report
     *
     * @return array
     */
    public function generateSystemHealthReport(): array
    {
        $metrics = $this->collectSystemMetrics();
        
        $health = [
            'overall_status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'components' => [],
            'alerts' => [],
        ];

        // Check database health
        if ($metrics['database']['status'] === 'connected' && $metrics['database']['response_time_ms'] < 100) {
            $health['components']['database'] = 'healthy';
        } elseif ($metrics['database']['response_time_ms'] < 500) {
            $health['components']['database'] = 'degraded';
            $health['alerts'][] = 'Database response time is elevated';
        } else {
            $health['components']['database'] = 'unhealthy';
            $health['alerts'][] = 'Database connection issues detected';
            $health['overall_status'] = 'degraded';
        }

        // Check cache health
        if ($metrics['cache']['status'] === 'operational' && $metrics['cache']['response_time_ms'] < 50) {
            $health['components']['cache'] = 'healthy';
        } elseif ($metrics['cache']['response_time_ms'] < 200) {
            $health['components']['cache'] = 'degraded';
            $health['alerts'][] = 'Cache response time is elevated';
        } else {
            $health['components']['cache'] = 'unhealthy';
            $health['alerts'][] = 'Cache system issues detected';
            $health['overall_status'] = 'degraded';
        }

        // Check memory usage
        if ($metrics['memory']['usage_percent'] < 70) {
            $health['components']['memory'] = 'healthy';
        } elseif ($metrics['memory']['usage_percent'] < 90) {
            $health['components']['memory'] = 'degraded';
            $health['alerts'][] = 'Memory usage is high';
        } else {
            $health['components']['memory'] = 'critical';
            $health['alerts'][] = 'Memory usage is critical';
            $health['overall_status'] = 'critical';
        }

        // Check queue health
        $activeJobs = $metrics['import_jobs']['active']['processing'] + $metrics['import_jobs']['active']['pending'];
        if ($activeJobs < 10) {
            $health['components']['queue'] = 'healthy';
        } elseif ($activeJobs < 50) {
            $health['components']['queue'] = 'busy';
        } else {
            $health['components']['queue'] = 'overloaded';
            $health['alerts'][] = 'Queue is overloaded with jobs';
            $health['overall_status'] = 'degraded';
        }

        return $health;
    }

    /**
     * Store system metrics in cache
     *
     * @param array $metrics
     * @return void
     */
    private function storeSystemMetrics(array $metrics): void
    {
        Cache::put('import:system_metrics', $metrics, 300); // 5 minutes
        
        // Also store historical data
        $historyKey = 'import:metrics_history:' . now()->format('Y-m-d-H');
        $history = Cache::get($historyKey, []);
        $history[] = $metrics;
        
        // Keep only last 60 entries (1 hour if collected every minute)
        if (count($history) > 60) {
            $history = array_slice($history, -60);
        }
        
        Cache::put($historyKey, $history, 3600); // 1 hour
    }

    /**
     * Get memory limit in MB
     *
     * @return int
     */
    private function getMemoryLimitMB(): int
    {
        $limit = ini_get('memory_limit');
        
        if ($limit === '-1') {
            return -1; // No limit
        }
        
        $value = (int) $limit;
        $unit = strtoupper(substr($limit, -1));
        
        switch ($unit) {
            case 'G':
                return $value * 1024;
            case 'M':
                return $value;
            case 'K':
                return (int) ($value / 1024);
            default:
                return (int) ($value / 1024 / 1024);
        }
    }

    /**
     * Get memory usage percentage
     *
     * @return float
     */
    private function getMemoryUsagePercent(): float
    {
        $current = memory_get_usage(true) / 1024 / 1024;
        $limit = $this->getMemoryLimitMB();
        
        if ($limit === -1) {
            return 0; // No limit set
        }
        
        return round(($current / $limit) * 100, 2);
    }

    /**
     * Estimate processing time for an import job
     *
     * @param ImportJob $importJob
     * @return int Estimated seconds
     */
    private function estimateProcessingTime(ImportJob $importJob): int
    {
        // Base rate: 50 rows per second for CSV, 30 for Excel
        $baseRate = 50;
        
        // Adjust based on file size
        if (file_exists($importJob->file_path)) {
            $fileSize = filesize($importJob->file_path);
            if ($fileSize > 10 * 1024 * 1024) { // > 10MB
                $baseRate *= 0.8;
            }
        }
        
        return max(60, ceil($importJob->total_rows / $baseRate));
    }

    /**
     * Get system metrics for dashboard
     *
     * @return array
     */
    public function getDashboardMetrics(): array
    {
        return Cache::get('import:system_metrics', []);
    }

    /**
     * Get metrics history for charts
     *
     * @param int $hours
     * @return array
     */
    public function getMetricsHistory(int $hours = 24): array
    {
        $history = [];
        
        for ($i = $hours; $i >= 0; $i--) {
            $hour = now()->subHours($i)->format('Y-m-d-H');
            $hourlyData = Cache::get("import:metrics_history:{$hour}", []);
            
            if (!empty($hourlyData)) {
                $history[$hour] = $hourlyData;
            }
        }
        
        return $history;
    }
}