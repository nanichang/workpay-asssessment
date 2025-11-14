<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Repositories\EmployeeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private EmployeeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new EmployeeRepository();
    }

    /** @test */
    public function it_can_find_employee_by_employee_number()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP001',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
        ]);

        $foundEmployee = $this->repository->findByEmployeeNumber('EMP001');

        $this->assertNotNull($foundEmployee);
        $this->assertEquals($employee->id, $foundEmployee->id);
        $this->assertEquals('EMP001', $foundEmployee->employee_number);
    }

    /** @test */
    public function it_returns_null_when_employee_not_found_by_number()
    {
        $foundEmployee = $this->repository->findByEmployeeNumber('NONEXISTENT');

        $this->assertNull($foundEmployee);
    }

    /** @test */
    public function it_can_find_employee_by_email()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP002',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'jane.smith@example.com',
        ]);

        $foundEmployee = $this->repository->findByEmail('jane.smith@example.com');

        $this->assertNotNull($foundEmployee);
        $this->assertEquals($employee->id, $foundEmployee->id);
        $this->assertEquals('jane.smith@example.com', $foundEmployee->email);
    }

    /** @test */
    public function it_returns_null_when_employee_not_found_by_email()
    {
        $foundEmployee = $this->repository->findByEmail('nonexistent@example.com');

        $this->assertNull($foundEmployee);
    }

    /** @test */
    public function it_can_check_duplicate_by_employee_number()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP003',
            'first_name' => 'Bob',
            'last_name' => 'Johnson',
            'email' => 'bob.johnson@example.com',
        ]);

        $duplicate = $this->repository->checkDuplicate('EMP003', 'different@example.com');

        $this->assertNotNull($duplicate);
        $this->assertEquals($employee->id, $duplicate->id);
    }

    /** @test */
    public function it_can_check_duplicate_by_email()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP004',
            'first_name' => 'Alice',
            'last_name' => 'Brown',
            'email' => 'alice.brown@example.com',
        ]);

        $duplicate = $this->repository->checkDuplicate('DIFFERENT001', 'alice.brown@example.com');

        $this->assertNotNull($duplicate);
        $this->assertEquals($employee->id, $duplicate->id);
    }

    /** @test */
    public function it_returns_null_when_no_duplicate_found()
    {
        Employee::create([
            'employee_number' => 'EMP005',
            'first_name' => 'Charlie',
            'last_name' => 'Wilson',
            'email' => 'charlie.wilson@example.com',
        ]);

        $duplicate = $this->repository->checkDuplicate('DIFFERENT002', 'different@example.com');

        $this->assertNull($duplicate);
    }

    /** @test */
    public function it_can_create_new_employee()
    {
        $employeeData = [
            'employee_number' => 'EMP006',
            'first_name' => 'David',
            'last_name' => 'Miller',
            'email' => 'david.miller@example.com',
            'department' => 'Engineering',
            'salary' => 75000.00,
            'currency' => 'USD',
            'country_code' => 'KE',
            'start_date' => '2023-01-15',
        ];

        $employee = $this->repository->createOrUpdate($employeeData);

        $this->assertInstanceOf(Employee::class, $employee);
        $this->assertEquals('EMP006', $employee->employee_number);
        $this->assertEquals('David', $employee->first_name);
        $this->assertEquals('Miller', $employee->last_name);
        $this->assertEquals('david.miller@example.com', $employee->email);
        $this->assertEquals('Engineering', $employee->department);
        $this->assertEquals('75000.00', $employee->salary);
        $this->assertEquals('USD', $employee->currency);
        $this->assertEquals('KE', $employee->country_code);

        // Verify it was actually saved to database
        $this->assertDatabaseHas('employees', [
            'employee_number' => 'EMP006',
            'email' => 'david.miller@example.com',
        ]);
    }

    /** @test */
    public function it_can_update_existing_employee_by_employee_number()
    {
        $existingEmployee = Employee::create([
            'employee_number' => 'EMP007',
            'first_name' => 'Original',
            'last_name' => 'Name',
            'email' => 'original@example.com',
            'department' => 'Sales',
            'salary' => 50000.00,
        ]);

        $updateData = [
            'employee_number' => 'EMP007', // Same employee number
            'first_name' => 'Updated',
            'last_name' => 'Name',
            'email' => 'updated@example.com',
            'department' => 'Engineering',
            'salary' => 75000.00,
            'currency' => 'USD',
        ];

        $updatedEmployee = $this->repository->createOrUpdate($updateData);

        $this->assertEquals($existingEmployee->id, $updatedEmployee->id);
        $this->assertEquals('Updated', $updatedEmployee->first_name);
        $this->assertEquals('updated@example.com', $updatedEmployee->email);
        $this->assertEquals('Engineering', $updatedEmployee->department);
        $this->assertEquals('75000.00', $updatedEmployee->salary);
        $this->assertEquals('USD', $updatedEmployee->currency);

        // Verify only one record exists
        $this->assertEquals(1, Employee::where('employee_number', 'EMP007')->count());
    }

    /** @test */
    public function it_can_update_existing_employee_by_email()
    {
        $existingEmployee = Employee::create([
            'employee_number' => 'EMP008',
            'first_name' => 'Original',
            'last_name' => 'User',
            'email' => 'same@example.com',
            'department' => 'HR',
        ]);

        $updateData = [
            'employee_number' => 'EMP999', // Different employee number
            'first_name' => 'Updated',
            'last_name' => 'User',
            'email' => 'same@example.com', // Same email
            'department' => 'Finance',
            'salary' => 60000.00,
        ];

        $updatedEmployee = $this->repository->createOrUpdate($updateData);

        $this->assertEquals($existingEmployee->id, $updatedEmployee->id);
        $this->assertEquals('EMP999', $updatedEmployee->employee_number); // Should be updated
        $this->assertEquals('Updated', $updatedEmployee->first_name);
        $this->assertEquals('Finance', $updatedEmployee->department);
        $this->assertEquals('60000.00', $updatedEmployee->salary);

        // Verify only one record exists with this email
        $this->assertEquals(1, Employee::where('email', 'same@example.com')->count());
    }

    /** @test */
    public function it_ensures_idempotency_on_multiple_calls()
    {
        $employeeData = [
            'employee_number' => 'EMP009',
            'first_name' => 'Idempotent',
            'last_name' => 'Test',
            'email' => 'idempotent@example.com',
            'department' => 'Testing',
            'salary' => 55000.00,
        ];

        // First call - creates employee
        $employee1 = $this->repository->createOrUpdate($employeeData);
        
        // Second call - should update same employee
        $employeeData['salary'] = 65000.00;
        $employee2 = $this->repository->createOrUpdate($employeeData);
        
        // Third call - should update same employee again
        $employeeData['department'] = 'QA';
        $employee3 = $this->repository->createOrUpdate($employeeData);

        // All should return the same employee ID
        $this->assertEquals($employee1->id, $employee2->id);
        $this->assertEquals($employee2->id, $employee3->id);

        // Verify only one record exists
        $this->assertEquals(1, Employee::where('employee_number', 'EMP009')->count());
        $this->assertEquals(1, Employee::where('email', 'idempotent@example.com')->count());

        // Verify final state
        $finalEmployee = Employee::find($employee1->id);
        $this->assertEquals('65000.00', $finalEmployee->salary);
        $this->assertEquals('QA', $finalEmployee->department);
    }

    /** @test */
    public function it_can_find_employees_by_multiple_employee_numbers()
    {
        Employee::create([
            'employee_number' => 'EMP010',
            'first_name' => 'First',
            'last_name' => 'Employee',
            'email' => 'first@example.com',
        ]);

        Employee::create([
            'employee_number' => 'EMP011',
            'first_name' => 'Second',
            'last_name' => 'Employee',
            'email' => 'second@example.com',
        ]);

        Employee::create([
            'employee_number' => 'EMP012',
            'first_name' => 'Third',
            'last_name' => 'Employee',
            'email' => 'third@example.com',
        ]);

        $employees = $this->repository->findByEmployeeNumbers(['EMP010', 'EMP012', 'NONEXISTENT']);

        $this->assertCount(2, $employees);
        $this->assertTrue($employees->contains('employee_number', 'EMP010'));
        $this->assertTrue($employees->contains('employee_number', 'EMP012'));
        $this->assertFalse($employees->contains('employee_number', 'EMP011'));
        $this->assertFalse($employees->contains('employee_number', 'NONEXISTENT'));
    }

    /** @test */
    public function it_can_find_employees_by_multiple_emails()
    {
        Employee::create([
            'employee_number' => 'EMP013',
            'first_name' => 'Alpha',
            'last_name' => 'User',
            'email' => 'alpha@example.com',
        ]);

        Employee::create([
            'employee_number' => 'EMP014',
            'first_name' => 'Beta',
            'last_name' => 'User',
            'email' => 'beta@example.com',
        ]);

        Employee::create([
            'employee_number' => 'EMP015',
            'first_name' => 'Gamma',
            'last_name' => 'User',
            'email' => 'gamma@example.com',
        ]);

        $employees = $this->repository->findByEmails(['alpha@example.com', 'gamma@example.com', 'nonexistent@example.com']);

        $this->assertCount(2, $employees);
        $this->assertTrue($employees->contains('email', 'alpha@example.com'));
        $this->assertTrue($employees->contains('email', 'gamma@example.com'));
        $this->assertFalse($employees->contains('email', 'beta@example.com'));
        $this->assertFalse($employees->contains('email', 'nonexistent@example.com'));
    }

    /** @test */
    public function it_can_check_batch_duplicates()
    {
        Employee::create([
            'employee_number' => 'EMP016',
            'first_name' => 'Existing',
            'last_name' => 'One',
            'email' => 'existing1@example.com',
        ]);

        Employee::create([
            'employee_number' => 'EMP017',
            'first_name' => 'Existing',
            'last_name' => 'Two',
            'email' => 'existing2@example.com',
        ]);

        $duplicates = $this->repository->checkBatchDuplicates(
            ['EMP016', 'EMP999', 'EMP020'],
            ['existing2@example.com', 'new@example.com', 'another@example.com']
        );

        $this->assertCount(2, $duplicates);
        $this->assertTrue($duplicates->contains('employee_number', 'EMP016'));
        $this->assertTrue($duplicates->contains('email', 'existing2@example.com'));
    }

    /** @test */
    public function it_can_count_total_employees()
    {
        $this->assertEquals(0, $this->repository->count());

        Employee::create([
            'employee_number' => 'EMP018',
            'first_name' => 'Count',
            'last_name' => 'Test1',
            'email' => 'count1@example.com',
        ]);

        Employee::create([
            'employee_number' => 'EMP019',
            'first_name' => 'Count',
            'last_name' => 'Test2',
            'email' => 'count2@example.com',
        ]);

        $this->assertEquals(2, $this->repository->count());
    }

    /** @test */
    public function it_can_delete_employee_by_id()
    {
        $employee = Employee::create([
            'employee_number' => 'EMP020',
            'first_name' => 'Delete',
            'last_name' => 'Test',
            'email' => 'delete@example.com',
        ]);

        $result = $this->repository->delete($employee->id);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('employees', [
            'id' => $employee->id,
            'employee_number' => 'EMP020',
        ]);
    }

    /** @test */
    public function it_returns_false_when_deleting_nonexistent_employee()
    {
        $result = $this->repository->delete(99999);

        $this->assertFalse($result);
    }
}