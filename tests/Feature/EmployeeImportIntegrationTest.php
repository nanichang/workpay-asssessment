<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\FileProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\UsesTestData;

class EmployeeImportIntegrationTest extends TestCase
{
    use RefreshDatabase, UsesTestData;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        
        // Use array cache for testing to avoid Redis dependency
        config(['import.cache.store' => 'array']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_completes_full_import_workflow_with_valid_csv_file()
    {
        // Create a valid CSV file with multiple employees
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        $csvContent .= "EMP-002,Jane,Smith,jane.smith@example.com,Finance,85000,USD,KE,2022-02-01\n";
        $csvContent .= "EMP-003,Bob,Johnson,bob.johnson@example.com,Sales,75000,KES,KE,2022-03-01\n";
        
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        // Step 1: Upload file via API
        $uploadResponse = $this->postJson('/api/employee-import/upload', [
            'file' => $file
        ]);

        $uploadResponse->assertStatus(201);
        $importJobId = $uploadResponse->json('data.import_job_id');

        // Verify import job was created
        $importJob = ImportJob::find($importJobId);
        $this->assertNotNull($importJob);
        $this->assertEquals('employees.csv', $importJob->filename);
        
        // Job may already be processed due to sync queue in testing
        $this->assertContains($importJob->status, ['pending', 'processing', 'completed']);

        // Step 2: Verify processing results (job should be completed by now)
        $importJob->refresh();
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(3, $importJob->total_rows);
        $this->assertEquals(3, $importJob->processed_rows);
        $this->assertEquals(3, $importJob->successful_rows);
        $this->assertEquals(0, $importJob->error_rows);

        // Step 3: Verify employees were created
        $this->assertEquals(3, Employee::count());
        
        $employee1 = Employee::where('employee_number', 'EMP-001')->first();
        $this->assertNotNull($employee1);
        $this->assertEquals('John', $employee1->first_name);
        $this->assertEquals('Doe', $employee1->last_name);
        $this->assertEquals('john.doe@example.com', $employee1->email);
        $this->assertEquals('Engineering', $employee1->department);
        $this->assertEquals(100000, $employee1->salary);
        $this->assertEquals('KES', $employee1->currency);

        // Step 4: Check progress API
        $progressResponse = $this->getJson("/api/employee-import/{$importJobId}/progress");
        $progressResponse->assertStatus(200);
        $progressData = $progressResponse->json('data');
        $this->assertEquals(100, $progressData['percentage']);
        $this->assertEquals('completed', $progressData['status']);

        // Step 5: Check errors API (should be empty)
        $errorsResponse = $this->getJson("/api/employee-import/{$importJobId}/errors");
        $errorsResponse->assertStatus(200);
        $this->assertCount(0, $errorsResponse->json('data.errors'));

        // Step 6: Check summary API
        $summaryResponse = $this->getJson("/api/employee-import/{$importJobId}/summary");
        $summaryResponse->assertStatus(200);
        $summaryData = $summaryResponse->json('data');
        $this->assertEquals(100, $summaryData['statistics']['success_rate']);
        $this->assertEquals(0, $summaryData['statistics']['error_rate']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_validation_errors_in_full_workflow()
    {
        // Create CSV with validation errors
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n"; // Valid
        $csvContent .= ",Jane,Smith,invalid-email,Finance,85000,USD,KE,2022-02-01\n"; // Missing employee_number, invalid email
        $csvContent .= "EMP-003,Bob,,bob.johnson@example.com,Sales,-75000,XXX,ZZ,2030-03-01\n"; // Missing last_name, negative salary, invalid currency/country, future date
        $csvContent .= "EMP-004,Alice,Brown,alice.brown@example.com,HR,90000,KES,KE,2022-04-01\n"; // Valid
        
        $file = UploadedFile::fake()->createWithContent('employees_with_errors.csv', $csvContent);

        // Step 1: Upload file
        $uploadResponse = $this->postJson('/api/employee-import/upload', [
            'file' => $file
        ]);

        $uploadResponse->assertStatus(201);
        $importJobId = $uploadResponse->json('data.import_job_id');

        // Step 2: Process the job
        $importJob = ImportJob::find($importJobId);
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Step 3: Verify processing results
        $importJob->refresh();
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(4, $importJob->total_rows);
        $this->assertEquals(4, $importJob->processed_rows);
        $this->assertEquals(2, $importJob->successful_rows); // Only 2 valid rows
        $this->assertEquals(2, $importJob->error_rows); // 2 rows with errors

        // Step 4: Verify only valid employees were created
        $this->assertEquals(2, Employee::count());
        $this->assertNotNull(Employee::where('employee_number', 'EMP-001')->first());
        $this->assertNotNull(Employee::where('employee_number', 'EMP-004')->first());

        // Step 5: Verify errors were recorded
        $errors = ImportError::where('import_job_id', $importJobId)->get();
        $this->assertGreaterThan(0, $errors->count());

        // Step 6: Check errors API
        $errorsResponse = $this->getJson("/api/employee-import/{$importJobId}/errors");
        $errorsResponse->assertStatus(200);
        $errorData = $errorsResponse->json('data.errors');
        $this->assertGreaterThan(0, count($errorData));

        // Verify error details
        $errorMessages = collect($errorData)->pluck('error_message')->toArray();
        $this->assertTrue(in_array('Employee number is required', $errorMessages) || 
                         in_array('Invalid email format', $errorMessages) ||
                         str_contains(implode(' ', $errorMessages), 'employee_number') ||
                         str_contains(implode(' ', $errorMessages), 'email'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_processes_large_file_efficiently()
    {
        // Create a larger CSV file for performance testing
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        
        $departments = ['Engineering', 'Finance', 'Sales', 'HR', 'Marketing'];
        $currencies = ['KES', 'USD', 'ZAR', 'NGN'];
        $countries = ['KE', 'NG', 'ZA', 'UG'];
        
        // Generate 1000 employee records
        for ($i = 1; $i <= 1000; $i++) {
            $empNum = sprintf('EMP-%04d', $i);
            $firstName = 'Employee' . $i;
            $lastName = 'Test' . $i;
            $email = "employee{$i}@example.com";
            $department = $departments[$i % count($departments)];
            $salary = rand(50000, 150000);
            $currency = $currencies[$i % count($currencies)];
            $country = $countries[$i % count($countries)];
            $startDate = '2022-01-01';
            
            $csvContent .= "{$empNum},{$firstName},{$lastName},{$email},{$department},{$salary},{$currency},{$country},{$startDate}\n";
        }
        
        $file = UploadedFile::fake()->createWithContent('large_employees.csv', $csvContent);

        // Measure performance
        $startTime = microtime(true);
        $memoryBefore = memory_get_usage(true);

        // Upload and process
        $uploadResponse = $this->postJson('/api/employee-import/upload', [
            'file' => $file
        ]);

        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);
        
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        $endTime = microtime(true);
        $memoryAfter = memory_get_usage(true);

        // Verify results
        $importJob->refresh();
        $this->assertEquals('completed', $importJob->status);
        $this->assertEquals(1000, $importJob->total_rows);
        $this->assertEquals(1000, $importJob->processed_rows);
        $this->assertEquals(1000, $importJob->successful_rows);
        $this->assertEquals(0, $importJob->error_rows);

        // Performance assertions
        $processingTime = $endTime - $startTime;
        $memoryUsed = $memoryAfter - $memoryBefore;
        $rowsPerSecond = 1000 / $processingTime;

        $this->assertLessThan(60, $processingTime, 'Should process 1000 rows in under 60 seconds');
        $this->assertGreaterThan(10, $rowsPerSecond, 'Should process at least 10 rows per second');
        $this->assertLessThan(50 * 1024 * 1024, $memoryUsed, 'Should use less than 50MB for 1000 rows');

        // Verify all employees were created
        $this->assertEquals(1000, Employee::count());

        echo "\nLarge File Performance Results:\n";
        echo "- Rows: 1000\n";
        echo "- Processing time: " . number_format($processingTime, 2) . " seconds\n";
        echo "- Rows per second: " . number_format($rowsPerSecond, 2) . "\n";
        echo "- Memory used: " . number_format($memoryUsed / 1024 / 1024, 2) . " MB\n";
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_imports()
    {
        // Create two different CSV files
        $csvContent1 = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent1 .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        $csvContent1 .= "EMP-002,Jane,Smith,jane.smith@example.com,Finance,85000,USD,KE,2022-02-01\n";
        
        $csvContent2 = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent2 .= "EMP-003,Bob,Johnson,bob.johnson@example.com,Sales,75000,KES,KE,2022-03-01\n";
        $csvContent2 .= "EMP-004,Alice,Brown,alice.brown@example.com,HR,90000,KES,KE,2022-04-01\n";
        
        $file1 = UploadedFile::fake()->createWithContent('employees1.csv', $csvContent1);
        $file2 = UploadedFile::fake()->createWithContent('employees2.csv', $csvContent2);

        // Upload both files
        $uploadResponse1 = $this->postJson('/api/employee-import/upload', ['file' => $file1]);
        $uploadResponse2 = $this->postJson('/api/employee-import/upload', ['file' => $file2]);

        $uploadResponse1->assertStatus(201);
        $uploadResponse2->assertStatus(201);

        $importJobId1 = $uploadResponse1->json('data.import_job_id');
        $importJobId2 = $uploadResponse2->json('data.import_job_id');

        // Process both jobs
        $importJob1 = ImportJob::find($importJobId1);
        $importJob2 = ImportJob::find($importJobId2);
        
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob1);
        $fileProcessor->processImport($importJob2);

        // Verify both completed successfully
        $importJob1->refresh();
        $importJob2->refresh();
        
        $this->assertEquals('completed', $importJob1->status);
        $this->assertEquals('completed', $importJob2->status);
        $this->assertEquals(2, $importJob1->successful_rows);
        $this->assertEquals(2, $importJob2->successful_rows);

        // Verify all 4 employees were created
        $this->assertEquals(4, Employee::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_provides_real_time_progress_updates()
    {
        // Create a medium-sized CSV file
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        
        for ($i = 1; $i <= 50; $i++) {
            $csvContent .= "EMP-{$i},Employee{$i},Test{$i},employee{$i}@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        }
        
        $file = UploadedFile::fake()->createWithContent('progress_test.csv', $csvContent);

        // Upload file
        $uploadResponse = $this->postJson('/api/employee-import/upload', [
            'file' => $file
        ]);

        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);

        // Check initial progress
        $progressResponse = $this->getJson("/api/employee-import/{$importJobId}/progress");
        $progressResponse->assertStatus(200);
        $initialProgress = $progressResponse->json('data');
        $this->assertEquals(0, $initialProgress['percentage']);
        $this->assertEquals('pending', $initialProgress['status']);

        // Process the job
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Check final progress
        $progressResponse = $this->getJson("/api/employee-import/{$importJobId}/progress");
        $progressResponse->assertStatus(200);
        $finalProgress = $progressResponse->json('data');
        $this->assertEquals(100, $finalProgress['percentage']);
        $this->assertEquals('completed', $finalProgress['status']);
        $this->assertEquals(50, $finalProgress['total_rows']);
        $this->assertEquals(50, $finalProgress['processed_rows']);
        $this->assertEquals(50, $finalProgress['successful_rows']);
    }
}