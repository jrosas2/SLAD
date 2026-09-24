<?php

namespace App\Services\Imports;

use Generator;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;

class HistoricalExcelReader
{
    private const CHUNK_SIZE = 250;

    public function __construct(private HistoricalHeaderMapper $headerMapper) {}

    /**
     * @return array{sheet: string, header_row: int, column_map: array<int, string>, total_rows: int, last_column: string, headers: list<string>}
     */
    public function inspect(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('El archivo Excel no es legible.');
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $worksheets = $reader->listWorksheetInfo($path);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('El archivo no corresponde a un Excel legible.', previous: $exception);
        }

        if ($worksheets === []) {
            throw new InvalidArgumentException('El archivo Excel no contiene hojas.');
        }

        usort($worksheets, fn (array $left, array $right): int => $this->sheetPriority((string) $left['worksheetName']) <=> $this->sheetPriority((string) $right['worksheetName']));

        foreach ($worksheets as $worksheet) {
            $sheetName = (string) $worksheet['worksheetName'];
            $lastColumn = (string) $worksheet['lastColumnLetter'];
            $totalRows = (int) $worksheet['totalRows'];
            $headerData = $this->readRange($reader, $path, $sheetName, $lastColumn, 1, min(15, max(1, $totalRows)));

            foreach ($headerData as $offset => $headers) {
                $columnMap = $this->headerMapper->map($headers);

                if ($this->headerMapper->hasMinimumStructure($columnMap)) {
                    return [
                        'sheet' => $sheetName,
                        'header_row' => $offset + 1,
                        'column_map' => $columnMap,
                        'total_rows' => $totalRows,
                        'last_column' => $lastColumn,
                        'headers' => array_values(array_map(fn (mixed $value): string => trim((string) $value), $headers)),
                    ];
                }
            }
        }

        throw new InvalidArgumentException('No se encontró una hoja con los encabezados mínimos de B.D. GENERAL.');
    }

    /**
     * @param  array{sheet: string, header_row: int, column_map: array<int, string>, total_rows: int, last_column: string, headers: list<string>}  $metadata
     * @return Generator<int, array{fila: int, valores: array<string, mixed>}>
     */
    public function rows(string $path, array $metadata): Generator
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $start = $metadata['header_row'] + 1;
        $consecutiveEmptyRows = 0;

        while ($start <= $metadata['total_rows']) {
            $end = min($metadata['total_rows'], $start + self::CHUNK_SIZE - 1);
            $rows = $this->readRange($reader, $path, $metadata['sheet'], $metadata['last_column'], $start, $end);

            foreach ($rows as $offset => $rawRow) {
                $values = [];

                foreach ($metadata['column_map'] as $columnIndex => $field) {
                    $values[$field] = $rawRow[$columnIndex] ?? null;
                }

                if (collect($values)->every(fn (mixed $value): bool => blank($value))) {
                    $consecutiveEmptyRows++;

                    if ($consecutiveEmptyRows >= self::CHUNK_SIZE * 2) {
                        return;
                    }

                    continue;
                }

                $consecutiveEmptyRows = 0;
                yield ['fila' => $start + $offset, 'valores' => $values];
            }

            $start = $end + 1;
        }
    }

    /** @return list<array<int, mixed>> */
    private function readRange(IReader $reader, string $path, string $sheetName, string $lastColumn, int $startRow, int $endRow): array
    {
        $reader->setLoadSheetsOnly($sheetName);
        $reader->setReadFilter(new HistoricalChunkReadFilter($startRow, $endRow));
        $spreadsheet = $reader->load($path);
        $worksheet = $spreadsheet->getSheetByName($sheetName);

        if ($worksheet === null) {
            $spreadsheet->disconnectWorksheets();

            throw new InvalidArgumentException("No se pudo leer la hoja {$sheetName}.");
        }

        $rows = $worksheet->rangeToArray("A{$startRow}:{$lastColumn}{$endRow}", null, true, false, false);
        $spreadsheet->disconnectWorksheets();

        return array_values($rows);
    }

    private function sheetPriority(string $sheetName): int
    {
        return $this->headerMapper->normalize($sheetName) === 'B D GENERAL' ? 0 : 1;
    }
}
