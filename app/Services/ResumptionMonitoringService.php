<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\ImportResumptionLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class ResumptionMonitoringService
{
    /**
     * Monitor resumption success rates and trigger alerts if needed.
     *
     * @param \Carbon\Carbon|null $since
     * @return array
     */
    public function monitorResumptionHealth(?\Carbon\Carbon $since = null): array
    {
        $since = $since ?? now()->subHours(24); // Default to last 24 hours
        
        $stats = ImportResumptionLog::getResumptionStatistics($since);
        $integrityStats = app(FileIntegrityService::class)->getIntegrityStatistics($since);
        $lockStats = app(DynamicLockManager::class)->getLockStatistics($since);
        
        $healthReport = [
            'period' => [
                'since' => $since->toISOString(),
                'until' => now()->toISOString(),
            ],
            'resumption' => $stats,
            'file_integrity' => $integrityStats,
            'lock_management' => $lockStats,
            'overall_health' => $this->calculateOverallHealth($stats, $integrityStats, $lockStats),
            'alerts' => [],
        ];

        // Check for alert conditions
        $alerts = $this->checkAlertConditions($healthReport);
        $healthReport['alerts'] = $alerts;

        // Trigger alerts if necessary
        if (!empty($alerts)) {
            $this->triggerAlerts($alerts, $healthReport);
        }

        Log::info("Resumption health monitoring completed", [
            'overall_health' => $healthReport['overall_health'],
            'alert_count' => count($alerts),
        ]);

        return $healthReport;
    }

    /**
     * Get detailed resumption failure analysis.
     *
     * @param \Carbon\Carbon|null $since
     * @return array
     */
    public function analyzeResumptionFailures(?\Carbon\Carbon $since = null): array
    {
        $since = $since ?? now()->subHours(24);
        
        $failedResumptions = ImportResumptionLog::getFailedResumptions($since);
        
        $analysis = [
            'total_failures' => $failedResumptions->count(),
            'failure_reasons' => [],
            'affected_jobs' => [],
            'failure_timeline' => [],
            'recommendations' => [],
        ];

        foreach ($failedResumptions as $failure) {
            // Categorize failure reasons
            $reason = $failure->details;
            if (!isset($analysis['failure_reasons'][$reason])) {
                $analysis['failure_reasons'][$reason] = 0;
            }
            $analysis['failure_reasons'][$reason]++;

            // Track affected jobs
            $jobId = $failure->import_job_id;
            if (!isset($analysis['affected_jobs'][$jobId])) {
                $analysis['affected_jobs'][$jobId] = [
                    'job' => $failure->importJob,
                    'failure_count' => 0,
                    'last_failure' => null,
                ];
            }
            $analysis['affected_jobs'][$jobId]['failure_count']++;
            $analysis['affected_jobs'][$jobId]['last_failure'] = $failure->created_at;

            // Build timeline
            $analysis['failure_timeline'][] = [
                'timestamp' => $failure->created_at,
                'job_id' => $jobId,
                'reason' => $reason,
            ];
        }

        // Sort failure reasons by frequency
        arsort($analysis['failure_reasons']);

        // Generate recommendations
        $analysis['recommendations'] = $this->generateFailureRecommendations($analysis);

        return $analysis;
    }

    /**
     * Check for jobs that may need manual intervention.
     *
     * @return array
     */
    public function identifyProblematicJobs(): array
    {
        $problematicJobs = [];

        // Find jobs with multiple resumption failures
        $jobsWithFailures = ImportJob::whereHas('resumptionLogs', function ($query) {
            $query->where('event_type', ImportResumptionLog::EVENT_RESUMPTION_FAILURE)
                  ->where('created_at', '>=', now()->subHours(24));
        })->with(['resumptionLogs' => function ($query) {
            $query->where('event_type', ImportResumptionLog::EVENT_RESUMPTION_FAILURE)
                  ->where('created_at', '>=', now()->subHours(24))
                  ->orderBy('created_at', 'desc');
        }])->get();

        foreach ($jobsWithFailures as $job) {
            $failureCount = $job->resumptionLogs->count();
            
            if ($failureCount >= 3) { // 3 or more failures in 24 hours
                $problematicJobs[] = [
                    'job' => $job,
                    'failure_count' => $failureCount,
                    'last_failure' => $job->resumptionLogs->first()->created_at,
                    'common_reasons' => $job->resumptionLogs->pluck('details')->unique()->values(),
                    'recommendation' => $this->getJobRecommendation($job, $failureCount),
                ];
            }
        }

        // Find jobs stuck in processing state for too long
        $stuckJobs = ImportJob::where('status', ImportJob::STATUS_PROCESSING)
            ->where('started_at', '<', now()->subHours(6)) // Processing for more than 6 hours
            ->get();

        foreach ($stuckJobs as $job) {
            $problematicJobs[] = [
                'job' => $job,
                'issue' => 'stuck_processing',
                'processing_duration' => $job->started_at->diffForHumans(),
                'recommendation' => 'Consider manual intervention or job restart',
            ];
        }

        return $problematicJobs;
    }

    /**
     * Generate resumption performance metrics.
     *
     * @param \Carbon\Carbon|null $since
     * @return array
     */
    public function generatePerformanceMetrics(?\Carbon\Carbon $since = null): array
    {
        $since = $since ?? now()->subDays(7); // Default to last 7 days
        
        $jobs = ImportJob::where('created_at', '>=', $since)
            ->with('resumptionLogs')
            ->get();

        $metrics = [
            'total_jobs' => $jobs->count(),
            'jobs_with_resumption' => 0,
            'average_resumption_attempts' => 0,
            'resumption_success_rate' => 0,
            'average_time_to_resume' => 0,
            'file_integrity_success_rate' => 0,
            'performance_trends' => [],
        ];

        $jobsWithResumption = $jobs->filter(function ($job) {
            return $job->resumptionLogs->where('event_type', ImportResumptionLog::EVENT_RESUMPTION_ATTEMPT)->count() > 0;
        });

        $metrics['jobs_with_resumption'] = $jobsWithResumption->count();

        if ($jobsWithResumption->count() > 0) {
            $totalAttempts = $jobsWithResumption->sum(function ($job) {
                return $job->resumptionLogs->where('event_type', ImportResumptionLog::EVENT_RESUMPTION_ATTEMPT)->count();
            });

            $successfulResumptions = $jobsWithResumption->sum(function ($job) {
                return $job->resumptionLogs->where('event_type', ImportResumptionLog::EVENT_RESUMPTION_SUCCESS)->count();
            });

            $metrics['average_resumption_attempts'] = round($totalAttempts / $jobsWithResumption->count(), 2);
            $metrics['resumption_success_rate'] = $totalAttempts > 0 ? round(($successfulResumptions / $totalAttempts) * 100, 2) : 0;
        }

        // Calculate file integrity success rate
        $integrityChecks = ImportResumptionLog::byEventType(ImportResumptionLog::EVENT_INTEGRITY_CHECK)
            ->where('created_at', '>=', $since)
            ->get();

        if ($integrityChecks->count() > 0) {
            $passedChecks = $integrityChecks->where('metadata.passed', true)->count();
            $metrics['file_integrity_success_rate'] = round(($passedChecks / $integrityChecks->count()) * 100, 2);
        }

        return $metrics;
    }

    /**
     * Calculate overall health score.
     */
    private function calculateOverallHealth(array $resumptionStats, array $integrityStats, array $lockStats): array
    {
        $scores = [];
        
        // Resumption health (40% weight)
        $resumptionScore = $resumptionStats['success_rate'] ?? 0;
        $scores['resumption'] = ['score' => $resumptionScore, 'weight' => 0.4];
        
        // File integrity health (30% weight)
        $integrityScore = $integrityStats['success_rate'] ?? 0;
        $scores['integrity'] = ['score' => $integrityScore, 'weight' => 0.3];
        
        // Lock management health (30% weight)
        $lockScore = ($lockStats['acquire_success_rate'] + $lockStats['renewal_success_rate']) / 2;
        $scores['locks'] = ['score' => $lockScore, 'weight' => 0.3];
        
        // Calculate weighted average
        $totalScore = 0;
        $totalWeight = 0;
        
        foreach ($scores as $component => $data) {
            $totalScore += $data['score'] * $data['weight'];
            $totalWeight += $data['weight'];
        }
        
        $overallScore = $totalWeight > 0 ? round($totalScore / $totalWeight, 2) : 0;
        
        return [
            'overall_score' => $overallScore,
            'health_level' => $this->getHealthLevel($overallScore),
            'component_scores' => $scores,
        ];
    }

    /**
     * Get health level based on score.
     */
    private function getHealthLevel(float $score): string
    {
        if ($score >= 90) return 'excellent';
        if ($score >= 75) return 'good';
        if ($score >= 60) return 'fair';
        if ($score >= 40) return 'poor';
        return 'critical';
    }

    /**
     * Check for alert conditions.
     */
    private function checkAlertConditions(array $healthReport): array
    {
        $alerts = [];
        
        $overallScore = $healthReport['overall_health']['overall_score'];
        $resumptionStats = $healthReport['resumption'];
        $integrityStats = $healthReport['file_integrity'];
        
        // Critical overall health
        if ($overallScore < 50) {
            $alerts[] = [
                'level' => 'critical',
                'type' => 'overall_health',
                'message' => "Overall resumption health is critical ({$overallScore}%)",
                'action_required' => true,
            ];
        }
        
        // High failure rate
        if ($resumptionStats['failed_resumptions'] > 0 && $resumptionStats['success_rate'] < 70) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'high_failure_rate',
                'message' => "Resumption success rate is low ({$resumptionStats['success_rate']}%)",
                'action_required' => true,
            ];
        }
        
        // File integrity issues
        if ($integrityStats['failed_checks'] > 0 && $integrityStats['success_rate'] < 90) {
            $alerts[] = [
                'level' => 'warning',
                'type' => 'integrity_issues',
                'message' => "File integrity check success rate is low ({$integrityStats['success_rate']}%)",
                'action_required' => true,
            ];
        }
        
        return $alerts;
    }

    /**
     * Trigger alerts based on conditions.
     */
    private function triggerAlerts(array $alerts, array $healthReport): void
    {
        foreach ($alerts as $alert) {
            Log::warning("Resumption alert triggered", $alert);
            
            // Send notifications based on alert level
            if ($alert['level'] === 'critical') {
                $this->sendCriticalAlert($alert, $healthReport);
            } elseif ($alert['action_required']) {
                $this->sendWarningAlert($alert, $healthReport);
            }
        }
    }

    /**
     * Send critical alert notification.
     */
    private function sendCriticalAlert(array $alert, array $healthReport): void
    {
        // Implementation would depend on your notification setup
        Log::critical("CRITICAL RESUMPTION ALERT", [
            'alert' => $alert,
            'health_report' => $healthReport,
        ]);
        
        // Example: Send email, Slack notification, etc.
        // Mail::to(config('import.alerts.email'))->send(new CriticalResumptionAlert($alert, $healthReport));
    }

    /**
     * Send warning alert notification.
     */
    private function sendWarningAlert(array $alert, array $healthReport): void
    {
        Log::warning("Resumption warning alert", [
            'alert' => $alert,
            'health_summary' => $healthReport['overall_health'],
        ]);
    }

    /**
     * Generate recommendations based on failure analysis.
     */
    private function generateFailureRecommendations(array $analysis): array
    {
        $recommendations = [];
        
        foreach ($analysis['failure_reasons'] as $reason => $count) {
            if (str_contains($reason, 'file not found')) {
                $recommendations[] = "Consider implementing file backup/archival strategy to prevent file loss";
            } elseif (str_contains($reason, 'hash mismatch')) {
                $recommendations[] = "Investigate file modification issues - check file permissions and storage integrity";
            } elseif (str_contains($reason, 'size mismatch')) {
                $recommendations[] = "Check for concurrent file access or storage issues";
            } elseif (str_contains($reason, 'lock')) {
                $recommendations[] = "Review lock timeout settings and consider increasing timeout for large files";
            }
        }
        
        if (empty($recommendations)) {
            $recommendations[] = "Monitor trends and consider increasing retry attempts or timeout values";
        }
        
        return array_unique($recommendations);
    }

    /**
     * Get recommendation for a specific problematic job.
     */
    private function getJobRecommendation(ImportJob $job, int $failureCount): string
    {
        if ($failureCount >= 5) {
            return "Consider manual intervention - job may need to be restarted or file re-uploaded";
        } elseif ($failureCount >= 3) {
            return "Monitor closely - may need manual review if failures continue";
        } else {
            return "Continue monitoring - within acceptable failure threshold";
        }
    }
}