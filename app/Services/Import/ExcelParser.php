<?php

declare(strict_types=1);

namespace App\Services\Import;

class ExcelParser
{
    /**
     * @return list<array<string, string>>
     */
    public function parseCustomers(string $filePath): array
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        $data = match ($extension) {
            'csv' => $this->parseCsv($filePath),
            'json' => $this->parseJson($filePath),
            'xlsx', 'xls' => throw new \RuntimeException(
                'XLSX/XLS: загрузите CSV по фиксированному шаблону (type,name,phone,email,inn,kpp,legal_name)'
            ),
            default => throw new \RuntimeException('Unsupported file type'),
        };

        return $this->normalize($data);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseCsv(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException('File not found');
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open CSV');
        }

        $headers = fgetcsv($handle);
        if ($headers === false) {
            fclose($handle);
            throw new \RuntimeException('Empty CSV');
        }

        $headers = array_map(fn ($h) => $this->normalizeHeader((string) $h), $headers);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                continue;
            }
            $combined = [];
            foreach ($headers as $i => $header) {
                $combined[$header] = $row[$i] ?? '';
            }
            $rows[] = $combined;
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseJson(string $filePath): array
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new \RuntimeException('File not found');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid import JSON');
        }

        return array_is_list($decoded) ? $decoded : ($decoded['rows'] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, string>>
     */
    private function normalize(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $mapped = [];
            foreach ($row as $key => $value) {
                $mapped[$this->normalizeHeader((string) $key)] = $value;
            }

            $type = strtolower($this->string($mapped['type'] ?? ''));
            if (in_array($type, ['физлицо', 'фл', 'individual'], true)) {
                $type = 'individual';
            }
            if (in_array($type, ['юрлицо', 'юл', 'legal', 'company'], true)) {
                $type = 'legal';
            }

            $normalized[] = [
                'type' => $type,
                'name' => $this->string($mapped['name'] ?? $mapped['fio'] ?? ''),
                'phone' => $this->normalizePhone($this->string($mapped['phone'] ?? '')),
                'email' => $this->string($mapped['email'] ?? ''),
                'inn' => $this->string($mapped['inn'] ?? ''),
                'kpp' => $this->string($mapped['kpp'] ?? ''),
                'legal_name' => $this->string($mapped['legal_name'] ?? ''),
            ];
        }

        return $normalized;
    }

    private function normalizeHeader(string $header): string
    {
        $header = trim(mb_strtolower($header));
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;

        return match ($header) {
            'тип', 'type' => 'type',
            'фио', 'name', 'имя' => 'name',
            'телефон', 'phone', 'тел' => 'phone',
            'email', 'почта', 'e-mail' => 'email',
            'инн', 'inn' => 'inn',
            'кпп', 'kpp' => 'kpp',
            'наименование', 'legal_name', 'организация' => 'legal_name',
            default => $header,
        };
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? $phone;
    }

    private function string(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
