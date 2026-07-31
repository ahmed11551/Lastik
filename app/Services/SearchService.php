<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Vehicle;

final class SearchService
{
    /**
     * Быстрый поиск по ФИО, телефону, госномеру, номеру заказа (п. 36).
     *
     * @return array{
     *   query: string,
     *   customers: list<array<string, mixed>>,
     *   vehicles: list<array<string, mixed>>,
     *   orders: list<array<string, mixed>>
     * }
     */
    public function search(int $tenantId, string $query, ?int $locationId = null, int $limit = 20): array
    {
        $raw = trim($query);
        if ($raw === '' || mb_strlen($raw) < 2) {
            return [
                'query' => $raw,
                'customers' => [],
                'vehicles' => [],
                'orders' => [],
            ];
        }

        $phoneDigits = $this->digitsOnly($raw);
        $plateNorm = $this->normalizePlate($raw);
        // SQLite LOWER() ignores Cyrillic; keep raw LIKE for FIO and ASCII-lower for plates/brands.
        $likeRaw = '%'.$raw.'%';
        $likeAscii = '%'.mb_strtolower($raw).'%';

        $customers = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($likeRaw, $phoneDigits): void {
                $q->where('name', 'like', $likeRaw)
                    ->orWhere('legal_name', 'like', $likeRaw);

                if (mb_strlen($phoneDigits) >= 3) {
                    $q->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') LIKE ?",
                        ['%'.$phoneDigits.'%']
                    );
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'type' => $c->type,
                'name' => $c->name ?? $c->legal_name,
                'phone' => $c->phone,
                'inn' => $c->inn,
            ])
            ->all();

        $vehicles = Vehicle::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($likeRaw, $likeAscii, $plateNorm): void {
                $q->where('brand', 'like', $likeRaw)
                    ->orWhere('model', 'like', $likeRaw)
                    ->orWhereRaw('LOWER(COALESCE(brand, \'\')) LIKE ?', [$likeAscii])
                    ->orWhereRaw('LOWER(COALESCE(model, \'\')) LIKE ?', [$likeAscii]);

                if ($plateNorm !== '') {
                    $q->orWhereRaw(
                        "LOWER(REPLACE(REPLACE(COALESCE(plate,''), ' ', ''), '-', '')) LIKE ?",
                        ['%'.$plateNorm.'%']
                    );
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (Vehicle $v) => [
                'id' => $v->id,
                'plate' => $v->plate,
                'brand' => $v->brand,
                'model' => $v->model,
                'customer_id' => $v->customer_id,
            ])
            ->all();

        $ordersQuery = Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($likeRaw, $raw): void {
                $q->where('number', 'like', $likeRaw)
                    ->orWhere('id', (int) $raw > 0 ? (int) $raw : -1);
            });

        if ($locationId !== null) {
            $ordersQuery->where('location_id', $locationId);
        }

        $orders = $ordersQuery
            ->limit($limit)
            ->latest('id')
            ->get()
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status,
                'scenario' => $o->scenario,
                'customer_id' => $o->customer_id,
                'vehicle_id' => $o->vehicle_id,
                'total' => $o->total,
            ])
            ->all();

        return [
            'query' => $raw,
            'customers' => $customers,
            'vehicles' => $vehicles,
            'orders' => $orders,
        ];
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizePlate(string $value): string
    {
        $v = mb_strtolower($value);
        $v = preg_replace('/[\s\-]+/u', '', $v) ?? $v;

        return $v;
    }
}
