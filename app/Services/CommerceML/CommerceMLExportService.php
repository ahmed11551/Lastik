<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\CommerceML;

use Autometria\Models\Customer;
use Autometria\Models\OneCSyncLog;
use Autometria\Models\Order;
use Autometria\Models\Payment;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\Warehouse;
use Autometria\Services\OneC\OneCSyncLogger;
use DOMDocument;
use DOMElement;

/**
 * CommerceML 2.09 export: orders.xml + offers.xml.
 */
final class CommerceMLExportService
{
    public function __construct(
        private readonly OneCSyncLogger $logger = new OneCSyncLogger,
    ) {}

    /**
     * @return array{xml: string, log: OneCSyncLog, count: int}
     */
    public function exportOrders(int $tenantId, ?string $since = null, string $channel = 'manual_export'): array
    {
        $log = $this->logger->start($tenantId, 'orders.xml', 'outbound', $channel);

        try {
            $q = Order::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['orderItems.product', 'customer', 'payments'])
                ->orderBy('id');

            if ($since !== null && $since !== '') {
                $q->where('updated_at', '>=', $since);
            } else {
                $q->where('created_at', '>=', now()->subDays(30));
            }

            $orders = $q->limit(500)->get();
            $xml = $this->buildOrdersXml($orders);
            $bytes = strlen($xml);
            $this->logger->succeed($log, $orders->count(), $bytes, [
                'type' => 'orders',
                'order_ids' => $orders->pluck('id')->values()->all(),
            ]);

            return ['xml' => $xml, 'log' => $log->fresh(), 'count' => $orders->count()];
        } catch (\Throwable $e) {
            $this->logger->fail($log, $e);

            throw $e;
        }
    }

    /**
     * @return array{xml: string, log: OneCSyncLog, count: int}
     */
    public function exportOffers(int $tenantId, string $channel = 'manual_export'): array
    {
        $log = $this->logger->start($tenantId, 'offers.xml', 'outbound', $channel);

        try {
            $stocks = Stock::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->with(['product', 'warehouse'])
                ->orderBy('id')
                ->limit(5000)
                ->get();

            $xml = $this->buildOffersXml($tenantId, $stocks);
            $bytes = strlen($xml);
            $this->logger->succeed($log, $stocks->count(), $bytes, [
                'type' => 'offers',
                'warehouses' => $stocks->pluck('warehouse_id')->unique()->values()->all(),
            ]);

            return ['xml' => $xml, 'log' => $log->fresh(), 'count' => $stocks->count()];
        } catch (\Throwable $e) {
            $this->logger->fail($log, $e);

            throw $e;
        }
    }

    /**
     * JSON push payload for near-real-time sync.
     *
     * @return array{orders: list<array<string, mixed>>, offers: list<array<string, mixed>>, log: OneCSyncLog}
     */
    public function pushSnapshot(int $tenantId, string $channel = 'json_push'): array
    {
        $log = $this->logger->start($tenantId, 'push.json', 'outbound', $channel);

        try {
            $ordersExport = $this->ordersPayload($tenantId);
            $offersExport = $this->offersPayload($tenantId);
            $payload = ['orders' => $ordersExport, 'offers' => $offersExport];
            $bytes = strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->logger->succeed($log, count($ordersExport) + count($offersExport), $bytes, [
                'type' => 'json_push',
                'orders' => count($ordersExport),
                'offers' => count($offersExport),
            ]);

            return [
                'orders' => $ordersExport,
                'offers' => $offersExport,
                'log' => $log->fresh(),
            ];
        } catch (\Throwable $e) {
            $this->logger->fail($log, $e);

            throw $e;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Order>  $orders
     */
    private function buildOrdersXml($orders): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('КоммерческаяИнформация');
        $root->setAttribute('ВерсияСхемы', '2.09');
        $root->setAttribute('ДатаФормирования', now()->format('Y-m-d\TH:i:s'));
        $doc->appendChild($root);

        foreach ($orders as $order) {
            $root->appendChild($this->orderNode($doc, $order));
        }

        return (string) $doc->saveXML();
    }

    private function orderNode(DOMDocument $doc, Order $order): DOMElement
    {
        $node = $doc->createElement('Документ');
        $this->text($doc, $node, 'Ид', 'ORD-'.$order->id);
        $this->text($doc, $node, 'Номер', (string) ($order->number ?: $order->id));
        $this->text($doc, $node, 'Дата', optional($order->created_at)?->format('Y-m-d') ?: now()->format('Y-m-d'));
        $this->text($doc, $node, 'ХозОперация', 'Заказ товара');
        $this->text($doc, $node, 'Роль', 'Продавец');
        $this->text($doc, $node, 'Валюта', 'RUB');
        $this->text($doc, $node, 'Курс', '1');
        $this->text($doc, $node, 'Сумма', number_format((float) $order->total, 2, '.', ''));

        $contractors = $doc->createElement('Контрагенты');
        $contractor = $doc->createElement('Контрагент');
        /** @var Customer|null $customer */
        $customer = $order->customer;
        $this->text($doc, $contractor, 'Ид', $customer ? 'CUST-'.$customer->id : 'CUST-0');
        $this->text($doc, $contractor, 'Наименование', $customer?->legal_name ?: ($customer?->name ?: 'Розничный покупатель'));
        if ($customer?->inn) {
            $this->text($doc, $contractor, 'ИНН', (string) $customer->inn);
        }
        if ($customer?->phone) {
            $this->text($doc, $contractor, 'Телефон', (string) $customer->phone);
        }
        $this->text($doc, $contractor, 'Роль', 'Покупатель');
        $contractors->appendChild($contractor);
        $node->appendChild($contractors);

        $goods = $doc->createElement('Товары');
        foreach ($order->orderItems as $item) {
            if ($item->type === 'service' && $item->product_id === null) {
                continue;
            }
            $g = $doc->createElement('Товар');
            $product = $item->product;
            $ext = $product?->external_id ?: ('PROD-'.($item->product_id ?: $item->id));
            $this->text($doc, $g, 'Ид', (string) $ext);
            $this->text($doc, $g, 'Артикул', (string) ($product?->article ?: ''));
            $name = $item->snapshot['name'] ?? ($product?->name ?: 'Позиция');
            $this->text($doc, $g, 'Наименование', (string) $name);
            $this->text($doc, $g, 'Количество', number_format((float) $item->qty, 3, '.', ''));
            $this->text($doc, $g, 'Цена', number_format((float) $item->price, 2, '.', ''));
            $sum = round((float) $item->qty * (float) $item->price - (float) $item->discount, 2);
            $this->text($doc, $g, 'Сумма', number_format($sum, 2, '.', ''));
            $goods->appendChild($g);
        }
        $node->appendChild($goods);

        $props = $doc->createElement('ЗначенияРеквизитов');
        $this->prop($doc, $props, 'Статус заказа', (string) $order->status);
        $this->prop($doc, $props, 'Статус оплаты', (string) $order->payment_status);
        $paid = $order->payments?->sum(fn (Payment $p) => (float) $p->amount) ?? 0;
        $this->prop($doc, $props, 'Оплачено', number_format((float) $paid, 2, '.', ''));
        $node->appendChild($props);

        return $node;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Stock>  $stocks
     */
    private function buildOffersXml(int $tenantId, $stocks): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('КоммерческаяИнформация');
        $root->setAttribute('ВерсияСхемы', '2.09');
        $root->setAttribute('ДатаФормирования', now()->format('Y-m-d\TH:i:s'));
        $doc->appendChild($root);

        $pkg = $doc->createElement('ПакетПредложений');
        $this->text($doc, $pkg, 'Ид', 'OFFERS-'.$tenantId);
        $this->text($doc, $pkg, 'Наименование', 'Пакет предложений AUTOMETRIA');
        $this->text($doc, $pkg, 'ИдКаталога', 'CATALOG-'.$tenantId);

        $priceTypes = $doc->createElement('ТипыЦен');
        $pt = $doc->createElement('ТипЦены');
        $this->text($doc, $pt, 'Ид', 'PRICE-RETAIL');
        $this->text($doc, $pt, 'Наименование', 'Розничная');
        $this->text($doc, $pt, 'Валюта', 'RUB');
        $priceTypes->appendChild($pt);
        $pkg->appendChild($priceTypes);

        $offers = $doc->createElement('Предложения');
        $byProduct = $stocks->groupBy('product_id');
        foreach ($byProduct as $productId => $rows) {
            /** @var Stock $first */
            $first = $rows->first();
            $product = $first->product;
            if (! $product instanceof ProductService) {
                continue;
            }
            $offer = $doc->createElement('Предложение');
            $this->text($doc, $offer, 'Ид', (string) ($product->external_id ?: 'PROD-'.$productId));
            $this->text($doc, $offer, 'Артикул', (string) ($product->article ?: ''));
            $this->text($doc, $offer, 'Наименование', (string) $product->name);

            $price = Price::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('product_id', $productId)
                ->where('type', 'retail')
                ->orderByDesc('id')
                ->value('price');

            $prices = $doc->createElement('Цены');
            $priceNode = $doc->createElement('Цена');
            $this->text($doc, $priceNode, 'ИдТипаЦены', 'PRICE-RETAIL');
            $this->text($doc, $priceNode, 'ЦенаЗаЕдиницу', number_format((float) ($price ?? $product->base_price ?? 0), 2, '.', ''));
            $this->text($doc, $priceNode, 'Валюта', 'RUB');
            $prices->appendChild($priceNode);
            $offer->appendChild($prices);

            $balances = $doc->createElement('Остатки');
            foreach ($rows as $stock) {
                $wh = $stock->warehouse;
                $bal = $doc->createElement('Остаток');
                $this->text($doc, $bal, 'Склад', (string) ($wh instanceof Warehouse ? ($wh->external_id ?: 'WH-'.$wh->id) : 'WH-0'));
                $this->text($doc, $bal, 'Количество', number_format((float) $stock->available, 3, '.', ''));
                $balances->appendChild($bal);
            }
            $offer->appendChild($balances);
            $offers->appendChild($offer);
        }
        $pkg->appendChild($offers);
        $root->appendChild($pkg);

        return (string) $doc->saveXML();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ordersPayload(int $tenantId): array
    {
        return Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['orderItems.product', 'customer', 'payments'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status,
                'payment_status' => $o->payment_status,
                'total' => (float) $o->total,
                'customer' => $o->customer ? [
                    'id' => $o->customer->id,
                    'name' => $o->customer->name,
                    'inn' => $o->customer->inn,
                    'phone' => $o->customer->phone,
                ] : null,
                'items' => $o->orderItems->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'external_id' => $i->product?->external_id,
                    'qty' => (float) $i->qty,
                    'price' => (float) $i->price,
                ])->values()->all(),
                'payments' => $o->payments->map(fn (Payment $p) => [
                    'method' => $p->method,
                    'amount' => (float) $p->amount,
                ])->values()->all(),
                'updated_at' => optional($o->updated_at)?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function offersPayload(int $tenantId): array
    {
        return Stock::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with(['product', 'warehouse'])
            ->limit(2000)
            ->get()
            ->map(fn (Stock $s) => [
                'product_id' => $s->product_id,
                'external_id' => $s->product?->external_id,
                'warehouse_id' => $s->warehouse_id,
                'warehouse_external_id' => $s->warehouse?->external_id,
                'available' => (float) $s->available,
                'actual' => (float) $s->actual,
                'reserved' => (float) $s->reserved,
            ])->values()->all();
    }

    private function text(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $el = $doc->createElement($name);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }

    private function prop(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $prop = $doc->createElement('ЗначениеРеквизита');
        $this->text($doc, $prop, 'Наименование', $name);
        $this->text($doc, $prop, 'Значение', $value);
        $parent->appendChild($prop);
    }
}
