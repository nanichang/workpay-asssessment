<?php

namespace Tests\Unit;

use App\Models\ImportJob;
use App\Models\ImportError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportJobTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_an_import_job()
    {
        $jobData = [
            'filename' => 'employees.csv',
            'file_path' => '/storage/uploads/employees.csv',
            'status' => ImportJob::STATUS_PENDING,
            'total_rows' => 1000,
        ];

        $job = ImportJob::create($jobData);

        $this->assertInstanceOf(ImportJob::class, $job);
        $this->assertEquals('employees.csv', $job->filename);
        $this->assertEquals('/storage/uploads/employees.csv', $job->file_path);
        $this->assertEquals(ImportJob::STATUS_PENDING, $job->status);
        $this->assertEquals(1000, $job->total_rows);
        $this->assertIsString($job->id); // UUID
    }

    /** @test */
    public function it_returns_valid_status_values()
    {
        $validStatuses = ImportJob::getValidStatuses();
        
        $expectedStatuses = [
            ImportJob::STATUS_PENDING,
            ImportJob::STATUS_PROCESSING,
            ImportJob::STATUS_COMPLETED,
            ImportJob::STATUS_FAILED,
        ];
        
        $this->assertEquals($expectedStatuses, $validStatuses);
        $this->assertCount(4, $validStatuses);
    }

    /** @test */
    public function it_calculates_progress_percentage_correctly()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'total_rows' => 100,
            'processed_rows' => 25,
        ]);

        $this->assertEquals(25.0, $job->progress_percentage);
    }

    /** @test */
    public function it_returns_zero_progress_when_total_rows_is_zero()
    {
        $job = ImportJob::create([
            'filename' => 'empty.csv',
            'file_path' => '/empty.csv',
            'total_rows' => 0,
            'processed_rows' => 0,
        ]);

        $this->assertEquals(0.0, $job->progress_percentage);
    }

    /** @test */
    public function it_rounds_progress_percentage_to_two_decimal_places()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'total_rows' => 3,
            'processed_rows' => 1,
        ]);

        $this->assertEquals(33.33, $job->progress_percentage);
    }

    /** @test */
    public function it_can_check_if_job_is_completed()
    {
        $completedJob = ImportJob::create([
            'filename' => 'completed.csv',
            'file_path' => '/completed.csv',
            'status' => ImportJob::STATUS_COMPLETED,
        ]);

        $pendingJob = ImportJob::create([
            'filename' => 'pending.csv',
            'file_path' => '/pending.csv',
            'status' => ImportJob::STATUS_PENDING,
        ]);

        $this->assertTrue($completedJob->isCompleted());
        $this->assertFalse($pendingJob->isCompleted());
    }

    /** @test */
    public function it_can_check_if_job_is_processing()
    {
        $processingJob = ImportJob::create([
            'filename' => 'processing.csv',
            'file_path' => '/processing.csv',
            'status' => ImportJob::STATUS_PROCESSING,
        ]);

        $pendingJob = ImportJob::create([
            'filename' => 'pending.csv',
            'file_path' => '/pending.csv',
            'status' => ImportJob::STATUS_PENDING,
        ]);

        $this->assertTrue($processingJob->isProcessing());
        $this->assertFalse($pendingJob->isProcessing());
    }

    /** @test */
    public function it_can_check_if_job_has_failed()
    {
        $failedJob = ImportJob::create([
            'filename' => 'failed.csv',
            'file_path' => '/failed.csv',
            'status' => ImportJob::STATUS_FAILED,
        ]);

        $completedJob = ImportJob::create([
            'filename' => 'completed.csv',
            'file_path' => '/completed.csv',
            'status' => ImportJob::STATUS_COMPLETED,
        ]);

        $this->assertTrue($failedJob->hasFailed());
        $this->assertFalse($completedJob->hasFailed());
    }

    /** @test */
    public function it_can_check_if_job_is_pending()
    {
        $pendingJob = ImportJob::create([
            'filename' => 'pending.csv',
            'file_path' => '/pending.csv',
            'status' => ImportJob::STATUS_PENDING,
        ]);

        $processingJob = ImportJob::create([
            'filename' => 'processing.csv',
            'file_path' => '/processing.csv',
            'status' => ImportJob::STATUS_PROCESSING,
        ]);

        $this->assertTrue($pendingJob->isPending());
        $this->assertFalse($processingJob->isPending());
    }

    /** @test */
    public function it_can_mark_job_as_started()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'status' => ImportJob::STATUS_PENDING,
        ]);

        $this->assertNull($job->started_at);

        $job->markAsStarted();

        $job->refresh();
        $this->assertEquals(ImportJob::STATUS_PROCESSING, $job->status);
        $this->assertNotNull($job->started_at);
    }

    /** @test */
    public function it_can_mark_job_as_completed()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'status' => ImportJob::STATUS_PROCESSING,
        ]);

        $this->assertNull($job->completed_at);

        $job->markAsCompleted();

        $job->refresh();
        $this->assertEquals(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertNotNull($job->completed_at);
    }

    /** @test */
    public function it_can_mark_job_as_failed()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'status' => ImportJob::STATUS_PROCESSING,
        ]);

        $this->assertNull($job->completed_at);

        $job->markAsFailed();

        $job->refresh();
        $this->assertEquals(ImportJob::STATUS_FAILED, $job->status);
        $this->assertNotNull($job->completed_at);
    }

    /** @test */
    public function it_can_update_progress_counters()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'total_rows' => 100,
        ]);

        $job->updateProgress(50, 45, 5, 50);

        $job->refresh();
        $this->assertEquals(50, $job->processed_rows);
        $this->assertEquals(45, $job->successful_rows);
        $this->assertEquals(5, $job->error_rows);
        $this->assertEquals(50, $job->last_processed_row);
    }

    /** @test */
    public function it_can_increment_processed_rows_for_successful_row()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'processed_rows' => 10,
            'successful_rows' => 8,
            'error_rows' => 2,
        ]);

        $job->incrementProcessedRows(true, 11);

        $job->refresh();
        $this->assertEquals(11, $job->processed_rows);
        $this->assertEquals(9, $job->successful_rows);
        $this->assertEquals(2, $job->error_rows);
        $this->assertEquals(11, $job->last_processed_row);
    }

    /** @test */
    public function it_can_increment_processed_rows_for_error_row()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
            'processed_rows' => 10,
            'successful_rows' => 8,
            'error_rows' => 2,
        ]);

        $job->incrementProcessedRows(false, 11);

        $job->refresh();
        $this->assertEquals(11, $job->processed_rows);
        $this->assertEquals(8, $job->successful_rows);
        $this->assertEquals(3, $job->error_rows);
        $this->assertEquals(11, $job->last_processed_row);
    }

    /** @test */
    public function it_can_filter_by_status()
    {
        ImportJob::create([
            'filename' => 'pending.csv',
            'file_path' => '/pending.csv',
            'status' => ImportJob::STATUS_PENDING,
        ]);

        ImportJob::create([
            'filename' => 'completed.csv',
            'file_path' => '/completed.csv',
            'status' => ImportJob::STATUS_COMPLETED,
        ]);

        $pendingJobs = ImportJob::byStatus(ImportJob::STATUS_PENDING)->get();
        $completedJobs = ImportJob::byStatus(ImportJob::STATUS_COMPLETED)->get();

        $this->assertCount(1, $pendingJobs);
        $this->assertCount(1, $completedJobs);
        $this->assertEquals('pending.csv', $pendingJobs->first()->filename);
        $this->assertEquals('completed.csv', $completedJobs->first()->filename);
    }

    /** @test */
    public function it_has_import_errors_relationship()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/test.csv',
        ]);

        $error = ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test error',
        ]);

        $this->assertCount(1, $job->importErrors);
        $this->assertEquals($error->id, $job->importErrors->first()->id);
    }

    /** @test */
    public function it_can_generate_job_summary()
    {
        $job = ImportJob::create([
            'filename' => 'summary_test.csv',
            'file_path' => '/summary_test.csv',
            'status' => ImportJob::STATUS_COMPLETED,
            'total_rows' => 100,
            'processed_rows' => 100,
            'successful_rows' => 95,
            'error_rows' => 5,
        ]);

        ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test error 1',
        ]);

        ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_type' => ImportError::TYPE_VALIDATION,
            'error_message' => 'Test error 2',
        ]);

        $summary = $job->getSummary();

        $this->assertArrayHasKey('id', $summary);
        $this->assertArrayHasKey('filename', $summary);
        $this->assertArrayHasKey('status', $summary);
        $this->assertArrayHasKey('progress_percentage', $summary);
        $this->assertArrayHasKey('total_rows', $summary);
        $this->assertArrayHasKey('processed_rows', $summary);
        $this->assertArrayHasKey('successful_rows', $summary);
        $this->assertArrayHasKey('error_rows', $summary);
        $this->assertArrayHasKey('error_count', $summary);

        $this->assertEquals($job->id, $summary['id']);
        $this->assertEquals('summary_test.csv', $summary['filename']);
        $this->assertEquals(ImportJob::STATUS_COMPLETED, $summary['status']);
        $this->assertEquals(100.0, $summary['progress_percentage']);
        $this->assertEquals(100, $summary['total_rows']);
        $this->assertEquals(100, $summary['processed_rows']);
        $this->assertEquals(95, $summary['successful_rows']);
        $this->assertEquals(5, $summary['error_rows']);
        $this->assertEquals(2, $summary['error_count']);
    }
}