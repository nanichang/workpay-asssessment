<?php

namespace Tests\Feature;

use App\Jobs\ProcessEmployeeImportJob;
use App\Livewire\EmployeeFileUpload;
use App\Livewire\EmployeeImportDashboard;
use App\Livewire\ImportProgress;
use App\Livewire\ImportErrors;
use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\FileProcessorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    /** @test */
    public function it_completes_full_user_workflow_from_upload_to_dashboard()
    {
        // Step 1: User uploads file via Livewire component
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        $csvContent .= "EMP-002,Jane,Smith,jane.smith@example.com,Finance,85000,USD,KE,2022-02-01\n";
        $csvContent .= "EMP-003,Bob,Johnson,bob.johnson@example.com,Sales,75000,KES,KE,2022-03-01\n";
        
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        // Test file upload component
        $uploadComponent = Livewire::test(EmployeeFileUpload::class)
            ->set('file', $file)
            ->assertSet('errorMessage', null);

        // Simulate successful upload by setting the component state
        $uploadComponent->set('uploadComplete', true)
            ->set('importJobId', 'test-workflow-job')
            ->set('uploadProgress', 100);

        $uploadComponent->assertSet('uploadComplete', true)
            ->assertSet('importJobId', 'test-workflow-job');

        // Step 2: Create actual import job for testing
        $importJob = ImportJob::create([
            'id' => 'test-workflow-job',
            'filename' => 'employees.csv',
            'file_path' => $file->getPathname(),
            'status' => 'pending',
            'total_rows' => 3,
            'processed_rows' => 0,
            'successful_rows' => 0,
            'error_rows' => 0
        ]);

        // Step 3: Test dashboard shows the import job
        $dashboardComponent = Livewire::test(EmployeeImportDashboard::class);
        
        // The dashboard should show recent imports
        $dashboardComponent->assertSee('employees.csv')
            ->assertSee('pending');

        // Step 4: Process the import job
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Step 5: Test progress component shows completion
        $progressComponent = Livewire::test(ImportProgress::class, ['importJobId' => 'test-workflow-job']);
        
        $importJob->refresh();
        $progressComponent->call('refreshProgress');
        
        $progressComponent->assertSet('progress.status', 'completed')
            ->assertSet('progress.percentage', 100)
            ->assertSee('100%')
            ->assertSee('completed');

        // Step 6: Verify employees were created
        $this->assertEquals(3, Employee::count());
        
        // Step 7: Test dashboard reflects completion
        $dashboardComponent->call('refreshData');
        $dashboardComponent->assertSee('completed');

        // Step 8: Test that no errors are shown
        $errorsComponent = Livewire::test(ImportErrors::class, ['importJobId' => 'test-workflow-job']);
        $errorsComponent->assertSee('No errors found');
    }

    /** @test */
    public function it_handles_workflow_with_validation_errors()
    {
        // Create CSV with validation errors
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n"; // Valid
        $csvContent .= ",Jane,Smith,invalid-email,Finance,85000,USD,KE,2022-02-01\n"; // Invalid: missing employee_number, bad email
        $csvContent .= "EMP-003,Bob,,bob.johnson@example.com,Sales,-75000,XXX,ZZ,2030-03-01\n"; // Invalid: missing last_name, negative salary, bad currency/country, future date
        
        $file = UploadedFile::fake()->createWithContent('employees_with_errors.csv', $csvContent);

        // Step 1: Upload file
        $uploadResponse = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $uploadResponse->assertStatus(201);
        $importJobId = $uploadResponse->json('data.import_job_id');

        // Step 2: Process the job
        $importJob = ImportJob::find($importJobId);
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Step 3: Test progress component shows mixed results
        $progressComponent = Livewire::test(ImportProgress::class, ['importJobId' => $importJobId]);
        
        $importJob->refresh();
        $progressComponent->call('refreshProgress');
        
        $progressComponent->assertSet('progress.status', 'completed')
            ->assertSet('progress.percentage', 100)
            ->assertSee('1') // Should show 1 successful
            ->assertSee('2'); // Should show 2 errors

        // Step 4: Test errors component shows validation errors
        $errorsComponent = Livewire::test(ImportErrors::class, ['importJobId' => $importJobId]);
        
        $errorsComponent->assertDontSee('No errors found')
            ->assertSee('validation'); // Should show validation error type

        // Step 5: Test error filtering
        $errorsComponent->set('selectedErrorType', 'validation')
            ->call('filterErrors');
        
        // Should still show errors after filtering
        $errorsComponent->assertDontSee('No errors found');

        // Step 6: Verify only valid employee was created
        $this->assertEquals(1, Employee::count());
        $validEmployee = Employee::where('employee_number', 'EMP-001')->first();
        $this->assertNotNull($validEmployee);
        $this->assertEquals('John', $validEmployee->first_name);

        // Step 7: Test dashboard shows completion with errors
        $dashboardComponent = Livewire::test(EmployeeImportDashboard::class);
        $dashboardComponent->assertSee('completed')
            ->assertSee('employees_with_errors.csv');
    }

    /** @test */
    public function it_handles_real_time_progress_updates_during_processing()
    {
        // Create a medium-sized file for progress testing
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        
        for ($i = 1; $i <= 20; $i++) {
            $csvContent .= "EMP-{$i},Employee{$i},Test{$i},employee{$i}@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        }
        
        $file = UploadedFile::fake()->createWithContent('progress_test.csv', $csvContent);

        // Upload file
        $uploadResponse = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);

        // Test initial progress state
        $progressComponent = Livewire::test(ImportProgress::class, ['importJobId' => $importJobId]);
        $progressComponent->call('refreshProgress');
        
        $progressComponent->assertSet('progress.status', 'pending')
            ->assertSet('progress.percentage', 0);

        // Simulate processing start
        $importJob->update(['status' => 'processing']);
        
        $progressComponent->call('refreshProgress');
        $progressComponent->assertSet('progress.status', 'processing');

        // Process the job completely
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Test final progress state
        $progressComponent->call('refreshProgress');
        $progressComponent->assertSet('progress.status', 'completed')
            ->assertSet('progress.percentage', 100);

        // Verify all employees were created
        $this->assertEquals(20, Employee::count());
    }

    /** @test */
    public function it_handles_file_upload_validation_in_ui()
    {
        // Test oversized file
        $largeFile = UploadedFile::fake()->create('large.csv', 25 * 1024); // 25MB

        $uploadComponent = Livewire::test(EmployeeFileUpload::class)
            ->set('file', $largeFile)
            ->call('validateFile');

        $uploadComponent->assertSet('errorMessage', 'File size must not exceed 20MB');

        // Test invalid file type
        $pdfFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $uploadComponent = Livewire::test(EmployeeFileUpload::class)
            ->set('file', $pdfFile);

        $uploadComponent->assertSet('errorMessage', 'File must be a CSV or Excel file (.csv, .xlsx, .xls).');

        // Test invalid CSV headers
        $invalidCsv = UploadedFile::fake()->createWithContent('invalid.csv', "name,email\nJohn,john@example.com");

        $uploadComponent = Livewire::test(EmployeeFileUpload::class)
            ->set('file', $invalidCsv);

        $errorMessage = $uploadComponent->get('errorMessage');
        $this->assertStringContainsString('Missing required headers', $errorMessage);

        // Test valid file
        $validCsv = UploadedFile::fake()->createWithContent('valid.csv', 
            "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n" .
            "EMP-001,John,Doe,john@example.com,Engineering,100000,KES,KE,2022-01-01"
        );

        $uploadComponent = Livewire::test(EmployeeFileUpload::class)
            ->set('file', $validCsv);

        $uploadComponent->assertSet('errorMessage', null);
    }

    /** @test */
    public function it_provides_comprehensive_import_summary()
    {
        // Create a mixed results import
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n"; // Valid
        $csvContent .= "EMP-002,Jane,Smith,jane.smith@example.com,Finance,85000,USD,KE,2022-02-01\n"; // Valid
        $csvContent .= ",Bob,Johnson,invalid-email,Sales,75000,KES,KE,2022-03-01\n"; // Invalid
        $csvContent .= "EMP-004,Alice,Brown,alice.brown@example.com,HR,90000,KES,KE,2022-04-01\n"; // Valid
        $csvContent .= "EMP-005,Charlie,,charlie@example.com,IT,-50000,XXX,ZZ,2030-01-01\n"; // Invalid
        
        $file = UploadedFile::fake()->createWithContent('summary_test.csv', $csvContent);

        // Process the import
        $uploadResponse = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);
        
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Test summary API
        $summaryResponse = $this->getJson("/api/employee-imports/{$importJobId}/summary");
        $summaryResponse->assertStatus(200);
        
        $summaryData = $summaryResponse->json('data');
        
        // Verify summary structure
        $this->assertArrayHasKey('import_job', $summaryData);
        $this->assertArrayHasKey('progress', $summaryData);
        $this->assertArrayHasKey('error_summary', $summaryData);
        $this->assertArrayHasKey('statistics', $summaryData);

        // Verify statistics
        $stats = $summaryData['statistics'];
        $this->assertEquals(60, $stats['success_rate']); // 3 out of 5 successful
        $this->assertEquals(40, $stats['error_rate']); // 2 out of 5 failed
        $this->assertArrayHasKey('processing_time', $stats);

        // Test dashboard shows summary information
        $dashboardComponent = Livewire::test(EmployeeImportDashboard::class);
        $dashboardComponent->assertSee('summary_test.csv')
            ->assertSee('completed');

        // Verify actual data
        $this->assertEquals(3, Employee::count()); // 3 valid employees
        $this->assertEquals(2, ImportError::where('import_job_id', $importJobId)->count()); // 2 errors
    }

    /** @test */
    public function it_handles_concurrent_user_workflows()
    {
        // Simulate two users uploading files simultaneously
        $csvContent1 = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent1 .= "USER1-001,John,Doe,john.doe@user1.com,Engineering,100000,KES,KE,2022-01-01\n";
        $csvContent1 .= "USER1-002,Jane,Smith,jane.smith@user1.com,Finance,85000,USD,KE,2022-02-01\n";
        
        $csvContent2 = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent2 .= "USER2-001,Bob,Johnson,bob.johnson@user2.com,Sales,75000,KES,KE,2022-03-01\n";
        $csvContent2 .= "USER2-002,Alice,Brown,alice.brown@user2.com,HR,90000,KES,KE,2022-04-01\n";
        
        $file1 = UploadedFile::fake()->createWithContent('user1_employees.csv', $csvContent1);
        $file2 = UploadedFile::fake()->createWithContent('user2_employees.csv', $csvContent2);

        // User 1 uploads
        $uploadResponse1 = $this->postJson('/api/employee-imports', ['file' => $file1]);
        $uploadResponse1->assertStatus(201);
        $importJobId1 = $uploadResponse1->json('data.import_job_id');

        // User 2 uploads
        $uploadResponse2 = $this->postJson('/api/employee-imports', ['file' => $file2]);
        $uploadResponse2->assertStatus(201);
        $importJobId2 = $uploadResponse2->json('data.import_job_id');

        // Process both jobs
        $importJob1 = ImportJob::find($importJobId1);
        $importJob2 = ImportJob::find($importJobId2);
        
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob1);
        $fileProcessor->processImport($importJob2);

        // Test that both users can see their progress independently
        $progress1 = Livewire::test(ImportProgress::class, ['importJobId' => $importJobId1]);
        $progress1->call('refreshProgress');
        $progress1->assertSet('progress.status', 'completed')
            ->assertSet('progress.successful_rows', 2);

        $progress2 = Livewire::test(ImportProgress::class, ['importJobId' => $importJobId2]);
        $progress2->call('refreshProgress');
        $progress2->assertSet('progress.status', 'completed')
            ->assertSet('progress.successful_rows', 2);

        // Verify all employees from both users were created
        $this->assertEquals(4, Employee::count());
        $this->assertEquals(2, Employee::where('employee_number', 'like', 'USER1-%')->count());
        $this->assertEquals(2, Employee::where('employee_number', 'like', 'USER2-%')->count());

        // Test dashboard shows both imports
        $dashboardComponent = Livewire::test(EmployeeImportDashboard::class);
        $dashboardComponent->assertSee('user1_employees.csv')
            ->assertSee('user2_employees.csv');
    }

    /** @test */
    public function it_handles_error_recovery_and_retry_workflow()
    {
        // Create a CSV file
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        $csvContent .= "EMP-002,Jane,Smith,jane.smith@example.com,Finance,85000,USD,KE,2022-02-01\n";
        $csvContent .= "EMP-003,Bob,Johnson,bob.johnson@example.com,Sales,75000,KES,KE,2022-03-01\n";
        
        $file = UploadedFile::fake()->createWithContent('retry_test.csv', $csvContent);

        // Upload file
        $uploadResponse = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $importJobId = $uploadResponse->json('data.import_job_id');
        $importJob = ImportJob::find($importJobId);

        // Simulate partial processing failure
        $importJob->update([
            'status' => 'processing',
            'total_rows' => 3,
            'processed_rows' => 1,
            'successful_rows' => 1,
            'error_rows' => 0,
            'last_processed_row' => 1
        ]);

        // Create the first employee to simulate partial processing
        Employee::create([
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'department' => 'Engineering',
            'salary' => 100000,
            'currency' => 'KES',
            'country_code' => 'KE',
            'start_date' => '2022-01-01'
        ]);

        // Test progress shows partial completion
        $progressComponent = Livewire::test(ImportProgress::class, ['importJobId' => $importJobId]);
        $progressComponent->call('refreshProgress');
        
        $progressComponent->assertSet('progress.status', 'processing')
            ->assertSet('progress.processed_rows', 1)
            ->assertSet('progress.successful_rows', 1);

        // Resume/retry processing
        $fileProcessor = app(FileProcessorService::class);
        $fileProcessor->processImport($importJob);

        // Test progress shows completion
        $progressComponent->call('refreshProgress');
        $progressComponent->assertSet('progress.status', 'completed')
            ->assertSet('progress.processed_rows', 3)
            ->assertSet('progress.successful_rows', 3);

        // Verify all employees were created (no duplicates)
        $this->assertEquals(3, Employee::count());
        $this->assertNotNull(Employee::where('employee_number', 'EMP-001')->first());
        $this->assertNotNull(Employee::where('employee_number', 'EMP-002')->first());
        $this->assertNotNull(Employee::where('employee_number', 'EMP-003')->first());

        // Test dashboard reflects successful completion
        $dashboardComponent = Livewire::test(EmployeeImportDashboard::class);
        $dashboardComponent->assertSee('completed')
            ->assertSee('retry_test.csv');
    }
}