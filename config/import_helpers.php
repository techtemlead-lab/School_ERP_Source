<?php
// config/import_helpers.php - Excel(.xlsx)/CSV/TXT বাল্ক ইমপোর্টের জন্য শেয়ার্ড হেল্পার ফাংশন।
// Student Import, Employee Import ইত্যাদি সব বাল্ক-ইমপোর্ট ফিচার এই একই ফাইল ব্যবহার করে
// — কোনো composer library ছাড়াই .xlsx পড়া হয় (Excel ফাইল আসলে একটা ZIP + XML,
// ZipArchive + SimpleXML দিয়ে হালকা-পাতলা নিজস্ব রিডার)।

if (!function_exists('import_detect_delimiter')) {

function import_detect_delimiter(string $firstLine): string
{
    $counts = [',' => substr_count($firstLine, ','), "\t" => substr_count($firstLine, "\t"), ';' => substr_count($firstLine, ';')];
    arsort($counts);
    return array_key_first($counts) ?: ',';
}

function import_parse_csv_txt(string $path): array
{
    $content = file_get_contents($path);
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content); // UTF-8 BOM সরানো
    $lines = preg_split("/\r\n|\n|\r/", $content);
    $lines = array_filter($lines, fn($l) => trim($l) !== '');
    if (!$lines) return [];
    $delim = import_detect_delimiter(reset($lines));
    $rows = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line, $delim);
    }
    return $rows;
}

function import_xlsx_col_to_index(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letters = $m[1] ?? 'A';
    $col = 0;
    foreach (str_split($letters) as $ch) {
        $col = $col * 26 + (ord($ch) - 64);
    }
    return $col - 1;
}

function import_parse_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new Exception('ফাইলটি বৈধ .xlsx ফাইল না (ZIP হিসেবে খোলা যায়নি)।');
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ssDom = simplexml_load_string($ssXml);
        if ($ssDom !== false) {
            foreach ($ssDom->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $r) { $text .= (string) $r->t; }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false) {
        throw new Exception('এই .xlsx ফাইলে কোনো শীট খুঁজে পাওয়া যায়নি।');
    }

    $dom = simplexml_load_string($sheetXml);
    if ($dom === false) {
        throw new Exception('.xlsx ফাইলের শীট পড়া যায়নি (corrupted হতে পারে)।');
    }

    $rows = [];
    foreach ($dom->sheetData->row as $row) {
        $rowData = [];
        $maxCol = -1;
        $cellMap = [];
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $idx = $ref ? import_xlsx_col_to_index($ref) : (count($cellMap));
            $type = (string) $c['t'];
            $value = '';
            if ($type === 's') {
                $si = (int) $c->v;
                $value = $sharedStrings[$si] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) $c->is->t;
            } else {
                $value = (string) $c->v;
            }
            $cellMap[$idx] = $value;
            $maxCol = max($maxCol, $idx);
        }
        for ($i = 0; $i <= $maxCol; $i++) {
            $rowData[] = $cellMap[$i] ?? '';
        }
        $rows[] = $rowData;
    }
    return $rows;
}

function import_normalize_header(string $h): string
{
    $h = mb_strtolower(trim($h));
    $h = preg_replace('/\s+/', ' ', $h);
    return $h;
}

function import_map_headers(array $headerRow, array $aliases): array
{
    $map = []; // column index => field key
    foreach ($headerRow as $idx => $raw) {
        $norm = import_normalize_header((string) $raw);
        foreach ($aliases as $field => $variants) {
            if (in_array($norm, $variants, true)) {
                $map[$idx] = $field;
                break;
            }
        }
    }
    return $map;
}

// ফাইল এক্সটেনশন অনুযায়ী সঠিক পার্সার কল করা — সব import ফাইলের জন্য একই এন্ট্রি পয়েন্ট
function import_parse_file(string $tmpPath, string $ext): array
{
    return $ext === 'xlsx' ? import_parse_xlsx($tmpPath) : import_parse_csv_txt($tmpPath);
}

// টেম্প ফোল্ডারে ১ ঘন্টার বেশি পুরনো import_*.json ফাইল অটো ক্লিনআপ
function import_cleanup_old_temp(string $dir): void
{
    foreach (glob($dir . 'import_*') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > 3600) { @unlink($f); }
    }
}

} // function_exists guard
