<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Generator;
use Illuminate\Support\Facades\Log;

class ExcelStreamingService
{
    /**
     * Memory-efficient Excel reader with chunked processing
     */
    private int $chunkSize;
    private array $headers = [];

    public function __construct(int $chunkSize = 100)
    {
        $this->chunkSize = $chunkSize;
    }

    /**
     * Read Excel file in streaming chunks starting from a specific row
     *
     * @param string $filePath
     * @param int $startRow
     * @return Generator
     */
    public function readExcelInChunks(string $filePath, int $startRow = 1): Generator
    {
        try {
            $reader = IOFactory::createReaderForFile($filePath);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            
            // Load headers first
            $this->loadHeaders($reader, $filePath);
            
            // Calculate total rows for chunking
            $totalRows = $this->getTotalRows($reader, $filePath);
            
            // Process in chunks starting from startRow
            $currentChunkStart = max(2, $startRow + 1); // +1 because row 1 is headers
            
            while ($currentChunkStart <= $totalRows) {
                $chunkEnd = min($currentChunkStart + $this->chunkSize - 1, $totalRows);
                
                // Create filter for this chunk
                $filter = new ChunkReadFilter($currentChunkStart, $chunkEnd);
                $reader->setReadFilter($filter);
                
                // Load only this chunk
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getActiveSheet();
                
                // Yield rows from this chunk
                yield from $this->processChunk($worksheet, $currentChunkStart, $chunkEnd);
                
                // Clean up memory
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
                
                $currentChunkStart = $chunkEnd + 1;
            }
            
        } catch (\Exception $e) {
            Log::error("Excel streaming failed: " . $e->getMessage());
            throw $e;
        }
    }
}   
 /**
     * Load headers from Excel file
     */
    private function loadHeaders($reader, string $filePath): void
    {
        $filter = new ChunkReadFilter(1, 1); // Only read header row
        $reader->setReadFilter($filter);
        
        $spreadsheet = $reader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $highestColumn = $worksheet->getHighestColumn();
        
        $this->headers = [];
        for ($col = 'A'; $col <= $highestColumn; $col++) {
            $this->headers[] = $worksheet->getCell($col . '1')->getCalculatedValue();
        }
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * Get total rows in Excel file
     */
    private function getTotalRows($reader, string $filePath): int
    {
        $tempReader = clone $reader;
        $tempReader->setReadFilter(null); // Remove any filters
        
        $spreadsheet = $tempReader->load($filePath);
        $worksheet = $spreadsheet->getActiveSheet();
        $totalRows = $worksheet->getHighestRow();
        
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        
        return $totalRows;
    }

    /**
     * Process a chunk of rows
     */
    private function processChunk($worksheet, int $startRow, int $endRow): Generator
    {
        $highestColumn = $worksheet->getHighestColumn();
        
        for ($row = $startRow; $row <= $endRow; $row++) {
            $rowData = [];
            
            for ($col = 'A'; $col <= $highestColumn; $col++) {
                $cellValue = $worksheet->getCell($col . $row)->getCalculatedValue();
                $rowData[] = $cellValue;
            }
            
            // Combine with headers to create associative array
            $associativeData = array_combine($this->headers, $rowData);
            
            if ($associativeData !== false) {
                yield $associativeData;
            }
        }
    }

    /**
     * Set chunk size for processing
     */
    public function setChunkSize(int $size): void
    {
        $this->chunkSize = max(1, $size);
    }

    /**
     * Get current chunk size
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }
}

/**
 * Custom read filter for chunked Excel processing
 */
class ChunkReadFilter implements IReadFilter
{
    private int $startRow;
    private int $endRow;

    public function __construct(int $startRow, int $endRow)
    {
        $this->startRow = $startRow;
        $this->endRow = $endRow;
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}