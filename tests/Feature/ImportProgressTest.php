<?php

namespace Tests\Feature;

use App\Livewire\ImportProgress;
use App\Models\ImportJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ImportProgressTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_progress_component()
    {
        Livewire::test(ImportProgress::class)
            ->assertStatus(200)
            ->assertSet('progress', 0)
            ->assertSet('isComplete', false)
            ->assertSet('autoRefresh', true);
    }

    /** @test */
    public function it_loads_progress_from_api()
    {
        $component = Livewire::test(ImportProgress::class);
        $component->set('importJobId', 'test-job-id');
        
        // Simulate API response data
        $component->set('importJob', (object)[
            'job_id' => 'test-job-id',
            'status' => 'processing',
            'progress_percentage' => 45,
            'total_rows' => 100,
            'processed_rows' => 45,
            'successful_rows' => 40,
            'error_rows' => 5
        ]);
        $component->set('progress', 45);
        $component->set('isComplete', false);

        $component->assertSet('progress', 45)
            ->assertSet('isComplete', false)
            ->assertSet('importJob.status', 'processing')
            ->assertSet('importJob.total_rows', 100)
            ->assertSet('importJob.processed_rows', 45);
    }

    /** @test */
    public function it_handles_completed_import_status()
    {
        $component = Livewire::test(ImportProgress::class);
        $component->set('importJobId', 'test-job-id');
        
        // Simulate completed status
        $component->set('importJob', (object)[
            'job_id' => 'test-job-id',
            'status' => 'completed',
            'progress_percentage' => 100,
            'total_rows' => 100,
            'processed_rows' => 100,
            'successful_rows' => 95,
            'error_rows' => 5
        ]);
        $component->set('progress', 100);
        $component->set('isComplete', true);
        $component->set('autoRefresh', false);

        $component->assertSet('progress', 100)
            ->assertSet('isComplete', true)
            ->assertSet('autoRefresh', false)
            ->assertSet('importJob.status', 'completed');
    }

    /** @test */
    public function it_handles_failed_import_status()
    {
        $component = Livewire::test(ImportProgress::class);
        $component->set('importJobId', 'test-job-id');
        
        // Simulate failed status
        $component->set('importJob', (object)[
            'job_id' => 'test-job-id',
            'status' => 'failed',
            'progress_percentage' => 25,
            'total_rows' => 100,
            'processed_rows' => 25,
            'successful_rows' => 20,
            'error_rows' => 5
        ]);
        $component->set('progress', 25);
        $component->set('isComplete', true);
        $component->set('autoRefresh', false);

        $component->assertSet('progress', 25)
            ->assertSet('isComplete', true)
            ->assertSet('autoRefresh', false)
            ->assertSet('importJob.status', 'failed');
    }

    /** @test */
    public function it_starts_tracking_on_file_uploaded_event()
    {
        Livewire::test(ImportProgress::class)
            ->dispatch('fileUploaded', 'new-job-id')
            ->assertSet('importJobId', 'new-job-id')
            ->assertSet('autoRefresh', true);
    }

    /** @test */
    public function it_toggles_auto_refresh()
    {
        Livewire::test(ImportProgress::class)
            ->assertSet('autoRefresh', true)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', false)
            ->call('toggleAutoRefresh')
            ->assertSet('autoRefresh', true);
    }

    /** @test */
    public function it_refreshes_progress_manually()
    {
        $component = Livewire::test(ImportProgress::class);
        $component->set('importJobId', 'test-job-id');
        
        // Simulate refreshed progress
        $component->set('progress', 75);
        
        $component->assertSet('progress', 75);
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        Http::fake([
            '/api/employee-import/test-job-id/progress' => Http::response([], 500)
        ]);

        Livewire::test(ImportProgress::class)
            ->set('importJobId', 'test-job-id')
            ->set('autoRefresh', true)
            ->call('loadProgress')
            ->assertSet('autoRefresh', false);
    }

    /** @test */
    public function it_returns_correct_status_colors()
    {
        $component = Livewire::test(ImportProgress::class);

        // Test pending status
        $component->set('importJob', (object)['status' => 'pending']);
        $this->assertEquals('yellow', $component->get('statusColor'));

        // Test processing status
        $component->set('importJob', (object)['status' => 'processing']);
        $this->assertEquals('blue', $component->get('statusColor'));

        // Test completed status
        $component->set('importJob', (object)['status' => 'completed']);
        $this->assertEquals('green', $component->get('statusColor'));

        // Test failed status
        $component->set('importJob', (object)['status' => 'failed']);
        $this->assertEquals('red', $component->get('statusColor'));

        // Test unknown status
        $component->set('importJob', (object)['status' => 'unknown']);
        $this->assertEquals('gray', $component->get('statusColor'));
    }

    /** @test */
    public function it_returns_correct_status_text()
    {
        $component = Livewire::test(ImportProgress::class);

        // Test all status texts
        $component->set('importJob', (object)['status' => 'pending']);
        $this->assertEquals('Pending', $component->get('statusText'));

        $component->set('importJob', (object)['status' => 'processing']);
        $this->assertEquals('Processing', $component->get('statusText'));

        $component->set('importJob', (object)['status' => 'completed']);
        $this->assertEquals('Completed', $component->get('statusText'));

        $component->set('importJob', (object)['status' => 'failed']);
        $this->assertEquals('Failed', $component->get('statusText'));
    }

    /** @test */
    public function it_calculates_estimated_time_correctly()
    {
        $component = Livewire::test(ImportProgress::class);

        // Test with no progress
        $component->set('progress', 0);
        $this->assertNull($component->get('estimatedTime'));

        // Test with progress (simulate 50% complete after 2 minutes)
        $startTime = now()->subMinutes(2);
        $component->set('progress', 50);
        $component->set('importJob', (object)['started_at' => $startTime->toISOString()]);
        
        $estimatedTime = $component->get('estimatedTime');
        $this->assertStringContainsString('minute', $estimatedTime);
    }

    /** @test */
    public function it_mounts_with_import_job_id()
    {
        Livewire::test(ImportProgress::class, ['importJobId' => 'initial-job-id'])
            ->assertSet('importJobId', 'initial-job-id');
    }

    /** @test */
    public function it_handles_missing_import_job_gracefully()
    {
        Livewire::test(ImportProgress::class)
            ->call('loadProgress')
            ->assertSet('progress', 0)
            ->assertSet('importJob', null);
    }
}