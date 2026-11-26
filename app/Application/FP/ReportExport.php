<?php

declare(strict_types=1);

namespace App\Application\FP;

final class ReportExport
{
    public const MAX_ROWS = 5000;

    public const MAX_BYTES = 8388608;

    public const HEADERS = ['Membership ID', 'Nama FP', 'NIP', 'Team', 'Sumber Rekrutmen', 'Status', 'Tanggal referensi pendaftaran', 'Tanggal keluar', 'Durasi (hari)'];

    public static function csvCell(mixed $value): string
    {
        $v = (string) $value;

        return preg_match('/^[\s\x00-\x20\x{FEFF}]*[=+\-@]|^[\t\r\n]/u', $v) ? "'".$v : $v;
    }

    public static function render(array $rows, string $format, ?array $headers = null): string
    {
        if (count($rows) > self::MAX_ROWS) {
            self::reject();
        }
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            throw new \InvalidArgumentException('Unknown export format');
        }
        $headers ??= self::HEADERS;
        if (count($headers) < 1 || count($headers) > 26) { throw new \InvalidArgumentException('Invalid export headers'); }
        $data = [$headers, ...$rows];
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                foreach ($data as $row) {
                    fputcsv($stream, array_map(self::csvCell(...), $row), ',', '"', '', "\r\n");
                    self::limit(ftell($stream));
                }
                rewind($stream);

                return stream_get_contents($stream);
            } finally {
                fclose($stream);
            }
        }
        $xml = new \XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'worksheet', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->startElement('pane');
        $xml->writeAttribute('ySplit', '1');
        $xml->writeAttribute('topLeftCell', 'A2');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('sheetData');
        $size = 0;
        foreach ($data as $index => $row) {
            $xml->startElement('row');
            $xml->writeAttribute('r', (string) ($index + 1));
            foreach (array_values($row) as $column => $value) {
                $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
                $size += strlen($value) + 120;
                self::limit($size);
                $xml->startElement('c');
                $xml->writeAttribute('r', chr(65 + $column).($index + 1));
                $xml->writeAttribute('t', 'inlineStr');
                $xml->startElement('is');
                $xml->startElement('t');
                $xml->writeAttribute('xml:space', 'preserve');
                $xml->text($value);
                $xml->endElement();
                $xml->endElement();
                $xml->endElement();
            }$xml->endElement();
        }
        $xml->endElement();
        $xml->startElement('autoFilter');
        $xml->writeAttribute('ref', 'A1:'.chr(64 + count($headers)).count($data));
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $sheet = $xml->outputMemory();
        self::limit(strlen($sheet));
        $path = tempnam(sys_get_temp_dir(), 'fp-report-');
        if ($path === false) {
            throw new \RuntimeException('Export temporary file unavailable');
        }
        $zip = new \ZipArchive;
        $opened = false;
        try {
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('XLSX writer unavailable');
            }$opened = true;
            $parts = [
                '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Financial Planner" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
                'xl/worksheets/sheet1.xml' => $sheet,
            ];
            foreach ($parts as $name => $content) {
                if (! $zip->addFromString($name, ($name === 'xl/worksheets/sheet1.xml' ? $content : '<?xml version="1.0" encoding="UTF-8"?>'.$content))) {
                    throw new \RuntimeException('XLSX package failed');
                }
            }
            if (! $zip->close()) {
                throw new \RuntimeException('XLSX finalization failed');
            }
            $opened = false;
            $bytes = file_get_contents($path);
            self::limit(strlen($bytes));

            return $bytes;
        } finally {
            if ($opened) {
                $zip->close();
            }
            unlink($path);
        }
    }

    private static function limit(int $bytes): void
    {
        if ($bytes > self::MAX_BYTES) {
            self::reject();
        }
    }

    private static function reject(): never
    {
        throw \Illuminate\Validation\ValidationException::withMessages(['export' => 'Maksimal 5.000 baris / 8 MiB. Persempit filter laporan.']);
    }
}
