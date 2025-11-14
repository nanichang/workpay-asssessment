<?php

namespace Tests\Unit;

use App\Models\ImportJob;
use App\Models\Employee;
use App\Services\FileProcessorService;
use App\Services\EmployeeValidator;
use App\Services\DuplicateDetector;
use App\Services\ProgressTracker;
use App\Services\ValidationResult;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Mockery;

class FileProcessorServiceTest extends TestCase
{
    use DatabaseTransactions;

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
    public function it_can_detect_csv_file_type()
    {
        $csvPath = $this->createTestCsvFile();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->fileProcessor);
        $method = $reflection->getMethod('detectFileType');
        $method->setAccessible(true);
        
        $fileType = $method->invoke($this->fileProcessor, $csvPath);
        
        $this->assertEquals('csv', $fileType);
        
        unlink($csvPath);
    }

    /** @test */
    public function it_can_detect_excel_file_type()
    {
        $xlsxPath = $this->createTestExcelFile();
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->fileProcessor);
        $method = $reflection->getMethod('detectFileType');
        $method->setAccessible(true);
        
        $fileType = $method->invoke($this->fileProcessor, $xlsxPath);
        
        $this->assertEquals('excel', $fileType);
        
        unlink($xlsxPath);
    }

    /** @test */
    public function it_throws_exception_for_unsupported_file_type()
    {
        $txtPath = tempnam(sys_get_temp_dir(), 'test') . '.txt';
        file_put_contents($txtPath, 'test content');
        
        $reflection = new \ReflectionClass($this->fileProcessor);
        $method = $reflection->getMethod('detectFileType');
        $method->setAccessible(true);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported file type: txt');
        
        $method->invoke($this->fileProcessor, $txtPath);
        
        unlink($txtPath);
    }

    /** @test */
    public function it_can_read_csv_file_rows()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com']
        ]);
        
        $handle = fopen($csvPath, 'r');
        
        $reflection = new \ReflectionClass($this->fileProcessor);
        $method = $reflection->getMethod('readCsvRows');
        $method->setAccessible(true);
        
        $rows = iterator_to_array($method->invoke($this->fileProcessor, $handle, 1));
        
        $this->assertCount(2, $rows);
        $this->assertEquals([
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com'
        ], $rows[0]);
        
        fclose($handle);
        unlink($csvPath);
    }

    /** @test */
    public function it_can_count_total_rows_in_csv()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com'],
            ['EMP-003', 'Bob', 'Johnson', 'bob@example.com']
        ]);
        
        $handle = fopen($csvPath, 'r');
        
        $reflection = new \ReflectionClass($this->fileProcessor);
        $method = $reflection->getMethod('countTotalRows');
        $method->setAccessible(true);
        
        $totalRows = $method->invoke($this->fileProcessor, $handle, 'csv');
        
        $this->assertEquals(3, $totalRows); // Excludes header row
        
        fclose($handle);
        unlink($csvPath);
    }

    /** @test */
    public function it_processes_csv_file_in_chunks()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com'],
            ['EMP-003', 'Bob', 'Johnson', 'bob@example.com']
        ]);
        
        $job = ImportJob::create([
            'id' => 'test-job-1',
            'filename' => 'test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 3,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Set small chunk size for testing
        $this->fileProcessor->setChunkSize(2);
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->andReturn(true);
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress');
        $this->progressTracker->shouldReceive('markRowProcessed');
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(3, $job->processed_rows);
        
        unlink($csvPath);
    }

    /** @test */
    public function it_can_resume_processing_from_checkpoint()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com'],
            ['EMP-003', 'Bob', 'Johnson', 'bob@example.com'],
            ['EMP-004', 'Alice', 'Brown', 'alice@example.com']
        ]);
        
        $job = ImportJob::create([
            'id' => 'test-job-2',
            'filename' => 'test.csv',
            'file_path' => $csvPath,
            'status' => 'processing',
            'total_rows' => 4,
            'processed_rows' => 2,
            'successful_rows' => 2,
            'error_rows' => 0,
            'last_processed_row' => 2 // Resume from row 3
        ]);
        
        $this->assertTrue($this->fileProcessor->canResumeProcessing($job));
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->andReturn(true);
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress');
        $this->progressTracker->shouldReceive('markRowProcessed');
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(4, $job->total_rows);
        
        unlink($csvPath);
    }

    /** @test */
    public function it_handles_validation_errors_correctly()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['', 'Jane', 'Smith', 'invalid-email'], // Invalid row
            ['EMP-003', 'Bob', 'Johnson', 'bob@example.com']
        ]);
        
        $job = ImportJob::create([
            'id' => 'test-job-3',
            'filename' => 'test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 3,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Mock validator - first and third rows valid, second row invalid
        $validResult = new ValidationResult(true);
        $invalidResult = new ValidationResult(false, ['Missing employee number']);
        
        $this->validator->shouldReceive('validate')
            ->times(3)
            ->andReturn($validResult, $invalidResult, $validResult);
        
        // Mock duplicate detector for valid rows
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times(2); // Only for valid rows
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress');
        $this->progressTracker->shouldReceive('markRowProcessed');
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(3, $job->processed_rows);
        $this->assertEquals(2, $job->successful_rows);
        $this->assertEquals(1, $job->error_rows);
        
        // Check that error was recorded
        $this->assertEquals(1, $job->importErrors()->count());
        
        unlink($csvPath);
    }

    /** @test */
    public function it_adjusts_chunk_size_based_on_memory_usage()
    {
        $initialChunkSize = $this->fileProcessor->getChunkSize();
        
        // Test setting chunk size
        $this->fileProcessor->setChunkSize(50);
        $this->assertEquals(50, $this->fileProcessor->getChunkSize());
        
        // Test minimum chunk size enforcement
        $this->fileProcessor->setChunkSize(0);
        $this->assertEquals(1, $this->fileProcessor->getChunkSize());
        
        $this->fileProcessor->setChunkSize(-10);
        $this->assertEquals(1, $this->fileProcessor->getChunkSize());
    }

    /** @test */
    public function it_processes_large_csv_file_efficiently()
    {
        // Create a larger CSV file for performance testing
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
            'id' => 'test-job-large',
            'filename' => 'large-test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 100,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Set reasonable chunk size
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
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress')
            ->atLeast(1); // At least one progress update
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times(100);
        $this->progressTracker->shouldReceive('markCompleted');
        
        $startTime = microtime(true);
        $this->fileProcessor->processImport($job);
        $endTime = microtime(true);
        
        $processingTime = $endTime - $startTime;
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(100, $job->processed_rows);
        $this->assertEquals(100, $job->successful_rows);
        
        // Assert reasonable processing time (should be under 5 seconds for 100 rows)
        $this->assertLessThan(5.0, $processingTime, 'Processing should complete within 5 seconds');
        
        unlink($csvPath);
    }

    /** @test */
    public function it_tracks_progress_accurately_during_processing()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com'],
            ['EMP-003', 'Bob', 'Johnson', 'bob@example.com'],
            ['EMP-004', 'Alice', 'Brown', 'alice@example.com'],
            ['EMP-005', 'Charlie', 'Wilson', 'charlie@example.com']
        ]);
        
        $job = ImportJob::create([
            'id' => 'test-job-progress',
            'filename' => 'progress-test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 5,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Set chunk size to 2 for multiple progress updates
        $this->fileProcessor->setChunkSize(2);
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->times(5)
            ->andReturn($validResult);
        
        // Mock duplicate detector
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->times(5)
            ->andReturn(false);
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->times(5)
            ->andReturn(null);
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times(5);
        
        // Track progress updates
        $progressUpdates = [];
        $this->progressTracker->shouldReceive('updateProgress')
            ->andReturnUsing(function ($job, $processedRows) use (&$progressUpdates) {
                $progressUpdates[] = $processedRows;
            });
        
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times(5);
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        // Verify progress was updated correctly
        $this->assertCount(3, $progressUpdates); // 3 chunks: 2, 2, 1 rows
        $this->assertEquals([2, 4, 5], $progressUpdates);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(5, $job->processed_rows);
        
        unlink($csvPath);
    }

    /** @test */
    public function it_handles_duplicate_employees_correctly()
    {
        $csvPath = $this->createTestCsvFile([
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com'],
            ['EMP-001', 'John', 'Updated', 'john@example.com'] // Duplicate
        ]);
        
        $job = ImportJob::create([
            'id' => 'test-job-duplicates',
            'filename' => 'duplicates-test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 3,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Mock validator to return valid results
        $validResult = new ValidationResult(true);
        $this->validator->shouldReceive('validate')
            ->times(3)
            ->andReturn($validResult);
        
        // Mock duplicate detector - third row is duplicate
        $this->duplicateDetector->shouldReceive('wasAlreadyProcessed')
            ->times(3)
            ->andReturn(false, false, true); // Third call returns true (duplicate)
        
        $this->duplicateDetector->shouldReceive('findExistingEmployee')
            ->times(2) // Only called for non-duplicates
            ->andReturn(null);
        
        $this->duplicateDetector->shouldReceive('markAsProcessed')
            ->times(2); // Only for non-duplicates
        
        // Mock progress tracker
        $this->progressTracker->shouldReceive('updateProgress');
        $this->progressTracker->shouldReceive('markRowProcessed')
            ->times(3); // All rows are processed (including duplicates)
        $this->progressTracker->shouldReceive('markCompleted');
        
        $this->fileProcessor->processImport($job);
        
        $job->refresh();
        $this->assertEquals('completed', $job->status);
        $this->assertEquals(3, $job->processed_rows);
        $this->assertEquals(2, $job->successful_rows);
        $this->assertEquals(1, $job->error_rows); // Duplicate counted as error
        
        unlink($csvPath);
    }

    /** @test */
    public function it_cleans_row_data_correctly()
    {
        $csvPath = $this->createTestCsvFile([
            ['Employee Number', 'First Name', 'Last Name', 'Email'],
            ['  EMP-001  ', '  John  ', '  Doe  ', '  john@example.com  ']
        ]);
        
        $job = ImportJob::create([
            'id' => 'test-job-cleaning',
            'filename' => 'cleaning-test.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => 1,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);
        
        // Mock validator to capture cleaned data
        $cleanedData = null;
        $validResult = new ValidationResult();
        $this->validator->shouldReceive('validate')
            ->once()
            ->andReturnUsing(function ($data) use (&$cleanedData, $validResult) {
                $cleanedData = $data;
                return $validResult;
            });
        
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
        
        // Verify data was cleaned correctly
        $this->assertNotNull($cleanedData);
        $this->assertEquals('EMP-001', $cleanedData['employee_number']);
        $this->assertEquals('John', $cleanedData['first_name']);
        $this->assertEquals('Doe', $cleanedData['last_name']);
        $this->assertEquals('john@example.com', $cleanedData['email']);
        
        unlink($csvPath);
    }

    /** @test */
    public function it_processes_real_good_employees_csv_file()
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
        
        // Set reasonable chunk size for large file
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
        
        // Track progress updates
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
        $this->assertEquals('completed', $job->status);
        $this->assertEquals($totalRows, $job->processed_rows);
        $this->assertEquals($totalRows, $job->successful_rows);
        $this->assertEquals(0, $job->error_rows);
        
        // Verify progress was tracked correctly
        $this->assertNotEmpty($progressUpdates);
        $this->assertEquals($totalRows, end($progressUpdates));
        
        // Performance assertion - should process efficiently
        $rowsPerSecond = $totalRows / $processingTime;
        $this->assertGreaterThan(100, $rowsPerSecond, 'Should process at least 100 rows per second');
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
        
        // First row is valid, rest have various validation errors
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
        $this->assertCount(4, $progressUpdates); // 4 chunks
        $this->assertEquals(25, $progressUpdates[0]['processed_rows']);
        $this->assertEquals(25.0, $progressUpdates[0]['percentage']);
        $this->assertEquals(50, $progressUpdates[1]['processed_rows']);
        $this->assertEquals(50.0, $progressUpdates[1]['percentage']);
        $this->assertEquals(75, $progressUpdates[2]['processed_rows']);
        $this->assertEquals(75.0, $progressUpdates[2]['percentage']);
        $this->assertEquals(100, $progressUpdates[3]['processed_rows']);
        $this->assertEquals(100.0, $progressUpdates[3]['percentage']);
        
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
    private function createTestCsvFile(?array $data = null): string
    {
        $data = $data ?? [
            ['employee_number', 'first_name', 'last_name', 'email'],
            ['EMP-001', 'John', 'Doe', 'john@example.com'],
            ['EMP-002', 'Jane', 'Smith', 'jane@example.com']
        ];
        
        $csvPath = tempnam(sys_get_temp_dir(), 'test') . '.csv';
        $handle = fopen($csvPath, 'w');
        
        foreach ($data as $row) {
            fputcsv($handle, $row);
        }
        
        fclose($handle);
        return $csvPath;
    }

    /**
     * Create a test Excel file
     */
    private function createTestExcelFile(): string
    {
        $xlsxPath = tempnam(sys_get_temp_dir(), 'test') . '.xlsx';
        
        // Create a simple Excel file using PhpSpreadsheet
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Add headers
        $worksheet->setCellValue('A1', 'employee_number');
        $worksheet->setCellValue('B1', 'first_name');
        $worksheet->setCellValue('C1', 'last_name');
        $worksheet->setCellValue('D1', 'email');
        
        // Add data
        $worksheet->setCellValue('A2', 'EMP-001');
        $worksheet->setCellValue('B2', 'John');
        $worksheet->setCellValue('C2', 'Doe');
        $worksheet->setCellValue('D2', 'john@example.com');
        
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($xlsxPath);
        
        return $xlsxPath;
    }
}