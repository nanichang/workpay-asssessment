<?php

namespace App\Services;

class ValidationResult
{
    private bool $isValid;
    private array $errors;

    public function __construct(bool $isValid, array $errors = [])
    {
        $this->isValid = $isValid;
        $this->errors = $errors;
    }

    /**
     * Check if validation passed
     *
     * @return bool
     */
    public function isValid(): bool
    {
        return $this->isValid;
    }

    /**
     * Get validation errors
     *
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if validation failed
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !$this->isValid;
    }

    /**
     * Get first error message
     *
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }

    /**
     * Get all errors as a single string
     *
     * @param string $separator
     * @return string
     */
    public function getErrorsAsString(string $separator = '; '): string
    {
        return implode($separator, $this->errors);
    }
}