<?php

namespace App\Services\Reports\Stock;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use SplFileObject;
use ZipArchive;

class CapacityFileReader
{
    public function read(string $path, string $delimiter = ',', ?string $extension = null): array
    {
        return match (mb_strtolower($extension ?: pathinfo($path, PATHINFO_EXTENSION))) {
            'csv', 'txt' => $this->readCsv($path, $delimiter),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Formato no soportado. Utiliza CSV o XLSX.'),
        };
    }

    private function readCsv(string $path, string $delimiter): array
    {
        $file = new SplFileObject($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
        $file->setCsvControl($delimiter);
        $rows = [];

        foreach ($file as $row) {
            if (is_array($row) && $row !== [null]) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function readXlsx(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión PHP zip es necesaria para leer XLSX.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $sheetPath = $this->firstSheetPath($zip);
            $xml = $zip->getFromName($sheetPath);

            if ($xml === false) {
                throw new RuntimeException("No se encontró la hoja {$sheetPath}.");
            }

            $document = new DOMDocument;
            $document->loadXML($xml);
            $xpath = new DOMXPath($document);
            $rows = [];

            foreach ($xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowNode) {
                $row = [];

                foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cell) {
                    $reference = $cell->attributes?->getNamedItem('r')?->nodeValue ?? '';
                    $column = $this->columnIndex($reference);
                    $type = $cell->attributes?->getNamedItem('t')?->nodeValue;
                    $valueNode = $xpath->query('./*[local-name()="v"]', $cell)->item(0);
                    $value = $valueNode?->textContent;

                    if ($type === 's' && is_numeric($value)) {
                        $value = $sharedStrings[(int) $value] ?? null;
                    } elseif ($type === 'inlineStr') {
                        $value = $xpath->query('.//*[local-name()="t"]', $cell)->item(0)?->textContent;
                    }

                    $row[$column] = $value;
                }

                if ($row !== []) {
                    $max = max(array_keys($row));
                    $rows[] = array_map(fn (int $index) => $row[$index] ?? null, range(0, $max));
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $document = new DOMDocument;
        $document->loadXML($xml);
        $xpath = new DOMXPath($document);
        $strings = [];

        foreach ($xpath->query('//*[local-name()="si"]') as $item) {
            $value = '';
            foreach ($xpath->query('.//*[local-name()="t"]', $item) as $text) {
                $value .= $text->textContent;
            }
            $strings[] = $value;
        }

        return $strings;
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relationshipsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = new DOMDocument;
        $workbook->loadXML($workbookXml);
        $xpath = new DOMXPath($workbook);
        $sheet = $xpath->query('//*[local-name()="sheet"]')->item(0);
        $relationshipId = $sheet?->attributes?->getNamedItemNS(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
            'id',
        )?->nodeValue;

        $relationships = new DOMDocument;
        $relationships->loadXML($relationshipsXml);
        $relationshipXPath = new DOMXPath($relationships);

        foreach ($relationshipXPath->query('//*[local-name()="Relationship"]') as $relationship) {
            if ($relationship->attributes?->getNamedItem('Id')?->nodeValue === $relationshipId) {
                $target = $relationship->attributes?->getNamedItem('Target')?->nodeValue;

                return str_starts_with((string) $target, '/')
                    ? ltrim((string) $target, '/')
                    : 'xl/'.ltrim((string) $target, '/');
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function columnIndex(string $reference): int
    {
        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }
}
