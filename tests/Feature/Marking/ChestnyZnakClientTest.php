<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Tests\Feature\Marking;

use Autometria\Enums\MarkingValidationStatusEnum;
use Autometria\Exceptions\Domain\InvalidMarkingCodeException;
use Autometria\Services\Marking\ChestnyZnakClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Интеграционный (mock) тест live-контура Честного Знака / ГИС МТ.
 * Доказывает, что при MARKING_MOCK_MODE=false клиент уходит в HTTP API
 * и корректно маппит ответ ГИС МТ в статус валидации.
 */
final class ChestnyZnakClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.marking.mock_mode' => false]);
        // Клиент в live-режиме (mockMode=false). Токен не пустой для прохождения проверки.
        config(['services.marking.token' => 'test-token']);
    }

    public function test_live_validate_maps_unused_to_valid(): void
    {
        Http::fake([
            'trueapi.ruba.ru/*' => Http::response([
                'cis' => [['gtin' => '04612345678901', 'serial' => 'ABC', 'status' => 'UNUSED']],
            ], 200),
        ]);

        $client = new ChestnyZnakClient(false);
        $result = $client->validate('010460123456789101234567890AB12', [
            'gtin' => '04612345678901', 'serial' => 'ABC', 'raw' => '010460123456789101234567890AB12',
        ]);

        $this->assertSame(MarkingValidationStatusEnum::VALID, $result['status']);
        $this->assertSame('gis_mt', $result['payload']['source']);

        // Убеждаемся, что запрос ушёл в ГИС МТ с токеном.
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), 'trueapi.ruba.ru')
            && $req->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_live_validate_maps_used_to_sold(): void
    {
        Http::fake([
            'trueapi.ruba.ru/*' => Http::response([
                'cis' => [['gtin' => '04612345678901', 'status' => 'USED']],
            ], 200),
        ]);

        $client = new ChestnyZnakClient(false);
        $result = $client->validate('010460123456789101234567890AB12', [
            'gtin' => '04612345678901', 'serial' => 'ABC', 'raw' => '010460123456789101234567890AB12',
        ]);

        $this->assertSame(MarkingValidationStatusEnum::SOLD, $result['status']);
    }

    public function test_live_validate_throws_on_gis_error(): void
    {
        Http::fake(['trueapi.ruba.ru/*' => Http::response([], 500)]);

        $client = new ChestnyZnakClient(false);

        try {
            $client->validate('010460123456789101234567890AB12', [
                'gtin' => '04612345678901', 'serial' => 'ABC', 'raw' => '010460123456789101234567890AB12',
            ]);
            $this->fail('Expected InvalidMarkingCodeException was not thrown');
        } catch (InvalidMarkingCodeException $e) {
            $this->assertSame('MARKING_GIS_ERROR', $e->errorCode);
        }
    }

    public function test_live_validate_throws_without_token(): void
    {
        config(['services.marking.token' => null]);
        Http::fake(['trueapi.ruba.ru/*' => Http::response([], 200)]);

        $client = new ChestnyZnakClient(false);

        try {
            $client->validate('010460123456789101234567890AB12', [
                'gtin' => '04612345678901', 'serial' => 'ABC', 'raw' => '010460123456789101234567890AB12',
            ]);
            $this->fail('Expected InvalidMarkingCodeException was not thrown');
        } catch (InvalidMarkingCodeException $e) {
            $this->assertSame('MARKING_LIVE_UNAVAILABLE', $e->errorCode);
        }
    }
}
