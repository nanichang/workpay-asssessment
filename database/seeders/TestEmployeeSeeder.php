<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TestEmployeeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Clear existing test data
        DB::table('employees')->where('employee_number', 'LIKE', 'TEST-%')->delete();
        
        $employees = [
            [
                'employee_number' => 'TEST-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@testcompany.com',
                'department' => 'Engineering',
                'salary' => 100000.00,
                'currency' => 'KES',
                'country_code' => 'KE',
                'start_date' => '2022-01-15',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_number' => 'TEST-002',
                'first_name' => 'Jane',
                'last_name' => 'Smith',
                'email' => 'jane.smith@testcompany.com',
                'department' => 'Finance',
                'salary' => 85000.00,
                'currency' => 'USD',
                'country_code' => 'NG',
                'start_date' => '2022-03-01',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_number' => 'TEST-003',
                'first_name' => 'Bob',
                'last_name' => 'Johnson',
                'email' => 'bob.johnson@testcompany.com',
                'department' => 'Sales',
                'salary' => 75000.00,
                'currency' => 'ZAR',
                'country_code' => 'ZA',
                'start_date' => '2021-11-10',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_number' => 'TEST-004',
                'first_name' => 'Alice',
                'last_name' => 'Brown',
                'email' => 'alice.brown@testcompany.com',
                'department' => 'Customer Success',
                'salary' => 65000.00,
                'currency' => 'GHS',
                'country_code' => 'GH',
                'start_date' => '2023-02-20',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'employee_number' => 'TEST-005',
                'first_name' => 'Charlie',
                'last_name' => 'Wilson',
                'email' => 'charlie.wilson@testcompany.com',
                'department' => 'Support',
                'salary' => 55000.00,
                'currency' => 'UGX',
                'country_code' => 'UG',
                'start_date' => '2023-05-15',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('employees')->insert($employees);
        
        $this->command->info('Created ' . count($employees) . ' test employees');
    }

    /**
     * Generate a larger dataset for performance testing
     */
    public function generateLargeDataset(int $count = 1000): void
    {
        $this->command->info("Generating {$count} test employees for performance testing...");
        
        $departments = ['Engineering', 'Finance', 'Sales', 'Support', 'Customer Success', 'Product', 'Marketing', 'HR'];
        $currencies = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
        $countries = ['KE', 'NG', 'ZA', 'GH', 'UG', 'RW', 'TZ'];
        
        $employees = [];
        $batchSize = 100;
        
        for ($i = 1; $i <= $count; $i++) {
            $employees[] = [
                'employee_number' => 'PERF-' . str_pad($i, 6, '0', STR_PAD_LEFT),
                'first_name' => 'FirstName' . $i,
                'last_name' => 'LastName' . $i,
                'email' => "employee{$i}@perftest.com",
                'department' => $departments[array_rand($departments)],
                'salary' => rand(40000, 150000),
                'currency' => $currencies[array_rand($currencies)],
                'country_code' => $countries[array_rand($countries)],
                'start_date' => now()->subDays(rand(30, 1095))->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
            
            // Insert in batches to avoid memory issues
            if (count($employees) >= $batchSize) {
                DB::table('employees')->insert($employees);
                $employees = [];
                
                if ($i % 500 === 0) {
                    $this->command->info("Generated {$i}/{$count} employees...");
                }
            }
        }
        
        // Insert remaining employees
        if (!empty($employees)) {
            DB::table('employees')->insert($employees);
        }
        
        $this->command->info("Successfully generated {$count} performance test employees");
    }
}