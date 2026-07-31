<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Dictionary;
use App\Services\DictionaryService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.1 / 13 / 27 / 28 — справочники статусов и форм оплаты.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('dict-'.uniqid());
});

it('seeds default statuses and payment forms without hardcoding in runtime tables', function (): void {
    $fx = $this->fx;

    $forms = app(DictionaryService::class)->list($fx->tenant->id, Dictionary::TYPE_PAYMENT_FORM);
    $orderStatuses = app(DictionaryService::class)->list($fx->tenant->id, Dictionary::TYPE_ORDER_STATUS);

    expect(collect($forms)->pluck('code')->all())->toContain('cash', 'card', 'transfer');
    expect(collect($orderStatuses)->pluck('code')->all())->toContain('created', 'closed', 'cancelled');
});

it('allows adding payment form without code change', function (): void {
    $fx = $this->fx;
    $svc = app(DictionaryService::class);

    $dict = $svc->upsert(
        $fx->tenant->id,
        Dictionary::TYPE_PAYMENT_FORM,
        'crypto',
        'Криптовалюта',
        $fx->user->id,
        90,
    );

    expect($dict->code)->toBe('crypto');
    expect($dict->is_active)->toBeTrue();

    $svc->deactivate($fx->tenant->id, $dict->id, $fx->user->id);

    expect(
        Dictionary::query()->withoutGlobalScopes()->whereKey($dict->id)->value('is_active')
    )->toBeFalsy();

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->whereIn('action', ['dictionary.created', 'dictionary.deactivated'])
            ->count()
    )->toBeGreaterThanOrEqual(2);
});

it('rejects unknown payment form when dictionary is seeded', function (): void {
    $fx = $this->fx;

    expect(fn () => app(DictionaryService::class)->assertActiveCode(
        $fx->tenant->id,
        Dictionary::TYPE_PAYMENT_FORM,
        'bitcoin',
    ))->toThrow(InvalidArgumentException::class);
});
