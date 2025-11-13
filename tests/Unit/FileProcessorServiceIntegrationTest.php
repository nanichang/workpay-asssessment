<?php

namespace Tests\Unit;

use App\Models\ImportJob;
use App\Services\FileProcessorService;
use App\Services\EmployeeValidator;
use App\Services\DuplicateDetector;
use App\Services\ProgressTracker;
use App\Services\ValidationResult;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class FileProcessorServiceIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private FileProcessorService $fileProcessor;
    private EmployeeValidator $validator;
    private DuplicateDetector $duplicateDetector;
    private ProgressTracker $progressTracker;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->validator = Mockery::mock(EmployeeValidator::class);
        $this->duplicateDetector = Mockery::mock(DuplicateDetector::class);
        $this->progressTracker = Mockery::mock(ProgressTracker::class);
        
        $this->fileProcessor = new FileProcessorService(
            $this->validator,
            $this->duplicateDetector,
            $this->progressTracker
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_processes_real_good_employees_csv_file_with_chunked_processing()
    {
        $csvPath = base_path('good-employees.csv');
        
        if (!file_exists($csvPath)) {
            $this->markTestSkipped('good-employees.csv file not found');
        }
        
        // Count actual rows in the file
        $handle = fopen($csvPath, 'r');
        $totalRows = 0;
        fgetcsv($handle); // Skip header
        while (fgetcsv($handle) !== false) {
            $totalRows++;
        }
        fclose($handle);
        
        $job = ImportJob::create([
            'id' => 'test-job-real-good',
            'filename' => 'good-employees.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Set reasonable chunk size for large file testing
        $this->fileProcessor->setChunkSize(50);
        
        // Mock validator to return valid results for all rows
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->times($totalRows)
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->times($totalRows)
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->times($totalRows)
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times($totalRows);
        
        // Track progress updates for accuracy testing
        $progressUpdates = [];
        $this->progressTracker->shouldReceive('updateProgress')
            ->andReturnUsing(function ($job, $processedRows) use (&$progressUpdates) {
                $progressUpdates[] = $processedRows;
            });
        
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times($totalRows);
        $this->progressTracker->shouldReceive('markCompleted');
        
        $startTime = microtime(true);
        $this->fileProcessor->processImport($job);
        $endTime = microtime(true);
        
        $processingTime = $endTime - $startTime;
        
        $job->refresh();
        
        // Verify processing completed successfully
        $this->assertEquals('completed', $job->status);
        $this->assertEquals($totalRows, $job->processed_rows);
        $this->assertEquals($totalRows, $job->successful_rows);
        $this->assertEquals(0, $job->error_rows);
        
        // Verify progress tracking accuracy
        $this->assertNotEmpty($progressUpdates);
        $this->assertEquals($totalRows, end($progressUpdates));
        
        // Verify chunked processing occurred (multiple progress updates)
        $expectedChunks = ceil($totalRows / 50);
        $this->assertGreaterThanOrEqual($expectedChunks, count($progressUpdates));
        
        // Performance assertion - should process efficiently
        $rowsPerSecond = $totalRows / $processingTime;
        $this->assertGreaterThan(50, $rowsPerSecond, 'Should process at least 50 rows per second');
        
        // Verify memory efficiency (should not use excessive memory)
        $memoryUsed = memory_get_peak_usage(true);
        $this->assertLessThan(100 * 1024 * 1024, $memoryUsed, 'Memory usage should be under 100MB');
    }

    /** @test */
    public function it_processes_real_bad_employees_csv_file_with_validation_errors()
    {
        $csvPath = base_path('bad-employees.csv');
        
        if (!file_exists($csvPath)) {
            $this->markTestSkipped('bad-employees.csv file not found');
        }
        
        // Count actual rows in the file
        $handle = fopen($csvPath, 'r');
        $totalRows = 0;
        fgetcsv($handle); // Skip header
        while (fgetcsv($handle) !== false) {
            $totalRows++;
        }
        fclose($handle);
        
        $job = ImportJob::create([
            'id' => 'test-job-real-bad',
            'filename' => 'bad-employees.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Mock validator to simulate validation errors for bad data
        $validResult = new ValidationResult(true);
        $invalidResult = new ValidationResult(false, ['Validation error']);
        
        // First row is valid, rest have various validation errors based on bad-employees.csv content
        $validationResults = [$validResult]; // First row valid
        for ($i = 1; $i < $totalRows; $i++) {
            $validationResults[] = $invalidResult; // Rest are invalid
        }
        
        $this->validator->shouldReceive('validate')
            ->times($totalRows)
            ->andReturn(...$validationResults);
        
        // Mock duplicate detector for valid rows only
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->once() // Only called for valid row
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->once() // Only called for valid row
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->once(); // Only called for valid row
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress');
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times($totalRows);
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        $job->refresh();
        
        // Verify processing completed with expected error handling
        $this->assertEquals('completed', $job->status);
        $this->assertEquals($totalRows, $job->processed_rows);
        $this->assertEquals(1, $job->successful_rows); // Only first row valid
        $this->assertEquals($totalRows - 1, $job->error_rows); // Rest are errors
        
        // Verify errors were recorded
        $this->assertEquals($totalRows - 1, $job->importErrors()->count());
    }

    /** @test */
    public function it_processes_excel_file_with_sample_data()
    {
        $excelPath = base_path('Assement Data Set.xlsx');
        
        if (!file_exists($excelPath)) {
            $this->markTestSkipped('Assessment Data Set.xlsx file not found');
        }
        
        // Create a job for Excel processing
        $job = ImportJob::create([
            'id' => 'test-job-excel',
            'filename' => 'Assessment Data Set.xlsx',
            'file_path' => $excelPath,
            'status' => 'pending',
            'total_rows' => 0, // Will be calculated during processing
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed');
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress');
        $this->progressTracker->shouldReceive('markRowProcessed');
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        $job->refresh();
        
        // Verify Excel file was processed successfully
        $this->assertEquals('completed', $job->status);
        $this->assertGreaterThan(0, $job->total_rows);
        $this->assertEquals($job->total_rows, $job->processed_rows);
    }

    /** @test */
    public function it_tracks_progress_accurately_with_chunked_processing()
    {
        // Create a CSV with exactly 100 rows for precise progress tracking
        $csvData = [['employee_number', 'first_name', 'last_name', 'email']];
        for ($i = 1; $i <= 100; $i++) {
            $csvData[] = [
                "EMP-{$i}",
                "FirstName{$i}",
                "LastName{$i}",
                "user{$i}@example.com"
            ];
        }
        
        $csvPath = $this->createTestCsvFile($csvData);
        
        $job = ImportJob::create([
            'id' => 'test-job-progress-accuracy',
            'filename' => 'progress-accuracy-test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 100,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Set chunk size to 25 for 4 chunks exactly
        $this->fileProcessor->setChunkSize(25);
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->times(100)
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->times(100)
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->times(100)
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times(100);
        
        // Track detailed progress updates
        $progressUpdates = [];
        $rowProcessedCalls = [];
        
        $this->progressTracker->shouldReceive('updateProgress')
            ->andReturnUsing(function ($job, $processedRows) use (&$progressUpdates) {
                $progressUpdates[] = [
                    'processed_rows' => $processedRows,
                    'percentage' => ($processedRows / $job->total_rows) * 100
                ];
            });
        
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->andReturnUsing(function ($job, $success, $rowNumber) use (&$rowProcessedCalls) {
                $rowProcessedCalls[] = [
                    'success' => $success,
                    'row_number' => $rowNumber
                ];
            });
        
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        // Verify progress tracking accuracy
        $this->assertGreaterThanOrEqual(3, count($progressUpdates)); // At least 3 chunks
        $this->assertLessThanOrEqual(4, count($progressUpdates)); // At most 4 chunks
        
        // Verify final progress is 100%
        $finalProgress = end($progressUpdates);
        $this->assertEquals(100, $finalProgress['processed_rows']);
        $this->assertEquals(100.0, $finalProgress['percentage']);
        
        // Verify progress is monotonically increasing
        $previousRows = 0;
        foreach ($progressUpdates as $update) {
            $this->assertGreaterThan($previousRows, $update['processed_rows']);
            $previousRows = $update['processed_rows'];
        }
        
        // Verify all rows were marked as processed
        $this->assertCount(100, $rowProcessedCalls);
        
        // Verify all were successful
        $successfulRows = array_filter($rowProcessedCalls, fn($call) => $call['success']);
        $this->assertCount(100, $successfulRows);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(100, $job->processed_rows);
        $this->assertEquals(100, $job->successful_rows);
        
        unlink($csvPath);
    }

    /** @test */
    public function it_handles_memory_efficient_processing_with_large_chunks()
    {
        // Create a larger dataset to test memory efficiency
        $csvData = [['employee_number', 'first_name', 'last_name', 'email']];
        for ($i = 1; $i <= 500; $i++) {
            $csvData[] = [
                "EMP-{$i}",
                "FirstName{$i}",
                "LastName{$i}",
                "user{$i}@example.com"
            ];
        }
        
        $csvPath = $this->createTestCsvFile($csvData);
        
        $job = ImportJob::create([
            'id' => 'test-job-memory-efficiency',
            'filename' => 'memory-efficiency-test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 500,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Set large chunk size to test memory handling
        $this->fileProcessor->setChunkSize(100);
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->times(500)
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->times(500)
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->times(500)
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times(500);
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress')
            ->times(5); // 500 rows / 100 chunk size = 5 chunks
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times(500);
        $this->progressTracker->shouldReceive('markCompleted');
        
        $memoryBefore = memory_get_usage(true);
        
        $this->fileProcessor->processImport($job);
        
        $memoryAfter = memory_get_usage(true);
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(500, $job->processed_rows);
        
        // Memory usage should be reasonable (less than 50MB for 500 rows)
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Memory usage should be under 50MB');
        
        unlink($csvPath);
    }

    /** @test */
    public function it_processes_chunked_data_with_checkpoint_resumption()
    {
        // Create test data
        $csvData = [['employee_number', 'first_name', 'last_name', 'email']];
        for ($i = 1; $i <= 50; $i++) {
            $csvData[] = [
                "EMP-{$i}",
                "FirstName{$i}",
                "LastName{$i}",
                "user{$i}@example.com"
            ];
        }
        
        $csvPath = $this->createTestCsvFile($csvData);
        
        $job = ImportJob::create([
            'id' => 'test-job-checkpoint',
            'filename' => 'checkpoint-test.csv',
            'file_path' => $csvPath,
            'status' => 'processing',
            'total_rows' => 50,
            'processed_rows' => 20,
            'successful_rows' => 20,
            'error_rows' => 0,
            'last_processed_row' => 20 // Resume from row 21
        ]);
        
        // Set chunk size to 10 for multiple checkpoints
        $this->fileProcessor->setChunkSize(10);
        
        // Verify can resume processing
        $this->assertTrue($this->fileProcessor->canResumeProcessing($job));
        
        // Mock validator for remaining 30 rows
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->times(30) // Only remaining rows
            ->andReturn($validResult);
        
        // Mock duplicate detector for remaining rows
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->times(30)
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->times(30)
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times(30);
        
        // Track checkpoint updates
        $checkpointUpdates = [];
        $this->progressTracker->shouldReceive('updateProgress')
            ->times(3) // 30 remaining rows / 10 chunk size = 3 chunks
            ->andReturnUsing(function ($job, $processedRows) use (&$checkpointUpdates) {
                $checkpointUpdates[] = $processedRows;
            });
        
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times(30);
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        // Verify resumption worked correctly
        $this->assertEquals([30, 40, 50], $checkpointUpdates);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(50, $job->processed_rows);
        $this->assertEquals(50, $job->successful_rows);
        $this->assertEquals(50, $job->last_processed_row);
        
        unlink($csvPath);
    }

    /**
     * Create a test CSV file with given data
     */
    private function createTestCsvFile(array $data): string
    {
        $csvPath = tempnam(sys_get_temp_dir(), 'test') . '.csv';
        $handle = fopen($csvPath, 'w');
        
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }
        
        fclose($handle);
        return $csvPath;
    }
}