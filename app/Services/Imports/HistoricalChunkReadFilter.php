<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class HistoricalChunkReadFilter implements IReadFilter
{
    public function __construct(private int $startRow, private int $endRow) {}

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
