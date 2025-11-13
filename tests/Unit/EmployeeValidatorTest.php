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

    /** @test */
    public function it_validates_employee_number_edge_cases()
    {
        // Test whitespace handling
        $data = [
            'employee_number' => '  EMP-001  ',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid());

        // Test very long employee number (over 50 characters)
        $data['employee_number'] = str_repeat('A', 51);
        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString('Invalid employee number format', $result->getErrorsAsString());

        // Test empty after trim
        $data['employee_number'] = '   ';
        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid());
        $this->assertStringContainsString("Required field 'employee_number' is missing or empty", $result->getErrorsAsString());
    }

    /** @test */
    public function it_validates_name_field_edge_cases()
    {
        // Test whitespace-only names
        $testCases = [
            ['first_name' => '   ', 'field' => 'first_name'],
            ['last_name' => '   ', 'field' => 'last_name'],
        ];

        foreach ($testCases as $testCase) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
            ];
            
            $data[$testCase['field']] = $testCase[$testCase['field']];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Whitespace-only {$testCase['field']} should be invalid");
            $this->assertStringContainsString("Required field '{$testCase['field']}' is missing or empty", $result->getErrorsAsString());
        }
    }

    /** @test */
    public function it_validates_email_comprehensive_edge_cases()
    {
        $invalidEmails = [
            'plainaddress' => 'no @ symbol',
            '@missingusername.com' => 'missing username',
            'username@' => 'missing domain',
            'username@.com' => 'domain starts with dot',
            'username@com' => 'no dot in domain',
            'username@domain.' => 'domain ends with dot',
            'username@domain..com' => 'double dots in domain',
            'user name@domain.com' => 'space in username',
            'username@domain .com' => 'space in domain',
            'username@@domain.com' => 'double @ symbol',
            '' => 'empty email',
            '   ' => 'whitespace only',
        ];

        foreach ($invalidEmails as $email => $description) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => $email,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Email '{$email}' ({$description}) should be invalid");
        }

        // Test valid emails
        $validEmails = [
            'user@domain.com',
            'user.name@domain.com',
            'user+tag@domain.co.uk',
            'user123@domain123.com',
            'user_name@sub.domain.org',
        ];

        foreach ($validEmails as $email) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => $email,
            ];

            $result = $this->validator->validate($data);
            $this->assertTrue($result->isValid(), "Email '{$email}' should be valid");
        }
    }

    /** @test */
    public function it_validates_salary_comprehensive_edge_cases()
    {
        $invalidSalaries = [
            '0' => 'zero salary',
            '-100' => 'negative salary',
            '-0.01' => 'negative decimal',
            'abc' => 'text only',
            '50k' => 'text with k suffix',
            '66.5k' => 'decimal with k suffix',
            '1,000' => 'comma separator',
            '$50000' => 'currency symbol',
            '50 000' => 'space separator',
            'null' => 'null string',
            'undefined' => 'undefined string',
        ];

        foreach ($invalidSalaries as $salary => $description) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'salary' => $salary,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Salary '{$salary}' ({$description}) should be invalid");
            $this->assertStringContainsString('Salary must be a positive numeric value', $result->getErrorsAsString());
        }

        // Test boundary values
        $validSalaries = [
            '0.01' => 'minimum positive',
            '1' => 'integer one',
            '999999.99' => 'large decimal',
            1000 => 'integer type',
            50000.50 => 'float type',
        ];

        foreach ($validSalaries as $salary => $description) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'salary' => $salary,
            ];

            $result = $this->validator->validate($data);
            $this->assertTrue($result->isValid(), "Salary '{$salary}' ({$description}) should be valid");
        }
    }

    /** @test */
    public function it_validates_date_comprehensive_edge_cases()
    {
        $invalidDates = [
            '2023/01/15' => 'wrong separator',
            '01-15-2023' => 'US format',
            '15-01-2023' => 'European format',
            '2023-1-15' => 'single digit month',
            '2023-01-1' => 'single digit day',
            '23-01-15' => 'two digit year',
            '2023-13-01' => 'invalid month',
            '2023-02-30' => 'invalid day for month',
            '2023-04-31' => 'invalid day for April',
            '2023-00-15' => 'zero month',
            '2023-01-00' => 'zero day',
            '0000-01-15' => 'zero year',
            'not-a-date' => 'text',
        ];

        foreach ($invalidDates as $date => $description) {
            $data = [
                'employee_number' => 'EMP-001',
                'first_name' => 'John',
                'last_name' => 'Doe',
                'email' => 'john.doe@example.com',
                'start_date' => $date,
            ];

            $result = $this->validator->validate($data);
            $this->assertFalse($result->isValid(), "Date '{$date}' ({$description}) should be invalid");
        }

        // Test leap year dates
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'start_date' => '2024-02-29', // Valid leap year
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid(), 'Leap year date should be valid');

        // Test non-leap year
        $data['start_date'] = '2023-02-29'; // Invalid non-leap year
        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid(), 'Non-leap year Feb 29 should be invalid');
    }

    /** @test */
    public function it_validates_currency_and_country_case_insensitive()
    {
        // Test lowercase currencies
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'currency' => 'usd',
            'country_code' => 'ke',
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid(), 'Lowercase currency and country should be valid');

        // Test mixed case
        $data['currency'] = 'UsD';
        $data['country_code'] = 'Ke';
        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid(), 'Mixed case currency and country should be valid');
    }

    /** @test */
    public function it_validates_department_edge_cases()
    {
        // Test exactly at limit
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'department' => str_repeat('A', 100), // Exactly 100 characters
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid(), 'Department with exactly 100 characters should be valid');

        // Test over limit
        $data['department'] = str_repeat('A', 101); // 101 characters
        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid(), 'Department over 100 characters should be invalid');
        $this->assertStringContainsString('Department name cannot exceed 100 characters', $result->getErrorsAsString());
    }

    /** @test */
    public function it_formats_error_messages_correctly()
    {
        $data = [
            'employee_number' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => 'invalid-email',
            'salary' => '50k',
            'currency' => 'INVALID',
            'country_code' => 'XX',
            'start_date' => '2030-01-01', // Future date
            'department' => str_repeat('A', 101),
        ];

        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid());

        $errors = $result->getErrors();
        $this->assertIsArray($errors);
        $this->assertNotEmpty($errors);

        // Test error message formatting
        $errorString = $result->getErrorsAsString();
        $this->assertIsString($errorString);
        $this->assertStringContainsString(';', $errorString); // Default separator

        // Test custom separator
        $errorStringCustom = $result->getErrorsAsString(' | ');
        $this->assertStringContainsString(' | ', $errorStringCustom);

        // Test first error
        $firstError = $result->getFirstError();
        $this->assertIsString($firstError);
        $this->assertEquals($errors[0], $firstError);

        // Test hasErrors method
        $this->assertTrue($result->hasErrors());
    }

    /** @test */
    public function it_validates_multiple_errors_in_single_validation()
    {
        $data = [
            'employee_number' => '',
            'first_name' => '',
            'last_name' => '',
            'email' => '',
        ];

        $result = $this->validator->validate($data);
        $this->assertFalse($result->isValid());
        
        $errors = $result->getErrors();
        $this->assertCount(4, $errors, 'Should have exactly 4 required field errors');
        
        // Verify all required field errors are present
        $errorString = $result->getErrorsAsString();
        $this->assertStringContainsString("Required field 'employee_number'", $errorString);
        $this->assertStringContainsString("Required field 'first_name'", $errorString);
        $this->assertStringContainsString("Required field 'last_name'", $errorString);
        $this->assertStringContainsString("Required field 'email'", $errorString);
    }

    /** @test */
    public function it_treats_empty_optional_fields_as_valid()
    {
        // Test that empty optional fields are treated as valid (not validated)
        $data = [
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'salary' => '', // Empty optional field
            'start_date' => '', // Empty optional field
            'department' => '   ', // Whitespace optional field
        ];

        $result = $this->validator->validate($data);
        $this->assertTrue($result->isValid(), 'Empty optional fields should be valid');
        $this->assertEmpty($result->getErrors());
    }
}