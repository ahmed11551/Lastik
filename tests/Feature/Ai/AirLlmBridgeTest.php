<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Services\Ai\AirLlmBridgeService;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $this->service = new AirLlmBridgeService();
});

/**
 * Test 1: AI-путь — сервер отвечает, сводка помечается source=ai.
 */
it('uses ai engine when server responds', function (): void {
    Http::fake([
        '127.0.0.1:8100/v1/generate' => Http::response([
            'text' => 'Выручка выросла, маржа стабильна.',
            'model' => 'Qwen2.5-7B',
            'tokens' => 8,
            'elapsed_ms' => 120.0,
        ], 200),
        '127.0.0.1:8100/health' => Http::response(['status' => 'ok', 'model' => 'Qwen2.5-7B'], 200),
    ]);

    $result = $this->service->generateExecutiveSummary([
        'revenue' => 100000,
        'gross_profit' => 30000,
        'margin_pct' => 30,
        'orders_count' => 120,
    ]);

    expect($result['source'])->toBe('ai');
    expect($result['text'])->toContain('Выручка');
    expect($result['model'])->toBe('Qwen2.5-7B');
});

/**
 * Test 2: Fallback — сервер недоступен (503), возвращается структурированный текст без ИИ.
 */
it('falls back to structured summary when ai is unavailable', function (): void {
    Http::fake([
        '127.0.0.1:8100/*' => Http::response(null, 503),
    ]);

    $result = $this->service->generateExecutiveSummary([
        'revenue' => 100000,
        'gross_profit' => 30000,
        'margin_pct' => 30,
        'orders_count' => 120,
    ]);

    expect($result['source'])->toBe('fallback');
    expect($result['model'])->toBeNull();
    expect($result['text'])->toContain('Сводка');
    expect($result['text'])->toContain('100 000');
});

/**
 * Test 3: NLP-поиск через ИИ возвращает JSON-фильтры.
 */
it('parses natural query to filters via ai', function (): void {
    Http::fake([
        '127.0.0.1:8100/v1/generate' => Http::response([
            'text' => '{"filters": {"from": "2026-08-06", "to": "2026-08-06", "type": "expense"}, "interpretation": "Списания за вчера"}',
            'model' => 'Qwen2.5-7B',
            'tokens' => 20,
            'elapsed_ms' => 90.0,
        ], 200),
    ]);

    $result = $this->service->parseNaturalQuery('покажи списания за вчера');

    expect($result['source'])->toBe('ai');
    expect($result['filters'])->toHaveKey('type', 'expense');
    expect($result['filters'])->toHaveKey('from');
});

/**
 * Test 4: NLP-поиск fallback — эвристика распознаёт "вчера".
 */
it('falls back to heuristic nlp filters', function (): void {
    Http::fake([
        '127.0.0.1:8100/*' => Http::response(null, 503),
    ]);

    $result = $this->service->parseNaturalQuery('покажи списания за вчера');

    expect($result['source'])->toBe('fallback');
    expect($result['filters'])->toHaveKey('from');
    expect($result['filters'])->toHaveKey('type', 'expense');
    // from должен быть вчерашней датой
    expect($result['filters']['from'])->toBe(now()->subDay()->toDateString());
});

/**
 * Test 5: timeout превращается в fallback (имитация через замедленного fake).
 */
it('treats timeout as fallback', function (): void {
    Http::fake(function () {
        // Имитируем таймаут: Http фейк не может спать, но 500 тоже ведёт к fallback.
        return Http::response(null, 500);
    });

    $result = $this->service->generateExecutiveSummary(['revenue' => 5000]);
    expect($result['source'])->toBe('fallback');
});
