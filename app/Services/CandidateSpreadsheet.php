<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded, read-only XLSX/CSV reader. Never evaluates workbook formulas or external links. */
class CandidateSpreadsheet
{
    public static function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function xml(ZipArchive $zip, string $path): \SimpleXMLElement
    {
        $stat = $zip->statName($path);
        if (! $stat || $stat['size'] > 20000000) {
            self::fail('Invalid or oversized worksheet.');
        }
        $text = $zip->getFromName($path);
        if (stripos($text, '<!DOCTYPE') !== false || stripos($text, '<!ENTITY') !== false) {
            self::fail('Unsupported XML content.');
        }
        $xml = @simplexml_load_string($text, 'SimpleXMLElement', LIBXML_NONET);
        if (! $xml) {
            self::fail('The workbook contains invalid XML.');
        }

        return $xml;
    }

    public function read(string $path, string $extension, ?string $sheet = null): array
    {
        if ($extension === 'csv') {
            $handle = fopen($path, 'r');
            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
                if (count($rows) > 5005 || count($row) > 200) {
                    self::fail('Use at most 5,000 rows and 200 columns.');
                }
            }
            fclose($handle);

            return $this->table($rows, ['CSV'], 'CSV');
        }
        if ($extension !== 'xlsx') {
            self::fail('Upload an XLSX or UTF-8 CSV file.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            self::fail('Cannot open this XLSX file.');
        }
        try {
            $book = $this->xml($zip, 'xl/workbook.xml');
            $rels = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $targets = [];
            foreach ($rels->Relationship as $rel) {
                if ((string) $rel['TargetMode'] === 'External') {
                    continue;
                }
                $target = (string) $rel['Target'];
                if (str_contains($target, '..')) {
                    continue;
                }
                $targets[(string) $rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
            }
            $sheets = [];
            $selected = null;
            foreach ($book->sheets->sheet as $item) {
                $name = (string) $item['name'];
                $sheets[] = $name;
                if (($sheet !== null && $name === $sheet) || ($sheet === null && $selected === null && (string) $item['state'] !== 'hidden')) {
                    $selected = [$name, $targets[(string) $item->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id']] ?? ''];
                }
            }
            if (! $selected) {
                self::fail('Choose an existing worksheet.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $si) {
                    $strings[] = implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
                }
            }
            $rows = [];
            foreach ($this->xml($zip, $selected[1])->sheetData->row as $r) {
                $index = (int) $r['r'] - 1;
                if ($index > 5004) {
                    self::fail('Use at most 5,000 candidate rows.');
                }
                $row = [];
                foreach ($r->c as $c) {
                    preg_match('/^([A-Z]+)/', (string) $c['r'], $m);
                    $col = 0;
                    foreach (str_split($m[1] ?? 'A') as $letter) {
                        $col = $col * 26 + ord($letter) - 64;
                    }
                    if ($col > 200) {
                        self::fail('Use at most 200 columns.');
                    }
                    if (isset($c->f)) {
                        self::fail('Remove formulas or paste their values before importing.');
                    }
                    $value = (string) $c->v;
                    if ((string) $c['t'] === 's') {
                        $value = $strings[(int) $value] ?? '';
                    }
                    if ((string) $c['t'] === 'inlineStr') {
                        $value = implode('', array_map('strval', $c->xpath('.//*[local-name()="t"]')));
                    }
                    $row[$col - 1] = $value;
                }
                $rows[$index] = $row;
            }

            $table = $this->table($rows, $sheets, $selected[0]);
            if (in_array(strtolower((string) $book->workbookPr['date1904']), ['1', 'true'], true)) {
                foreach ($table['records'] as &$record) {
                    $record['excel_date_1904'] = true;
                }
                unset($record);
            }

            return $table;
        } finally {
            $zip->close();
        }
    }

    private function table(array $rows, array $sheets, string $sheet): array
    {
        $headerIndex = null;
        foreach ($rows as $i => $row) {
            if ($i > 20) {
                break;
            }
            foreach ($row as $cell) {
                if (in_array(self::key((string) $cell), ['aspirantname', 'studentname', 'firstname', 'fullname', 'candidatename', 'candidatefullname', 'studentfullname', 'nameofcandidate', 'nameofstudent', 'givenname', 'name'])) {
                    $headerIndex = $i;
                    break 2;
                }
            }
        }
        if ($headerIndex === null) {
            self::fail('No candidate headers found in the first 21 rows. Include first_name or Aspirant Name.');
        }
        $width = max(array_map(fn ($r) => $r ? max(array_keys($r)) + 1 : 0, $rows));
        $headers = [];
        $seen = [];
        for ($i = 0; $i < $width; $i++) {
            $label = trim(ltrim((string) ($rows[$headerIndex][$i] ?? ''), "\xEF\xBB\xBF"));
            $label = $label ?: 'Column '.($i + 1);
            $seen[$label] = ($seen[$label] ?? 0) + 1;
            $headers[] = $label.($seen[$label] > 1 ? ' ['.$seen[$label].']' : '');
        }
        $records = [];
        foreach ($rows as $i => $row) {
            if ($i <= $headerIndex || ! array_filter($row, fn ($v) => trim((string) $v) !== '')) {
                continue;
            }
            $values = [];
            foreach ($headers as $j => $label) {
                $values[$label] = (string) ($row[$j] ?? '');
            }
            $records[] = ['row' => $i + 1, 'values' => $values];
        }
        if (! $records) {
            self::fail('The selected worksheet has no candidate records.');
        }
        if (count($records) > 5000) {
            self::fail('Use at most 5,000 candidates.');
        }

        return compact('headers', 'records', 'sheets', 'sheet');
    }

    public static function key(string $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($value));
    }
}
