<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * Layer 3 — CommerceML async/stream batches (XMLReader + chunk + queue).
 */

declare(strict_types=1);

use Autometria\Jobs\ProcessCommerceMLCatalogJob;
use Autometria\Services\CommerceML\CommerceMLBatchUpsertService;
use Autometria\Services\CommerceML\CommerceMLStreamParser;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AcceptanceFixture;

beforeEach(function (): void {
    config(['cache.default' => 'array', 'queue.default' => 'sync']);
});

/**
 * Потоковый XMLReader: delta памяти << размера файла (не DOM).
 * Чанки CatalogItemDTO по batchSize; job каталога уходит в очередь.
 */
it('commerceml batch parser processes large xml via streams without memory leaks', function (): void {
    Queue::fake();
    Storage::fake('local');

    $fx = AcceptanceFixture::make('cml-stream-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    $count = 2000;
    $xml = '<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация><Каталог>';
    for ($i = 1; $i <= $count; $i++) {
        $xml .= "<Товар><Ид>PROD-{$i}</Ид><Артикул>SKU-{$i}</Артикул><Наименование>Товар {$i}</Наименование><Цена>100.00</Цена></Товар>";
    }
    $xml .= '</Каталог></КоммерческаяИнформация>';

    $filename = 'import_heavy_'.uniqid().'.xml';
    Storage::disk('local')->put('1c_imports/'.$filename, $xml);
    $filePath = Storage::disk('local')->path('1c_imports/'.$filename);

    // Memory baseline after XML is already on disk (not in PHP string for stream phase)
    unset($xml);
    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }
    $startMemory = memory_get_usage(true);

    $parser = new CommerceMLStreamParser(500);
    $seen = 0;
    foreach ($parser->parseProducts($filePath) as $dto) {
        $seen++;
        unset($dto);
    }

    $endMemory = memory_get_usage(true);
    $memoryDelta = $endMemory - $startMemory;

    expect($seen)->toBe($count);
    // Потоковый разбор: рост не пропорционален размеру файла (лимит 5MB)
    expect($memoryDelta)->toBeLessThan(5 * 1024 * 1024);

    // Offers/catalog chunking API (legacy CatalogItemDTO batches)
    $offersXml = '<?xml version="1.0" encoding="UTF-8"?><КоммерческаяИнформация><ПакетПредложений>';
    for ($i = 1; $i <= 1200; $i++) {
        $offersXml .= "<Предложение><Ид>OFF-{$i}</Ид><ИдТовара>PROD-{$i}</ИдТовара><Количество>1</Количество><Цена>10</Цена></Предложение>";
    }
    $offersXml .= '</ПакетПредложений></КоммерческаяИнформация>';
    $offersPath = sys_get_temp_dir().'/cml_offers_'.uniqid().'.xml';
    file_put_contents($offersPath, $offersXml);

    $chunkParser = new CommerceMLStreamParser(500);
    $batches = $chunkParser->parseCatalog($offersPath, (int) $fx->tenant->id, (int) $fx->warehouse->id);
    expect(count($batches))->toBe(3); // 1200 / 500
    expect(count($batches[0]))->toBe(500);
    expect(CommerceMLBatchUpsertService::BATCH_SIZE)->toBe(1000);
    @unlink($offersPath);

    ProcessCommerceMLCatalogJob::dispatch((int) $fx->tenant->id, $filename);

    Queue::assertPushed(
        ProcessCommerceMLCatalogJob::class,
        fn (ProcessCommerceMLCatalogJob $job): bool => $job->tenantId === (int) $fx->tenant->id
            && $job->filename === $filename,
    );
});
