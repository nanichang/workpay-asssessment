<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\ImportProcessedRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DuplicateDetector
{
    /**
     * Track processed employee numbers and emails within the current file
     */
    private array $processedEmployeeNumbers = [];
    private array $processedEmails = [];

    /**
     * Track all rows in the current file for duplicate detection
     */
    private array $fileRows = [];

    /**
     * Current import job for persistent tracking
     */
    private ?ImportJob $currentJob = null;

    /**
     * Initialize the detector with all rows from the file for duplicate detection
     *
     * @param array $allRows All rows from the CSV file
     * @param ImportJob|null $job Current import job for persistent tracking
     * @return void
     */
    public function initializeWithFileData(array $allRows, ?ImportJob $job = null): void
    {
        $this->fileRows = $allRows;
        $this->currentJob = $job;
        
        // Load previously processed records if resuming
        if ($job && $job->last_processed_row > 0) {
            $this->loadProcessedRecords($job);
        } else {
            $this->processedEmployeeNumbers = [];
            $this->processedEmails = [];
        }
    }

    /**
     * Load previously processed records for resumption.
     *
     * @param ImportJob $job
     * @return void
     */
    private function loadProcessedRecords(ImportJob $job): void
    {
        try {
            $this->processedEmployeeNumbers = ImportProcessedRecord::getProcessedEmployeeNumbers($job->id);
            $this->processedEmails = ImportProcessedRecord::getProcessedEmails($job->id);
            
            Log::info("Loaded {count} processed employee numbers and {emailCount} emails for job {jobId}", [
                'count' => count($this->processedEmployeeNumbers),
                'emailCount' => count($this->processedEmails),
                'jobId' => $job->id,
            ]);
            
        } catch (\Exception $e) {
            Log::error("Failed to load processed records for job {$job->id}: " . $e->getMessage());
            // Fallback to empty arrays
            $this->processedEmployeeNumbers = [];
            $this->processedEmails = [];
        }
    }

    /**
     * Check if the current row is a duplicate within the file
     * Returns true if this is NOT the last occurrence of the duplicate
     *
     * @param array $rowData Current row data
     * @param int $currentRowIndex Current row index (0-based)
     * @return bool True if this row should be skipped (not the last occurrence)
     */
    public function isDuplicateInFile(array $rowData, int $currentRowIndex): bool
    {
        $employeeNumber = trim($rowData['employee_number'] ?? '');
        $email = trim($rowData['email'] ?? '');

        if (empty($employeeNumber) || empty($email)) {
            return false; // Can't determine duplicates without these fields
        }

        // Find the last occurrence of this employee_number or email in the file
        $lastOccurrenceIndex = $this->findLastOccurrenceIndex($employeeNumber, $email);

        // If current row is not the last occurrence, it's a duplicate to skip
        return $currentRowIndex !== $lastOccurrenceIndex;
    }

    /**
     * Check if employee exists in database by employee_number or email
     *
     * @param string $employeeNumber
     * @param string $email
     * @return Employee|null
     */
    public function findExistingEmployee(string $employeeNumber, string $email): ?Employee
    {
        // Check by employee_number first
        $employee = Employee::where('employee_number', $employeeNumber)->first();
        
        if ($employee) {
            return $employee;
        }

        // Check by email if not found by employee_number
        return Employee::where('email', $email)->first();
    }

    /**
     * Mark a row as processed to track duplicates within the current processing session
     *
     * @param string $employeeNumber
     * @param string $email
     * @param int $rowNumber
     * @param string $status
     * @return void
     */
    public function markAsProcessed(string $employeeNumber, string $email, int $rowNumber, string $status = 'processed'): void
    {
        // Add to in-memory tracking
        $this->processedEmployeeNumbers[] = $employeeNumber;
        $this->processedEmails[] = $email;
        
        // Persist to database if we have a current job
        if ($this->currentJob) {
            try {
                ImportProcessedRecord::recordProcessed(
                    $this->currentJob->id,
                    $employeeNumber,
                    $email,
                    $rowNumber,
                    $status
                );
            } catch (\Exception $e) {
                Log::error("Failed to persist processed record for job {$this->currentJob->id}: " . $e->getMessage());
                // Continue processing even if persistence fails
            }
        }
    }

    /**
     * Check if employee_number or email was already processed in current session
     *
     * @param string $employeeNumber
     * @param string $email
     * @return bool
     */
    public function wasAlreadyProcessed(string $employeeNumber, string $email): bool
    {
        // Check in-memory first (faster)
        $inMemoryCheck = in_array($employeeNumber, $this->processedEmployeeNumbers, true) ||
                        in_array($email, $this->processedEmails, true);
        
        if ($inMemoryCheck) {
            return true;
        }
        
        // Check persistent storage if we have a current job
        if ($this->currentJob) {
            return ImportProcessedRecord::wasEmployeeNumberProcessed($this->currentJob->id, $employeeNumber) ||
                   ImportProcessedRecord::wasEmailProcessed($this->currentJob->id, $email);
        }
        
        return false;
    }

    /**
     * Find the last occurrence index of employee_number or email in the file
     *
     * @param string $employeeNumber
     * @param string $email
     * @return int
     */
    private function findLastOccurrenceIndex(string $employeeNumber, string $email): int
    {
        $lastIndex = -1;

        foreach ($this->fileRows as $index => $row) {
            $rowEmployeeNumber = trim($row['employee_number'] ?? '');
            $rowEmail = trim($row['email'] ?? '');

            // Check if this row matches by employee_number or email
            if ($rowEmployeeNumber === $employeeNumber || $rowEmail === $email) {
                $lastIndex = $index;
            }
        }

        return $lastIndex;
    }

    /**
     * Get duplicate statistics for the file
     *
     * @return array
     */
    public function getDuplicateStatistics(): array
    {
        $duplicates = [];
        $employeeNumbers = [];
        $emails = [];

        foreach ($this->fileRows as $index => $row) {
            $employeeNumber = trim($row['employee_number'] ?? '');
            $email = trim($row['email'] ?? '');

            if (!empty($employeeNumber)) {
                if (isset($employeeNumbers[$employeeNumber])) {
                    $duplicates['employee_number'][] = [
                        'value' => $employeeNumber,
                        'rows' => array_merge($employeeNumbers[$employeeNumber], [$index + 1])
                    ];
                } else {
                    $employeeNumbers[$employeeNumber] = [$index + 1];
                }
            }

            if (!empty($email)) {
                if (isset($emails[$email])) {
                    $duplicates['email'][] = [
                        'value' => $email,
                        'rows' => array_merge($emails[$email], [$index + 1])
                    ];
                } else {
                    $emails[$email] = [$index + 1];
                }
            }
        }

        return [
            'total_duplicates' => count($duplicates['employee_number'] ?? []) + count($duplicates['email'] ?? []),
            'duplicate_employee_numbers' => $duplicates['employee_number'] ?? [],
            'duplicate_emails' => $duplicates['email'] ?? []
        ];
    }

    /**
     * Reset the detector state for a new file
     *
     * @return void
     */
    public function reset(): void
    {
        $this->processedEmployeeNumbers = [];
        $this->processedEmails = [];
        $this->fileRows = [];
        $this->currentJob = null;
    }

    /**
     * Get duplicate detection statistics with caching.
     *
     * @return array
     */
    public function getDuplicateStatisticsWithCache(): array
    {
        if (!$this->currentJob) {
            return $this->getDuplicateStatistics();
        }

        $cacheKey = "duplicate_stats:{$this->currentJob->id}";
        
        return Cache::remember($cacheKey, 300, function () {
            return $this->getDuplicateStatistics();
        });
    }

    /**
     * Clear duplicate detection cache for a job.
     *
     * @param string $jobId
     * @return void
     */
    public function clearDuplicateCache(string $jobId): void
    {
        Cache::forget("duplicate_stats:{$jobId}");
    }

    /**
     * Get resumption-safe duplicate statistics.
     *
     * @param ImportJob $job
     * @return array
     */
    public function getResumptionDuplicateStats(ImportJob $job): array
    {
        $processedRecords = ImportProcessedRecord::byImportJob($job->id)->get();
        
        return [
            'total_processed' => $processedRecords->count(),
            'unique_employee_numbers' => $processedRecords->pluck('employee_number')->unique()->count(),
            'unique_emails' => $processedRecords->pluck('email')->unique()->count(),
            'skipped_duplicates' => $processedRecords->where('status', 'skipped')->count(),
            'processed_successfully' => $processedRecords->where('status', 'processed')->count(),
            'processing_errors' => $processedRecords->where('status', 'error')->count(),
        ];
    }

    /**
     * Rebuild duplicate tracking state from persistent storage.
     *
     * @param ImportJob $job
     * @return array
     */
    public function rebuildTrackingState(ImportJob $job): array
    {
        try {
            $this->currentJob = $job;
            $this->loadProcessedRecords($job);
            
            $stats = [
                'employee_numbers_loaded' => count($this->processedEmployeeNumbers),
                'emails_loaded' => count($this->processedEmails),
                'last_processed_row' => $job->last_processed_row,
            ];
            
            Log::info("Rebuilt duplicate tracking state for job {$job->id}", $stats);
            
            return $stats;
            
        } catch (\Exception $e) {
            Log::error("Failed to rebuild tracking state for job {$job->id}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate duplicate tracking consistency.
     *
     * @param ImportJob $job
     * @return array
     */
    public function validateTrackingConsistency(ImportJob $job): array
    {
        $result = [
            'consistent' => true,
            'issues' => [],
            'stats' => [],
        ];

        try {
            $processedRecords = ImportProcessedRecord::byImportJob($job->id)->get();
            $expectedCount = $job->processed_rows;
            $actualCount = $processedRecords->count();
            
            $result['stats'] = [
                'expected_processed_rows' => $expectedCount,
                'actual_processed_records' => $actualCount,
                'difference' => $actualCount - $expectedCount,
            ];
            
            if ($actualCount !== $expectedCount) {
                $result['consistent'] = false;
                $result['issues'][] = "Processed record count mismatch: expected {$expectedCount}, found {$actualCount}";
            }
            
            // Check for duplicate records in tracking table
            $duplicateEmployeeNumbers = $processedRecords
                ->groupBy('employee_number')
                ->filter(fn($group) => $group->count() > 1);
                
            if ($duplicateEmployeeNumbers->isNotEmpty()) {
                $result['consistent'] = false;
                $result['issues'][] = "Found duplicate employee numbers in tracking: " . 
                    $duplicateEmployeeNumbers->keys()->implode(', ');
            }
            
            $duplicateEmails = $processedRecords
                ->groupBy('email')
                ->filter(fn($group) => $group->count() > 1);
                
            if ($duplicateEmails->isNotEmpty()) {
                $result['consistent'] = false;
                $result['issues'][] = "Found duplicate emails in tracking: " . 
                    $duplicateEmails->keys()->implode(', ');
            }
            
        } catch (\Exception $e) {
            $result['consistent'] = false;
            $result['issues'][] = "Validation failed: " . $e->getMessage();
        }

        return $result;
    }
}