<?php

namespace Tests\Unit;

use App\Models\ImportError;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportErrorTest extends TestCase
{
    use RefreshDatabase;

    private ImportJob $importJob;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->importJob = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'status' => ImportJob::STATUS_PROCESSING,
        ]);
    }

    /** @test */
    public function it_can_create_an_import_error()
    {
        $errorData = [
            'import_job_id' => $this->importJob->id,
            'row_number' => 5,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Email is required',
            'row_data' => ['employee_number' => 'EMP001', 'first_name' => 'John'],
        ];

        $error = ImportError::create($errorData);

        $this->assertInstanceOf(ImportError::class, $error);
        $this->assertEquals($this->importJob->id, $error->import_job_id);
        $this->assertEquals(5, $error->row_number);
        $this->assertEquals(ImportError::TYPE_VALIDATION, $error->error_type);
        $this->assertEquals('Email is required', $error->error_message);
        $this->assertEquals(['employee_number' => 'EMP001', 'first_name' => 'John'], $error->row_data);
    }

    /** @test */
    public function it_casts_row_data_to_array()
    {
        $error = ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test error',
            'row_data' => ['key' => 'value'],
        ]);

        $this->assertIsArray($error->row_data);
        $this->assertEquals(['key' => 'value'], $error->row_data);
    }

    /** @test */
    public function it_casts_row_number_to_integer()
    {
        $error = ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => '10',
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test error',
        ]);

        $this->assertIsInt($error->row_number);
        $this->assertEquals(10, $error->row_number);
    }

    /** @test */
    public function it_returns_valid_error_types()
    {
        $validTypes = ImportError::getValidErrorTypes();
        
        $expectedTypes = [
            ImportError::TYPE_VALIDATION,
            ImportError::TYPE_DUPLICATE,
            ImportError::TYPE_FORMAT,
            ImportError::TYPE_BUSINESS_RULE,
            ImportError::TYPE_SYSTEM,
        ];
        
        $this->assertEquals($expectedTypes, $validTypes);
        $this->assertCount(5, $validTypes);
    }

    /** @test */
    public function it_belongs_to_import_job()
    {
        $error = ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test error',
        ]);

        $this->assertInstanceOf(ImportJob::class, $error->importJob);
        $this->assertEquals($this->importJob->id, $error->importJob->id);
    }

    /** @test */
    public function it_can_filter_by_error_type()
    {
        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Validation error',
        ]);

        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 2,
            'error_type' => ImportError::TYPE_DUPLICATE,
            'error_message' => 'Duplicate error',
        ]);

        $validationErrors = ImportError::byErrorType(ImportError::TYPE_VALIDATION)->get();
        $duplicateErrors = ImportError::byErrorType(ImportError::TYPE_DUPLICATE)->get();

        $this->assertCount(1, $validationErrors);
        $this->assertCount(1, $duplicateErrors);
        $this->assertEquals('Validation error', $validationErrors->first()->error_message);
        $this->assertEquals('Duplicate error', $duplicateErrors->first()->error_message);
    }

    /** @test */
    public function it_can_filter_by_import_job()
    {
        $anotherJob = ImportJob::create([
            'filename' => 'another.csv',
            'file_path' => '/another.csv',
        ]);

        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Error for job 1',
        ]);

        ImportError::create([
            'import_job_id' => $anotherJob->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Error for job 2',
        ]);

        $job1Errors = ImportError::byImportJob($this->importJob->id)->get();
        $job2Errors = ImportError::byImportJob($anotherJob->id)->get();

        $this->assertCount(1, $job1Errors);
        $this->assertCount(1, $job2Errors);
        $this->assertEquals('Error for job 1', $job1Errors->first()->error_message);
        $this->assertEquals('Error for job 2', $job2Errors->first()->error_message);
    }

    /** @test */
    public function it_can_filter_by_row_range()
    {
        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 5,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Error at row 5',
        ]);

        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 10,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Error at row 10',
        ]);

        ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 15,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Error at row 15',
        ]);

        $errorsInRange = ImportError::byRowRange(8, 12)->get();
        $errorsFromRow = ImportError::byRowRange(10)->get();

        $this->assertCount(1, $errorsInRange);
        $this->assertEquals(10, $errorsInRange->first()->row_number);

        $this->assertCount(2, $errorsFromRow);
        $this->assertTrue($errorsFromRow->pluck('row_number')->contains(10));
        $this->assertTrue($errorsFromRow->pluck('row_number')->contains(15));
    }

    /** @test */
    public function it_can_create_validation_error()
    {
        $error = ImportError::createValidationError(
            $this->importJob->id,
            5,
            'Email is required',
            ['employee_number' => 'EMP001']
        );

        $this->assertEquals($this->importJob->id, $error->import_job_id);
        $this->assertEquals(5, $error->row_number);
        $this->assertEquals(ImportError::TYPE_VALIDATION, $error->error_type);
        $this->assertEquals('Email is required', $error->error_message);
        $this->assertEquals(['employee_number' => 'EMP001'], $error->row_data);
    }

    /** @test */
    public function it_can_create_duplicate_error()
    {
        $error = ImportError::createDuplicateError(
            $this->importJob->id,
            8,
            'Duplicate employee number',
            ['employee_number' => 'EMP001', 'email' => 'test@example.com']
        );

        $this->assertEquals($this->importJob->id, $error->import_job_id);
        $this->assertEquals(8, $error->row_number);
        $this->assertEquals(ImportError::TYPE_DUPLICATE, $error->error_type);
        $this->assertEquals('Duplicate employee number', $error->error_message);
        $this->assertEquals(['employee_number' => 'EMP001', 'email' => 'test@example.com'], $error->row_data);
    }

    /** @test */
    public function it_can_create_format_error()
    {
        $error = ImportError::createFormatError(
            $this->importJob->id,
            3,
            'Invalid date format',
            ['start_date' => '2023/01/15']
        );

        $this->assertEquals(ImportError::TYPE_FORMAT, $error->error_type);
        $this->assertEquals('Invalid date format', $error->error_message);
    }

    /** @test */
    public function it_can_create_business_rule_error()
    {
        $error = ImportError::createBusinessRuleError(
            $this->importJob->id,
            7,
            'Salary cannot be negative',
            ['salary' => '-1000']
        );

        $this->assertEquals(ImportError::TYPE_BUSINESS_RULE, $error->error_type);
        $this->assertEquals('Salary cannot be negative', $error->error_message);
    }

    /** @test */
    public function it_can_create_system_error()
    {
        $error = ImportError::createSystemError(
            $this->importJob->id,
            12,
            'Database connection failed',
            []
        );

        $this->assertEquals(ImportError::TYPE_SYSTEM, $error->error_type);
        $this->assertEquals('Database connection failed', $error->error_message);
    }

    /** @test */
    public function it_can_get_error_statistics()
    {
        // Create errors of different types
        ImportError::createValidationError($this->importJob->id, 1, 'Validation error 1');
        ImportError::createValidationError($this->importJob->id, 2, 'Validation error 2');
        ImportError::createDuplicateError($this->importJob->id, 3, 'Duplicate error');
        ImportError::createFormatError($this->importJob->id, 4, 'Format error');

        $statistics = ImportError::getErrorStatistics($this->importJob->id);

        $this->assertEquals(4, $statistics['total_errors']);
        $this->assertEquals(2, $statistics['validation_errors']);
        $this->assertEquals(1, $statistics['duplicate_errors']);
        $this->assertEquals(1, $statistics['format_errors']);
        $this->assertEquals(0, $statistics['business_rule_errors']);
        $this->assertEquals(0, $statistics['system_errors']);
    }

    /** @test */
    public function it_returns_zero_statistics_for_job_with_no_errors()
    {
        $statistics = ImportError::getErrorStatistics($this->importJob->id);

        $this->assertEquals(0, $statistics['total_errors']);
        $this->assertEquals(0, $statistics['validation_errors']);
        $this->assertEquals(0, $statistics['duplicate_errors']);
        $this->assertEquals(0, $statistics['format_errors']);
        $this->assertEquals(0, $statistics['business_rule_errors']);
        $this->assertEquals(0, $statistics['system_errors']);
    }

    /** @test */
    public function it_can_get_formatted_error_details()
    {
        $error = ImportError::create([
            'import_job_id' => $this->importJob->id,
            'row_number' => 15,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test formatting',
            'row_data' => ['test' => 'data'],
        ]);

        $details = $error->getFormattedDetails();

        $this->assertArrayHasKey('id', $details);
        $this->assertArrayHasKey('row_number', $details);
        $this->assertArrayHasKey('error_type', $details);
        $this->assertArrayHasKey('error_message', $details);
        $this->assertArrayHasKey('row_data', $details);
        $this->assertArrayHasKey('created_at', $details);

        $this->assertEquals($error->id, $details['id']);
        $this->assertEquals(15, $details['row_number']);
        $this->assertEquals(ImportError::TYPE_VALIDATION, $details['error_type']);
        $this->assertEquals('Test formatting', $details['error_message']);
        $this->assertEquals(['test' => 'data'], $details['row_data']);
    }
}