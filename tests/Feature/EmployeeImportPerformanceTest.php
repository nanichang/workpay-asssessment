<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ImportJob;
use App\Services\FileProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\UsesTestData;

class EmployeeImportPerformanceTest extends TestCase
{
    use RefreshDatabase, UsesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_processes_large_csv_files_within_memory_limits()
    {
        // Generate a large CSV file (5000 rows)
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        
        $departments = ['Engineering', 'Finance', 'Sales', 'HR', 'Marketing', 'Operations', 'Legal', 'Support'];
        $currencies = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
        $countries = ['KE', 'NG', 'ZA', 'UG', 'GH', 'RW', 'TZ'];
        
        for ($i = 1; $i <= 5000; $i++) {
            $empNum = sprintf('PERF-%05d', $i);
            $firstName = 'Employee' . $i;
            $lastName = 'Performance' . $i;
            $email = "perf.employee{$i}@company.com";
            $department = $departments[$i % count($departments)];
            $salary = rand(40000, 200000);
            $currency = $currencies[$i % count($currencies)];
            $country = $countries[$i % count($countries)];
            $startDate = '2022-01-01';
            
            $csvContent .= "{$empNum},{$firstName},{$lastName},{$email},{$department},{$salary},{$currency},{$country},{$startDate}\n";
        }
        
        $file = UploadedFile::fake()->createWithContent('performance_test_5k.csv', $csvContent);

        // Measure performance metrics
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);
        $peakMemoryBefore = memory_get_peak_usage(true);

        // Upload and process
        $uploadResponse = $this->postJson('/api/employee-import/upload', [
            'file' => $file
        ]);

        $uploadResponse->assertStatus(201);
        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);
        
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        $endTime = microtime(true);
        $memoryAfter = memory_get_usage(true);
        $peakMemoryAfter = memory_get_peak_usage(true);

        // Calculate metrics
        $processingTime = $endTime - $startTime;
        $memoryUsed = $memoryAfter - $memoryBefore;
        $peakMemoryUsed = $peakMemoryAfter - $peakMemoryBefore;
        $rowsPerSecond = 5000 / $processingTime;
        $memoryPerRow = $memoryUsed / 5000;

        // Verify processing results
        $importJob->refresh();
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(5000, $importJob->total_rows);
        $this->assertEquals(5000, $importJob->processed_rows);
        $this->assertEquals(5000, $importJob->successful_rows);
        $this->assertEquals(0, $importJob->error_rows);

        // Performance assertions
        $this->assertLessThan(300, $processingTime, 'Should process 5000 rows in under 5 minutes');
        $this->assertGreaterThan(15, $rowsPerSecond, 'Should process at least 15 rows per second');
        $this->assertLessThan(100 * 1024 * 1024, $peakMemoryUsed, 'Peak memory should be under 100MB');
        $this->assertLessThan(20 * 1024, $memoryPerRow, 'Should use less than 20KB per row on average');

        // Verify data integrity
        $this->assertEquals(5000, Employee::count());
        
        // Sample check - verify first and last employees
        $firstEmployee = Employee::where('employee_number', 'PERF-00001')->first();
        $this->assertNotNull($firstEmployee);
        $this->assertEquals('Employee1', $firstEmployee->first_name);
        
        $lastEmployee = Employee::where('employee_number', 'PERF-05000')->first();
        $this->assertNotNull($lastEmployee);
        $this->assertEquals('Employee5000', $lastEmployee->first_name);

        echo "\n=== Large File Performance Test Results ===\n";
        echo "File: 5,000 employee records\n";
        echo "Processing time: " . number_format($processingTime, 2) . " seconds\n";
        echo "Rows per second: " . number_format($rowsPerSecond, 2) . "\n";
        echo "Memory used: " . number_format($memoryUsed / 1024 / 1024, 2) . " MB\n";
        echo "Peak memory: " . number_format($peakMemoryUsed / 1024 / 1024, 2) . " MB\n";
        echo "Memory per row: " . number_format($memoryPerRow / 1024, 2) . " KB\n";
        echo "Success rate: 100%\n";
    }

    /** @test */
    public function it_handles_chunked_processing_efficiently()
    {
        // Test different chunk sizes for optimal performance
        $chunkSizes = [50, 100, 200];
        $results = [];
        
        foreach ($chunkSizes as $chunkSize) {
            // Generate test data
            $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
            
            for ($i = 1; $i <= 1000; $i++) {
                $empNum = sprintf('CHUNK-%d-%04d', $chunkSize, $i);
                $csvContent .= "{$empNum},Employee{$i},Test{$i},emp{$i}@test.com,Engineering,100000,KES,KE,2022-01-01\n";
            }
            
            $file = UploadedFile::fake()->createWithContent("chunk_test_{$chunkSize}.csv", $csvContent);

            // Process with specific chunk size
            $startTime = microtime(true);
            
            $uploadResponse = $this->postJson('/api/employee-imports', [
                'file' => $file
            ]);
            
            $importJobId = $uploadResponse->json('data.import_job_id');
            $importJob = ImportJob::find($importJobId);
            
            $fileProcessor = app(FileProcessorService::class);
            $fileProcessor->setChunkSize($chunkSize);
            $fileProcessor->processImport($importJob);
            
            $endTime = microtime(true);
            $processingTime = $endTime - $startTime;
            
            // Verify results
            $importJob->refresh();
            $this->assertEquals('completed', $importJob->status);
            $this->assertEquals(1000, $importJob->successful_rows);
            
            $results[$chunkSize] = [
                'processing_time' => $processingTime,
                'rows_per_second' => 1000 / $processingTime,
                'chunks_processed' => ceil(1000 / $chunkSize)
            ];
            
            // Clean up for next iteration
            Employee::where('employee_number', 'like', "CHUNK-{$chunkSize}-%")->delete();
        }

        // Analyze results
        echo "\n=== Chunk Size Performance Comparison ===\n";
        foreach ($results as $chunkSize => $metrics) {
            echo "Chunk size {$chunkSize}:\n";
            echo "  - Processing time: " . number_format($metrics['processing_time'], 2) . " seconds\n";
            echo "  - Rows per second: " . number_format($metrics['rows_per_second'], 2) . "\n";
            echo "  - Chunks processed: {$metrics['chunks_processed']}\n";
        }

        // All chunk sizes should complete successfully
        foreach ($results as $chunkSize => $metrics) {
            $this->assertLessThan(60, $metrics['processing_time'], "Chunk size {$chunkSize} should complete in under 60 seconds");
            $this->assertGreaterThan(10, $metrics['rows_per_second'], "Chunk size {$chunkSize} should process at least 10 rows per second");
        }
    }

    /** @test */
    public function it_maintains_performance_with_validation_errors()
    {
        // Generate a large file with mixed valid and invalid data
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        
        for ($i = 1; $i <= 2000; $i++) {
            $empNum = sprintf('VALID-%04d', $i);
            
            // Every 5th record has validation errors
            if ($i % 5 === 0) {
                // Invalid record
                $csvContent .= ",Invalid{$i},,invalid-email-{$i},Department,-50000,XXX,ZZ,2030-01-01\n";
            } else {
                // Valid record
                $csvContent .= "{$empNum},Employee{$i},Test{$i},employee{$i}@test.com,Engineering,100000,KES,KE,2022-01-01\n";
            }
        }
        
        $file = UploadedFile::fake()->createWithContent('validation_performance_test.csv', $csvContent);

        // Process and measure performance
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);

        $uploadResponse = $this->postJson('/api/employee-import/upload', [
            'file' => $file
        ]);

        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);
        
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        $endTime = microtime(true);
        $memoryAfter = memory_get_usage(true);

        // Calculate metrics
        $processingTime = $endTime - $startTime;
        $memoryUsed = $memoryAfter - $memoryBefore;
        $rowsPerSecond = 2000 / $processingTime;

        // Verify processing results
        $importJob->refresh();
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(2000, $importJob->total_rows);
        $this->assertEquals(2000, $importJob->processed_rows);
        
        // Should have ~1600 successful (80%) and ~400 errors (20%)
        $this->assertGreaterThan(1500, $importJob->successful_rows);
        $this->assertGreaterThan(300, $importJob->error_rows);
        $this->assertEquals(2000, $importJob->successful_rows + $importJob->error_rows);

        // Performance should still be acceptable with validation errors
        $this->assertLessThan(120, $processingTime, 'Should process 2000 mixed records in under 2 minutes');
        $this->assertGreaterThan(15, $rowsPerSecond, 'Should maintain at least 15 rows per second with validation');

        echo "\n=== Validation Performance Test Results ===\n";
        echo "File: 2,000 records (80% valid, 20% invalid)\n";
        echo "Processing time: " . number_format($processingTime, 2) . " seconds\n";
        echo "Rows per second: " . number_format($rowsPerSecond, 2) . "\n";
        echo "Memory used: " . number_format($memoryUsed / 1024 / 1024, 2) . " MB\n";
        echo "Successful imports: {$importJob->successful_rows}\n";
        echo "Validation errors: {$importJob->error_rows}\n";
        echo "Success rate: " . number_format(($importJob->successful_rows / $importJob->total_rows) * 100, 1) . "%\n";
    }

    /** @test */
    public function it_processes_sample_data_files_for_performance_benchmarks()
    {
        $results = [];
        
        // Test good-employees.csv if available
        if ($this->hasGoodEmployeesCsv()) {
            $csvPath = $this->getGoodEmployeesCsvPath();
            $totalRows = $this->countCsvRows($csvPath);
            
            $job = ImportJob::create([
                'id' => 'benchmark-good-csv',
                'filename' => 'good-employees.csv',
                'file_path' => $csvPath,
                'status' => 'pending',
                'total_rows' => $totalRows
            ]);

            $startTime = microtime(true);
            $memoryBefore = memory_get_usage(true);
            
            $fileProcessor = app(FileProcessorService::class);
            $fileProcessor->processImport($job);
            
            $endTime = microtime(true);
            $memoryAfter = memory_get_usage(true);
            
            $job->refresh();
            
            $results['good_csv'] = [
                'file' => 'good-employees.csv',
                'total_rows' => $totalRows,
                'processing_time' => $endTime - $startTime,
                'memory_used' => $memoryAfter - $memoryBefore,
                'rows_per_second' => $totalRows / ($endTime - $startTime),
                'success_rate' => ($job->successful_rows / $job->total_rows) * 100,
                'successful_rows' => $job->successful_rows,
                'error_rows' => $job->error_rows
            ];
        }

        // Test bad-employees.csv if available
        if ($this->hasBadEmployeesCsv()) {
            $csvPath = $this->getBadEmployeesCsvPath();
            $totalRows = $this->countCsvRows($csvPath);
            
            $job = ImportJob::create([
                'id' => 'benchmark-bad-csv',
                'filename' => 'bad-employees.csv',
                'file_path' => $csvPath,
                'status' => 'pending',
                'total_rows' => $totalRows
            ]);

            $startTime = microtime(true);
            
            $fileProcessor = app(FileProcessorService::class);
            $fileProcessor->processImport($job);
            
            $endTime = microtime(true);
            
            $job->refresh();
            
            $results['bad_csv'] = [
                'file' => 'bad-employees.csv',
                'total_rows' => $totalRows,
                'processing_time' => $endTime - $startTime,
                'rows_per_second' => $totalRows / ($endTime - $startTime),
                'success_rate' => ($job->successful_rows / $job->total_rows) * 100,
                'successful_rows' => $job->successful_rows,
                'error_rows' => $job->error_rows
            ];
        }

        // Test performance test files from storage
        $performanceFiles = [
            'storage/app/test-scenarios/performance-test-25.csv',
            'storage/app/test-scenarios/basic-test-employees.csv'
        ];

        foreach ($performanceFiles as $filePath) {
            if (file_exists(base_path($filePath))) {
                $filename = basename($filePath);
                $totalRows = $this->countCsvRows(base_path($filePath));
                
                if ($totalRows > 0) {
                    $job = ImportJob::create([
                        'id' => 'benchmark-' . str_replace(['.', '-'], '_', $filename),
                        'filename' => $filename,
                        'file_path' => base_path($filePath),
                        'status' => 'pending',
                        'total_rows' => $totalRows
                    ]);

                    $startTime = microtime(true);
                    
                    $fileProcessor = app(FileProcessorService::class);
                    $fileProcessor->processImport($job);
                    
                    $endTime = microtime(true);
                    
                    $job->refresh();
                    
                    $results[str_replace(['.', '-'], '_', $filename)] = [
                        'file' => $filename,
                        'total_rows' => $totalRows,
                        'processing_time' => $endTime - $startTime,
                        'rows_per_second' => $totalRows / ($endTime - $startTime),
                        'success_rate' => ($job->successful_rows / $job->total_rows) * 100,
                        'successful_rows' => $job->successful_rows,
                        'error_rows' => $job->error_rows
                    ];
                }
            }
        }

        // Display comprehensive benchmark results
        echo "\n=== Sample Data Performance Benchmarks ===\n";
        
        if (empty($results)) {
            echo "No sample data files found for benchmarking.\n";
            $this->markTestSkipped('No sample data files available for performance testing');
        }

        foreach ($results as $key => $result) {
            echo "\n{$result['file']}:\n";
            echo "  - Total rows: {$result['total_rows']}\n";
            echo "  - Processing time: " . number_format($result['processing_time'], 2) . " seconds\n";
            echo "  - Rows per second: " . number_format($result['rows_per_second'], 2) . "\n";
            echo "  - Success rate: " . number_format($result['success_rate'], 1) . "%\n";
            echo "  - Successful imports: {$result['successful_rows']}\n";
            echo "  - Validation errors: {$result['error_rows']}\n";
            
            if (isset($result['memory_used'])) {
                echo "  - Memory used: " . number_format($result['memory_used'] / 1024 / 1024, 2) . " MB\n";
            }
        }

        // Performance assertions
        foreach ($results as $result) {
            if ($result['total_rows'] > 100) {
                $this->assertGreaterThan(10, $result['rows_per_second'], 
                    "File {$result['file']} should process at least 10 rows per second");
            }
            
            $this->assertLessThan(300, $result['processing_time'], 
                "File {$result['file']} should complete processing in under 5 minutes");
        }

        $this->assertTrue(count($results) > 0, 'Should have processed at least one sample file');
    }

    /** @test */
    public function it_measures_memory_efficiency_across_file_sizes()
    {
        $fileSizes = [100, 500, 1000, 2500];
        $memoryResults = [];
        
        foreach ($fileSizes as $size) {
            // Generate CSV content
            $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
            
            for ($i = 1; $i <= $size; $i++) {
                $empNum = sprintf('MEM-%d-%04d', $size, $i);
                $csvContent .= "{$empNum},Employee{$i},Test{$i},emp{$i}@memory.test,Engineering,100000,KES,KE,2022-01-01\n";
            }
            
            $file = UploadedFile::fake()->createWithContent("memory_test_{$size}.csv", $csvContent);

            // Measure memory usage
            $memoryBefore = memory_get_usage(true);
            $peakBefore = memory_get_peak_usage(true);
            
            $uploadResponse = $this->postJson('/api/employee-imports', [
                'file' => $file
            ]);
            
            $importJobId = $uploadResponse->json('data.import_job_id');
            $importJob = ImportJob::find($importJobId);
            
            $fileProcessor = app(FileProcessorService::class);
            $fileProcessor->processImport($importJob);
            
            $memoryAfter = memory_get_usage(true);
            $peakAfter = memory_get_peak_usage(true);
            
            $memoryUsed = $memoryAfter - $memoryBefore;
            $peakMemoryUsed = $peakAfter - $peakBefore;
            
            $memoryResults[$size] = [
                'rows' => $size,
                'memory_used' => $memoryUsed,
                'peak_memory' => $peakMemoryUsed,
                'memory_per_row' => $memoryUsed / $size,
                'peak_per_row' => $peakMemoryUsed / $size
            ];
            
            // Verify processing completed
            $importJob->refresh();
            $this->assertEquals('completed', $importJob->status);
            $this->assertEquals($size, $importJob->successful_rows);
            
            // Clean up
            Employee::where('employee_number', 'like', "MEM-{$size}-%")->delete();
        }

        // Display memory efficiency results
        echo "\n=== Memory Efficiency Analysis ===\n";
        foreach ($memoryResults as $size => $result) {
            echo "File size: {$result['rows']} rows\n";
            echo "  - Memory used: " . number_format($result['memory_used'] / 1024 / 1024, 2) . " MB\n";
            echo "  - Peak memory: " . number_format($result['peak_memory'] / 1024 / 1024, 2) . " MB\n";
            echo "  - Memory per row: " . number_format($result['memory_per_row'] / 1024, 2) . " KB\n";
            echo "  - Peak per row: " . number_format($result['peak_per_row'] / 1024, 2) . " KB\n\n";
        }

        // Memory efficiency assertions
        foreach ($memoryResults as $size => $result) {
            $this->assertLessThan(50 * 1024, $result['memory_per_row'], 
                "Memory per row should be under 50KB for {$size} rows");
            $this->assertLessThan(100 * 1024, $result['peak_per_row'], 
                "Peak memory per row should be under 100KB for {$size} rows");
        }

        // Memory should scale reasonably with file size
        $smallFileMemory = $memoryResults[100]['memory_per_row'];
        $largeFileMemory = $memoryResults[2500]['memory_per_row'];
        
        // Memory per row shouldn't increase dramatically with file size (should be relatively constant)
        $memoryIncrease = $largeFileMemory / $smallFileMemory;
        $this->assertLessThan(3, $memoryIncrease, 
            'Memory per row should not increase more than 3x between small and large files');
    }
}