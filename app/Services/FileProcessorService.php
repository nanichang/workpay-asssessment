<?php

namespace App\Services;

use App\Models\ImportJob;
use App\Models\Employee;
use App\Services\EmployeeValidator;
use App\Services\DuplicateDetector;
use App\Services\ProgressTracker;
use App\Services\FileIntegrityService;
use App\Services\ExcelStreamingService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Generator;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FileProcessorService
{
    private EmployeeValidator $validator;
    private DuplicateDetector $duplicateDetector;
    private ProgressTracker $progressTracker;
    private int $chunkSize;

    public function __construct(
        EmployeeValidator $validator,
        DuplicateDetector $duplicateDetector,
        ProgressTracker $progressTracker
    ) {
        $this->validator = $validator;
        $this->duplicateDetector = $duplicateDetector;
        $this->progressTracker = $progressTracker;
        $this->chunkSize = config('import.chunk_size', 100);
    }

    /**
     * Process the import job
     *
     * @param ImportJob $job
     * @return void
     * @throws Exception
     */
    public function processImport(ImportJob $job): void
    {
        Log::info("Starting import processing for job {$job->id}");

        try {
            $job->markAsStarted();

            // Initialize duplicate detector with job context
            $this->duplicateDetector->initializeWithFileData([], $job);

            // Get full storage path
            $fullPath = $this->getFullFilePath($job->file_path);
            $fileType = $this->detectFileType($fullPath);
            
            // Use enhanced Excel streaming if it's an Excel file
            if ($fileType === 'excel') {
                $this->processExcelFileWithStreaming($job, $fullPath);
            } else {
                $reader = $this->getFileReader($fullPath, $fileType);
                
                // Count total rows if not already set
                if ($job->total_rows === 0) {
                    $totalRows = $this->countTotalRows($reader, $fileType);
                    $job->update(['total_rows' => $totalRows]);
                }

                // Process file in chunks
                $this->processFileInChunks($job, $reader, $fileType);
            }

            $job->markAsCompleted();
            $this->progressTracker->markCompleted($job);
            
            // Clean up integrity data for completed job
            app(FileIntegrityService::class)->cleanupIntegrityData($job);
            
            Log::info("Import processing completed for job {$job->id}");

        } catch (Exception $e) {
            Log::error("Import processing failed for job {$job->id}: " . $e->getMessage());
            $job->markAsFailed();
            $this->progressTracker->markFailed($job, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Detect file type based on extension
     *
     * @param string $filePath
     * @return string
     */
    private function detectFileType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        return match ($extension) {
            'csv' => 'csv',
            'xlsx', 'xls' => 'excel',
            default => throw new Exception("Unsupported file type: {$extension}")
        };
    }

    /**
     * Get appropriate file reader based on type
     *
     * @param string $filePath
     * @param string $fileType
     * @return mixed
     */
    private function getFileReader(string $filePath, string $fileType)
    {
        if ($fileType === 'csv') {
            return fopen($filePath, 'r');
        }

        // For Excel files, use PhpSpreadsheet
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        
        return $reader->load($filePath);
    }

    /**
     * Count total rows in the file
     *
     * @param mixed $reader
     * @param string $fileType
     * @return int
     */
    private function countTotalRows($reader, string $fileType): int
    {
        if ($fileType === 'csv') {
            $count = 0;
            rewind($reader);
            
            // Skip header row
            fgetcsv($reader);
            
            while (fgetcsv($reader) !== false) {
                $count++;
            }
            
            rewind($reader);
            return $count;
        }

        // For Excel files
        $worksheet = $reader->getActiveSheet();
        $highestRow = $worksheet->getHighestRow();
        
        // Subtract 1 for header row
        return max(0, $highestRow - 1);
    }

    /**
     * Process file in chunks with resumable processing
     *
     * @param ImportJob $job
     * @param mixed $reader
     * @param string $fileType
     * @return void
     */
    private function processFileInChunks(ImportJob $job, $reader, string $fileType): void
    {
        $startRow = $job->last_processed_row;
        $chunk = [];
        $currentRow = 0;
        $chunkNumber = 0;

        Log::info("Starting chunked processing from row {$startRow} for job {$job->id}");

        foreach ($this->readFileRows($reader, $fileType, $startRow) as $rowData) {
            $currentRow++;
            $actualRowNumber = $startRow + $currentRow;
            
            $chunk[] = [
                'data' => $rowData,
                'row_number' => $actualRowNumber
            ];

            // Process chunk when it reaches the configured size
            if (count($chunk) >= $this->chunkSize) {
                $chunkNumber++;
                Log::debug("Processing chunk {$chunkNumber} (rows " . ($actualRowNumber - count($chunk) + 1) . "-{$actualRowNumber}) for job {$job->id}");
                
                $this->processChunk($chunk, $job);
                $chunk = [];
                
                // Create checkpoint after each chunk for resumability
                $this->createCheckpoint($job, $actualRowNumber);
                
                // Update progress tracking
                $this->progressTracker->updateProgress($job, $actualRowNumber);
            }
        }

        // Process remaining rows in the last chunk
        if (!empty($chunk)) {
            $chunkNumber++;
            $lastRowNumber = $startRow + $currentRow;
            Log::debug("Processing final chunk {$chunkNumber} for job {$job->id}");
            
            $this->processChunk($chunk, $job);
            $this->createCheckpoint($job, $lastRowNumber);
            
            // Final progress update
            $this->progressTracker->updateProgress($job, $lastRowNumber);
        }

        // Close file handle for CSV
        if ($fileType === 'csv' && is_resource($reader)) {
            fclose($reader);
        }

        Log::info("Completed chunked processing for job {$job->id}. Processed {$chunkNumber} chunks.");
    }

    /**
     * Create a checkpoint for resumable processing
     *
     * @param ImportJob $job
     * @param int $lastProcessedRow
     * @return void
     */
    private function createCheckpoint(ImportJob $job, int $lastProcessedRow): void
    {
        $job->update(['last_processed_row' => $lastProcessedRow]);
        
        Log::debug("Checkpoint created at row {$lastProcessedRow} for job {$job->id}");
    }

    /**
     * Resume processing from the last checkpoint
     *
     * @param ImportJob $job
     * @return bool
     */
    public function canResumeProcessing(ImportJob $job): bool
    {
        return $job->last_processed_row > 0 && 
               $job->last_processed_row < $job->total_rows &&
               !$job->isCompleted();
    }

    /**
     * Get processing statistics for a chunk
     *
     * @param array $chunk
     * @return array
     */
    private function getChunkStatistics(array $chunk): array
    {
        return [
            'size' => count($chunk),
            'start_row' => $chunk[0]['row_number'] ?? 0,
            'end_row' => end($chunk)['row_number'] ?? 0,
        ];
    }

    /**
     * Read file rows as a generator for memory efficiency
     *
     * @param mixed $reader
     * @param string $fileType
     * @param int $startRow
     * @return Generator
     */
    private function readFileRows($reader, string $fileType, int $startRow = 1): Generator
    {
        if ($fileType === 'csv') {
            yield from $this->readCsvRows($reader, $startRow);
        } else {
            yield from $this->readExcelRows($reader, $startRow);
        }
    }

    /**
     * Read CSV rows starting from a specific row
     *
     * @param resource $handle
     * @param int $startRow
     * @return Generator
     */
    private function readCsvRows($handle, int $startRow): Generator
    {
        rewind($handle);
        
        // Read and store header row
        $headers = fgetcsv($handle);
        if ($headers === false) {
            return;
        }

        $currentRow = 0;
        
        while (($data = fgetcsv($handle)) !== false) {
            $currentRow++;
            
            // Skip rows until we reach the start row
            if ($currentRow < $startRow) {
                continue;
            }

            // Combine headers with data to create associative array
            $rowData = array_combine($headers, $data);
            
            yield $rowData ?: [];
        }
    }

    /**
     * Read Excel rows starting from a specific row
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet
     * @param int $startRow
     * @return Generator
     */
    private function readExcelRows($spreadsheet, int $startRow): Generator
    {
        $worksheet = $spreadsheet->getActiveSheet();
        $highestColumn = $worksheet->getHighestColumn();
        $highestRow = $worksheet->getHighestRow();

        // Get headers from first row
        $headers = [];
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $headers[] = $worksheet->getCell($col . '1')->getCalculatedValue();
        }

        // Read data rows starting from the specified row
        $actualStartRow = $startRow + 1; // +1 because Excel rows are 1-indexed and we skip header
        
        for ($row = $actualStartRow; $row <= $highestRow; $row++) {
            $rowData = [];
            
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $cellValue = $worksheet->getCell($col . $row)->getCalculatedValue();
                $rowData[] = $cellValue;
            }

            // Combine headers with data
            $associativeData = array_combine($headers, $rowData);
            
            yield $associativeData ?: [];
        }
    }

    /**
     * Process a chunk of rows
     *
     * @param array $chunk
     * @param ImportJob $job
     * @return void
     */
    private function processChunk(array $chunk, ImportJob $job): void
    {
        $chunkStats = $this->getChunkStatistics($chunk);
        
        DB::transaction(function () use ($chunk, $job) {
            foreach ($chunk as $item) {
                $this->validateAndProcessRow(
                    $item['data'], 
                    $item['row_number'], 
                    $job
                );
            }
        });

        // Adjust chunk size based on memory usage after processing
        $this->adjustChunkSizeForMemory();
        
        Log::debug("Processed chunk with {$chunkStats['size']} rows (rows {$chunkStats['start_row']}-{$chunkStats['end_row']}) for job {$job->id}");
    }

    /**
     * Validate and process a single row
     *
     * @param array $rowData
     * @param int $rowNumber
     * @param ImportJob $job
     * @return void
     */
    private function validateAndProcessRow(array $rowData, int $rowNumber, ImportJob $job): void
    {
        try {
            // Clean and normalize row data
            $cleanData = $this->cleanRowData($rowData);

            // Validate the row data
            $validationResult = $this->validator->validate($cleanData);
            
            if ($validationResult->hasErrors()) {
                $this->recordError($job, $rowNumber, 'validation', $validationResult->getErrorsAsString(), $cleanData);
                $this->progressTracker->markRowProcessed($job, false, $rowNumber);
                return;
            }

            // Check for duplicates within the file and database
            $employeeNumber = $cleanData['employee_number'] ?? '';
            $email = $cleanData['email'] ?? '';
            
            // Check if already processed in current session
            if ($this->duplicateDetector->wasAlreadyProcessed($employeeNumber, $email)) {
                $this->recordError($job, $rowNumber, 'duplicate', 'Duplicate employee_number or email already processed in this session', $cleanData);
                $this->progressTracker->markRowProcessed($job, false, $rowNumber);
                return;
            }

            // Check if exists in database
            $existingEmployee = $this->duplicateDetector->findExistingEmployee($employeeNumber, $email);
            if ($existingEmployee && !$this->shouldUpdateExisting($existingEmployee, $cleanData)) {
                $this->recordError($job, $rowNumber, 'duplicate', 'Employee already exists in database', $cleanData);
                $this->progressTracker->markRowProcessed($job, false, $rowNumber);
                return;
            }

            // Create or update employee record
            $this->createOrUpdateEmployee($cleanData);
            
            // Mark as processed to track duplicates
            $this->duplicateDetector->markAsProcessed($employeeNumber, $email, $rowNumber, 'processed');
            
            $this->progressTracker->markRowProcessed($job, true, $rowNumber);

        } catch (Exception $e) {
            Log::error("Error processing row {$rowNumber} in job {$job->id}: " . $e->getMessage());
            $this->recordError($job, $rowNumber, 'system', $e->getMessage(), $rowData);
            $this->progressTracker->markRowProcessed($job, false, $rowNumber);
        }
    }

    /**
     * Clean and normalize row data
     *
     * @param array $rowData
     * @return array
     */
    private function cleanRowData(array $rowData): array
    {
        $cleaned = [];
        
        foreach ($rowData as $key => $value) {
            // Normalize key names (remove spaces, convert to snake_case)
            $normalizedKey = strtolower(str_replace(' ', '_', trim($key)));
            
            // Clean the value
            $cleanedValue = is_string($value) ? trim($value) : $value;
            
            // Convert empty strings to null
            if ($cleanedValue === '') {
                $cleanedValue = null;
            }
            
            $cleaned[$normalizedKey] = $cleanedValue;
        }

        return $cleaned;
    }

    /**
     * Create or update employee record
     *
     * @param array $data
     * @return Employee
     */
    private function createOrUpdateEmployee(array $data): Employee
    {
        // Find existing employee by employee_number or email
        $existing = Employee::where('employee_number', $data['employee_number'])
            ->orWhere('email', $data['email'])
            ->first();

        if ($existing) {
            $existing->update($data);
            return $existing;
        }

        return Employee::create($data);
    }

    /**
     * Determine if existing employee should be updated
     *
     * @param Employee $existing
     * @param array $newData
     * @return bool
     */
    private function shouldUpdateExisting(Employee $existing, array $newData): bool
    {
        // For now, always allow updates (idempotent behavior)
        // This could be enhanced with business rules like:
        // - Only update if new data is more recent
        // - Only update specific fields
        // - Require explicit permission for updates
        return true;
    }

    /**
     * Record an error for the import job
     *
     * @param ImportJob $job
     * @param int $rowNumber
     * @param string $errorType
     * @param string $message
     * @param array $rowData
     * @return void
     */
    private function recordError(ImportJob $job, int $rowNumber, string $errorType, string $message, array $rowData): void
    {
        $job->importErrors()->create([
            'row_number' => $rowNumber,
            'error_type' => $errorType,
            'error_message' => $message,
            'row_data' => $rowData,
        ]);
    }

    /**
     * Set chunk size for processing
     *
     * @param int $size
     * @return void
     */
    public function setChunkSize(int $size): void
    {
        $this->chunkSize = max(1, $size);
    }

    /**
     * Get current chunk size
     *
     * @return int
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * Adjust chunk size based on memory usage
     *
     * @return void
     */
    private function adjustChunkSizeForMemory(): void
    {
        $memoryUsage = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimitInBytes();
        
        if ($memoryLimit > 0) {
            $memoryUsagePercent = ($memoryUsage / $memoryLimit) * 100;
            
            // If memory usage is high, reduce chunk size
            if ($memoryUsagePercent > 80) {
                $this->chunkSize = max(10, intval($this->chunkSize * 0.5));
                Log::warning("High memory usage detected ({$memoryUsagePercent}%). Reducing chunk size to {$this->chunkSize}");
            }
            // If memory usage is low, we can increase chunk size for better performance
            elseif ($memoryUsagePercent < 30 && $this->chunkSize < 500) {
                $this->chunkSize = min(500, intval($this->chunkSize * 1.5));
                Log::info("Low memory usage detected ({$memoryUsagePercent}%). Increasing chunk size to {$this->chunkSize}");
            }
        }
    }

    /**
     * Get memory limit in bytes
     *
     * @return int
     */
    private function getMemoryLimitInBytes(): int
    {
        $memoryLimit = ini_get('memory_limit');
        
        if ($memoryLimit === '-1') {
            return 0; // No limit
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = intval($memoryLimit);
        
        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value
        };
    }

    /**
     * Get the full file path, handling different storage configurations.
     *
     * @param string $relativePath
     * @return string
     */
    private function getFullFilePath(string $relativePath): string
    {
        // If it's already an absolute path, return as-is
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }

        // Handle Laravel storage paths
        if (str_starts_with($relativePath, 'imports/')) {
            return Storage::disk('local')->path($relativePath);
        }

        // Default to storage_path for relative paths
        return storage_path('app/private/' . ltrim($relativePath, '/'));
    }

    /**
     * Process Excel file using enhanced streaming service.
     *
     * @param ImportJob $job
     * @param string $filePath
     * @return void
     */
    private function processExcelFileWithStreaming(ImportJob $job, string $filePath): void
    {
        $streamingService = new ExcelStreamingService($this->chunkSize);
        
        // Count total rows if not already set
        if ($job->total_rows === 0) {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);
            $totalRows = $spreadsheet->getActiveSheet()->getHighestRow() - 1; // Subtract header row
            $job->update(['total_rows' => $totalRows]);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }

        $startRow = $job->last_processed_row;
        $currentRow = $startRow;
        $chunk = [];

        Log::info("Starting Excel streaming processing from row {$startRow} for job {$job->id}");

        foreach ($streamingService->readExcelInChunks($filePath, $startRow) as $rowData) {
            $currentRow++;
            
            $chunk[] = [
                'data' => $rowData,
                'row_number' => $currentRow
            ];

            // Process chunk when it reaches the configured size
            if (count($chunk) >= $this->chunkSize) {
                $this->processChunk($chunk, $job);
                $chunk = [];
                
                // Create checkpoint after each chunk for resumability
                $this->createCheckpoint($job, $currentRow);
                
                // Update progress tracking
                $this->progressTracker->updateProgress($job, $currentRow);
            }
        }

        // Process remaining rows in the last chunk
        if (!empty($chunk)) {
            $this->processChunk($chunk, $job);
            $this->createCheckpoint($job, $currentRow);
            $this->progressTracker->updateProgress($job, $currentRow);
        }

        Log::info("Completed Excel streaming processing for job {$job->id}");
    }
}