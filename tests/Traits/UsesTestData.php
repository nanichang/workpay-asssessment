<?php

namespace Tests\Traits;

trait UsesTestData
{
    /**
     * Get the path to the good employees CSV file
     */
    protected function getGoodEmployeesCsvPath(): string
    {
        return base_path('good-employees.csv');
    }

    /**
     * Get the path to the bad employees CSV file
     */
    protected function getBadEmployeesCsvPath(): string
    {
        return base_path('bad-employees.csv');
    }

    /**
     * Get the path to the Excel assessment data file
     */
    protected function getAssessmentDataExcelPath(): string
    {
        return base_path('Assement Data Set.xlsx');
    }

    /**
     * Check if good employees CSV file exists
     */
    protected function hasGoodEmployeesCsv(): bool
    {
        return file_exists($this->getGoodEmployeesCsvPath());
    }

    /**
     * Check if bad employees CSV file exists
     */
    protected function hasBadEmployeesCsv(): bool
    {
        return file_exists($this->getBadEmployeesCsvPath());
    }

    /**
     * Check if assessment data Excel file exists
     */
    protected function hasAssessmentDataExcel(): bool
    {
        return file_exists($this->getAssessmentDataExcelPath());
    }

    /**
     * Count rows in a CSV file (excluding header)
     */
    protected function countCsvRows(string $filePath): int
    {
        if (!file_exists($filePath)) {
            return 0;
        }

        $handle = fopen($filePath, 'r');
        $rowCount = 0;
        
        // Skip header row
        fgetcsv($handle);
        
        while (fgetcsv($handle) !== false) {
            $rowCount++;
        }
        
        fclose($handle);
        return $rowCount;
    }

    /**
     * Get sample of CSV data for inspection
     */
    protected function getCsvSample(string $filePath, int $maxRows = 5): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $handle = fopen($filePath, 'r');
        $data = [];
        
        // Get header
        $header = fgetcsv($handle);
        if ($header) {
            $data['header'] = $header;
            $data['rows'] = [];
            
            $rowCount = 0;
            while (($row = fgetcsv($handle)) !== false && $rowCount < $maxRows) {
                $data['rows'][] = array_combine($header, $row);
                $rowCount++;
            }
        }
        
        fclose($handle);
        return $data;
    }

    /**
     * Validate that test files have expected structure
     */
    protected function validateTestFileStructure(): array
    {
        $results = [];
        
        // Expected CSV headers for employee data
        $expectedHeaders = [
            'employee_number',
            'first_name', 
            'last_name',
            'email',
            'department',
            'salary',
            'currency',
            'country_code',
            'start_date'
        ];

        // Check good employees CSV
        if ($this->hasGoodEmployeesCsv()) {
            $sample = $this->getCsvSample($this->getGoodEmployeesCsvPath(), 1);
            $results['good_csv'] = [
                'exists' => true,
                'row_count' => $this->countCsvRows($this->getGoodEmployeesCsvPath()),
                'has_expected_headers' => !empty($sample['header']) && 
                    count(array_intersect($expectedHeaders, $sample['header'])) === count($expectedHeaders),
                'headers' => $sample['header'] ?? [],
                'sample_row' => $sample['rows'][0] ?? null
            ];
        } else {
            $results['good_csv'] = ['exists' => false];
        }

        // Check bad employees CSV
        if ($this->hasBadEmployeesCsv()) {
            $sample = $this->getCsvSample($this->getBadEmployeesCsvPath(), 1);
            $results['bad_csv'] = [
                'exists' => true,
                'row_count' => $this->countCsvRows($this->getBadEmployeesCsvPath()),
                'has_expected_headers' => !empty($sample['header']) && 
                    count(array_intersect($expectedHeaders, $sample['header'])) === count($expectedHeaders),
                'headers' => $sample['header'] ?? [],
                'sample_row' => $sample['rows'][0] ?? null
            ];
        } else {
            $results['bad_csv'] = ['exists' => false];
        }

        // Check Excel file
        $results['excel'] = [
            'exists' => $this->hasAssessmentDataExcel(),
            'file_size' => $this->hasAssessmentDataExcel() ? 
                filesize($this->getAssessmentDataExcelPath()) : 0
        ];

        return $results;
    }
}