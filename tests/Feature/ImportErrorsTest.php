<?php

namespace Tests\Feature;

use App\Livewire\ImportErrors;
use App\Models\ImportJob;
use App\Models\ImportError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ImportErrorsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_renders_errors_component()
    {
        Livewire::test(ImportErrors::class)
            ->assertStatus(200)
            ->assertSet('errors', [])
            ->assertSet('errorSummary', [])
            ->assertSet('selectedErrorType', '')
            ->assertSet('searchTerm', '')
            ->assertSet('perPage', 10);
    }

    /** @test */
    public function it_loads_errors_from_api()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 1,
                        'row_number' => 2,
                        'error_type' => 'validation',
                        'error_message' => 'Invalid email format',
                        'row_data' => ['email' => 'invalid-email']
                    ],
                    [
                        'id' => 2,
                        'row_number' => 3,
                        'error_type' => 'duplicate',
                        'error_message' => 'Duplicate employee number',
                        'row_data' => ['employee_number' => 'EMP-001']
                    ]
                ],
                'summary' => [
                    'total_errors' => 2,
                    'validation_errors' => 1,
                    'duplicate_errors' => 1
                ],
                'current_page' => 1,
                'per_page' => 10,
                'total' => 2
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->call('loadErrors')
            ->assertSet('errors', function ($errors) {
                return count($errors) === 2 && 
                       $errors[0]['error_type'] === 'validation' &&
                       $errors[1]['error_type'] === 'duplicate';
            })
            ->assertSet('errorSummary.total_errors', 2);
    }

    /** @test */
    public function it_filters_errors_by_type()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 1,
                        'row_number' => 2,
                        'error_type' => 'validation',
                        'error_message' => 'Invalid email format'
                    ]
                ],
                'summary' => ['validation_errors' => 1],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->set('selectedErrorType', 'validation')
            ->call('loadErrors')
            ->assertSet('errors', function ($errors) {
                return count($errors) === 1 && $errors[0]['error_type'] === 'validation';
            });
    }

    /** @test */
    public function it_searches_errors_by_term()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 1,
                        'row_number' => 2,
                        'error_type' => 'validation',
                        'error_message' => 'Invalid email format'
                    ]
                ],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->set('searchTerm', 'email')
            ->call('loadErrors')
            ->assertSet('errors', function ($errors) {
                return count($errors) === 1 && 
                       str_contains($errors[0]['error_message'], 'email');
            });
    }

    /** @test */
    public function it_sorts_errors_by_field()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->call('sortBy', 'error_type')
            ->assertSet('sortBy', 'error_type')
            ->assertSet('sortDirection', 'asc')
            ->call('sortBy', 'error_type') // Click again to reverse
            ->assertSet('sortDirection', 'desc');
    }

    /** @test */
    public function it_updates_pagination()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->set('perPage', 25)
            ->assertSet('perPage', 25);
    }

    /** @test */
    public function it_clears_filters()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->set('selectedErrorType', 'validation')
            ->set('searchTerm', 'test search')
            ->call('clearFilters')
            ->assertSet('selectedErrorType', '')
            ->assertSet('searchTerm', '');
    }

    /** @test */
    public function it_refreshes_errors()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 1,
                        'row_number' => 2,
                        'error_type' => 'validation',
                        'error_message' => 'Updated error message'
                    ]
                ],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->call('refreshErrors')
            ->assertSet('errors', function ($errors) {
                return count($errors) === 1 && 
                       $errors[0]['error_message'] === 'Updated error message';
            });
    }

    /** @test */
    public function it_starts_tracking_on_file_uploaded_event()
    {
        Http::fake([
            '/api/employee-import/new-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class)
            ->dispatch('fileUploaded', 'new-job-id')
            ->assertSet('importJobId', 'new-job-id');
    }

    /** @test */
    public function it_handles_api_errors_gracefully()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([], 500)
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->call('loadErrors')
            ->assertSet('errors', [])
            ->assertSet('errorSummary', []);
    }

    /** @test */
    public function it_returns_correct_error_type_colors()
    {
        $component = Livewire::test(ImportErrors::class);
        $colors = $component->get('errorTypeColor');

        $this->assertEquals('red', $colors['validation']);
        $this->assertEquals('yellow', $colors['duplicate']);
        $this->assertEquals('orange', $colors['format']);
        $this->assertEquals('purple', $colors['business_rule']);
        $this->assertEquals('gray', $colors['system']);
    }

    /** @test */
    public function it_returns_error_type_options()
    {
        $component = Livewire::test(ImportErrors::class);
        $options = $component->call('getErrorTypeOptions');

        $this->assertArrayHasKey('', $options);
        $this->assertArrayHasKey('validation', $options);
        $this->assertArrayHasKey('duplicate', $options);
        $this->assertArrayHasKey('format', $options);
        $this->assertArrayHasKey('business_rule', $options);
        $this->assertArrayHasKey('system', $options);
        
        $this->assertEquals('All Error Types', $options['']);
        $this->assertEquals('Validation Errors', $options['validation']);
    }

    /** @test */
    public function it_mounts_with_import_job_id()
    {
        Http::fake([
            '/api/employee-import/initial-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [
                    [
                        'id' => 1,
                        'row_number' => 2,
                        'error_type' => 'validation',
                        'error_message' => 'Test error'
                    ]
                ],
                'current_page' => 1
            ], 200)
        ]);

        Livewire::test(ImportErrors::class, ['importJobId' => 'initial-job-id'])
            ->assertSet('importJobId', 'initial-job-id')
            ->assertSet('errors', function ($errors) {
                return count($errors) === 1;
            });
    }

    /** @test */
    public function it_handles_missing_import_job_gracefully()
    {
        Livewire::test(ImportErrors::class)
            ->call('loadErrors')
            ->assertSet('errors', [])
            ->assertSet('errorSummary', []);
    }

    /** @test */
    public function it_resets_page_when_filters_change()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => Http::response([
                'success' => true,
                'data' => [],
                'current_page' => 1
            ], 200)
        ]);

        $component = Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id');

        // Set page to 2
        $component->setPage(2);
        
        // Change filter - should reset to page 1
        $component->set('selectedErrorType', 'validation');
        
        $this->assertEquals(1, $component->get('page'));
    }

    /** @test */
    public function it_builds_correct_api_query_parameters()
    {
        Http::fake([
            '/api/employee-import/test-job-id/errors*' => function ($request) {
                $query = $request->url();
                
                // Verify query parameters are included
                $this->assertStringContainsString('error_type=validation', $query);
                $this->assertStringContainsString('search=test', $query);
                $this->assertStringContainsString('sort_by=row_number', $query);
                $this->assertStringContainsString('sort_direction=desc', $query);
                $this->assertStringContainsString('per_page=25', $query);
                
                return Http::response([
                    'success' => true,
                    'data' => [],
                    'current_page' => 1
                ], 200);
            }
        ]);

        Livewire::test(ImportErrors::class)
            ->set('importJobId', 'test-job-id')
            ->set('selectedErrorType', 'validation')
            ->set('searchTerm', 'test')
            ->set('sortBy', 'row_number')
            ->set('sortDirection', 'desc')
            ->set('perPage', 25)
            ->call('loadErrors');
    }
}