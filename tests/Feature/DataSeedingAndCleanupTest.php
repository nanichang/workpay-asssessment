<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ImportJob;
use App\Models\ImportError;
use Database\Seeders\TestEmployeeSeeder;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

class DataSeedingAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_seed_test_employee_data()
    {
        // Ensure no employees exist initially
        $this->assertEquals(0, Employee::count());

        // Run the test employee seeder
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this->createMockCommand());
        $seeder->run();

        // Verify employees were created
        $this->assertGreaterThan(0, Employee::count());
        
        // Verify test employees have correct prefix
        $testEmployees = Employee::where('employee_number', 'LIKE', 'TEST-%')->get();
        $this->assertGreaterThan(0, $testEmployees->count());
        
        // Verify employee data structure
        $firstEmployee = $testEmployees->first();
        $this->assertNotNull($firstEmployee->employee_number);
        $this->assertNotNull($firstEmployee->first_name);
        $this->assertNotNull($firstEmployee->last_name);
        $this->assertNotNull($firstEmployee->email);
        $this->assertNotNull($firstEmployee->department);
        $this->assertNotNull($firstEmployee->salary);
        $this->assertNotNull($firstEmployee->currency);
        $this->assertNotNull($firstEmployee->country_code);
        $this->assertNotNull($firstEmployee->start_date);
    }

    /** @test */
    public function it_can_generate_large_performance_dataset()
    {
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this->createMockCommand());
        
        // Generate a smaller dataset for testing (50 records)
        $seeder->generateLargeDataset(50);
        
        // Verify performance test employees were created
        $perfEmployees = Employee::where('employee_number', 'LIKE', 'PERF-%')->get();
        $this->assertEquals(50, $perfEmployees->count());
        
        // Verify data variety
        $departments = $perfEmployees->pluck('department')->unique();
        $currencies = $perfEmployees->pluck('currency')->unique();
        $countries = $perfEmployees->pluck('country_code')->unique();
        
        $this->assertGreaterThan(1, $departments->count(), 'Should have multiple departments');
        $this->assertGreaterThan(1, $currencies->count(), 'Should have multiple currencies');
        $this->assertGreaterThan(1, $countries->count(), 'Should have multiple countries');
    }

    /** @test */
    public function it_can_clear_all_import_data()
    {
        // Create test data
        $this->createTestImportData();
        
        // Verify data exists
        $this->assertGreaterThan(0, Employee::count());
        $this->assertGreaterThan(0, ImportJob::count());
        $this->assertGreaterThan(0, ImportError::count());
        
        // Clear all data
        Artisan::call('import:clear', ['--type' => 'all', '--confirm' => true]);
        
        // Verify all data is cleared
        $this->assertEquals(0, Employee::count());
        $this->assertEquals(0, ImportJob::count());
        $this->assertEquals(0, ImportError::count());
    }

    /** @test */
    public function it_can_clear_only_employees()
    {
        // Create test data
        $this->createTestImportData();
        
        $initialJobCount = ImportJob::count();
        $initialErrorCount = ImportError::count();
        
        // Clear only employees
        Artisan::call('import:clear', ['--type' => 'employees', '--confirm' => true]);
        
        // Verify only employees are cleared
        $this->assertEquals(0, Employee::count());
        $this->assertEquals($initialJobCount, ImportJob::count());
        $this->assertEquals($initialErrorCount, ImportError::count());
    }

    /** @test */
    public function it_can_clear_only_test_data()
    {
        // Create mixed data - test and non-test
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this->createMockCommand());
        $seeder->run();
        $seeder->generateLargeDataset(10);
        
        // Create a non-test employee
        Employee::create([
            'employee_number' => 'REAL-001',
            'first_name' => 'Real',
            'last_name' => 'Employee',
            'email' => 'real@company.com',
            'department' => 'Engineering',
            'salary' => 100000,
            'currency' => 'KES',
            'country_code' => 'KE',
            'start_date' => '2022-01-01',
        ]);
        
        $totalEmployees = Employee::count();
        $testEmployees = Employee::where('employee_number', 'LIKE', 'TEST-%')
            ->orWhere('employee_number', 'LIKE', 'PERF-%')
            ->count();
        
        // Clear only test data
        Artisan::call('import:clear', ['--type' => 'test-data', '--confirm' => true]);
        
        // Verify only test employees are cleared
        $remainingEmployees = Employee::count();
        $this->assertEquals($totalEmployees - $testEmployees, $remainingEmployees);
        
        // Verify real employee still exists
        $this->assertTrue(Employee::where('employee_number', 'REAL-001')->exists());
    }

    /** @test */
    public function it_can_generate_basic_test_scenario()
    {
        Artisan::call('import:generate-scenarios', ['--scenario' => 'basic']);
        
        // Verify test employees were created
        $this->assertGreaterThan(0, Employee::where('employee_number', 'LIKE', 'TEST-%')->count());
        
        // Verify CSV file was generated in storage/app/test-scenarios
        $csvPath = storage_path('app/test-scenarios/basic-test-employees.csv');
        $this->assertTrue(file_exists($csvPath), 'CSV file should be generated');
    }

    /** @test */
    public function it_can_generate_validation_test_scenario()
    {
        Artisan::call('import:generate-scenarios', ['--scenario' => 'validation']);
        
        // Verify CSV file with errors was generated
        $csvPath = storage_path('app/test-scenarios/validation-test-errors.csv');
        $this->assertTrue(file_exists($csvPath), 'Validation CSV file should be generated');
        
        // Read and verify the CSV contains intentional errors
        $csvContent = file_get_contents($csvPath);
        $this->assertStringContainsString('employee_number', $csvContent);
        $this->assertNotEmpty($csvContent);
    }

    /** @test */
    public function it_can_generate_performance_test_scenario()
    {
        Artisan::call('import:generate-scenarios', ['--scenario' => 'performance', '--count' => 25]);
        
        // Verify performance employees were created
        $this->assertEquals(25, Employee::where('employee_number', 'LIKE', 'PERF-%')->count());
        
        // Verify CSV file was generated
        $csvPath = storage_path('app/test-scenarios/performance-test-25.csv');
        $this->assertTrue(file_exists($csvPath), 'Performance CSV file should be generated');
    }

    /** @test */
    public function it_can_generate_mixed_test_scenario()
    {
        // First seed some test employees
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this->createMockCommand());
        $seeder->run();
        
        Artisan::call('import:generate-scenarios', ['--scenario' => 'mixed']);
        
        // Verify CSV file was generated
        $csvPath = storage_path('app/test-scenarios/mixed-test-scenario.csv');
        $this->assertTrue(file_exists($csvPath), 'Mixed CSV file should be generated');
        
        // Verify the CSV contains both duplicate and new records
        $csvContent = file_get_contents($csvPath);
        $this->assertStringContainsString('TEST-001', $csvContent); // Duplicate
        $this->assertStringContainsString('MIX-', $csvContent); // New records
    }

    /** @test */
    public function it_can_display_import_statistics()
    {
        // Create test data with various statuses
        $this->createTestImportData();
        
        // Test overall statistics
        $exitCode = Artisan::call('import:stats');
        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Import System Statistics', $output);
        $this->assertStringContainsString('Total Import Jobs', $output);
        $this->assertStringContainsString('Total Employees', $output);
    }

    /** @test */
    public function it_can_display_detailed_statistics()
    {
        // Create test data
        $this->createTestImportData();
        
        // Test detailed statistics
        $exitCode = Artisan::call('import:stats', ['--detailed' => true]);
        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString('Performance Metrics', $output);
        $this->assertStringContainsString('System Health', $output);
    }

    /** @test */
    public function it_can_display_job_specific_statistics()
    {
        // Create a specific import job
        $job = ImportJob::create([
            'id' => 'test-stats-job-123',
            'filename' => 'test-stats.csv',
            'file_path' => '/tmp/test.csv',
            'status' => 'completed',
            'total_rows' => 100,
            'processed_rows' => 100,
            'successful_rows' => 95,
            'error_rows' => 5,
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinutes(5),
        ]);
        
        // Create some errors for this job
        ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'error_type' => 'validation',
            'error_message' => 'Test error message',
            'row_data' => ['test' => 'data'],
        ]);
        
        // Test job-specific statistics
        $exitCode = Artisan::call('import:stats', ['--job' => $job->id]);
        $this->assertEquals(0, $exitCode);
        
        $output = Artisan::output();
        $this->assertStringContainsString($job->id, $output);
        $this->assertStringContainsString('Success Rate', $output);
        $this->assertStringContainsString('Error Breakdown', $output);
        $this->assertStringContainsString('Sample Errors', $output);
    }

    /**
     * Create test import data for testing cleanup functionality
     */
    private function createTestImportData(): void
    {
        // Create test employees
        Employee::create([
            'employee_number' => 'TEST-CLEANUP-001',
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => 'test.cleanup@example.com',
            'department' => 'Testing',
            'salary' => 50000,
            'currency' => 'KES',
            'country_code' => 'KE',
            'start_date' => '2022-01-01',
        ]);
        
        // Create import job
        $job = ImportJob::create([
            'id' => 'test-cleanup-job',
            'filename' => 'test-cleanup.csv',
            'file_path' => '/tmp/test-cleanup.csv',
            'status' => 'completed',
            'total_rows' => 10,
            'processed_rows' => 10,
            'successful_rows' => 8,
            'error_rows' => 2,
        ]);
        
        // Create import errors
        ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => 1,
            'error_type' => 'validation',
            'error_message' => 'Test validation error',
            'row_data' => ['test' => 'data'],
        ]);
        
        ImportError::create([
            'import_job_id' => $job->id,
            'row_number' => 2,
            'error_type' => 'duplicate',
            'error_message' => 'Test duplicate error',
            'row_data' => ['test' => 'data2'],
        ]);
    }

    /**
     * Create a mock command for testing seeders
     */
    private function createMockCommand()
    {
        return new class extends \Illuminate\Console\Command {
            protected $signature = 'mock:command';
            protected $description = 'Mock command for testing';
            
            public function handle() {
                return 0;
            }
            
            public function info($message) {
                // Mock implementation
            }
        };
    }
}