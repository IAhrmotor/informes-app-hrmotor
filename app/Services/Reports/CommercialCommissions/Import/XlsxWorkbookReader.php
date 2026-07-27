<?php

namespace App\Services\Reports\CommercialCommissions\Import;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

class XlsxWorkbookReader
{
    private const MAIN_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const PACKAGE_RELATIONSHIP_NAMESPACE = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Reads the cell values required for a small administrative import without
     * adding a spreadsheet dependency to the application runtime.
     *
     * @return array<int, array{name: string, rows: array<int, array<int, mixed>>}>
     */
    public function sheets(string $path): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new CommercialFinancingPenaltyImportException('El servidor no tiene disponible la extension ZIP necesaria para leer archivos XLSX.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new CommercialFinancingPenaltyImportException('No se pudo abrir el archivo XLSX.');
        }

        try {
            $sharedStrings = $this->sharedStrings($zip);
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $relationships = $this->relationships($zip);
            $workbook->registerXPathNamespace('x', self::MAIN_NAMESPACE);

            $sheets = [];

            foreach ($workbook->xpath('//x:sheets/x:sheet') ?: [] as $sheet) {
                $attributes = $sheet->attributes();
                $relationshipAttributes = $sheet->attributes(self::RELATIONSHIP_NAMESPACE);
                $relationshipId = (string) ($relationshipAttributes['id'] ?? '');
                $target = $relationships[$relationshipId] ?? null;

                if ($target === null) {
                    continue;
                }

                $sheetXml = $this->xml($zip, 'xl/'.ltrim($target, '/'));
                $sheets[] = [
                    'name' => (string) ($attributes['name'] ?? 'Hoja'),
                    'rows' => $this->rows($sheetXml, $sharedStrings),
                ];
            }

            return $sheets;
        } catch (CommercialFinancingPenaltyImportException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new CommercialFinancingPenaltyImportException('El archivo XLSX no tiene un formato valido: '.$exception->getMessage(), previous: $exception);
        } finally {
            $zip->close();
        }
    }

    /** @return array<int, string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $xml = $this->xml($zip, 'xl/sharedStrings.xml');
        $xml->registerXPathNamespace('x', self::MAIN_NAMESPACE);

        return array_map(
            fn (SimpleXMLElement $node): string => $this->nodeText($node),
            $xml->xpath('//x:si') ?: []
        );
    }

    /** @return array<string, string> */
    private function relationships(ZipArchive $zip): array
    {
        $xml = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
        $xml->registerXPathNamespace('r', self::PACKAGE_RELATIONSHIP_NAMESPACE);
        $relationships = [];

        foreach ($xml->xpath('//r:Relationship') ?: [] as $relationship) {
            $attributes = $relationship->attributes();
            $relationships[(string) $attributes['Id']] = (string) $attributes['Target'];
        }

        return $relationships;
    }

    /** @return array<int, array<int, mixed>> */
    private function rows(SimpleXMLElement $xml, array $sharedStrings): array
    {
        $xml->registerXPathNamespace('x', self::MAIN_NAMESPACE);
        $rows = [];

        foreach ($xml->xpath('//x:sheetData/x:row') ?: [] as $row) {
            $row->registerXPathNamespace('x', self::MAIN_NAMESPACE);
            $values = [];
            $rowAttributes = $row->attributes();

            foreach ($row->xpath('./x:c') ?: [] as $cell) {
                $cell->registerXPathNamespace('x', self::MAIN_NAMESPACE);
                $attributes = $cell->attributes();
                $column = $this->columnIndex((string) ($attributes['r'] ?? 'A1'));
                $type = (string) ($attributes['t'] ?? '');
                $valueNode = $cell->xpath('./x:v')[0] ?? null;
                $rawValue = $valueNode === null ? '' : (string) $valueNode;

                $values[$column] = match ($type) {
                    's' => $sharedStrings[(int) $rawValue] ?? '',
                    'inlineStr' => $this->nodeText($cell),
                    'b' => $rawValue === '1',
                    default => $rawValue,
                };
            }

            if ($values !== []) {
                ksort($values);
                $values['__row_number'] = (int) ($rowAttributes['r'] ?? 0);
                $rows[] = $values;
            }
        }

        return $rows;
    }

    private function xml(ZipArchive $zip, string $entry): SimpleXMLElement
    {
        $content = $zip->getFromName($entry);

        if (! is_string($content)) {
            throw new RuntimeException("Falta {$entry} en el archivo XLSX.");
        }

        $xml = simplexml_load_string($content);

        if (! $xml instanceof SimpleXMLElement) {
            throw new RuntimeException("No se pudo leer {$entry}.");
        }

        return $xml;
    }

    private function nodeText(SimpleXMLElement $node): string
    {
        $node->registerXPathNamespace('x', self::MAIN_NAMESPACE);

        return implode('', array_map(
            fn (SimpleXMLElement $text): string => (string) $text,
            $node->xpath('.//x:t') ?: []
        ));
    }

    private function columnIndex(string $cellReference): int
    {
        preg_match('/^[A-Z]+/i', $cellReference, $match);
        $letters = strtoupper($match[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }
}
