<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\ImportJob;
use Database\Seeders\TestEmployeeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GenerateTestScenarios extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'import:generate-scenarios 
                            {--scenario=basic : Scenario type (basic, performance, validation, mixed)}
                            {--count=100 : Number of records for performance scenarios}';

    /**
     * The console command description.
     */
    protected $description = 'Generate test scenarios for import testing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $scenario = $this->option('scenario');
        $count = (int) $this->option('count');

        $this->info("Generating test scenario: {$scenario}");

        switch ($scenario) {
            case 'basic':
                $this->generateBasicScenario();
                break;
            case 'performance':
                $this->generatePerformanceScenario($count);
                break;
            case 'validation':
                $this->generateValidationScenario();
                break;
            case 'mixed':
                $this->generateMixedScenario();
                break;
            default:
                $this->error("Invalid scenario: {$scenario}. Valid options: basic, performance, validation, mixed");
                return 1;
        }

        $this->info('Test scenario generation completed.');
        return 0;
    }

    /**
     * Generate basic test scenario with clean data
     */
    private function generateBasicScenario(): void
    {
        $this->info('Generating basic test scenario...');
        
        // Seed basic test employees
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this);
        $seeder->run();
        
        // Generate a small CSV file for testing
        $this->generateTestCsvFile('basic-test-employees.csv', 10, false);
        
        $this->info('Basic scenario generated with 5 database employees and 10 CSV records.');
    }

    /**
     * Generate performance test scenario
     */
    private function generatePerformanceScenario(int $count): void
    {
        $this->info("Generating performance test scenario with {$count} records...");
        
        // Generate large dataset in database
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this);
        $seeder->generateLargeDataset($count);
        
        // Generate large CSV file
        $this->generateTestCsvFile("performance-test-{$count}.csv", $count, false);
        
        $this->info("Performance scenario generated with {$count} database and CSV records.");
    }

    /**
     * Generate validation test scenario with intentional errors
     */
    private function generateValidationScenario(): void
    {
        $this->info('Generating validation test scenario...');
        
        // Generate CSV with validation errors
        $this->generateTestCsvFile('validation-test-errors.csv', 20, true);
        
        $this->info('Validation scenario generated with 20 records containing various validation errors.');
    }

    /**
     * Generate mixed scenario with existing data and new imports
     */
    private function generateMixedScenario(): void
    {
        $this->info('Generating mixed test scenario...');
        
        // Seed some existing employees
        $seeder = new TestEmployeeSeeder();
        $seeder->setCommand($this);
        $seeder->run();
        
        // Generate CSV with some duplicates and some new records
        $this->generateMixedCsvFile('mixed-test-scenario.csv');
        
        $this->info('Mixed scenario generated with existing employees and CSV containing duplicates and new records.');
    }

    /**
     * Generate a test CSV file
     */
    private function generateTestCsvFile(string $filename, int $count, bool $includeErrors): void
    {
        $filePath = storage_path("app/test-scenarios/{$filename}");
        
        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $handle = fopen($filePath, 'w');
        
        // Write header
        fputcsv($handle, [
            'employee_number',
            'first_name',
            'last_name',
            'email',
            'department',
            'salary',
            'currency',
            'country_code',
            'start_date'
        ]);
        
        $departments = ['Engineering', 'Finance', 'Sales', 'Support', 'Customer Success', 'Product', 'Marketing'];
        $currencies = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
        $countries = ['KE', 'NG', 'ZA', 'GH', 'UG', 'RW', 'TZ'];
        
        for ($i = 1; $i <= $count; $i++) {
            $row = [
                'employee_number' => 'GEN-' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'first_name' => 'Generated' . $i,
                'last_name' => 'Employee' . $i,
                'email' => "generated{$i}@testcompany.com",
                'department' => $departments[array_rand($departments)],
                'salary' => rand(40000, 150000),
                'currency' => $currencies[array_rand($currencies)],
                'country_code' => $countries[array_rand($countries)],
                'start_date' => now()->subDays(rand(30, 1095))->format('Y-m-d'),
            ];
            
            // Introduce errors for validation testing
            if ($includeErrors && $i % 4 === 0) {
                $row = $this->introduceValidationError($row, $i);
            }
            
            fputcsv($handle, $row);
        }
        
        fclose($handle);
        
        $this->info("Generated CSV file: {$filePath}");
    }

    /**
     * Generate mixed CSV file with duplicates and new records
     */
    private function generateMixedCsvFile(string $filename): void
    {
        $filePath = storage_path("app/test-scenarios/{$filename}");
        
        // Ensure directory exists
        $directory = dirname($filePath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        
        $handle = fopen($filePath, 'w');
        
        // Write header
        fputcsv($handle, [
            'employee_number',
            'first_name',
            'last_name',
            'email',
            'department',
            'salary',
            'currency',
            'country_code',
            'start_date'
        ]);
        
        // Add some records that match existing test employees (duplicates)
        $duplicateRows = [
            ['TEST-001', 'John', 'Doe', 'john.doe@testcompany.com', 'Engineering', '105000', 'KES', 'KE', '2022-01-15'],
            ['TEST-002', 'Jane', 'Smith', 'jane.smith@testcompany.com', 'Finance', '90000', 'USD', 'NG', '2022-03-01'],
        ];
        
        foreach ($duplicateRows as $row) {
            fputcsv($handle, $row);
        }
        
        // Add new records
        $departments = ['Engineering', 'Finance', 'Sales', 'Support'];
        $currencies = ['KES', 'USD', 'ZAR', 'NGN'];
        $countries = ['KE', 'NG', 'ZA', 'GH'];
        
        for ($i = 1; $i <= 15; $i++) {
            $row = [
                'MIX-' . str_pad($i, 3, '0', STR_PAD_LEFT),
                'Mixed' . $i,
                'Test' . $i,
                "mixed{$i}@testcompany.com",
                $departments[array_rand($departments)],
                rand(50000, 120000),
                $currencies[array_rand($currencies)],
                $countries[array_rand($countries)],
                now()->subDays(rand(30, 500))->format('Y-m-d'),
            ];
            
            fputcsv($handle, $row);
        }
        
        fclose($handle);
        
        $this->info("Generated mixed CSV file: {$filePath}");
    }

    /**
     * Introduce validation errors into a row
     */
    private function introduceValidationError(array $row, int $index): array
    {
        $errorType = $index % 8;
        
        switch ($errorType) {
            case 0:
                // Missing employee number
                $row['employee_number'] = '';
                break;
            case 1:
                // Invalid email format
                $row['email'] = 'invalid-email-format';
                break;
            case 2:
                // Negative salary
                $row['salary'] = -50000;
                break;
            case 3:
                // Invalid currency
                $row['currency'] = 'INVALID';
                break;
            case 4:
                // Invalid country code
                $row['country_code'] = 'XX';
                break;
            case 5:
                // Future start date
                $row['start_date'] = now()->addDays(30)->format('Y-m-d');
                break;
            case 6:
                // Text in salary field
                $row['salary'] = '50k';
                break;
            case 7:
                // Missing required field
                $row['first_name'] = '';
                break;
        }
        
        return $row;
    }
}