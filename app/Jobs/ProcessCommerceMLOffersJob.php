<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Jobs
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Jobs;

use Autometria\Models\OneCSyncLog;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\Warehouse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use XMLReader;

/**
 * Потоковый парсинг offers.xml (CommerceML 2.10) через XMLReader.
 *
 * Обрабатывает Предложения: типы цен, цены и остатки по складам.
 * Остатки пишутся в stocks (по product_id + warehouse_id), цены — в prices.
 */
class ProcessCommerceMLOffersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public int $tenantId,
        public string $filename,
    ) {}

    public function handle(): void
    {
        $path = Storage::disk('local')->path("1c_imports/{$this->filename}");
        if (! file_exists($path)) {
            return;
        }

        $log = OneCSyncLog::query()->withoutGlobalScopes()->create([
            'tenant_id' => $this->tenantId,
            'file_name' => $this->filename,
            'status' => 'processing',
            'processed_count' => 0,
        ]);

        $reader = new XMLReader();
        $reader->open($path);

        // Типы цен: Ид -> наименование.
        $priceTypes = [];
        // Типы цен аккумулируются (collectPriceTypes возвращает только типы из
        // текущего узла; при встрече <Предложение> возвращает [], merge сохраняет).
        $priceTypes = array_merge($priceTypes, $this->collectPriceTypes($reader));

        $offers = [];
        $errors = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->name === 'Предложение' || $reader->name === 'Offer') {
                $node = $reader->readOuterXML();
                $offer = $this->parseOffer($node, $priceTypes);
                if ($offer !== null) {
                    $offers[] = $offer;
                }
            }

            if (count($offers) >= 300) {
                $this->flushOffers($offers, $errors);
                $offers = [];
            }
        }

        if ($offers !== []) {
            $this->flushOffers($offers, $errors);
        }

        $reader->close();

        $log->update([
            'status' => $errors === [] ? 'done' : 'failed',
            'errors' => $errors === [] ? null : implode("\n", $errors),
            'processed_count' => $log->processed_count,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function collectPriceTypes(XMLReader $reader): array
    {
        $types = [];
        $xml = $reader->readOuterXML();
        if (str_contains($xml, 'ТипыЦен') || str_contains($xml, 'Склад')) {
            $doc = @simplexml_load_string($xml);
            if ($doc !== false) {
                foreach ($doc->xpath('//ТипЦены') ?: [] as $t) {
                    $id = (string) ($t->{'Ид'} ?? '');
                    $name = (string) ($t->{'Наименование'} ?? '');
                    if ($id !== '') {
                        $types[$id] = $name;
                    }
                }
            }
        }

        return $types;
    }

    /**
     * @param  array<string, string>  $priceTypes
     * @return array<string, mixed>|null
     */
    private function parseOffer(string $xml, array $priceTypes): ?array
    {
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            return null;
        }
        $productId = (string) ($doc->{'Ид'} ?? '');
        if ($productId === '') {
            return null;
        }

        $prices = [];
        if (isset($doc->{'Цены'}->{'Цена'})) {
            foreach ($doc->{'Цены'}->{'Цена'} as $p) {
                $typeId = (string) ($p->{'ИдТипаЦены'} ?? '');
                $value = (float) ($p->{'ЦенаЗаЕдиницу'} ?? 0);
                $prices[$priceTypes[$typeId] ?? $typeId] = $value;
            }
        }

        // Остатки по складам (вложенные <Остаток> или <Склад><Количество>).
        $stocks = [];
        if (isset($doc->{'Остатки'}->{'Остаток'})) {
            foreach ($doc->{'Остатки'}->{'Остаток'} as $o) {
                $wh = (string) ($o->{'Склад'} ?? '');
                $qty = (float) ($o->{'Количество'} ?? 0);
                if ($wh !== '') {
                    $stocks[$wh] = $qty;
                }
            }
        } elseif (isset($doc->{'Количество'})) {
            $stocks['__default__'] = (float) $doc->{'Количество'};
        }

        return [
            'external_id' => $productId,
            'prices' => $prices,
            'stocks' => $stocks,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $offers
     * @param  list<string>  $errors
     */
    private function flushOffers(array $offers, array &$errors): void
    {
        DB::transaction(function () use ($offers, &$errors): void {
            foreach ($offers as $o) {
                $product = ProductService::query()->withoutGlobalScopes()
                    ->where('tenant_id', $this->tenantId)
                    ->where('external_id', $o['external_id'])
                    ->first();

                if ($product === null) {
                    $errors[] = "offer {$o['external_id']}: product not found";
                    continue;
                }

                // Цены.
                foreach ($o['prices'] as $type => $value) {
                    $price = Price::query()->withoutGlobalScopes()
                        ->firstOrNew([
                            'tenant_id' => $this->tenantId,
                            'product_id' => $product->id,
                            'type' => $type,
                        ]);
                    $price->forceFill([
                        'tenant_id' => $this->tenantId,
                        'product_id' => $product->id,
                        'type' => $type,
                        'amount' => $value,
                        'price' => $value,
                    ])->save();
                }

                // Остатки по складам.
                foreach ($o['stocks'] as $whExternal => $qty) {
                    if ($whExternal === '__default__') {
                        // Без привязки к складу — обновляем первый/любой stock товара.
                        $stock = Stock::query()->withoutGlobalScopes()
                            ->firstOrNew([
                                'tenant_id' => $this->tenantId,
                                'product_id' => $product->id,
                            ], [
                                'warehouse_id' => null,
                            ]);
                        $stock->forceFill([
                            'tenant_id' => $this->tenantId,
                            'product_id' => $product->id,
                            'warehouse_id' => $stock->warehouse_id,
                            'actual' => $qty,
                            'reserved' => $stock->reserved ?? 0,
                        ]);
                        $stock->recalcAvailable(true);
                        continue;
                    }

                    $warehouse = Warehouse::query()->withoutGlobalScopes()
                        ->where('tenant_id', $this->tenantId)
                        ->where('external_id', $whExternal)
                        ->first();
                    if ($warehouse === null) {
                        $errors[] = "offer {$o['external_id']}: warehouse {$whExternal} not found";
                        continue;
                    }

                    $stock = Stock::query()->withoutGlobalScopes()
                        ->firstOrNew([
                            'tenant_id' => $this->tenantId,
                            'product_id' => $product->id,
                            'warehouse_id' => $warehouse->id,
                        ]);
                    $stock->forceFill([
                        'tenant_id' => $this->tenantId,
                        'product_id' => $product->id,
                        'warehouse_id' => $warehouse->id,
                        'actual' => $qty,
                        'reserved' => $stock->reserved ?? 0,
                    ]);
                    $stock->recalcAvailable(true);
                }
            }
        });
    }
}
