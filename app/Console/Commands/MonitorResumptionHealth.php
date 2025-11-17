<?php

namespace App\Console\Commands;

use App\Services\ResumptionMonitoringService;
use Illuminate\Console\Command;

class MonitorResumptionHealth extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:monitor-resumption 
                            {--hours=24 : Number of hours to look back for monitoring}
                            {--alert : Trigger alerts if issues are found}
                            {--detailed : Show detailed failure analysis}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor import resumption health and trigger alerts if needed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $showDetailed = $this->option('detailed');
        $triggerAlerts = $this->option('alert');

        $this->info("Monitoring import resumption health for the last {$hours} hours...");

        $monitoringService = app(ResumptionMonitoringService::class);
        $since = now()->subHours($hours);

        try {
            // Get health report
            $healthReport = $monitoringService->monitorResumptionHealth($since);
            
            $this->displayHealthReport($healthReport);

            // Show detailed analysis if requested
            if ($showDetailed) {
                $this->newLine();
                $this->info('Detailed Failure Analysis:');
                $failureAnalysis = $monitoringService->analyzeResumptionFailures($since);
                $this->displayFailureAnalysis($failureAnalysis);
            }

            // Check for problematic jobs
            $this->newLine();
            $this->info('Checking for problematic jobs...');
            $problematicJobs = $monitoringService->identifyProblematicJobs();
            $this->displayProblematicJobs($problematicJobs);

            // Show performance metrics
            $this->newLine();
            $this->info('Performance Metrics:');
            $metrics = $monitoringService->generatePerformanceMetrics($since);
            $this->displayPerformanceMetrics($metrics);

            // Determine exit code based on health
            $overallScore = $healthReport['overall_health']['overall_score'];
            
            if ($overallScore < 50) {
                $this->error('CRITICAL: Overall resumption health is below 50%');
                return Command::FAILURE;
            } elseif ($overallScore < 75) {
                $this->warn('WARNING: Overall resumption health is below 75%');
                return Command::SUCCESS;
            } else {
                $this->info('✓ Resumption health is good');
                return Command::SUCCESS;
            }

        } catch (\Exception $e) {
            $this->error("Failed to monitor resumption health: " . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Display health report in a formatted way.
     */
    private function displayHealthReport(array $healthReport): void
    {
        $overall = $healthReport['overall_health'];
        
        $this->table(
            ['Metric', 'Value', 'Status'],
            [
                ['Overall Health Score', $overall['overall_score'] . '%', $this->getHealthStatus($overall['health_level'])],
                ['Resumption Success Rate', $healthReport['resumption']['success_rate'] . '%', ''],
                ['File Integrity Success Rate', $healthReport['file_integrity']['success_rate'] . '%', ''],
                ['Lock Acquire Success Rate', $healthReport['lock_management']['acquire_success_rate'] . '%', ''],
                ['Lock Renewal Success Rate', $healthReport['lock_management']['renewal_success_rate'] . '%', ''],
            ]
        );

        if (!empty($healthReport['alerts'])) {
            $this->newLine();
            $this->warn('Active Alerts:');
            foreach ($healthReport['alerts'] as $alert) {
                $level = strtoupper($alert['level']);
                $this->line("  [{$level}] {$alert['message']}");
            }
        }
    }

    /**
     * Display failure analysis.
     */
    private function displayFailureAnalysis(array $analysis): void
    {
        $this->line("Total Failures: {$analysis['total_failures']}");
        
        if (!empty($analysis['failure_reasons'])) {
            $this->newLine();
            $this->line('Top Failure Reasons:');
            foreach (array_slice($analysis['failure_reasons'], 0, 5, true) as $reason => $count) {
                $this->line("  • {$reason}: {$count} occurrences");
            }
        }

        if (!empty($analysis['recommendations'])) {
            $this->newLine();
            $this->line('Recommendations:');
            foreach ($analysis['recommendations'] as $recommendation) {
                $this->line("  → {$recommendation}");
            }
        }
    }

    /**
     * Display problematic jobs.
     */
    private function displayProblematicJobs(array $jobs): void
    {
        if (empty($jobs)) {
            $this->info('✓ No problematic jobs found');
            return;
        }

        $this->warn("Found " . count($jobs) . " problematic job(s):");
        
        $tableData = [];
        foreach ($jobs as $jobInfo) {
            $job = $jobInfo['job'];
            $tableData[] = [
                $job->id,
                $job->filename,
                $jobInfo['failure_count'] ?? $jobInfo['issue'] ?? 'Unknown',
                $jobInfo['recommendation'] ?? 'Review manually',
            ];
        }

        $this->table(
            ['Job ID', 'Filename', 'Issue', 'Recommendation'],
            $tableData
        );
    }

    /**
     * Display performance metrics.
     */
    private function displayPerformanceMetrics(array $metrics): void
    {
        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Jobs', $metrics['total_jobs']],
                ['Jobs with Resumption', $metrics['jobs_with_resumption']],
                ['Average Resumption Attempts', $metrics['average_resumption_attempts']],
                ['Resumption Success Rate', $metrics['resumption_success_rate'] . '%'],
                ['File Integrity Success Rate', $metrics['file_integrity_success_rate'] . '%'],
            ]
        );
    }

    /**
     * Get health status indicator.
     */
    private function getHealthStatus(string $level): string
    {
        return match ($level) {
            'excellent' => '🟢 Excellent',
            'good' => '🟡 Good',
            'fair' => '🟠 Fair',
            'poor' => '🔴 Poor',
            'critical' => '🚨 Critical',
            default => '❓ Unknown',
        };
    }
}