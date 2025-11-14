<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ValidationCache
{
    /**
     * Get cache configuration from config
     */
    private function getCacheConfig(): array
    {
        return config('import.cache', [
            'store' => 'redis',
            'ttl' => ['validation' => 1800],
            'prefixes' => ['validation' => 'import:validation:']
        ]);
    }

    /**
     * Get cache store instance
     */
    private function getCacheStore(): \Illuminate\Contracts\Cache\Repository
    {
        $config = $this->getCacheConfig();
        return Cache::store($config['store']);
    }

    /**
     * Get cache prefix for validation data
     */
    private function getCachePrefix(): string
    {
        $config = $this->getCacheConfig();
        return $config['prefixes']['validation'];
    }

    /**
     * Get cache TTL for validation data
     */
    private function getCacheTTL(): int
    {
        $config = $this->getCacheConfig();
        return $config['ttl']['validation'];
    }

    /**
     * Cache validation result for a specific data combination
     *
     * @param array $data
     * @param array $validationResult
     * @return void
     */
    public function cacheValidationResult(array $data, array $validationResult): void
    {
        $cacheKey = $this->generateValidationCacheKey($data);
        
        $cacheData = [
            'is_valid' => $validationResult['is_valid'],
            'errors' => $validationResult['errors'],
            'cached_at' => now()->toISOString(),
        ];

        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL());
        
        Log::debug("Cached validation result for key: {$cacheKey}");
    }

    /**
     * Get cached validation result
     *
     * @param array $data
     * @return array|null
     */
    public function getCachedValidationResult(array $data): ?array
    {
        $cacheKey = $this->generateValidationCacheKey($data);
        $cached = $this->getCacheStore()->get($cacheKey);
        
        if ($cached) {
            Log::debug("Retrieved cached validation result for key: {$cacheKey}");
        }
        
        return $cached;
    }

    /**
     * Cache duplicate check result
     *
     * @param string $employeeNumber
     * @param string $email
     * @param bool $isDuplicate
     * @param array $duplicateInfo
     * @return void
     */
    public function cacheDuplicateCheck(string $employeeNumber, string $email, bool $isDuplicate, array $duplicateInfo = []): void
    {
        $cacheKey = $this->generateDuplicateCacheKey($employeeNumber, $email);
        
        $cacheData = [
            'is_duplicate' => $isDuplicate,
            'duplicate_info' => $duplicateInfo,
            'cached_at' => now()->toISOString(),
        ];

        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL());
        
        Log::debug("Cached duplicate check result for: {$employeeNumber} / {$email}");
    }

    /**
     * Get cached duplicate check result
     *
     * @param string $employeeNumber
     * @param string $email
     * @return array|null
     */
    public function getCachedDuplicateCheck(string $employeeNumber, string $email): ?array
    {
        $cacheKey = $this->generateDuplicateCacheKey($employeeNumber, $email);
        $cached = $this->getCacheStore()->get($cacheKey);
        
        if ($cached) {
            Log::debug("Retrieved cached duplicate check for: {$employeeNumber} / {$email}");
        }
        
        return $cached;
    }

    /**
     * Cache email validation result
     *
     * @param string $email
     * @param bool $isValid
     * @return void
     */
    public function cacheEmailValidation(string $email, bool $isValid): void
    {
        $cacheKey = $this->getCachePrefix() . 'email:' . md5(strtolower($email));
        
        $cacheData = [
            'is_valid' => $isValid,
            'cached_at' => now()->toISOString(),
        ];

        // Cache email validation for longer since email formats don't change
        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL() * 2);
    }

    /**
     * Get cached email validation result
     *
     * @param string $email
     * @return bool|null
     */
    public function getCachedEmailValidation(string $email): ?bool
    {
        $cacheKey = $this->getCachePrefix() . 'email:' . md5(strtolower($email));
        $cached = $this->getCacheStore()->get($cacheKey);
        
        return $cached ? $cached['is_valid'] : null;
    }

    /**
     * Cache currency validation result
     *
     * @param string $currency
     * @param bool $isValid
     * @return void
     */
    public function cacheCurrencyValidation(string $currency, bool $isValid): void
    {
        $cacheKey = $this->getCachePrefix() . 'currency:' . strtoupper($currency);
        
        $cacheData = [
            'is_valid' => $isValid,
            'cached_at' => now()->toISOString(),
        ];

        // Cache currency validation for a long time since supported currencies rarely change
        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL() * 4);
    }

    /**
     * Get cached currency validation result
     *
     * @param string $currency
     * @return bool|null
     */
    public function getCachedCurrencyValidation(string $currency): ?bool
    {
        $cacheKey = $this->getCachePrefix() . 'currency:' . strtoupper($currency);
        $cached = $this->getCacheStore()->get($cacheKey);
        
        return $cached ? $cached['is_valid'] : null;
    }

    /**
     * Cache country validation result
     *
     * @param string $countryCode
     * @param bool $isValid
     * @return void
     */
    public function cacheCountryValidation(string $countryCode, bool $isValid): void
    {
        $cacheKey = $this->getCachePrefix() . 'country:' . strtoupper($countryCode);
        
        $cacheData = [
            'is_valid' => $isValid,
            'cached_at' => now()->toISOString(),
        ];

        // Cache country validation for a long time since supported countries rarely change
        $this->getCacheStore()->put($cacheKey, $cacheData, $this->getCacheTTL() * 4);
    }

    /**
     * Get cached country validation result
     *
     * @param string $countryCode
     * @return bool|null
     */
    public function getCachedCountryValidation(string $countryCode): ?bool
    {
        $cacheKey = $this->getCachePrefix() . 'country:' . strtoupper($countryCode);
        $cached = $this->getCacheStore()->get($cacheKey);
        
        return $cached ? $cached['is_valid'] : null;
    }

    /**
     * Clear all validation cache for a specific import job
     *
     * @param string $importJobId
     * @return void
     */
    public function clearImportValidationCache(string $importJobId): void
    {
        $pattern = $this->getCachePrefix() . 'import:' . $importJobId . ':*';
        
        // Note: This is a simplified implementation. In production, you might want to
        // use Redis SCAN command or maintain a list of cache keys for efficient cleanup
        Log::info("Clearing validation cache for import job: {$importJobId}");
    }

    /**
     * Clear all validation cache
     *
     * @return void
     */
    public function clearAllValidationCache(): void
    {
        // This is a simplified implementation. In production, you might want to
        // use Redis SCAN command or maintain a list of cache keys for efficient cleanup
        Log::info("Clearing all validation cache");
    }

    /**
     * Get validation cache statistics
     *
     * @return array
     */
    public function getValidationCacheStats(): array
    {
        // This would require Redis-specific commands to get accurate statistics
        // For now, return basic information
        return [
            'cache_store' => $this->getCacheConfig()['store'],
            'cache_prefix' => $this->getCachePrefix(),
            'cache_ttl' => $this->getCacheTTL(),
            'last_checked' => now()->toISOString(),
        ];
    }

    /**
     * Generate cache key for validation result
     *
     * @param array $data
     * @return string
     */
    private function generateValidationCacheKey(array $data): string
    {
        // Create a consistent hash of the validation-relevant data
        $relevantData = [
            'employee_number' => $data['employee_number'] ?? '',
            'email' => strtolower($data['email'] ?? ''),
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? '',
            'salary' => $data['salary'] ?? '',
            'currency' => strtoupper($data['currency'] ?? ''),
            'country_code' => strtoupper($data['country_code'] ?? ''),
            'start_date' => $data['start_date'] ?? '',
        ];

        $hash = md5(json_encode($relevantData));
        return $this->getCachePrefix() . 'row:' . $hash;
    }

    /**
     * Generate cache key for duplicate check
     *
     * @param string $employeeNumber
     * @param string $email
     * @return string
     */
    private function generateDuplicateCacheKey(string $employeeNumber, string $email): string
    {
        $hash = md5($employeeNumber . '|' . strtolower($email));
        return $this->getCachePrefix() . 'duplicate:' . $hash;
    }

    /**
     * Warm up cache with common validation results
     *
     * @return void
     */
    public function warmUpCache(): void
    {
        Log::info("Warming up validation cache...");

        // Cache supported currencies
        $supportedCurrencies = config('import.validation.supported.currencies', []);
        foreach ($supportedCurrencies as $currency) {
            $this->cacheCurrencyValidation($currency, true);
        }

        // Cache supported countries
        $supportedCountries = config('import.validation.supported.countries', []);
        foreach ($supportedCountries as $country) {
            $this->cacheCountryValidation($country, true);
        }

        Log::info("Validation cache warm-up completed");
    }
}