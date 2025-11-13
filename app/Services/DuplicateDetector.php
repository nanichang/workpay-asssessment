<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

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
     * Initialize the detector with all rows from the file for duplicate detection
     *
     * @param array $allRows All rows from the CSV file
     * @return void
     */
    public function initializeWithFileData(array $allRows): void
    {
        $this->fileRows = $allRows;
        $this->processedEmployeeNumbers = [];
        $this->processedEmails = [];
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
     * @return void
     */
    public function markAsProcessed(string $employeeNumber, string $email): void
    {
        $this->processedEmployeeNumbers[] = $employeeNumber;
        $this->processedEmails[] = $email;
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
        return in_array($employeeNumber, $this->processedEmployeeNumbers, true) ||
               in_array($email, $this->processedEmails, true);
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
    }
}