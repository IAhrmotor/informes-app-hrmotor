<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

class SimpleXlsxWorkbookWriter
{
    /**
     * @param array<int, array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>}> $sheets
     */
    public function write(array $sheets): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('El servidor no tiene disponible la extension ZIP necesaria para generar archivos XLSX.');
        }

        $path = tempnam(sys_get_temp_dir(), 'hrmotor-commissions-');

        if ($path === false) {
            throw new RuntimeException('No se pudo crear el archivo temporal para la exportacion.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($path);

            throw new RuntimeException('No se pudo crear el archivo XLSX.');
        }

        try {
            $safeSheets = $this->safeSheets($sheets);
            $zip->addFromString('[Content_Types].xml', $this->contentTypes(count($safeSheets)));
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbook($safeSheets));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships(count($safeSheets)));
            $zip->addFromString('xl/styles.xml', $this->styles());

            foreach ($safeSheets as $index => $sheet) {
                $zip->addFromString('xl/worksheets/sheet'.($index + 1).'.xml', $this->sheet($sheet));
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($path);

            throw $exception;
        }

        $zip->close();

        return $path;
    }

    /** @param array<int, array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>}> $sheets */
    private function safeSheets(array $sheets): array
    {
        $names = [];

        return array_values(array_map(function (array $sheet) use (&$names): array {
            $baseName = trim(preg_replace('/[\\\\\\/:*?\[\]]/', ' ', (string) ($sheet['name'] ?? 'Hoja')) ?? 'Hoja');
            $baseName = mb_substr($baseName !== '' ? $baseName : 'Hoja', 0, 31);
            $name = $baseName;
            $suffix = 2;

            while (in_array($name, $names, true)) {
                $suffixLabel = ' '.$suffix;
                $name = mb_substr($baseName, 0, 31 - mb_strlen($suffixLabel)).$suffixLabel;
                $suffix++;
            }

            $names[] = $name;

            return [
                'name' => $name,
                'headers' => array_values($sheet['headers'] ?? []),
                'rows' => array_values($sheet['rows'] ?? []),
            ];
        }, $sheets));
    }

    private function contentTypes(int $sheetCount): string
    {
        $overrides = '';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /** @param array<int, array{name: string}> $sheets */
    private function workbook(array $sheets): string
    {
        $sheetXml = '';

        foreach ($sheets as $index => $sheet) {
            $sheetXml .= '<sheet name="'.$this->escape($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetXml.'</sheets>'
            .'</workbook>';
    }

    private function workbookRelationships(int $sheetCount): string
    {
        $relationships = '';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationships .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationships
            .'<Relationship Id="rId'.($sheetCount + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** @param array{name: string, headers: array<int, string>, rows: array<int, array<int, string|int|float|null>>} $sheet */
    private function sheet(array $sheet): string
    {
        $rows = [$this->row(1, $sheet['headers'], true)];

        foreach ($sheet['rows'] as $index => $values) {
            $rows[] = $this->row($index + 2, $values, false);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>'
            .'<cols><col min="1" max="1" width="30" customWidth="1"/><col min="2" max="2" width="18" customWidth="1"/></cols>'
            .'<sheetData>'.implode('', $rows).'</sheetData>'
            .'</worksheet>';
    }

    /** @param array<int, string|int|float|null> $values */
    private function row(int $rowNumber, array $values, bool $isHeader): string
    {
        $cells = '';

        foreach (array_values($values) as $index => $value) {
            $reference = $this->columnName($index + 1).$rowNumber;

            if (! $isHeader && is_numeric($value)) {
                $cells .= '<c r="'.$reference.'" s="2"><v>'.number_format((float) $value, 2, '.', '').'</v></c>';
                continue;
            }

            $style = $isHeader ? ' s="1"' : '';
            $cells .= '<c r="'.$reference.'"'.$style.' t="inlineStr"><is><t xml:space="preserve">'.$this->escape((string) ($value ?? '')).'</t></is></c>';
        }

        return '<row r="'.$rowNumber.'">'.$cells.'</row>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            .'</styleSheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';

        while ($index > 0) {
            $index--;
            $name = chr(65 + ($index % 26)).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
