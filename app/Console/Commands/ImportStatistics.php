<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\ImportError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportStatistics extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'import:stats 
                            {--detailed : Show detailed statistics}
                            {--job= : Show statistics for specific job ID}
                            {--recent=10 : Number of recent jobs to show}';

    /**
     * The console command description.
     */
    protected $description = 'Display import statistics and monitoring information';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jobId = $this->option('job');
        $detailed = $this->option('detailed');
        $recent = (int) $this->option('recent');

        if ($jobId) {
            $this->showJobStatistics($jobId);
        } else {
            $this->showOverallStatistics($detailed, $recent);
        }

        return 0;
    }

    /**
     * Show statistics for a specific job
     */
    private function showJobStatistics(string $jobId): void
    {
        $job = ImportJob::find($jobId);
        
        if (!$job) {
            $this->error("Import job not found: {$jobId}");
            return;
        }

        $this->info("=== Import Job Statistics: {$jobId} ===");
        $this->line("Filename: {$job->filename}");
        $this->line("Status: {$job->status}");
        $this->line("Total Rows: {$job->total_rows}");
        $this->line("Processed Rows: {$job->processed_rows}");
        $this->line("Successful Rows: {$job->successful_rows}");
        $this->line("Error Rows: {$job->error_rows}");
        
        if ($job->total_rows > 0) {
            $successRate = ($job->successful_rows / $job->total_rows) * 100;
            $this->line("Success Rate: " . number_format($successRate, 2) . "%");
        }
        
        $this->line("Started: " . ($job->started_at ? $job->started_at->format('Y-m-d H:i:s') : 'Not started'));
        $this->line("Completed: " . ($job->completed_at ? $job->completed_at->format('Y-m-d H:i:s') : 'Not completed'));
        
        if ($job->started_at && $job->completed_at) {
            $duration = $job->started_at->diffInSeconds($job->completed_at);
            $this->line("Duration: {$duration} seconds");
            
            if ($job->total_rows > 0) {
                $rowsPerSecond = $job->total_rows / $duration;
                $this->line("Processing Speed: " . number_format($rowsPerSecond, 2) . " rows/second");
            }
        }

        // Show error breakdown
        if ($job->error_rows > 0) {
            $this->line("\n=== Error Breakdown ===");
            $errorStats = ImportError::where('import_job_id', $jobId)
                ->select('error_type', DB::raw('count(*) as count'))
                ->groupBy('error_type')
                ->orderBy('count', 'desc')
                ->get();

            foreach ($errorStats as $stat) {
                $this->line("{$stat->error_type}: {$stat->count} errors");
            }

            // Show sample errors
            $this->line("\n=== Sample Errors ===");
            $sampleErrors = ImportError::where('import_job_id', $jobId)
                ->orderBy('row_number')
                ->take(5)
                ->get();

            foreach ($sampleErrors as $error) {
                $this->line("Row {$error->row_number}: {$error->error_message}");
            }
        }
    }

    /**
     * Show overall import statistics
     */
    private function showOverallStatistics(bool $detailed, int $recent): void
    {
        $this->info("=== Import System Statistics ===");

        // Overall counts
        $totalJobs = ImportJob::count();
        $totalEmployees = Employee::count();
        $totalErrors = ImportError::count();

        $this->line("Total Import Jobs: {$totalJobs}");
        $this->line("Total Employees: {$totalEmployees}");
        $this->line("Total Import Errors: {$totalErrors}");

        // Job status breakdown
        $this->line("\n=== Job Status Breakdown ===");
        $statusStats = ImportJob::select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        foreach ($statusStats as $stat) {
            $this->line("{$stat->status}: {$stat->count} jobs");
        }

        // Success rates
        $completedJobs = ImportJob::where('status', 'completed')->get();
        if ($completedJobs->count() > 0) {
            $totalProcessed = $completedJobs->sum('processed_rows');
            $totalSuccessful = $completedJobs->sum('successful_rows');
            $overallSuccessRate = $totalProcessed > 0 ? ($totalSuccessful / $totalProcessed) * 100 : 0;
            
            $this->line("\n=== Success Rates ===");
            $this->line("Overall Success Rate: " . number_format($overallSuccessRate, 2) . "%");
            $this->line("Average Rows per Job: " . number_format($completedJobs->avg('total_rows'), 0));
        }

        // Recent jobs
        $this->line("\n=== Recent Import Jobs ===");
        $recentJobs = ImportJob::orderBy('created_at', 'desc')
            ->take($recent)
            ->get();

        if ($recentJobs->count() > 0) {
            $headers = ['ID', 'Filename', 'Status', 'Rows', 'Success', 'Errors', 'Created'];
            $rows = [];

            foreach ($recentJobs as $job) {
                $rows[] = [
                    substr($job->id, 0, 8) . '...',
                    substr($job->filename, 0, 20) . (strlen($job->filename) > 20 ? '...' : ''),
                    $job->status,
                    $job->total_rows,
                    $job->successful_rows,
                    $job->error_rows,
                    $job->created_at->format('m-d H:i'),
                ];
            }

            $this->table($headers, $rows);
        } else {
            $this->line("No import jobs found.");
        }

        // Error statistics
        if ($detailed && $totalErrors > 0) {
            $this->line("\n=== Error Type Breakdown ===");
            $errorTypeStats = ImportError::select('error_type', DB::raw('count(*) as count'))
                ->groupBy('error_type')
                ->orderBy('count', 'desc')
                ->get();

            foreach ($errorTypeStats as $stat) {
                $this->line("{$stat->error_type}: {$stat->count} errors");
            }
        }

        // Performance metrics for completed jobs
        if ($detailed && $completedJobs->count() > 0) {
            $this->line("\n=== Performance Metrics ===");
            
            $jobsWithDuration = $completedJobs->filter(function ($job) {
                return $job->started_at && $job->completed_at;
            });

            if ($jobsWithDuration->count() > 0) {
                $durations = $jobsWithDuration->map(function ($job) {
                    return $job->started_at->diffInSeconds($job->completed_at);
                });

                $avgDuration = $durations->avg();
                $maxDuration = $durations->max();
                $minDuration = $durations->min();

                $this->line("Average Processing Time: " . number_format($avgDuration, 2) . " seconds");
                $this->line("Fastest Job: " . number_format($minDuration, 2) . " seconds");
                $this->line("Slowest Job: " . number_format($maxDuration, 2) . " seconds");

                // Calculate average processing speed
                $speedStats = $jobsWithDuration->map(function ($job) {
                    $duration = $job->started_at->diffInSeconds($job->completed_at);
                    return $duration > 0 ? $job->total_rows / $duration : 0;
                })->filter(function ($speed) {
                    return $speed > 0;
                });

                if ($speedStats->count() > 0) {
                    $avgSpeed = $speedStats->avg();
                    $this->line("Average Processing Speed: " . number_format($avgSpeed, 2) . " rows/second");
                }
            }
        }

        // System health indicators
        $this->line("\n=== System Health ===");
        $failedJobs = ImportJob::where('status', 'failed')->count();
        $stuckJobs = ImportJob::where('status', 'processing')
            ->where('updated_at', '<', now()->subHours(2))
            ->count();

        $this->line("Failed Jobs: {$failedJobs}");
        $this->line("Potentially Stuck Jobs: {$stuckJobs}");

        if ($failedJobs > 0 || $stuckJobs > 0) {
            $this->warn("⚠ System may need attention - check failed or stuck jobs");
        } else {
            $this->info("✓ System appears healthy");
        }
    }
}