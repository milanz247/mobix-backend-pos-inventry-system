<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvDownload
{
    /** @param iterable<array<array-key, mixed>> $rows */
    public static function make(string $filename, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                throw new \RuntimeException('Could not open CSV output.');
            }
            fwrite($output, "\xEF\xBB\xBF");
            foreach ($rows as $row) {
                $cells = array_map(function ($value): string {
                    $text = $value === null ? '' : (string) $value;

                    // Prevent spreadsheet formula execution from customer/product text.
                    return preg_match('/^[\s]*[=+@\-]/u', $text) && ! is_numeric($text) ? "'".$text : $text;
                }, array_values($row));
                fputcsv($output, $cells, ',', '"', '');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
