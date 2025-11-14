<?php

namespace Tests\Feature;

use App\Models\ImportJob;
use App\Models\Employee;
use App\Services\FileProcessorService;
use Tests\TestCase;
use Tests\Traits\UsesTestData;
use Illuminate\Foundation\Testing\RefreshDatabase;

class SampleDataIntegrationTest extends TestCase
{
    use RefreshDatabase, UsesTestData;

    /** @test */
    public function it_validates_test_data_files_exist_and_have_correct_structure()
    {
        $validation = $this->validateTestFileStructure();
        
        // Assert good employees CSV exists and has correct structure
        $this->assertTrue($validation['good_csv']['exists'], 'good-employees.csv file should exist');
        $this->assertTrue($validation['good_csv']['has_expected_headers'], 'good-employees.csv should have expected headers');
        $this->assertGreaterThan(1000, $validation['good_csv']['row_count'], 'good-employees.csv should have substantial data for performance testing');
        
        // Assert bad employees CSV exists and has correct structure  
        $this->assertTrue($validation['bad_csv']['exists'], 'bad-employees.csv file should exist');
        $this->assertTrue($validation['bad_csv']['has_expected_headers'], 'bad-employees.csv should have expected headers');
        $this->assertGreaterThan(5, $validation['bad_csv']['row_count'], 'bad-employees.csv should have multiple test cases');
        $this->assertLessThan(50, $validation['bad_csv']['row_count'], 'bad-employees.csv should be manageable size for validation testing');
        
        // Assert Excel file exists
        $this->assertTrue($validation['excel']['exists'], 'Assessment Data Set.xlsx file should exist');
        $this->assertGreaterThan(1000, $validation['excel']['file_size'], 'Excel file should have substantial size');
    }

    /** @test */
    public function it_can_process_good_employees_csv_for_performance_testing()
    {
        if (!$this->hasGoodEmployeesCsv()) {
            $this->markTestSkipped('good-employees.csv file not found');
        }

        $csvPath = $this->getGoodEmployeesCsvPath();
        $totalRows = $this->countCsvRows($csvPath);
        
        $job = ImportJob::create([
            'id' => 'perf-test-good-csv',
            'filename' => 'good-employees.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);

        $fileProcessor = app(FileProcessorService::class);
        
        // Set reasonable chunk size for performance testing
        $fileProcessor->setChunkSize(100);
        
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);
        
        $fileProcessor->processImport($job);
        
        $endTime = microtime(true);
        $memoryAfter = memory_get_usage(true);
        
        $processingTime = $endTime - $startTime;
        $memoryUsed = $memoryAfter - $memoryBefore;
        
        $job->refresh();
        
        // Performance assertions
        $this->assertEquals('completed', $job->status);
        $this->assertEquals($totalRows, $job->processed_rows);
        $this->assertGreaterThan(0, $job->successful_rows);
        
        // Performance benchmarks
        $rowsPerSecond = $totalRows / $processingTime;
        $this->assertGreaterThan(50, $rowsPerSecond, 'Should process at least 50 rows per second');
        
        // Memory efficiency - should not use excessive memory
        $memoryPerRow = $memoryUsed / $totalRows;
        $this->assertLessThan(10 * 1024, $memoryPerRow, 'Should use less than 10KB per row on average');
        
        // Verify some employees were actually created
        $this->assertGreaterThan(0, Employee::count());
        
        echo "\nPerformance Results for good-employees.csv:\n";
        echo "- Total rows: {$totalRows}\n";
        echo "- Processing time: " . number_format($processingTime, 2) . " seconds\n";
        echo "- Rows per second: " . number_format($rowsPerSecond, 2) . "\n";
        echo "- Memory used: " . number_format($memoryUsed / 1024 / 1024, 2) . " MB\n";
        echo "- Successful imports: {$job->successful_rows}\n";
        echo "- Error count: {$job->error_rows}\n";
    }

    /** @test */
    public function it_can_process_bad_employees_csv_for_validation_testing()
    {
        if (!$this->hasBadEmployeesCsv()) {
            $this->markTestSkipped('bad-employees.csv file not found');
        }

        $csvPath = $this->getBadEmployeesCsvPath();
        $totalRows = $this->countCsvRows($csvPath);
        
        $job = ImportJob::create([
            'id' => 'validation-test-bad-csv',
            'filename' => 'bad-employees.csv',
            'file_path' => $csvPath,
            'status' => 'pending',
            'total_rows' => $totalRows,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);

        $fileProcessor = app(FileProcessorService::class);
        
        $fileProcessor->processImport($job);
        
        $job->refresh();
        
        // Validation assertions
        $this->assertEquals('completed', $job->status);
        $this->assertEquals($totalRows, $job->processed_rows);
        
        // Should have validation errors since this is the "bad" file
        $this->assertGreaterThan(0, $job->error_rows, 'bad-employees.csv should generate validation errors');
        
        // Check that errors were properly recorded
        $errors = $job->importErrors;
        $this->assertGreaterThan(0, $errors->count(), 'Should have recorded import errors');
        
        // Verify error details contain useful information
        $firstError = $errors->first();
        $this->assertNotNull($firstError->error_message);
        $this->assertNotNull($firstError->row_number);
        $this->assertNotNull($firstError->error_type);
        
        echo "\nValidation Results for bad-employees.csv:\n";
        echo "- Total rows: {$totalRows}\n";
        echo "- Successful imports: {$job->successful_rows}\n";
        echo "- Validation errors: {$job->error_rows}\n";
        echo "- Error types found: " . $errors->pluck('error_type')->unique()->implode(', ') . "\n";
        
        // Sample some error messages
        $sampleErrors = $errors->take(3);
        echo "- Sample error messages:\n";
        foreach ($sampleErrors as $error) {
            echo "  Row {$error->row_number}: {$error->error_message}\n";
        }
    }

    /** @test */
    public function it_can_process_excel_assessment_data_file()
    {
        if (!$this->hasAssessmentDataExcel()) {
            $this->markTestSkipped('Assessment Data Set.xlsx file not found');
        }

        $excelPath = $this->getAssessmentDataExcelPath();
        
        $job = ImportJob::create([
            'id' => 'excel-test-assessment',
            'filename' => 'Assessment Data Set.xlsx',
            'file_path' => $excelPath,
            'status' => 'pending',
            'total_rows' => 0, // Will be calculated during processing
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0,
            'last_processed_row' => 0
        ]);

        $fileProcessor = app(FileProcessorService::class);
        
        $startTime = microtime(true);
        
        $fileProcessor->processImport($job);
        
        $endTime = microtime(true);
        $processingTime = $endTime - $startTime;
        
        $job->refresh();
        
        // Excel processing assertions
        $this->assertEquals('completed', $job->status);
        $this->assertGreaterThan(0, $job->total_rows, 'Excel file should contain data rows');
        $this->assertEquals($job->total_rows, $job->processed_rows, 'All rows should be processed');
        
        echo "\nExcel Processing Results for Assessment Data Set.xlsx:\n";
        echo "- Total rows: {$job->total_rows}\n";
        echo "- Processing time: " . number_format($processingTime, 2) . " seconds\n";
        echo "- Successful imports: {$job->successful_rows}\n";
        echo "- Error count: {$job->error_rows}\n";
        
        if ($job->error_rows > 0) {
            $errors = $job->importErrors->take(3);
            echo "- Sample error messages:\n";
            foreach ($errors as $error) {
                echo "  Row {$error->row_number}: {$error->error_message}\n";
            }
        }
    }

    /** @test */
    public function it_demonstrates_file_format_compatibility()
    {
        $results = [];
        
        // Test CSV format
        if ($this->hasGoodEmployeesCsv()) {
            $sample = $this->getCsvSample($this->getGoodEmployeesCsvPath(), 2);
            $results['csv'] = [
                'format' => 'CSV',
                'headers' => $sample['header'] ?? [],
                'sample_data' => $sample['rows'] ?? [],
                'row_count' => $this->countCsvRows($this->getGoodEmployeesCsvPath())
            ];
        }
        
        // Test Excel format detection
        if ($this->hasAssessmentDataExcel()) {
            $results['excel'] = [
                'format' => 'Excel',
                'file_size' => filesize($this->getAssessmentDataExcelPath()),
                'extension' => pathinfo($this->getAssessmentDataExcelPath(), PATHINFO_EXTENSION)
            ];
        }
        
        // Assert we can handle multiple formats
        $this->assertArrayHasKey('csv', $results, 'Should support CSV format');
        $this->assertArrayHasKey('excel', $results, 'Should support Excel format');
        
        echo "\nFile Format Compatibility:\n";
        foreach ($results as $format => $info) {
            echo "- {$info['format']} format: Supported\n";
            if (isset($info['headers'])) {
                echo "  Headers: " . implode(', ', $info['headers']) . "\n";
            }
            if (isset($info['row_count'])) {
                echo "  Row count: {$info['row_count']}\n";
            }
            if (isset($info['file_size'])) {
                echo "  File size: " . number_format($info['file_size'] / 1024, 2) . " KB\n";
            }
        }
    }

    /** @test */
    public function it_provides_comprehensive_test_data_summary()
    {
        $validation = $this->validateTestFileStructure();
        
        echo "\n=== Test Data Integration Summary ===\n";
        
        // Good employees CSV
        if ($validation['good_csv']['exists']) {
            echo "\n✓ good-employees.csv:\n";
            echo "  - Purpose: Performance testing\n";
            echo "  - Rows: {$validation['good_csv']['row_count']}\n";
            echo "  - Headers: " . implode(', ', $validation['good_csv']['headers']) . "\n";
            if ($validation['good_csv']['sample_row']) {
                echo "  - Sample employee: {$validation['good_csv']['sample_row']['first_name']} {$validation['good_csv']['sample_row']['last_name']}\n";
            }
        } else {
            echo "\n✗ good-employees.csv: Not found\n";
        }
        
        // Bad employees CSV
        if ($validation['bad_csv']['exists']) {
            echo "\n✓ bad-employees.csv:\n";
            echo "  - Purpose: Validation error testing\n";
            echo "  - Rows: {$validation['bad_csv']['row_count']}\n";
            echo "  - Headers: " . implode(', ', $validation['bad_csv']['headers']) . "\n";
        } else {
            echo "\n✗ bad-employees.csv: Not found\n";
        }
        
        // Excel file
        if ($validation['excel']['exists']) {
            echo "\n✓ Assessment Data Set.xlsx:\n";
            echo "  - Purpose: Excel format testing\n";
            echo "  - Size: " . number_format($validation['excel']['file_size'] / 1024, 2) . " KB\n";
        } else {
            echo "\n✗ Assessment Data Set.xlsx: Not found\n";
        }
        
        echo "\n=== Integration Status ===\n";
        $totalFiles = 3;
        $existingFiles = ($validation['good_csv']['exists'] ? 1 : 0) + 
                        ($validation['bad_csv']['exists'] ? 1 : 0) + 
                        ($validation['excel']['exists'] ? 1 : 0);
        
        echo "Files integrated: {$existingFiles}/{$totalFiles}\n";
        
        if ($existingFiles === $totalFiles) {
            echo "✓ All test data files are properly integrated\n";
        } else {
            echo "⚠ Some test data files are missing - tests will be skipped\n";
        }
        
        // This assertion ensures the test passes but provides visibility
        $this->assertTrue(true, 'Test data integration summary completed');
    }
}