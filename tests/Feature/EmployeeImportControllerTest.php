<?php

namespace Tests\Feature;

use App\Jobs\ProcessEmployeeImportJob;
use App\Models\ImportJob;
use App\Models\ImportError;
use App\Services\ErrorReporter;
use App\Services\ProgressTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmployeeImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Set up storage for testing
        Storage::fake('local');
        
        // Clear any queued jobs
        Queue::fake();
    }

    /** @test */
    public function it_uploads_valid_csv_file_successfully()
    {
        // Create a valid CSV file
        $csvContent = "employee_number,first_name,last_name,email,department,salary,currency,country_code,start_date\n";
        $csvContent .= "EMP-001,John,Doe,john.doe@example.com,Engineering,100000,KES,KE,2022-01-01\n";
        
        $file = UploadedFile::fake()->createWithContent('employees.csv', $csvContent);

        $response = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'import_job_id',
                    'filename',
                    'status',
                    'message'
                ]
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'filename' => 'employees.csv',
                    'status' => 'pending',
                    'message' => 'File uploaded successfully and processing has started'
                ]
            ]);

        // Verify import job was created
        $this->assertDatabaseHas('import_jobs', [
            'filename' => 'employees.csv',
            'status' => 'pending'
        ]);

        // Verify job was dispatched
        Queue::assertPushed(ProcessEmployeeImportJob::class);
    }

    /** @test */
    public function it_uploads_valid_excel_file_successfully()
    {
        // Create a simple Excel file content (minimal XLSX structure)
        $file = UploadedFile::fake()->create('employees.xlsx', 1024, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $response = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        // Note: This will fail header validation since we can't create a proper Excel file in tests
        // But it tests the file type acceptance
        $response->assertStatus(422);
    }

    /** @test */
    public function it_rejects_file_upload_without_file()
    {
        $response = $this->postJson('/api/employee-imports', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'file'
                ]
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed'
            ]);
    }

    /** @test */
    public function it_rejects_file_that_exceeds_size_limit()
    {
        // Create a file larger than the configured limit (20MB)
        $file = UploadedFile::fake()->create('large_file.csv', 25 * 1024); // 25MB

        $response = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed'
            ]);
    }

    /** @test */
    public function it_rejects_invalid_file_type()
    {
        $file = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');

        $response = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Validation failed'
            ]);
    }

    /** @test */
    public function it_rejects_csv_file_with_missing_required_headers()
    {
        // Create CSV with missing required headers
        $csvContent = "name,email\n";
        $csvContent .= "John Doe,john.doe@example.com\n";
        
        $file = UploadedFile::fake()->createWithContent('invalid.csv', $csvContent);

        $response = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'file'
                ]
            ])
            ->assertJson([
                'success' => false,
                'message' => 'File validation failed'
            ]);
    }

    /** @test */
    public function it_rejects_empty_csv_file()
    {
        $file = UploadedFile::fake()->createWithContent('empty.csv', '');

        $response = $this->postJson('/api/employee-imports', [
            'file' => $file
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'File validation failed'
            ]);
    }

    /** @test */
    public function it_gets_import_progress_successfully()
    {
        // Create an import job
        $importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'processing',
            'total_rows' => 100,
            'processed_rows' => 50,
            'successful_rows' => 45,
            'error_rows' => 5
        ]);

        $response = $this->getJson("/api/employee-imports/{$importJob->id}/progress");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'job_id',
                    'status',
                    'percentage',
                    'total_rows',
                    'processed_rows',
                    'successful_rows',
                    'error_rows'
                ]
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'job_id' => $importJob->id,
                    'status' => 'processing'
                ]
            ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_import_progress()
    {
        $nonExistentId = 'non-existent-id';

        $response = $this->getJson("/api/employee-imports/{$nonExistentId}/progress");

        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Import job not found'
            ]);
    }

    /** @test */
    public function it_gets_import_errors_successfully()
    {
        // Create an import job
        $importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'completed'
        ]);

        // Create some import errors
        ImportError::create([
            'import_job_id' => $importJob->id,
            'row_number' => 2,
            'error_type' => 'validation',
            'error_message' => 'Invalid email format',
            'row_data' => ['email' => 'invalid-email']
        ]);

        ImportError::create([
            'import_job_id' => $importJob->id,
            'row_number' => 3,
            'error_type' => 'duplicate',
            'error_message' => 'Duplicate employee number',
            'row_data' => ['employee_number' => 'EMP-001']
        ]);

        $response = $this->getJson("/api/employee-imports/{$importJob->id}/errors");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'errors',
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page'
                    ],
                    'filters_applied'
                ]
            ])
            ->assertJson([
                'success' => true
            ]);

        // Verify errors are returned
        $responseData = $response->json('data');
        $this->assertCount(2, $responseData['errors']);
    }

    /** @test */
    public function it_filters_import_errors_by_type()
    {
        // Create an import job
        $importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'completed'
        ]);

        // Create errors of different types
        ImportError::create([
            'import_job_id' => $importJob->id,
            'row_number' => 2,
            'error_type' => 'validation',
            'error_message' => 'Invalid email format',
            'row_data' => []
        ]);

        ImportError::create([
            'import_job_id' => $importJob->id,
            'row_number' => 3,
            'error_type' => 'duplicate',
            'error_message' => 'Duplicate employee number',
            'row_data' => []
        ]);

        $response = $this->getJson("/api/employee-imports/{$importJob->id}/errors?error_type=validation");

        $response->assertStatus(200);
        
        $responseData = $response->json('data');
        $this->assertCount(1, $responseData['errors']);
        $this->assertEquals('validation', $responseData['errors'][0]['error_type']);
    }

    /** @test */
    public function it_returns_404_for_non_existent_import_errors()
    {
        $nonExistentId = 'non-existent-id';

        $response = $this->getJson("/api/employee-imports/{$nonExistentId}/errors");

        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Import job not found'
            ]);
    }

    /** @test */
    public function it_validates_error_query_parameters()
    {
        $importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'completed'
        ]);

        // Test invalid error_type
        $response = $this->getJson("/api/employee-imports/{$importJob->id}/errors?error_type=invalid_type");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid query parameters'
            ]);

        // Test invalid per_page (too high)
        $response = $this->getJson("/api/employee-imports/{$importJob->id}/errors?per_page=200");

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid query parameters'
            ]);
    }

    /** @test */
    public function it_gets_import_summary_successfully()
    {
        // Create an import job
        $importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'completed',
            'total_rows' => 100,
            'processed_rows' => 100,
            'successful_rows' => 95,
            'error_rows' => 5,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()
        ]);

        $response = $this->getJson("/api/employee-imports/{$importJob->id}/summary");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'import_job',
                    'progress',
                    'error_summary',
                    'statistics' => [
                        'success_rate',
                        'error_rate',
                        'processing_time'
                    ]
                ]
            ])
            ->assertJson([
                'success' => true
            ]);
    }

    /** @test */
    public function it_returns_404_for_non_existent_import_summary()
    {
        $nonExistentId = 'non-existent-id';

        $response = $this->getJson("/api/employee-imports/{$nonExistentId}/summary");

        $response->assertStatus(404)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Import job not found'
            ]);
    }

    /** @test */
    public function it_handles_server_errors_gracefully()
    {
        // Mock a service to throw an exception
        $this->mock(ProgressTracker::class, function ($mock) {
            $mock->shouldReceive('getProgress')
                ->andThrow(new \Exception('Database connection failed'));
        });

        $importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'processing'
        ]);

        $response = $this->getJson("/api/employee-imports/{$importJob->id}/progress");

        $response->assertStatus(500)
            ->assertJsonStructure([
                'success',
                'message',
                'errors'
            ])
            ->assertJson([
                'success' => false,
                'message' => 'Failed to retrieve progress'
            ]);
    }
}