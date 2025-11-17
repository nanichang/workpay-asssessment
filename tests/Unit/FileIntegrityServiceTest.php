<?php

namespace Tests\Unit;

use App\Models\ImportJob;
use App\Services\FileIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileIntegrityServiceTest extends TestCase
{
    use RefreshDatabase;

    private FileIntegrityService $service;
    private string $testFilePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileIntegrityService();
        
        // Create a test file
        $this->testFilePath = tempnam(sys_get_temp_dir(), 'test_integrity_');
        file_put_contents($this->testFilePath, "test,data\n1,value1\n2,value2\n");
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_can_calculate_file_integrity()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => $this->testFilePath,
            'status' => ImportJob::STATUS_PENDING,
        ]);

        $integrity = $this->service->calculateFileIntegrity($job, $this->testFilePath);

        $this->assertArrayHasKey('file_size', $integrity);
        $this->assertArrayHasKey('file_hash', $integrity);
        $this->assertArrayHasKey('file_last_modified', $integrity);
        
        $job->refresh();
        $this->assertNotNull($job->file_size);
        $this->assertNotNull($job->file_hash);
        $this->assertNotNull($job->file_last_modified);
    }

    /** @test */
    public function it_can_verify_file_integrity_successfully()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => $this->testFilePath,
            'status' => ImportJob::STATUS_PENDING,
        ]);

        // Calculate integrity first
        $this->service->calculateFileIntegrity($job, $this->testFilePath);

        // Verify integrity
        $result = $this->service->verifyFileIntegrity($job);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertContains('File integrity verified successfully', $result['details']);
    }

    /** @test */
    public function it_detects_file_size_mismatch()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => $this->testFilePath,
            'status' => ImportJob::STATUS_PENDING,
            'file_size' => 999, // Wrong size
            'file_hash' => 'dummy_hash',
        ]);

        $result = $this->service->verifyFileIntegrity($job);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('File size mismatch', $result['errors'][0]);
    }

    /** @test */
    public function it_detects_missing_file()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => '/nonexistent/file.csv',
            'status' => ImportJob::STATUS_PENDING,
            'file_size' => 100,
            'file_hash' => 'dummy_hash',
        ]);

        $result = $this->service->verifyFileIntegrity($job);

        $this->assertFalse($result['valid']);
        $this->assertContains('Import file no longer exists', $result['errors']);
    }

    /** @test */
    public function it_can_validate_resumption_point()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => $this->testFilePath,
            'status' => ImportJob::STATUS_PROCESSING,
            'total_rows' => 100,
            'last_processed_row' => 50,
        ]);

        // Calculate integrity first
        $this->service->calculateFileIntegrity($job, $this->testFilePath);

        $result = $this->service->validateResumptionPoint($job, 60);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    /** @test */
    public function it_rejects_invalid_resumption_point()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => $this->testFilePath,
            'status' => ImportJob::STATUS_PROCESSING,
            'total_rows' => 100,
        ]);

        // Calculate integrity first
        $this->service->calculateFileIntegrity($job, $this->testFilePath);

        $result = $this->service->validateResumptionPoint($job, 150); // Beyond total rows

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('exceeds total rows', $result['errors'][0]);
    }

    /** @test */
    public function it_can_create_and_restore_backup()
    {
        $job = ImportJob::create([
            'filename' => 'test.csv',
            'file_path' => $this->testFilePath,
            'status' => ImportJob::STATUS_PROCESSING,
            'last_processed_row' => 25,
            'processed_rows' => 25,
            'successful_rows' => 20,
            'error_rows' => 5,
        ]);

        // Create backup
        $backup = $this->service->createResumptionBackup($job);

        $this->assertEquals(25, $backup['last_processed_row']);
        $this->assertEquals(25, $backup['processed_rows']);

        // Modify job state
        $job->update([
            'last_processed_row' => 50,
            'processed_rows' => 50,
        ]);

        // Restore from backup
        $restored = $this->service->restoreFromBackup($job);

        $this->assertTrue($restored);
        
        $job->refresh();
        $this->assertEquals(25, $job->last_processed_row);
        $this->assertEquals(25, $job->processed_rows);
    }
}