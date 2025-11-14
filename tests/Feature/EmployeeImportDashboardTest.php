<?php

namespace Tests\Feature;

use App\Livewire\EmployeeImportDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeImportDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_dashboard_component()
    {
        Livewire::test(EmployeeImportDashboard::class)
            ->assertStatus(200)
            ->assertSet('currentImportJobId', null)
            ->assertSet('activeTab', 'upload');
    }

    /** @test */
    public function it_handles_file_uploaded_event()
    {
        Livewire::test(EmployeeImportDashboard::class)
            ->dispatch('fileUploaded', 'test-job-id')
            ->assertSet('currentImportJobId', 'test-job-id')
            ->assertSet('activeTab', 'progress');
    }

    /** @test */
    public function it_switches_active_tabs()
    {
        Livewire::test(EmployeeImportDashboard::class)
            ->call('setActiveTab', 'progress')
            ->assertSet('activeTab', 'progress')
            ->call('setActiveTab', 'errors')
            ->assertSet('activeTab', 'errors')
            ->call('setActiveTab', 'upload')
            ->assertSet('activeTab', 'upload');
    }

    /** @test */
    public function it_maintains_import_job_id_across_tab_switches()
    {
        Livewire::test(EmployeeImportDashboard::class)
            ->dispatch('fileUploaded', 'persistent-job-id')
            ->assertSet('currentImportJobId', 'persistent-job-id')
            ->call('setActiveTab', 'errors')
            ->assertSet('currentImportJobId', 'persistent-job-id')
            ->assertSet('activeTab', 'errors');
    }

    /** @test */
    public function it_starts_with_upload_tab_by_default()
    {
        Livewire::test(EmployeeImportDashboard::class)
            ->assertSet('activeTab', 'upload')
            ->assertSet('currentImportJobId', null);
    }

    /** @test */
    public function it_automatically_switches_to_progress_after_upload()
    {
        Livewire::test(EmployeeImportDashboard::class)
            ->assertSet('activeTab', 'upload')
            ->dispatch('fileUploaded', 'new-upload-job-id')
            ->assertSet('activeTab', 'progress')
            ->assertSet('currentImportJobId', 'new-upload-job-id');
    }
}