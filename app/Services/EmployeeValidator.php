<?php

namespace App\Services;

class EmployeeValidator
{
    /**
     * Valid currency codes based on requirements
     */
    private const VALID_CURRENCIES = ['KES', 'USD', 'ZAR', 'NGN', 'GHS', 'UGX', 'RWF', 'TZS'];

    /**
     * Valid country codes based on requirements
     */
    private const VALID_COUNTRIES = ['KE', 'NG', 'GH', 'UG', 'ZA', 'TZ', 'RW'];

    /**
     * Required fields for employee validation
     */
    private const REQUIRED_FIELDS = ['employee_number', 'first_name', 'last_name', 'email'];

    /**
     * Maximum department name length
     */
    private const MAX_DEPARTMENT_LENGTH = 100;

    /**
     * Validation cache service
     */
    private ValidationCache $validationCache;

    /**
     * Constructor
     */
    public function __construct(ValidationCache $validationCache)
    {
        $this->validationCache = $validationCache;
    }

    /**
     * Validate employee data and return validation result
     *
     * @param array $data Employee data to validate
     * @return ValidationResult
     */
    public function validate(array $data): ValidationResult
    {
        // Check cache first for complete validation result
        $cachedResult = $this->validationCache->getCachedValidationResult($data);
        if ($cachedResult) {
            return new ValidationResult($cachedResult['is_valid'], $cachedResult['errors']);
        }

        $errors = [];

        // Validate required fields
        $requiredErrors = $this->validateRequired($data);
        $errors = array_merge($errors, $requiredErrors);

        // Only proceed with other validations if required fields are present
        if (empty($requiredErrors)) {
            // Validate email format (with caching)
            if (!$this->validateEmailWithCache($data['email'])) {
                $errors[] = 'Invalid email format. Email must contain @ and a valid domain.';
            }

            // Validate employee number format
            if (!$this->validateEmployeeNumber($data['employee_number'])) {
                $errors[] = 'Invalid employee number format.';
            }
        }

        // Validate salary if present
        if (isset($data['salary']) && $data['salary'] !== '' && $data['salary'] !== null) {
            if (!$this->validateSalary($data['salary'])) {
                $errors[] = 'Salary must be a positive numeric value. Text formats like "50k" are not allowed.';
            }
        }

        // Validate currency if present (with caching)
        if (isset($data['currency']) && !empty($data['currency'])) {
            if (!$this->validateCurrencyWithCache($data['currency'])) {
                $errors[] = 'Invalid currency code. Valid currencies: ' . implode(', ', self::VALID_CURRENCIES);
            }
        }

        // Validate country code if present (with caching)
        if (isset($data['country_code']) && !empty($data['country_code'])) {
            if (!$this->validateCountryWithCache($data['country_code'])) {
                $errors[] = 'Invalid country code. Valid countries: ' . implode(', ', self::VALID_COUNTRIES);
            }
        }

        // Validate dates
        $dateErrors = $this->validateDates($data);
        $errors = array_merge($errors, $dateErrors);

        // Validate department length if present
        if (isset($data['department']) && !empty($data['department'])) {
            if (strlen($data['department']) > self::MAX_DEPARTMENT_LENGTH) {
                $errors[] = 'Department name cannot exceed ' . self::MAX_DEPARTMENT_LENGTH . ' characters.';
            }
        }

        $result = new ValidationResult(empty($errors), $errors);
        
        // Cache the validation result
        $this->validationCache->cacheValidationResult($data, [
            'is_valid' => $result->isValid(),
            'errors' => $result->getErrors()
        ]);

        return $result;
    }

    /**
     * Validate required fields are present and not empty
     *
     * @param array $data
     * @return array
     */
    private function validateRequired(array $data): array
    {
        $errors = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($data[$field]) || empty(trim($data[$field]))) {
                $errors[] = "Required field '{$field}' is missing or empty.";
            }
        }

        return $errors;
    }

    /**
     * Validate email format
     *
     * @param string $email
     * @return bool
     */
    private function validateEmail(string $email): bool
    {
        // Must contain @ symbol
        if (!str_contains($email, '@')) {
            return false;
        }

        // Use PHP's built-in email validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        // Additional check for domain part
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }

        $domain = $parts[1];
        
        // Domain must contain at least one dot and have valid characters
        if (!str_contains($domain, '.') || strlen($domain) < 3) {
            return false;
        }

        return true;
    }

    /**
     * Validate employee number format
     *
     * @param string $number
     * @return bool
     */
    private function validateEmployeeNumber(string $number): bool
    {
        // Employee number should not be empty and should have reasonable length
        $trimmed = trim($number);
        
        if (empty($trimmed)) {
            return false;
        }

        // Should be between 1 and 50 characters (reasonable business constraint)
        if (strlen($trimmed) > 50) {
            return false;
        }

        return true;
    }

    /**
     * Validate salary is positive numeric value
     *
     * @param mixed $salary
     * @return bool
     */
    private function validateSalary($salary): bool
    {
        // Convert to string for validation
        $salaryStr = (string) $salary;
        
        // Check for text formats like "50k", "66.5k", etc.
        if (preg_match('/[a-zA-Z]/', $salaryStr)) {
            return false;
        }

        // Must be numeric
        if (!is_numeric($salary)) {
            return false;
        }

        // Must be positive (greater than 0)
        $numericValue = (float) $salary;
        if ($numericValue <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Validate email format with caching
     *
     * @param string $email
     * @return bool
     */
    private function validateEmailWithCache(string $email): bool
    {
        $cachedResult = $this->validationCache->getCachedEmailValidation($email);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        $isValid = $this->validateEmail($email);
        $this->validationCache->cacheEmailValidation($email, $isValid);
        
        return $isValid;
    }

    /**
     * Validate currency code with caching
     *
     * @param string $currency
     * @return bool
     */
    private function validateCurrencyWithCache(string $currency): bool
    {
        $cachedResult = $this->validationCache->getCachedCurrencyValidation($currency);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        $isValid = $this->validateCurrency($currency);
        $this->validationCache->cacheCurrencyValidation($currency, $isValid);
        
        return $isValid;
    }

    /**
     * Validate country code with caching
     *
     * @param string $country
     * @return bool
     */
    private function validateCountryWithCache(string $country): bool
    {
        $cachedResult = $this->validationCache->getCachedCountryValidation($country);
        if ($cachedResult !== null) {
            return $cachedResult;
        }

        $isValid = $this->validateCountry($country);
        $this->validationCache->cacheCountryValidation($country, $isValid);
        
        return $isValid;
    }

    /**
     * Validate currency code
     *
     * @param string $currency
     * @return bool
     */
    private function validateCurrency(string $currency): bool
    {
        return in_array(strtoupper(trim($currency)), self::VALID_CURRENCIES, true);
    }

    /**
     * Validate country code
     *
     * @param string $country
     * @return bool
     */
    private function validateCountry(string $country): bool
    {
        return in_array(strtoupper(trim($country)), self::VALID_COUNTRIES, true);
    }

    /**
     * Validate date fields
     *
     * @param array $data
     * @return array
     */
    private function validateDates(array $data): array
    {
        $errors = [];

        if (isset($data['start_date']) && !empty($data['start_date'])) {
            $dateError = $this->validateDateFormat($data['start_date']);
            if ($dateError) {
                $errors[] = $dateError;
            }
        }

        return $errors;
    }

    /**
     * Validate date format (YYYY-MM-DD) and ensure it's not in the future
     *
     * @param string $date
     * @return string|null Error message or null if valid
     */
    private function validateDateFormat(string $date): ?string
    {
        $trimmedDate = trim($date);
        
        // Check format using regex (YYYY-MM-DD)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmedDate)) {
            return 'Date must be in YYYY-MM-DD format.';
        }

        // Validate the date is actually valid
        $dateParts = explode('-', $trimmedDate);
        $year = (int) $dateParts[0];
        $month = (int) $dateParts[1];
        $day = (int) $dateParts[2];

        if (!checkdate($month, $day, $year)) {
            return 'Invalid date provided.';
        }

        // Check if date is in the future
        $providedDate = new \DateTime($trimmedDate);
        $today = new \DateTime('today');

        if ($providedDate > $today) {
            return 'Start date cannot be in the future.';
        }

        return null;
    }
}