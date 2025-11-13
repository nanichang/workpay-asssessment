<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class Employee extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'employee_number',
        'first_name',
        'last_name',
        'email',
        'department',
        'salary',
        'currency',
        'country_code',
        'start_date',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'salary' => 'decimal:2',
        ];
    }

    /**
     * Get validation rules for employee data.
     *
     * @param int|null $employeeId
     * @return array<string, mixed>
     */
    public static function validationRules(?int $employeeId = null): array
    {
        $validCurrencies = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
        $validCountries = ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'];

        return [
            'employee_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('employees', 'employee_number')->ignore($employeeId),
            ],
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('employees', 'email')->ignore($employeeId),
            ],
            'department' => 'nullable|string|max:100',
            'salary' => 'nullable|numeric|min:0',
            'currency' => ['nullable', 'string', Rule::in($validCurrencies)],
            'country_code' => ['nullable', 'string', Rule::in($validCountries)],
            'start_date' => 'nullable|date|before_or_equal:today',
        ];
    }

    /**
     * Get the valid currency codes.
     *
     * @return array<string>
     */
    public static function getValidCurrencies(): array
    {
        return ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];
    }

    /**
     * Get the valid country codes.
     *
     * @return array<string>
     */
    public static function getValidCountries(): array
    {
        return ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'];
    }

    /**
     * Scope to find employee by employee number.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $employeeNumber
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEmployeeNumber($query, string $employeeNumber)
    {
        return $query->where('employee_number', $employeeNumber);
    }

    /**
     * Scope to find employee by email.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $email
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByEmail($query, string $email)
    {
        return $query->where('email', $email);
    }
}