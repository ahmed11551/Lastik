<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Dictionary;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class DictionaryService
{
    /**
     * @return list<array{type: string, code: string, label: string, sort: int, meta?: array<string, mixed>}>
     */
    public static function defaults(): array
    {
        return [
            // order statuses (п. 27.1)
            ['type' => Dictionary::TYPE_ORDER_STATUS, 'code' => 'created', 'label' => 'Создан', 'sort' => 10],
            ['type' => Dictionary::TYPE_ORDER_STATUS, 'code' => 'in_progress', 'label' => 'В работе', 'sort' => 20],
            ['type' => Dictionary::TYPE_ORDER_STATUS, 'code' => 'ready', 'label' => 'Готов к выдаче / выполнению', 'sort' => 30],
            ['type' => Dictionary::TYPE_ORDER_STATUS, 'code' => 'issued', 'label' => 'Выдан / выполнен', 'sort' => 40],
            ['type' => Dictionary::TYPE_ORDER_STATUS, 'code' => 'closed', 'label' => 'Закрыт', 'sort' => 50],
            ['type' => Dictionary::TYPE_ORDER_STATUS, 'code' => 'cancelled', 'label' => 'Отменён', 'sort' => 60],

            // payment statuses (п. 27.2)
            ['type' => Dictionary::TYPE_PAYMENT_STATUS, 'code' => 'unpaid', 'label' => 'Не оплачено', 'sort' => 10],
            ['type' => Dictionary::TYPE_PAYMENT_STATUS, 'code' => 'partial', 'label' => 'Частично оплачено', 'sort' => 20],
            ['type' => Dictionary::TYPE_PAYMENT_STATUS, 'code' => 'paid', 'label' => 'Оплачено', 'sort' => 30],
            ['type' => Dictionary::TYPE_PAYMENT_STATUS, 'code' => 'debt', 'label' => 'Долг', 'sort' => 40],
            ['type' => Dictionary::TYPE_PAYMENT_STATUS, 'code' => 'refund', 'label' => 'Возврат', 'sort' => 50],
            ['type' => Dictionary::TYPE_PAYMENT_STATUS, 'code' => 'correction', 'label' => 'Корректировка', 'sort' => 60],

            // item statuses (п. 27.3)
            ['type' => Dictionary::TYPE_ITEM_STATUS, 'code' => 'added', 'label' => 'Добавлен', 'sort' => 10],
            ['type' => Dictionary::TYPE_ITEM_STATUS, 'code' => 'reserved', 'label' => 'Зарезервирован', 'sort' => 20],
            ['type' => Dictionary::TYPE_ITEM_STATUS, 'code' => 'issued', 'label' => 'Выдан', 'sort' => 30],
            ['type' => Dictionary::TYPE_ITEM_STATUS, 'code' => 'cancelled', 'label' => 'Отменён', 'sort' => 40],
            ['type' => Dictionary::TYPE_ITEM_STATUS, 'code' => 'returned', 'label' => 'Возвращён', 'sort' => 50],

            // payment forms (п. 28) — не hardcode в бизнес-логике
            ['type' => Dictionary::TYPE_PAYMENT_FORM, 'code' => 'cash', 'label' => 'Наличные', 'sort' => 10],
            ['type' => Dictionary::TYPE_PAYMENT_FORM, 'code' => 'card', 'label' => 'Карта', 'sort' => 20],
            ['type' => Dictionary::TYPE_PAYMENT_FORM, 'code' => 'transfer', 'label' => 'Перевод', 'sort' => 30],
            ['type' => Dictionary::TYPE_PAYMENT_FORM, 'code' => 'wire', 'label' => 'Перечисление', 'sort' => 40],

            // reasons
            ['type' => Dictionary::TYPE_CANCEL_REASON, 'code' => 'client_refused', 'label' => 'Клиент отказался', 'sort' => 10],
            ['type' => Dictionary::TYPE_CANCEL_REASON, 'code' => 'duplicate', 'label' => 'Дубль заказа', 'sort' => 20],
            ['type' => Dictionary::TYPE_DELETE_REASON, 'code' => 'wrong_item', 'label' => 'Ошибочная позиция', 'sort' => 10],
            ['type' => Dictionary::TYPE_RETURN_REASON, 'code' => 'defect', 'label' => 'Брак', 'sort' => 10],
            ['type' => Dictionary::TYPE_CORRECTION_REASON, 'code' => 'cashier_error', 'label' => 'Ошибка кассира', 'sort' => 10],
        ];
    }

    public function seedDefaults(int $tenantId): void
    {
        foreach (self::defaults() as $row) {
            Dictionary::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'type' => $row['type'],
                    'code' => $row['code'],
                ],
                [
                    'label' => $row['label'],
                    'sort' => $row['sort'],
                    'is_active' => true,
                    'meta' => $row['meta'] ?? null,
                ]
            );
        }
    }

    /**
     * @return list<Dictionary>
     */
    public function list(int $tenantId, ?string $type = null, bool $onlyActive = true): array
    {
        $q = Dictionary::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderBy('type')
            ->orderBy('sort');

        if ($type) {
            $q->where('type', $type);
        }

        if ($onlyActive) {
            $q->where('is_active', true);
        }

        return $q->get()->all();
    }

    public function upsert(
        int $tenantId,
        string $type,
        string $code,
        string $label,
        int $userId,
        ?int $sort = null,
        bool $isActive = true,
        ?array $meta = null,
    ): Dictionary {
        return DB::transaction(function () use ($tenantId, $type, $code, $label, $userId, $sort, $isActive, $meta): Dictionary {
            $existing = Dictionary::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('type', $type)
                ->where('code', $code)
                ->first();

            $old = $existing?->only(['label', 'sort', 'is_active', 'meta']) ?? [];

            $dict = Dictionary::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenantId, 'type' => $type, 'code' => $code],
                [
                    'label' => $label,
                    'sort' => $sort ?? ($existing->sort ?? 100),
                    'is_active' => $isActive,
                    'meta' => $meta,
                ]
            );

            AuditLog::write(
                $tenantId,
                $userId,
                $existing ? 'dictionary.updated' : 'dictionary.created',
                Dictionary::class,
                (int) $dict->id,
                $old,
                $dict->only(['type', 'code', 'label', 'sort', 'is_active']),
            );

            return $dict;
        });
    }

    public function deactivate(int $tenantId, int $id, int $userId): Dictionary
    {
        $dict = Dictionary::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->firstOrFail();

        $old = ['is_active' => $dict->is_active];
        $dict->update(['is_active' => false]);

        AuditLog::write(
            $tenantId,
            $userId,
            'dictionary.deactivated',
            Dictionary::class,
            (int) $dict->id,
            $old,
            ['is_active' => false],
        );

        return $dict->fresh();
    }

    public function assertActiveCode(int $tenantId, string $type, string $code): void
    {
        $exists = Dictionary::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('code', $code)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw new InvalidArgumentException("Unknown or inactive dictionary code [{$type}:{$code}]");
        }
    }
}
