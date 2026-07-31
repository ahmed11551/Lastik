<?php

declare(strict_types=1);

namespace App\Services\Import;

class CmlParser
{
    public static function parseRemains(string $filePath): array
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'xml') {
            return self::parseXml($filePath);
        }

        if ($extension === 'zip') {
            return self::parseZipImport($filePath);
        }

        if ($extension === 'json') {
            return self::parseJson($filePath);
        }

        throw new \RuntimeException('Unsupported CommerceML2 file format');
    }

    /**
     * Тестовый / упрощённый JSON-формат остатков:
     * [{"external_id":"1C-1","warehouses":[{"warehouse":"Склад Север","qty":5}]}]
     *
     * @return list<array{external_id: string, warehouses: list<array{warehouse: string, qty: float}>}>
     */
    private static function parseJson(string $filePath): array
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            throw new \RuntimeException('CommerceML2 file not found');
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid CommerceML JSON');
        }

        $rows = array_is_list($decoded) ? $decoded : ($decoded['rows'] ?? []);
        $result = [];

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['external_id'])) {
                continue;
            }

            $warehouses = [];
            foreach ($row['warehouses'] ?? [] as $wh) {
                $warehouses[] = [
                    'warehouse' => (string) ($wh['warehouse'] ?? ''),
                    'qty' => (float) ($wh['qty'] ?? 0),
                ];
            }

            $result[] = [
                'external_id' => (string) $row['external_id'],
                'warehouses' => $warehouses,
            ];
        }

        return $result;
    }

    private static function parseXml(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new \RuntimeException('CommerceML2 file not found');
        }

        /** @var array<string, mixed>|false $xml */
        $xml = simplexml_load_file($filePath);
        if (! $xml) {
            throw new \RuntimeException('Failed to parse CommerceML2 XML');
        }

        $result = [];
        $ns = $xml->getDocNamespaces(true);
        $xml->registerXPathNamespace('c', (string) ($ns[''] ?? ''));
        $catalog = $xml->xpath('//Каталог');
        /** @var \SimpleXMLElement $note */
        foreach ($catalog[0]->Товары->Товар ?? [] as $item) {
            $externalId = (string) ($item->ИД ?? '');
            $id = (string) ($item->Ид ?? '');
            if ($externalId === '' && $id === '') {
                continue;
            }
            $key = $externalId !== '' ? $externalId : $id;

            if (! isset($result[$key])) {
                $result[$key] = [
                    'external_id' => $key === '' ? null : $key,
                    'warehouses' => [],
                ];
            }
        }

        foreach ($xml->xpath('//Остатки') as $remains) {
            foreach ($remains->Остаток ?? [] as $remain) {
                $productId = (string) ($remain->ИдТовара ?? '');
                $key = $productId === '' ? (string) ($remains->ИД ?? '') : $productId;

                if (! isset($result[$key])) {
                    $result[$key] = [
                        'external_id' => $key === '' ? null : $key,
                        'warehouses' => [],
                    ];
                }

                foreach ($remain->Склад ?? [] as $warehouse) {
                    $result[$key]['warehouses'][] = [
                        'warehouse' => (string) ($warehouse->ИдСклада ?? ''),
                        'qty' => (float) ($warehouse->Количество ?? 0),
                    ];
                }
            }
        }

        return array_values($result);
    }

    private static function parseZipImport(string $filePath): array
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZIP parsing requires ext-zip');
        }

        $zip = new \ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Failed to open zip archive');
        }

        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $offersName = null;
        $remainsName = null;
        foreach ($names as $name) {
            if (str_contains($name, 'offers') && $offersName === null) {
                $offersName = $name;
            }
            if (str_contains($name, 'remains') && $remainsName === null) {
                $remainsName = $name;
            }
        }

        if ($offersName === null || $remainsName === null) {
            throw new \RuntimeException('Invalid CommerceML2 zip archive structure');
        }

        $offersPath = sys_get_temp_dir().'/cml2_offers_'.uniqid().'.xml';
        $remainsPath = sys_get_temp_dir().'/cml2_remains_'.uniqid().'.xml';

        file_put_contents($offersPath, $zip->getFromName($offersName));
        file_put_contents($remainsPath, $zip->getFromName($remainsName));
        $zip->close();

        $offers = self::parseOffersXml($offersPath);
        $remains = self::parseRemainsXml($remainsPath);

        unlink($offersPath);
        unlink($remainsPath);

        return self::mergeOffersAndRemains($offers, $remains);
    }

    private static function parseOffersXml(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        if (function_exists('mb_convert_encoding')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        if (preg_match('/<\?xml[^>]*encoding=["\'][^"\']+["\']/', $content)) {
            // already encoded
        }

        try {
            $xml = simplexml_load_string($content);
        } catch (\Throwable $e) {
            return [];
        }

        if (! $xml) {
            return [];
        }

        $result = [];
        foreach ($xml->xpath('//Предложение') as $offer) {
            $id = (string) ($offer->Ид ?? '');
            $name = (string) ($offer->Наименование ?? '');
            if ($id === '') {
                continue;
            }

            $result[$id] = [
                'external_id' => $id,
                'name' => $name !== '' ? $name : null,
            ];
        }

        return $result;
    }

    private static function parseRemainsXml(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        try {
            $xml = simplexml_load_string($content);
        } catch (\Throwable $e) {
            return [];
        }

        if (! $xml) {
            return [];
        }

        $result = [];
        foreach ($xml->xpath('//Остаток') as $remain) {
            $id = (string) ($remain->ИдТовара ?? '');
            if ($id === '') {
                continue;
            }

            if (! isset($result[$id])) {
                $result[$id] = [
                    'external_id' => $id,
                    'warehouses' => [],
                ];
            }

            foreach ($remain->Склад ?? [] as $warehouse) {
                $result[$id]['warehouses'][] = [
                    'warehouse' => (string) ($warehouse->ИдСклада ?? ''),
                    'qty' => (float) ($warehouse->Количество ?? 0),
                ];
            }
        }

        return $result;
    }

    private static function mergeOffersAndRemains(array $offers, array $remains): array
    {
        foreach ($offers as $externalId => $meta) {
            if (! isset($remains[$externalId])) {
                $remains[$externalId] = [
                    'external_id' => $externalId,
                    'warehouses' => [],
                ];
            }
        }

        return array_values($remains);
    }
}
