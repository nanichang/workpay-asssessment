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
}