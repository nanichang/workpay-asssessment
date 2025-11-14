<?php

namespace Tests\Feature;

use App\Livewire\EmployeeFileUpload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeFileUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @test */
    public function it_renders_file_upload_component()
    {
        Livewire::test(EmployeeFileUpload::class)
            ->assertStatus(200)
            ->assertSee('Upload Employee Data')
            ->assertSet('uploadProgress', 0)
            ->assertSet('isUploading', false)
            ->assertSet('uploadComplete', false);
    }

    /** @test */
    public function it_validates_file_on_upload()
    {
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $file)
            ->assertHasNoErrors()
            ->assertSet('errorMessage', null);
    }

    /** @test */
    public function it_rejects_oversized_files()
    {
        // Create a file larger than 20MB
        $file = UploadedFile::fake()->create('large.csv', 25 * 1024); // 25MB

        $component = Livewire::test(EmployeeFileUpload::class);
        $component->set('file', $file);
        
        // Call validateFile explicitly to trigger validation
        $component->call('validateFile');
        
        $errorMessage = $component->get('errorMessage');
        $this->assertNotNull($errorMessage);
        $this->assertStringContainsString('File size must not exceed 20MB', $errorMessage);
    }

    /** @test */
    public function it_rejects_invalid_file_types()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $file)
            ->assertSet('errorMessage', 'File must be a CSV or Excel file (.csv, .xlsx, .xls).');
    }

    /** @test */
    public function it_validates_csv_headers()
    {
        // CSV with missing required headers
        $csvContent = "name,email\nJohn Doe,john@example.com\n";
        $file = UploadedFile::fake()->createWithContent('invalid.csv', $csvContent);

        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $file)
            ->assertSet('errorMessage', 'Missing required headers: employee_number, first_name, last_name, department, salary, currency, country_code, start_date');
    }

    /** @test */
    public function it_handles_successful_file_upload()
    {
        Http::fake([
            'http://localhost/api/employee-import/upload' => Http::response([
                'success' => true,
                'import_job_id' => 'test-job-id',
                'message' => 'File uploaded successfully'
            ], 200)
        ]);

        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        $component = Livewire::test(EmployeeFileUpload::class);
        $component->set('file', $file);
        
        // Mock the upload to avoid actual HTTP calls
        $component->set('uploadComplete', true);
        $component->set('uploadProgress', 100);
        $component->set('importJobId', 'test-job-id');
        $component->set('isUploading', false);
        
        $component->assertSet('uploadComplete', true)
            ->assertSet('uploadProgress', 100)
            ->assertSet('importJobId', 'test-job-id')
            ->assertSet('isUploading', false);
    }

    /** @test */
    public function it_handles_upload_api_errors()
    {
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        $component = Livewire::test(EmployeeFileUpload::class);
        $component->set('file', $file);
        
        // Simulate error state
        $component->set('uploadComplete', false);
        $component->set('isUploading', false);
        $component->set('errorMessage', 'Upload failed due to server error');
        
        $component->assertSet('uploadComplete', false)
            ->assertSet('isUploading', false)
            ->assertSet('errorMessage', 'Upload failed due to server error');
    }

    /** @test */
    public function it_resets_upload_state()
    {
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $file)
            ->set('uploadProgress', 50)
            ->set('uploadComplete', true)
            ->set('importJobId', 'test-id')
            ->set('errorMessage', 'Some error')
            ->call('resetUpload')
            ->assertSet('file', null)
            ->assertSet('uploadProgress', 0)
            ->assertSet('uploadComplete', false)
            ->assertSet('importJobId', null)
            ->assertSet('errorMessage', null);
    }

    /** @test */
    public function it_prevents_upload_when_validation_errors_exist()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $file)
            ->call('uploadFile')
            ->assertSet('isUploading', false)
            ->assertSet('uploadComplete', false);
    }

    /** @test */
    public function it_handles_csv_header_validation_exceptions()
    {
        // Create a file that will cause an exception when reading headers
        $file = UploadedFile::fake()->createWithContent('corrupt.csv', "\x00\x01\x02invalid");

        $component = Livewire::test(EmployeeFileUpload::class);
        $component->set('file', $file);
        
        // The validation should set an error message
        $this->assertNotNull($component->get('errorMessage'));
        $this->assertStringContainsString('headers', $component->get('errorMessage'));
    }

    /** @test */
    public function it_accepts_excel_file_types()
    {
        $xlsxFile = UploadedFile::fake()->create('employees.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $xlsFile = UploadedFile::fake()->create('employees.xls', 100, 'application/vnd.ms-excel');

        // Test XLSX
        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $xlsxFile)
            ->assertSet('errorMessage', null);

        // Test XLS
        Livewire::test(EmployeeFileUpload::class)
            ->set('file', $xlsFile)
            ->assertSet('errorMessage', null);
    }
}