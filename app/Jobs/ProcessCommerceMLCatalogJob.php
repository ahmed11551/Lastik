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

use Autometria\Models\Category;
use Autometria\Models\OneCSyncLog;
use Autometria\Models\ProductService;
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
 * Потоковый парсинг import.xml (CommerceML 2.10) через XMLReader.
 *
 * Обрабатывает Классификатор (категории) и Каталог (товары) батчами по 200-500
 * записей без полной загрузки файла в DOM — для номенклатуры 100k+ позиций.
 */
class ProcessCommerceMLCatalogJob implements ShouldQueue
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

        $categories = [];
        $products = [];
        $batch = 0;
        $errors = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->name === 'Группа' || $reader->name === 'Категория') {
                $node = $reader->readOuterXML();
                $cat = $this->parseCategory($node);
                if ($cat !== null) {
                    $categories[] = $cat;
                }
            } elseif ($reader->name === 'Товар' || $reader->name === 'Товар1') {
                $node = $reader->readOuterXML();
                $prod = $this->parseProduct($node);
                if ($prod !== null) {
                    $products[] = $prod;
                }
            }

            // Сброс батча при накоплении.
            if (count($categories) >= 300 || count($products) >= 300) {
                $batch++;
                $this->flushBatch($categories, $products, $errors);
                $categories = [];
                $products = [];
            }
        }

        // Финальный батч.
        if ($categories !== [] || $products !== []) {
            $this->flushBatch($categories, $products, $errors);
        }

        $reader->close();

        $count = $log->processed_count;
        $log->update([
            'status' => $errors === [] ? 'done' : 'failed',
            'errors' => $errors === [] ? null : implode("\n", $errors),
            'processed_count' => $count,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     * @param  list<array<string, mixed>>  $products
     * @param  list<string>  $errors
     */
    private function flushBatch(array $categories, array $products, array &$errors): void
    {
        DB::transaction(function () use ($categories, $products, &$errors): void {
            foreach ($categories as $c) {
                $model = Category::query()->withoutGlobalScopes()
                    ->firstOrNew(['tenant_id' => $this->tenantId, 'external_id' => $c['external_id']]);
                $model->forceFill([
                    'tenant_id' => $this->tenantId,
                    'name' => $c['name'],
                    'parent_id' => $c['parent_id'] ?? null,
                ])->save();
            }

            foreach ($products as $p) {
                try {
                    $categoryId = null;
                    if (! empty($p['category_external_id'])) {
                        $categoryId = Category::query()->withoutGlobalScopes()
                            ->where('tenant_id', $this->tenantId)
                            ->where('external_id', $p['category_external_id'])
                            ->value('id');
                    }

                    $model = ProductService::query()->withoutGlobalScopes()
                        ->firstOrNew(['tenant_id' => $this->tenantId, 'external_id' => $p['external_id']]);
                    $model->forceFill([
                        'tenant_id' => $this->tenantId,
                        'name' => $p['name'],
                        'article' => $p['article'] ?? null,
                        'category_id' => $categoryId,
                        'base_price' => $p['price'] ?? 0,
                        'is_active' => true,
                    ])->save();
                } catch (\Throwable $e) {
                    $errors[] = "product {$p['external_id']}: " . $e->getMessage();
                }
            }
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCategory(string $xml): ?array
    {
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return null;
        }
        $id = (string) ($doc->{'Ид'} ?? $doc->{'Код'} ?? '');
        $name = (string) ($doc->{'Наименование'} ?? '');

        return $id === '' ? null : [
            'external_id' => $id,
            'name' => $name,
            'parent_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseProduct(string $xml): ?array
    {
        $doc = simplexml_load_string($xml);
        if ($doc === false) {
            return null;
        }
        $id = (string) ($doc->{'Ид'} ?? $doc->{'Код'} ?? '');
        if ($id === '') {
            return null;
        }

        $price = 0;
        if (isset($doc->{'Цены'}->{'Цена'})) {
            $price = (float) ($doc->{'Цены'}->{'Цена'}->{'ЦенаЗаЕдиницу'} ?? 0);
        }

        $catId = (string) ($doc->{'Группы'}->{'Ид'} ?? '');

        return [
            'external_id' => $id,
            'name' => (string) ($doc->{'Наименование'} ?? $id),
            'article' => (string) ($doc->{'Артикул'} ?? ''),
            'price' => $price,
            'category_external_id' => $catId,
        ];
    }
}
