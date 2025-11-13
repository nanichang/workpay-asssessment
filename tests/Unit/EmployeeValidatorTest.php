<?php

namespace Tests\Unit;

use App\Services\EmployeeValidator;
use App\Services\ValidationResult;
use Tests\TestCase;

class EmployeeValidatorTest extends TestCase
{
    private EmployeeValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new EmployeeValidator();
    }

    /** @test */
    public function it_validates_valid_employee_data()
    {
        $validData = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'department' => 'Engineering',
            'salary' => '75000',
            'currency' => 'USD',
            'country_code' => 'KE',
            'start_date' => '2023-01-15',
        ];

        $result = $this->validator->validate($validData);

        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->getErrors());
    }

    /** @test */
    public function it_fails_validation_for_missing_required_fields()
    {
        $invalidData = [
            'department' => 'Engineering',
            'salary' => '75000',
        ];

        $result = $this->validator->validate($invalidData);

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasErrors());
        
        $errors = $result->getErrors();
        $this->assertCount(4, $errors);
        $this->assertContains("Required field 'employee_number' is missing or empty.", $errors);
        $this->assertContains("Required field 'first_name' is missing or empty.", $errors);
        $this->assertContains("Required field 'last_name' is missing or empty.", $errors);
        $this->assertContains("Required field 'email' is missing or empty.", $errors);
    }

    /** @test */
    public function it_fails_validation_for_invalid_email_formats()
    {
        $testCases = [
            ['email' => 'invalid-email', 'expected' => 'Invalid email format'],
            ['email' => 'missing-at-symbol.com', 'expected' => 'Invalid email format'],
            ['email' => 'user@', 'expected' => 'Invalid email format'],
            ['email' => '@domain.com', 'expected' => 'Invalid email format'],
            ['email' => 'user@domain', 'expected' => 'Invalid email format'],
        ];

        foreach ($testCases as $testCase) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => $testCase['email'],
            ];

            $result = $this->validator->validate($data);
            
            $this->assertFalse($result->isValid(), "Email '{$testCase['email']}' should be invalid");
            $this->assertStringContainsString($testCase['expected'], $result->getErrorsAsString());
        }
    }

    /** @test */
    public function it_validates_salary_correctly()
    {
        // Valid salaries
        $validSalaries = ['1000', '50000.50', '100000', 1000, 50000.50];
        
        foreach ($validSalaries as $salary) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'salary' => $salary,
            ];

            $result = $this->validator->validate($data);
            $this->assertTrue($result->isValid(), "Salary '{$salary}' should be valid");
        }

        // Invalid salaries
        $invalidSalaries = ['50k', '66.5k', '-1000', '0', 'abc'];
        
        foreach ($invalidSalaries as $salary) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'salary' => $salary,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Salary '{$salary}' should be invalid");
            $this->assertStringContainsString('Salary must be a positive numeric value', $result->getErrorsAsString());
        }
    }

    /** @test */
    public function it_validates_currency_codes()
    {
        $validCurrencies = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
        
        foreach ($validCurrencies as $currency) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'currency' => $currency,
            ];

            $result = $this->validator->validate($data);
            $this->assertTrue($result->isValid(), "Currency '{$currency}' should be valid");
        }

        // Invalid currencies
        $invalidCurrencies = ['AAA', 'XXX', 'EUR', 'GBP'];
        
        foreach ($invalidCurrencies as $currency) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'currency' => $currency,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Currency '{$currency}' should be invalid");
            $this->assertStringContainsString('Invalid currency code', $result->getErrorsAsString());
        }
    }

    /** @test */
    public function it_validates_country_codes()
    {
        $validCountries = ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'];
        
        foreach ($validCountries as $country) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'country_code' => $country,
            ];

            $result = $this->validator->validate($data);
            $this->assertTrue($result->isValid(), "Country '{$country}' should be valid");
        }

        // Invalid countries
        $invalidCountries = ['XX', 'US', 'GB', 'FR'];
        
        foreach ($invalidCountries as $country) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'country_code' => $country,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Country '{$country}' should be invalid");
            $this->assertStringContainsString('Invalid country code', $result->getErrorsAsString());
        }
    }

    /** @test */
    public function it_validates_date_formats()
    {
        // Valid dates
        $validDates = ['2023-01-15', '2020-12-31', '2022-06-01'];
        
        foreach ($validDates as $date) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'start_date' => $date,
            ];

            $result = $this->validator->validate($data);
            $this->assertTrue($result->isValid(), "Date '{$date}' should be valid");
        }

        // Invalid date formats
        $invalidDates = ['15-2023-01', '2023/01/15', '01-15-2023', '2023-13-01', '2023-02-30'];
        
        foreach ($invalidDates as $date) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'start_date' => $date,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Date '{$date}' should be invalid");
        }
    }

    /** @test */
    public function it_rejects_future_dates()
    {
        $futureDate = date('Y-m-d', strtotime('+1 year'));
        
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'start_date' => $futureDate,
        ];

        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Start date cannot be in the future', $result->getErrorsAsString());
    }

    /** @test */
    public function it_validates_department_length()
    {
        // Valid department
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'department' => 'Engineering',
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid());

        // Invalid department (too long)
        $data['department'] = str_repeat('A', 101); // 101 characters
        
        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Department name cannot exceed 100 characters', $result->getErrorsAsString());
    }

    /** @test */
    public function it_handles_empty_optional_fields()
    {
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'department' => '',
            'salary' => '',
            'currency' => '',
            'country_code' => '',
            'start_date' => '',
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid());
    }
}