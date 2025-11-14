<?php

namespace Tests\Unit;

use App\Models\ImportError;
use App\Models\ImportJob;
use App\Services\ErrorReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorReporterTest extends TestCase
{
    use RefreshDatabase;

    private ErrorReporter $errorReporter;
    private ImportJob $importJob;

    protected function setUp(): void
    {
        parent::setUp();
        $this->errorReporter = new ErrorReporter();
        
        $this->importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'processing',
            'total_rows' => 100,
        ]);
    }

    /** @test */
    public function it_can_record_a_generic_error()
    {
        $error = $this->errorReporter->recordError(
            $this->importJob,
            5,
            'validation',
            'Invalid email format',
            ['email' => 'invalid-email']
        );

        $this->assertInstanceOf(ImportError::class, $error);
        $this->assertEquals($this->importJob->id, $error->import_job_id);
        $this->assertEquals(5, $error->row_number);
        $this->assertEquals('validation', $error->error_type);
        $this->assertEquals('Invalid email format', $error->error_message);
        $this->assertEquals(['email' => 'invalid-email'], $error->row_data);

        $this->assertDatabaseHas('import_errors', [
            'import_job_id' => $this->importJob->id,
            'row_number' => 5,
            'error_type' => 'validation',
            'error_message' => 'Invalid email format',
        ]);
    }

    /** @test */
    public function it_can_record_validation_error()
    {
        $error = $this->errorReporter->recordValidationError(
            $this->importJob,
            10,
            'Missing required field: first_name',
            ['first_name' => '', 'last_name' => 'Doe']
        );

        $this->assertEquals(ImportError::TYPE_VALIDATION, $error->error_type);
        $this->assertEquals('Missing required field: first_name', $error->error_message);
        $this->assertEquals(10, $error->row_number);
    }

    /** @test */
    public function it_can_record_duplicate_error()
    {
        $error = $this->errorReporter->recordDuplicateError(
            $this->importJob,
            15,
            'Duplicate employee number: EMP001',
            ['employee_number' => 'EMP001']
        );

        $this->assertEquals(ImportError::TYPE_DUPLICATE, $error->error_type);
        $this->assertEquals('Duplicate employee number: EMP001', $error->error_message);
        $this->assertEquals(15, $error->row_number);
    }

    /** @test */
    public function it_can_get_all_errors_for_import_job()
    {
        // Create multiple errors
        $this->errorReporter->recordValidationError($this->importJob, 1, 'Error 1');
        $this->errorReporter->recordDuplicateError($this->importJob, 2, 'Error 2');
        $this->errorReporter->recordFormatError($this->importJob, 3, 'Error 3');

        // Create error for different job
        $otherJob = ImportJob::create([
            'filename' => 'other.csv',
            'file_path' => '/tmp/other.csv',
            'status' => 'processing',
        ]);
        $this->errorReporter->recordValidationError($otherJob, 1, 'Other error');

        $errors = $this->errorReporter->getErrors($this->importJob->id);

        $this->assertCount(3, $errors);
        $this->assertEquals(1, $errors->first()->row_number);
        $this->assertEquals(3, $errors->last()->row_number);
    }

    /** @test */
    public function it_can_get_error_count()
    {
        $this->assertEquals(0, $this->errorReporter->getErrorCount($this->importJob->id));

        $this->errorReporter->recordValidationError($this->importJob, 1, 'Error 1');
        $this->errorReporter->recordValidationError($this->importJob, 2, 'Error 2');

        $this->assertEquals(2, $this->errorReporter->getErrorCount($this->importJob->id));
    }

    /** @test */
    public function it_can_clear_all_errors_for_import_job()
    {
        $this->errorReporter->recordValidationError($this->importJob, 1, 'Error 1');
        $this->errorReporter->recordValidationError($this->importJob, 2, 'Error 2');
        $this->errorReporter->recordValidationError($this->importJob, 3, 'Error 3');

        // Create error for different job to ensure it's not deleted
        $otherJob = ImportJob::create([
            'filename' => 'other.csv',
            'file_path' => '/tmp/other.csv',
            'status' => 'processing',
        ]);
        $this->errorReporter->recordValidationError($otherJob, 1, 'Other error');

        $deletedCount = $this->errorReporter->clearErrors($this->importJob->id);

        $this->assertEquals(3, $deletedCount);
        $this->assertEquals(0, $this->errorReporter->getErrorCount($this->importJob->id));
        $this->assertEquals(1, $this->errorReporter->getErrorCount($otherJob->id)); // Other job errors remain
    }
}