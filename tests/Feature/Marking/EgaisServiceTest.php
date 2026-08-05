<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Tests\Feature\Marking;

use Autometria\Enums\EgaisDocumentStatusEnum;
use Autometria\Models\EgaisDocument;
use Autometria\Services\Marking\EgaisService;
use Illuminate\Support\Facades\Http;
use Tests\Support\AcceptanceFixture;

/**
 * Интеграционный (mock) тест отправки акта вскрытия ЕГАИС в ФСРАР.
 * Доказывает, что при EGAIS_MOCK_MODE=false акт уходит в HTTP API
 * и документ переводится в статус SENT.
 */
test('egais unseal act is sent to fsrar in live mode', function (): void {
    $fx = AcceptanceFixture::make('egais-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    config(['services.egais.mock_mode' => false]);
    config(['services.egais.cert_thumbprint' => 'TEST-THUMBPRINT']);

    Http::fake([
        'api.egais.ru/*' => Http::response(['id' => 1, 'status' => 'ACCEPTED'], 200),
    ]);

    $service = new EgaisService();
    $doc = $service->createEgaisUnsealAct($fx->product->id, 0.75, '1234567890');

    expect($doc->status)->toBe(EgaisDocumentStatusEnum::SENT->value);
    expect($doc->payload['sent_to_fsrar'])->toBeTrue();
    expect($doc->payload['source'])->toBe('local');

    Http::assertSent(fn ($req) => str_contains((string) $req->url(), 'api.egais.ru')
        && $req->hasHeader('cert_thumbprint', 'TEST-THUMBPRINT'));
});

test('egais unseal act stays local in mock mode', function (): void {
    $fx = AcceptanceFixture::make('egais-mock-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    config(['services.egais.mock_mode' => true]);

    $service = new EgaisService();
    $doc = $service->createEgaisUnsealAct($fx->product->id, 1.0, '0987654321');

    expect($doc->status)->toBe(EgaisDocumentStatusEnum::DRAFT->value);
    expect($doc->payload['sent_to_fsrar'])->toBeFalse();
    expect(EgaisDocument::query()->withoutGlobalScopes()->count())->toBeGreaterThan(0);
});
