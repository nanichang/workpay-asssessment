<?php

namespace App\Console\Commands;

use App\Models\ImportJob;
use App\Services\ImportMonitoringService;
use App\Services\ImportLogger;
use Illuminate\Console\Command;

class ImportStats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:stats 
                            {--job= : Show stats for specific import job ID}
                            {--today : Show today\'s statistics only}
                            {--health : Show system health report}
                            {--monitor : Start continuous monitoring mode}
                            {--interval=30 : Monitoring interval in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display import statistics and monitoring information';

    private ImportMonitoringService $monitoring;
    private ImportLogger $logger;

    /**
     * Create a new command instance.
     */
    public function __construct(ImportMonitoringService $monitoring, ImportLogger $logger)
    {
        parent::__construct();
        $this->monitoring = $monitoring;
        $this->logger = $logger;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('📊 Employee Import Statistics');
        $this->newLine();

        if ($this->option('job')) {
            return $this->showJobStats($this->option('job'));
        }

        if ($this->option('health')) {
            return $this->showHealthReport();
        }

        if ($this->option('monitor')) {
            return $this->startMonitoring();
        }

        if ($this->option('today')) {
            return $this->showTodayStats();
        }

        return $this->showOverallStats();
    }

    /**
     * Show statistics for a specific job
     */
    private function showJobStats(string $jobId): int
    {
        $job = ImportJob::find($jobId);
        
        if (!$job) {
            $this->error("Import job '{$jobId}' not found.");
            return Command::FAILURE;
        }

        $this->info("📋 Import Job Details: {$job->filename}");
        $this->newLine();

        // Basic job information
        $this->table(['Property', 'Value'], [
            ['Job ID', $job->id],
            ['Filename', $job->filename],
            ['Status', $this->getStatusWithColor($job->status)],
            ['Total Rows', number_format($job->total_rows)],
            ['Processed Rows', number_format($job->processed_rows)],
            ['Successful Rows', number_format($job->successful_rows)],
            ['Error Rows', number_format($job->error_rows)],
            ['Success Rate', $job->total_rows > 0 ? round(($job->successful_rows / $job->total_rows) * 100, 2) . '%' : 'N/A'],
            ['Created At', $job->created_at->format('Y-m-d H:i:s')],
            ['Started At', $job->started_at?->format('Y-m-d H:i:s') ?? 'Not started'],
            ['Completed At', $job->completed_at?->format('Y-m-d H:i:s') ?? 'Not completed'],
        ]);

        // Performance metrics
        if ($job->started_at) {
            $this->newLine();
            $this->info('⚡ Performance Metrics');
            
            $duration = $job->completed_at 
                ? $job->started_at->diffInSeconds($job->completed_at)
                : $job->started_at->diffInSeconds(now());
            
            $processingRate = $duration > 0 ? round($job->processed_rows / $duration, 2) : 0;
            
            $this->table(['Metric', 'Value'], [
                ['Duration', $this->formatDuration($duration)],
                ['Processing Rate', $processingRate . ' rows/second'],
                ['Estimated Completion', $job->isCompleted() ? 'Completed' : $this->estimateCompletion($job)],
            ]);
        }

        // Health check
        $health = $this->monitoring->monitorJobHealth($job);
        $this->newLine();
        $this->info('🏥 Job Health');
        $this->line("Health Score: {$health['health_score']}/100");
        
        if (!empty($health['issues'])) {
            $this->warn('Issues:');
            foreach ($health['issues'] as $issue) {
                $this->line("  • {$issue}");
            }
        }
        
        if (!empty($health['recommendations'])) {
            $this->info('Recommendations:');
            foreach ($health['recommendations'] as $recommendation) {
                $this->line("  • {$recommendation}");
            }
        }

        // Performance metrics from logger
        $performanceMetrics = $this->logger->getPerformanceMetrics($jobId);
        if (!empty($performanceMetrics)) {
            $this->newLine();
            $this->info('📈 Detailed Performance Data Available');
            $this->line('Use --monitor flag to see real-time updates');
        }

        return Command::SUCCESS;
    }

    /**
     * Show system health report
     */
    private function showHealthReport(): int
    {
        $this->info('🏥 System Health Report');
        $this->newLine();

        $health = $this->monitoring->generateSystemHealthReport();
        
        // Overall status
        $statusColor = match($health['overall_status']) {
            'healthy' => 'info',
            'degraded' => 'warn',
            'critical' => 'error',
            default => 'line'
        };
        
        $this->$statusColor("Overall Status: " . strtoupper($health['overall_status']));
        $this->newLine();

        // Component status
        $this->info('Component Health:');
        foreach ($health['components'] as $component => $status) {
            $icon = match($status) {
                'healthy' => '✅',
                'degraded', 'busy' => '⚠️',
                'unhealthy', 'critical', 'overloaded' => '❌',
                default => '❓'
            };
            
            $this->line("  {$icon} " . ucfirst($component) . ": {$status}");
        }

        // Alerts
        if (!empty($health['alerts'])) {
            $this->newLine();
            $this->warn('🚨 Active Alerts:');
            foreach ($health['alerts'] as $alert) {
                $this->line("  • {$alert}");
            }
        }

        // System metrics
        $metrics = $this->monitoring->collectSystemMetrics();
        $this->newLine();
        $this->info('📊 Current Metrics:');
        
        $this->table(['Component', 'Metric', 'Value'], [
            ['Memory', 'Current Usage', $metrics['memory']['current_usage_mb'] . ' MB'],
            ['Memory', 'Usage Percent', $metrics['memory']['usage_percent'] . '%'],
            ['Database', 'Response Time', $metrics['database']['response_time_ms'] . ' ms'],
            ['Cache', 'Response Time', $metrics['cache']['response_time_ms'] . ' ms'],
            ['Queue', 'Pending Jobs', $metrics['import_jobs']['active']['pending']],
            ['Queue', 'Processing Jobs', $metrics['import_jobs']['active']['processing']],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Start continuous monitoring
     */
    private function startMonitoring(): int
    {
        $interval = (int) $this->option('interval');
        
        $this->info("🔄 Starting continuous monitoring (interval: {$interval}s)");
        $this->info('Press Ctrl+C to stop');
        $this->newLine();

        while (true) {
            // Clear screen
            $this->output->write("\033[2J\033[H");
            
            $this->info('🔄 Import System Monitor - ' . now()->format('Y-m-d H:i:s'));
            $this->newLine();

            // System health
            $health = $this->monitoring->generateSystemHealthReport();
            $this->line("System Status: " . strtoupper($health['overall_status']));
            
            // Active jobs
            $activeJobs = ImportJob::where('status', ImportJob::STATUS_PROCESSING)->get();
            $this->line("Active Jobs: " . $activeJobs->count());
            
            if ($activeJobs->isNotEmpty()) {
                $this->newLine();
                $this->info('📋 Active Import Jobs:');
                
                $jobData = [];
                foreach ($activeJobs as $job) {
                    $progress = $job->total_rows > 0 ? round(($job->processed_rows / $job->total_rows) * 100, 1) : 0;
                    $duration = $job->started_at ? $job->started_at->diffInSeconds(now()) : 0;
                    
                    $jobData[] = [
                        substr($job->id, 0, 8) . '...',
                        substr($job->filename, 0, 30),
                        number_format($job->processed_rows) . '/' . number_format($job->total_rows),
                        $progress . '%',
                        $this->formatDuration($duration),
                    ];
                }
                
                $this->table(['Job ID', 'Filename', 'Progress', '%', 'Duration'], $jobData);
            }

            // System metrics
            $metrics = $this->monitoring->collectSystemMetrics();
            $this->newLine();
            $this->line("Memory: {$metrics['memory']['current_usage_mb']} MB ({$metrics['memory']['usage_percent']}%)");
            $this->line("DB Response: {$metrics['database']['response_time_ms']} ms");
            $this->line("Cache Response: {$metrics['cache']['response_time_ms']} ms");

            sleep($interval);
        }

        return Command::SUCCESS;
    }

    /**
     * Show today's statistics
     */
    private function showTodayStats(): int
    {
        $this->info("📅 Today's Import Statistics");
        $this->newLine();

        $today = now()->startOfDay();
        
        $stats = [
            'total_jobs' => ImportJob::whereDate('created_at', $today)->count(),
            'completed' => ImportJob::whereDate('completed_at', $today)->count(),
            'failed' => ImportJob::where('status', ImportJob::STATUS_FAILED)
                ->whereDate('updated_at', $today)->count(),
            'processing' => ImportJob::where('status', ImportJob::STATUS_PROCESSING)->count(),
            'pending' => ImportJob::where('status', ImportJob::STATUS_PENDING)->count(),
            'total_rows' => ImportJob::whereDate('updated_at', $today)->sum('successful_rows'),
        ];

        $this->table(['Metric', 'Count'], [
            ['Jobs Started Today', number_format($stats['total_jobs'])],
            ['Jobs Completed Today', number_format($stats['completed'])],
            ['Jobs Failed Today', number_format($stats['failed'])],
            ['Currently Processing', number_format($stats['processing'])],
            ['Pending Jobs', number_format($stats['pending'])],
            ['Total Rows Processed', number_format($stats['total_rows'])],
        ]);

        // Success rate
        if ($stats['total_jobs'] > 0) {
            $successRate = round(($stats['completed'] / $stats['total_jobs']) * 100, 2);
            $this->newLine();
            $this->info("Success Rate: {$successRate}%");
        }

        return Command::SUCCESS;
    }

    /**
     * Show overall statistics
     */
    private function showOverallStats(): int
    {
        $this->info('📈 Overall Import Statistics');
        $this->newLine();

        // All-time stats
        $allTimeStats = [
            'total_jobs' => ImportJob::count(),
            'completed' => ImportJob::where('status', ImportJob::STATUS_COMPLETED)->count(),
            'failed' => ImportJob::where('status', ImportJob::STATUS_FAILED)->count(),
            'processing' => ImportJob::where('status', ImportJob::STATUS_PROCESSING)->count(),
            'pending' => ImportJob::where('status', ImportJob::STATUS_PENDING)->count(),
            'total_rows' => ImportJob::sum('successful_rows'),
        ];

        $this->table(['Metric', 'All Time', 'Today'], [
            ['Total Jobs', number_format($allTimeStats['total_jobs']), number_format(ImportJob::whereDate('created_at', today())->count())],
            ['Completed Jobs', number_format($allTimeStats['completed']), number_format(ImportJob::whereDate('completed_at', today())->count())],
            ['Failed Jobs', number_format($allTimeStats['failed']), number_format(ImportJob::where('status', ImportJob::STATUS_FAILED)->whereDate('updated_at', today())->count())],
            ['Processing Jobs', number_format($allTimeStats['processing']), number_format($allTimeStats['processing'])],
            ['Pending Jobs', number_format($allTimeStats['pending']), number_format($allTimeStats['pending'])],
            ['Rows Processed', number_format($allTimeStats['total_rows']), number_format(ImportJob::whereDate('updated_at', today())->sum('successful_rows'))],
        ]);

        // Recent activity
        $this->newLine();
        $this->info('🕒 Recent Activity (Last 10 Jobs):');
        
        $recentJobs = ImportJob::orderBy('created_at', 'desc')->limit(10)->get();
        
        if ($recentJobs->isNotEmpty()) {
            $recentData = [];
            foreach ($recentJobs as $job) {
                $recentData[] = [
                    substr($job->id, 0, 8) . '...',
                    substr($job->filename, 0, 30),
                    $this->getStatusWithColor($job->status),
                    number_format($job->successful_rows) . '/' . number_format($job->total_rows),
                    $job->created_at->diffForHumans(),
                ];
            }
            
            $this->table(['Job ID', 'Filename', 'Status', 'Success/Total', 'Created'], $recentData);
        } else {
            $this->line('No import jobs found.');
        }

        return Command::SUCCESS;
    }

    /**
     * Get status with appropriate color
     */
    private function getStatusWithColor(string $status): string
    {
        return match($status) {
            ImportJob::STATUS_COMPLETED => '✅ Completed',
            ImportJob::STATUS_PROCESSING => '🔄 Processing',
            ImportJob::STATUS_PENDING => '⏳ Pending',
            ImportJob::STATUS_FAILED => '❌ Failed',
            default => $status
        };
    }

    /**
     * Format duration in human readable format
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        
        if ($minutes < 60) {
            return "{$minutes}m {$remainingSeconds}s";
        }
        
        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;
        
        return "{$hours}h {$remainingMinutes}m";
    }

    /**
     * Estimate completion time for a job
     */
    private function estimateCompletion(ImportJob $job): string
    {
        if ($job->processed_rows === 0 || !$job->started_at) {
            return 'Unknown';
        }
        
        $elapsedSeconds = $job->started_at->diffInSeconds(now());
        $remainingRows = $job->total_rows - $job->processed_rows;
        $rowsPerSecond = $job->processed_rows / $elapsedSeconds;
        
        if ($rowsPerSecond <= 0) {
            return 'Unknown';
        }
        
        $estimatedSecondsRemaining = $remainingRows / $rowsPerSecond;
        $estimatedCompletion = now()->addSeconds($estimatedSecondsRemaining);
        
        return $estimatedCompletion->format('H:i:s') . ' (' . $this->formatDuration((int)$estimatedSecondsRemaining) . ' remaining)';
    }
}
