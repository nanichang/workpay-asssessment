<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\ImportError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ClearImportData extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'import:clear 
                            {--type=all : Type of data to clear (all, employees, jobs, errors, test-data)}
                            {--confirm : Skip confirmation prompt}';

    /**
     * The console command description.
     */
    protected $description = 'Clear import-related data from the database and storage';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $skipConfirmation = $this->option('confirm');

        if (!$skipConfirmation) {
            $confirmed = $this->confirm("Are you sure you want to clear {$type} data? This action cannot be undone.");
            if (!$confirmed) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        switch ($type) {
            case 'all':
                $this->clearAllData();
                break;
            case 'employees':
                $this->clearEmployees();
                break;
            case 'jobs':
                $this->clearImportJobs();
                break;
            case 'errors':
                $this->clearImportErrors();
                break;
            case 'test-data':
                $this->clearTestData();
                break;
            default:
                $this->error("Invalid type: {$type}. Valid options: all, employees, jobs, errors, test-data");
                return 1;
        }

        $this->info('Data clearing completed successfully.');
        return 0;
    }

    /**
     * Clear all import-related data
     */
    private function clearAllData(): void
    {
        $this->info('Clearing all import data...');
        
        $this->clearImportErrors();
        $this->clearImportJobs();
        $this->clearEmployees();
        $this->clearUploadedFiles();
        
        $this->info('All import data cleared.');
    }

    /**
     * Clear all employees
     */
    private function clearEmployees(): void
    {
        $count = Employee::count();
        
        if ($count > 0) {
            Employee::truncate();
            $this->info("Cleared {$count} employees.");
        } else {
            $this->info('No employees to clear.');
        }
    }

    /**
     * Clear import jobs and related files
     */
    private function clearImportJobs(): void
    {
        $jobs = ImportJob::all();
        $count = $jobs->count();
        
        if ($count > 0) {
            // Clear related errors first
            ImportError::whereIn('import_job_id', $jobs->pluck('id'))->delete();
            
            // Clear uploaded files
            foreach ($jobs as $job) {
                if ($job->file_path && Storage::exists($job->file_path)) {
                    Storage::delete($job->file_path);
                }
            }
            
            // Clear jobs
            ImportJob::truncate();
            
            $this->info("Cleared {$count} import jobs and their files.");
        } else {
            $this->info('No import jobs to clear.');
        }
    }

    /**
     * Clear import errors
     */
    private function clearImportErrors(): void
    {
        $count = ImportError::count();
        
        if ($count > 0) {
            ImportError::truncate();
            $this->info("Cleared {$count} import errors.");
        } else {
            $this->info('No import errors to clear.');
        }
    }

    /**
     * Clear only test data (employees with TEST- or PERF- prefixes)
     */
    private function clearTestData(): void
    {
        $testEmployees = Employee::where('employee_number', 'LIKE', 'TEST-%')
            ->orWhere('employee_number', 'LIKE', 'PERF-%')
            ->count();
        
        if ($testEmployees > 0) {
            Employee::where('employee_number', 'LIKE', 'TEST-%')
                ->orWhere('employee_number', 'LIKE', 'PERF-%')
                ->delete();
            
            $this->info("Cleared {$testEmployees} test employees.");
        } else {
            $this->info('No test employees to clear.');
        }
        
        // Clear test import jobs
        $testJobs = ImportJob::where('filename', 'LIKE', '%test%')
            ->orWhere('filename', 'LIKE', '%good-employees%')
            ->orWhere('filename', 'LIKE', '%bad-employees%')
            ->orWhere('filename', 'LIKE', '%Assessment%')
            ->get();
        
        if ($testJobs->count() > 0) {
            ImportError::whereIn('import_job_id', $testJobs->pluck('id'))->delete();
            
            foreach ($testJobs as $job) {
                if ($job->file_path && Storage::exists($job->file_path)) {
                    Storage::delete($job->file_path);
                }
            }
            
            ImportJob::whereIn('id', $testJobs->pluck('id'))->delete();
            
            $this->info("Cleared {$testJobs->count()} test import jobs.");
        } else {
            $this->info('No test import jobs to clear.');
        }
    }

    /**
     * Clear uploaded files from storage
     */
    private function clearUploadedFiles(): void
    {
        $files = Storage::files('imports');
        $count = count($files);
        
        if ($count > 0) {
            Storage::delete($files);
            $this->info("Cleared {$count} uploaded files.");
        } else {
            $this->info('No uploaded files to clear.');
        }
    }
}