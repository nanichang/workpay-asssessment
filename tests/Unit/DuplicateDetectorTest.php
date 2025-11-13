<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\DuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    private DuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new DuplicateDetector();
    }

    public function test_it_detects_duplicates_within_file_by_employee_number()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John'],
            ['employee_number' => 'EMP-002', 'email' => 'jane@example.com', 'first_name' => 'Jane'],
            ['employee_number' => 'EMP-001', 'email' => 'john.doe@example.com', 'first_name' => 'John'], // Duplicate employee_number
        ];

        $this->detector->initializeWithFileData($fileRows);

        // First occurrence should be marked as duplicate (not last occurrence)
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[0], 0));
        
        // Different employee should not be duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
        
        // Last occurrence should not be marked as duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[2], 2));
    }

    public function test_it_detects_duplicates_within_file_by_email()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John'],
            ['employee_number' => 'EMP-002', 'email' => 'jane@example.com', 'first_name' => 'Jane'],
            ['employee_number' => 'EMP-003', 'email' => 'john@example.com', 'first_name' => 'John'], // Duplicate email
        ];

        $this->detector->initializeWithFileData($fileRows);

        // First occurrence should be marked as duplicate (not last occurrence)
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[0], 0));
        
        // Different employee should not be duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
        
        // Last occurrence should not be marked as duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[2], 2));
    }

    public function test_it_processes_last_occurrence_when_duplicates_exist()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John', 'salary' => '50000'],
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John', 'salary' => '60000'],
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John', 'salary' => '70000'], // This should be processed
        ];

        $this->detector->initializeWithFileData($fileRows);

        // First two occurrences should be duplicates
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[0], 0));
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[1], 1));
        
        // Last occurrence should not be duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[2], 2));
    }

    public function test_it_finds_existing_employee_by_employee_number()
    {
        // Create an employee in the database
        $employee = Employee::create([
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $foundEmployee = $this->detector->findExistingEmployee('EMP-001', 'different@example.com');
        
        $this->assertNotNull($foundEmployee);
        $this->assertEquals($employee->id, $foundEmployee->id);
        $this->assertEquals('EMP-001', $foundEmployee->employee_number);
    }

    public function test_it_finds_existing_employee_by_email()
    {
        // Create an employee in the database
        $employee = Employee::create([
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $foundEmployee = $this->detector->findExistingEmployee('EMP-999', 'john.doe@example.com');
        
        $this->assertNotNull($foundEmployee);
        $this->assertEquals($employee->id, $foundEmployee->id);
        $this->assertEquals('john.doe@example.com', $foundEmployee->email);
    }

    public function test_it_returns_null_when_employee_not_found()
    {
        $foundEmployee = $this->detector->findExistingEmployee('EMP-999', 'nonexistent@example.com');
        
        $this->assertNull($foundEmployee);
    }

    public function test_it_tracks_processed_employees()
    {
        $this->assertFalse($this->detector->wasAlreadyProcessed('EMP-001', 'john@example.com'));
        
        $this->detector->markAsProcessed('EMP-001', 'john@example.com');
        
        $this->assertTrue($this->detector->wasAlreadyProcessed('EMP-001', 'different@example.com'));
        $this->assertTrue($this->detector->wasAlreadyProcessed('EMP-999', 'john@example.com'));
    }

    public function test_it_handles_empty_employee_number_and_email()
    {
        $fileRows = [
            ['employee_number' => '', 'email' => '', 'first_name' => 'John'],
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'Jane'],
        ];

        $this->detector->initializeWithFileData($fileRows);

        // Empty fields should not be considered duplicates
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[0], 0));
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
    }

    public function test_it_generates_duplicate_statistics()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com'],
            ['employee_number' => 'EMP-002', 'email' => 'jane@example.com'],
            ['employee_number' => 'EMP-001', 'email' => 'john.duplicate@example.com'], // Duplicate employee_number
            ['employee_number' => 'EMP-003', 'email' => 'john@example.com'], // Duplicate email
        ];

        $this->detector->initializeWithFileData($fileRows);
        $stats = $this->detector->getDuplicateStatistics();

        $this->assertArrayHasKey('total_duplicates', $stats);
        $this->assertArrayHasKey('duplicate_employee_numbers', $stats);
        $this->assertArrayHasKey('duplicate_emails', $stats);
        
        $this->assertGreaterThan(0, $stats['total_duplicates']);
    }

    public function test_it_resets_state()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com'],
        ];

        $this->detector->initializeWithFileData($fileRows);
        $this->detector->markAsProcessed('EMP-001', 'john@example.com');
        
        $this->assertTrue($this->detector->wasAlreadyProcessed('EMP-001', 'john@example.com'));
        
        $this->detector->reset();
        
        $this->assertFalse($this->detector->wasAlreadyProcessed('EMP-001', 'john@example.com'));
    }

    public function test_it_handles_complex_duplicate_scenarios()
    {
        // Test scenario with multiple types of duplicates
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John'],
            ['employee_number' => 'EMP-002', 'email' => 'jane@example.com', 'first_name' => 'Jane'],
            ['employee_number' => 'EMP-001', 'email' => 'john.new@example.com', 'first_name' => 'John'], // Duplicate employee_number
            ['employee_number' => 'EMP-003', 'email' => 'john@example.com', 'first_name' => 'Johnny'], // Duplicate email
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com', 'first_name' => 'John'], // Both duplicates
        ];

        $this->detector->initializeWithFileData($fileRows);

        // First occurrence should be duplicate (not last)
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[0], 0));
        
        // Unique record should not be duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
        
        // Duplicate employee_number should be duplicate (not last occurrence)
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[2], 2));
        
        // Duplicate email should be duplicate (not last occurrence)
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[3], 3));
        
        // Last occurrence should not be duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[4], 4));
    }

    public function test_it_handles_whitespace_in_duplicate_detection()
    {
        $fileRows = [
            ['employee_number' => '  EMP-001  ', 'email' => '  john@example.com  '],
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com'],
        ];

        $this->detector->initializeWithFileData($fileRows);

        // Should detect duplicates even with whitespace
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[0], 0));
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
    }

    public function test_it_handles_case_sensitivity_in_duplicate_detection()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'John@Example.com'],
            ['employee_number' => 'emp-001', 'email' => 'john@example.com'],
        ];

        $this->detector->initializeWithFileData($fileRows);

        // Should be case sensitive - these are different
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[0], 0));
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
    }

    public function test_it_handles_missing_fields_in_duplicate_detection()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001'], // Missing email
            ['email' => 'john@example.com'], // Missing employee_number
            ['first_name' => 'John'], // Missing both
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com'],
        ];

        $this->detector->initializeWithFileData($fileRows);

        // Rows with missing required fields should not be considered duplicates
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[0], 0));
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[1], 1));
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[2], 2));
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[3], 3));
    }

    public function test_it_handles_large_file_duplicate_detection()
    {
        // Create a large array with duplicates at various positions
        $fileRows = [];
        
        // Add 100 unique records
        for ($i = 1; $i <= 100; $i++) {
            $fileRows[] = [
                'employee_number' => "EMP-{$i}",
                'email' => "user{$i}@example.com"
            ];
        }
        
        // Add duplicates of first and last records
        $fileRows[] = ['employee_number' => 'EMP-1', 'email' => 'user1@example.com']; // Index 100
        $fileRows[] = ['employee_number' => 'EMP-100', 'email' => 'user100@example.com']; // Index 101

        $this->detector->initializeWithFileData($fileRows);

        // Original records should be duplicates (not last occurrence)
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[0], 0)); // EMP-1
        $this->assertTrue($this->detector->isDuplicateInFile($fileRows[99], 99)); // EMP-100
        
        // Last occurrences should not be duplicates
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[100], 100)); // EMP-1 duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[101], 101)); // EMP-100 duplicate
        
        // Random middle record should not be duplicate
        $this->assertFalse($this->detector->isDuplicateInFile($fileRows[50], 50)); // EMP-51
    }

    public function test_it_tracks_processed_employees_with_edge_cases()
    {
        // Test with empty strings
        $this->detector->markAsProcessed('', '');
        $this->assertTrue($this->detector->wasAlreadyProcessed('', 'any@example.com'));
        $this->assertTrue($this->detector->wasAlreadyProcessed('EMP-001', ''));

        $this->detector->reset();

        // Test with whitespace
        $this->detector->markAsProcessed('  EMP-001  ', '  john@example.com  ');
        $this->assertTrue($this->detector->wasAlreadyProcessed('  EMP-001  ', 'different@example.com'));
        $this->assertTrue($this->detector->wasAlreadyProcessed('EMP-999', '  john@example.com  '));
    }

    public function test_it_finds_existing_employees_with_edge_cases()
    {
        // Create employees with various data
        $employee1 = Employee::create([
            'employee_number' => 'EMP-001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $employee2 = Employee::create([
            'employee_number' => 'EMP-002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
        ]);

        // Test finding by employee_number when email doesn't match
        $found = $this->detector->findExistingEmployee('EMP-001', 'different@example.com');
        $this->assertNotNull($found);
        $this->assertEquals($employee1->id, $found->id);

        // Test finding by email when employee_number doesn't match
        $found = $this->detector->findExistingEmployee('EMP-999', 'jane.smith@example.com');
        $this->assertNotNull($found);
        $this->assertEquals($employee2->id, $found->id);

        // Test with empty strings
        $found = $this->detector->findExistingEmployee('', '');
        $this->assertNull($found);

        // Test with non-existent data
        $found = $this->detector->findExistingEmployee('EMP-999', 'nonexistent@example.com');
        $this->assertNull($found);
    }

    public function test_duplicate_statistics_comprehensive()
    {
        $fileRows = [
            ['employee_number' => 'EMP-001', 'email' => 'john@example.com'],
            ['employee_number' => 'EMP-002', 'email' => 'jane@example.com'],
            ['employee_number' => 'EMP-001', 'email' => 'john.new@example.com'], // Duplicate employee_number
            ['employee_number' => 'EMP-003', 'email' => 'john@example.com'], // Duplicate email
            ['employee_number' => '', 'email' => ''], // Empty fields
            ['employee_number' => 'EMP-004', 'email' => 'unique@example.com'], // Unique
        ];

        $this->detector->initializeWithFileData($fileRows);
        $stats = $this->detector->getDuplicateStatistics();

        $this->assertArrayHasKey('total_duplicates', $stats);
        $this->assertArrayHasKey('duplicate_employee_numbers', $stats);
        $this->assertArrayHasKey('duplicate_emails', $stats);

        // Should detect duplicates properly
        $this->assertGreaterThan(0, $stats['total_duplicates']);
        
        // Verify structure of duplicate arrays
        if (!empty($stats['duplicate_employee_numbers'])) {
            $this->assertArrayHasKey('value', $stats['duplicate_employee_numbers'][0]);
            $this->assertArrayHasKey('rows', $stats['duplicate_employee_numbers'][0]);
        }
        
        if (!empty($stats['duplicate_emails'])) {
            $this->assertArrayHasKey('value', $stats['duplicate_emails'][0]);
            $this->assertArrayHasKey('rows', $stats['duplicate_emails'][0]);
        }
    }
}