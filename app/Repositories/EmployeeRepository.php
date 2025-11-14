<?php

namespace App\Repositories;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

class EmployeeRepository
{
    /**
     * Find an employee by employee number.
     *
     * @param string $employeeNumber
     * @return Employee|null
     */
    public function findByEmployeeNumber(string $employeeNumber): ?Employee
    {
        return Employee::byEmployeeNumber($employeeNumber)->first();
    }

    /**
     * Find an employee by email address.
     *
     * @param string $email
     * @return Employee|null
     */
    public function findByEmail(string $email): ?Employee
    {
        return Employee::byEmail($email)->first();
    }

    /**
     * Check if an employee exists by employee number or email.
     *
     * @param string $employeeNumber
     * @param string $email
     * @return Employee|null
     */
    public function checkDuplicate(string $employeeNumber, string $email): ?Employee
    {
        return Employee::where('employee_number', $employeeNumber)
            ->orWhere('email', $email)
            ->first();
    }

    /**
     * Create or update an employee record (idempotent upsert operation).
     *
     * @param array $data
     * @return Employee
     */
    public function createOrUpdate(array $data): Employee
    {
        // First check if employee exists by employee_number or email
        $existingEmployee = $this->checkDuplicate($data['employee_number'], $data['email']);

        if ($existingEmployee) {
            // Update existing employee
            $existingEmployee->update($data);
            return $existingEmployee->fresh();
        }

        // Create new employee
        return Employee::create($data);
    }

    /**
     * Find employees by multiple employee numbers (batch lookup).
     *
     * @param array $employeeNumbers
     * @return Collection
     */
    public function findByEmployeeNumbers(array $employeeNumbers): Collection
    {
        return Employee::whereIn('employee_number', $employeeNumbers)->get();
    }

    /**
     * Find employees by multiple email addresses (batch lookup).
     *
     * @param array $emails
     * @return Collection
     */
    public function findByEmails(array $emails): Collection
    {
        return Employee::whereIn('email', $emails)->get();
    }

    /**
     * Check for duplicates in batch (efficient for large imports).
     *
     * @param array $employeeNumbers
     * @param array $emails
     * @return Collection
     */
    public function checkBatchDuplicates(array $employeeNumbers, array $emails): Collection
    {
        return Employee::where(function ($query) use ($employeeNumbers, $emails) {
            $query->whereIn('employee_number', $employeeNumbers)
                  ->orWhereIn('email', $emails);
        })->get();
    }

    /**
     * Get total employee count.
     *
     * @return int
     */
    public function count(): int
    {
        return Employee::count();
    }

    /**
     * Delete an employee by ID.
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        $employee = Employee::find($id);
        
        if (!$employee) {
            return false;
        }

        return $employee->delete();
    }
}