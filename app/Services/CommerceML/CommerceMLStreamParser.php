<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\CommerceML;

use Autometria\DTOs\CommerceML\CatalogItemDTO;
use Autometria\DTOs\CommerceML\CommerceMLProductDTO;
use Autometria\DTOs\CommerceML\StockBalanceDTO;
use Autometria\Exceptions\Domain\CommerceMLImportException;
use Generator;
use SimpleXMLElement;
use XMLReader;

/**
 * Потоковый парсер CommerceML 2.08 (XMLReader) — без DOM на больших файлах.
 */
class CommerceMLStreamParser
{
    public function __construct(private int $batchSize = 1000) {}

    /**
     * @return Generator<int, CommerceMLProductDTO>
     */
    public function parseProducts(string $filePath): Generator
    {
        yield from $this->streamElements($filePath, ['Товар', 'Товары'], function (SimpleXMLElement $node): ?CommerceMLProductDTO {
            if (! isset($node->Ид) && ! isset($node->Наименование)) {
                return null;
            }

            return CommerceMLProductDTO::fromXMLNode($node);
        });
    }

    /**
     * Остатки / предложения CommerceML.
     *
     * @return Generator<int, StockBalanceDTO>
     */
    public function parseOffers(string $filePath): Generator
    {
        yield from $this->streamElements(
            $filePath,
            ['Предложение', 'Остаток', 'Offer'],
            function (SimpleXMLElement $node): array {
                $dtos = [];

                $productId = (string) (
                    $node->ИдТовара
                    ?? $node->Ид
                    ?? $node->Товар
                    ?? $node->Id
                    ?? $node->SKU
                    ?? ''
                );

                if ($productId === '' && isset($node->Товар->Ид)) {
                    $productId = (string) $node->Товар->Ид;
                }

                if ($productId === '') {
                    return [];
                }

                // Nested warehouses: <Остаток><Склад><ИдСклада/><Количество/></Склад></Остаток>
                if (isset($node->Склад)) {
                    foreach ($node->Склад as $wh) {
                        $warehouseId = (string) ($wh->ИдСклада ?? $wh->Ид ?? $wh->Наименование ?? '');
                        $qty = (float) ($wh->Количество ?? $wh->Остаток ?? 0);
                        $dtos[] = new StockBalanceDTO(
                            productExternalId: $productId,
                            warehouseExternalId: $warehouseId !== '' ? $warehouseId : 'default',
                            quantity: $qty,
                            price: $this->extractPrice($node),
                        );
                    }

                    return $dtos;
                }

                $warehouseId = (string) (
                    $node->{'ИдСклада'} ?? $node->WarehouseId ?? $node->Warehouse ?? ''
                );
                $qty = (float) (
                    $node->Количество ?? $node->Остаток ?? $node->Quantity ?? $node->Rest ?? 0
                );

                $dtos[] = new StockBalanceDTO(
                    productExternalId: $productId,
                    warehouseExternalId: $warehouseId !== '' ? $warehouseId : 'default',
                    quantity: $qty,
                    price: $this->extractPrice($node),
                );

                return $dtos;
            }
        );
    }

    /**
     * Legacy batch API (CatalogItemDTO chunks) — совместимость с CommerceMLUpsertService.
     *
     * @return list<list<CatalogItemDTO>>
     */
    public function parseCatalog(string $xmlPath, int $tenantId, int $warehouseId): array
    {
        $batches = [];
        $batch = [];
        $count = 0;

        foreach ($this->parseOffers($xmlPath) as $offer) {
            $batch[] = new CatalogItemDTO(
                tenantId: $tenantId,
                warehouseId: $warehouseId,
                sku: $offer->productExternalId,
                actual: $offer->quantity,
                reserved: 0.0,
                price: $offer->price,
                externalId: $offer->productExternalId,
            );
            $count++;

            if ($count >= $this->batchSize) {
                $batches[] = $batch;
                $batch = [];
                $count = 0;
            }
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param  list<string>  $localNames
     * @param  callable(SimpleXMLElement): (object|list<object>|null)  $map
     * @return Generator<int, object>
     */
    private function streamElements(string $filePath, array $localNames, callable $map): Generator
    {
        if (! is_file($filePath)) {
            throw CommerceMLImportException::invalidXml('File not found: '.$filePath);
        }

        // XXE hardening:
        // - На PHP < 8.2 отключаем загрузку внешних сущностей/DTD (на 8.2+ защита
        //   включена на уровне libxml по умолчанию, а сама функция libxml_disable_entity_loader
        //   удалена в PHP 8.4 и вызывать её нельзя).
        // - SUBST_ENTITIES выставлен в false ниже, LIBXML_NOENT не используется
        //   (это гарантирует, что &xxe; НЕ раскрывается в содержимое внешнего файла).
        if (PHP_VERSION_ID < 80200 && function_exists('libxml_disable_entity_loader')) {
            /** @psalm-suppress DeprecatedFunction */
            @libxml_disable_entity_loader(true);
        }

        $reader = new XMLReader;
        if (! $reader->open($filePath)) {
            throw CommerceMLImportException::invalidXml('Cannot open XML stream: '.$filePath);
        }

        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $reader->localName ?: $reader->name;
                if (! in_array($name, $localNames, true)) {
                    continue;
                }

                $outer = $reader->readOuterXml();
                if ($outer === '' || $outer === false) {
                    continue;
                }

                $node = @simplexml_load_string($outer, null, LIBXML_NONET);
                if (! $node instanceof SimpleXMLElement) {
                    continue;
                }

                $mapped = $map($node);
                if ($mapped === null) {
                    continue;
                }

                if (is_array($mapped)) {
                    foreach ($mapped as $dto) {
                        if ($dto !== null) {
                            yield $dto;
                        }
                    }

                    continue;
                }

                yield $mapped;
            }
        } finally {
            $reader->close();
        }
    }

    private function extractPrice(SimpleXMLElement $node): ?float
    {
        if (isset($node->Цены->Цена->ЦенаЗаЕдиницу)) {
            return (float) $node->Цены->Цена->ЦенаЗаЕдиницу;
        }

        if (isset($node->Price)) {
            return (float) $node->Price;
        }

        return null;
    }
}
