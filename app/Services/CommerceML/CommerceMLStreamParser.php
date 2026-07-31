<?php

declare(strict_types=1);

namespace App\Services\CommerceML;

use App\DTOs\CommerceML\CatalogItemDTO;
use App\Exceptions\Domain\CommerceMLImportException;
use XMLReader;

class CommerceMLStreamParser
{
    public function __construct(private int $batchSize = 500) {}

    /**
     * @return list<array<int, CatalogItemDTO>>
     */
    public function parseCatalog(string $xmlPath, int $tenantId, int $warehouseId): array
    {
        if (! file_exists($xmlPath)) {
            throw CommerceMLImportException::invalidXml('File not found: '.$xmlPath);
        }

        $reader = new XMLReader;
        if (! $reader->open($xmlPath) && ! $reader->open('php://temp')) {
            throw CommerceMLImportException::invalidXml('Cannot open XML stream: '.$xmlPath);
        }

        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        $batch = [];
        $batches = [];
        $count = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'Offer') {
                $srcXml = $reader->readInnerXML();
                $offer = $this->readOfferFragment($srcXml);

                if ($offer !== null) {
                    $batch[] = new CatalogItemDTO(
                        tenantId: $tenantId,
                        warehouseId: $warehouseId,
                        sku: (string) ($offer['sku'] ?? ''),
                        actual: (float) ($offer['actual'] ?? 0.0),
                        reserved: (float) ($offer['reserved'] ?? 0.0),
                        price: isset($offer['price']) ? (float) $offer['price'] : null,
                    );

                    $count++;

                    if ($count >= $this->batchSize) {
                        $batches[] = $batch;
                        $batch = [];
                        $count = 0;
                    }
                }
            }
        }

        $reader->close();

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @return array<string, mixed>
     */
    private function readOfferFragment(string $xml): ?array
    {
        $data = ['sku' => '', 'actual' => 0.0, 'reserved' => 0.0];

        $walk = new XMLReader;
        try {
            if (! $walk->open('data://text/plain,'.rawurlencode($xml))) {
                return $data;
            }

            while ($walk->read()) {
                if ($walk->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $walk->name;
                $value = $walk->readString();

                if ($name === 'Id' || $name === 'SKU') {
                    $data['sku'] = (string) $value;
                } elseif ($name === 'Quantity' || $name === 'Rest') {
                    $data['actual'] = (float) $value;
                } elseif ($name === 'Reserved') {
                    $data['reserved'] = (float) $value;
                } elseif ($name === 'Price') {
                    $data['price'] = (float) $value;
                }
            }
        } finally {
            $walk->close();
        }

        return $data;
    }
}
