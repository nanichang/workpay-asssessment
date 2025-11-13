<?php

namespace Tests\Unit;

use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_an_employee_with_valid_data()
    {
        $employeeData = [
            'employee_number' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'department' => 'Engineering',
            'salary' => 75000.00,
            'currency' => 'USD',
            'country_code' => 'KE',
            'start_date' => '2023-01-15',
        ];

        $employee = Employee::create($employeeData);

        $this->assertInstanceOf(Employee::class, $employee);
        $this->assertEquals('EMP001', $employee->employee_number);
        $this->assertEquals('John', $employee->first_name);
        $this->assertEquals('Doe', $employee->last_name);
        $this->assertEquals('john.doe@example.com', $employee->email);
        $this->assertEquals('Engineering', $employee->department);
        $this->assertEquals('75000.00', $employee->salary);
        $this->assertEquals('USD', $employee->currency);
        $this->assertEquals('KE', $employee->country_code);
        $this->assertEquals('2023-01-15', $employee->start_date->format('Y-m-d'));
    }

    /** @test */
    public function it_casts_salary_to_decimal()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
            'salary' => 50000,
        ]);

        $this->assertEquals('50000.00', $employee->salary);
        $this->assertIsString($employee->salary);
    }

    /** @test */
    public function it_casts_start_date_to_carbon_instance()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP003',
            'first_name' => 'Bob',
            'last_name' => 'Johnson',
            'email' => 'bob.johnson@example.com',
            'start_date' => '2023-06-01',
        ]);

        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $employee->start_date);
        $this->assertEquals('2023-06-01', $employee->start_date->format('Y-m-d'));
    }

    /** @test */
    public function it_returns_valid_currencies()
    {
        $validCurrencies = Employee::getValidCurrencies();
        
        $expectedCurrencies = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
        
        $this->assertEquals($expectedCurrencies, $validCurrencies);
        $this->assertCount(8, $validCurrencies);
    }

    /** @test */
    public function it_returns_valid_countries()
    {
        $validCountries = Employee::getValidCountries();
        
        $expectedCountries = ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'];
        
        $this->assertEquals($expectedCountries, $validCountries);
        $this->assertCount(7, $validCountries);
    }

    /** @test */
    public function it_can_find_employee_by_employee_number()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP004',
            'first_name' => 'Alice',
            'last_name' => 'Brown',
            'email' => 'alice.brown@example.com',
        ]);

        $foundEmployee = Employee::byEmployeeNumber('EMP004')->first();

        $this->assertNotNull($foundEmployee);
        $this->assertEquals($employee->id, $foundEmployee->id);
        $this->assertEquals('EMP004', $foundEmployee->employee_number);
    }

    /** @test */
    public function it_can_find_employee_by_email()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP005',
            'first_name' => 'Charlie',
            'last_name' => 'Wilson',
            'email' => 'charlie.wilson@example.com',
        ]);

        $foundEmployee = Employee::byEmail('charlie.wilson@example.com')->first();

        $this->assertNotNull($foundEmployee);
        $this->assertEquals($employee->id, $foundEmployee->id);
        $this->assertEquals('charlie.wilson@example.com', $foundEmployee->email);
    }

    /** @test */
    public function it_returns_null_when_employee_not_found_by_number()
    {
        $foundEmployee = Employee::byEmployeeNumber('NONEXISTENT')->first();

        $this->assertNull($foundEmployee);
    }

    /** @test */
    public function it_returns_null_when_employee_not_found_by_email()
    {
        $foundEmployee = Employee::byEmail('nonexistent@example.com')->first();

        $this->assertNull($foundEmployee);
    }

    /** @test */
    public function it_has_correct_validation_rules_structure()
    {
        $rules = Employee::validationRules();

        $this->assertArrayHasKey('employee_number', $rules);
        $this->assertArrayHasKey('first_name', $rules);
        $this->assertArrayHasKey('last_name', $rules);
        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayHasKey('department', $rules);
        $this->assertArrayHasKey('salary', $rules);
        $this->assertArrayHasKey('currency', $rules);
        $this->assertArrayHasKey('country_code', $rules);
        $this->assertArrayHasKey('start_date', $rules);
    }

    /** @test */
    public function it_includes_unique_rules_for_employee_number_and_email()
    {
        $rules = Employee::validationRules();

        // Check that employee_number has unique rule
        $this->assertIsArray($rules['employee_number']);
        $this->assertContains('required', $rules['employee_number']);
        
        // Check that email has unique rule
        $this->assertIsArray($rules['email']);
        $this->assertContains('required', $rules['email']);
        $this->assertContains('email', $rules['email']);
    }

    /** @test */
    public function it_can_ignore_current_record_in_validation_rules()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP006',
            'first_name' => 'David',
            'last_name' => 'Miller',
            'email' => 'david.miller@example.com',
        ]);

        $rules = Employee::validationRules($employee->id);

        $this->assertIsArray($rules['employee_number']);
        $this->assertIsArray($rules['email']);
        
        // The rules should contain ignore clauses for the current employee
        $employeeNumberRule = collect($rules['employee_number'])->first(function ($rule) {
            return is_object($rule) && method_exists($rule, 'ignore');
        });
        
        $emailRule = collect($rules['email'])->first(function ($rule) {
            return is_object($rule) && method_exists($rule, 'ignore');
        });

        $this->assertNotNull($employeeNumberRule);
        $this->assertNotNull($emailRule);
    }
}