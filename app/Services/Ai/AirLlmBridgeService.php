<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Ai;

use Autometria\Exceptions\Domain\AiEngineUnavailableException;
use Illuminate\Support\Facades\Http;

/**
 * Bridge к локальному AirLLM Python-микросервису (Local Enterprise AI Pack).
 *
 * Все методы имеют fallback: если Python-сервер не запущен или
 * отвечает дольше 5 сек — возвращается аккуратный структурированный
 * ответ БЕЗ ИИ, чтобы не ломать основной поток ERP.
 */
final class AirLlmBridgeService
{
    private const TIMEOUT_SECONDS = 5;

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = (string) config('services.airllm.base_url', 'http://127.0.0.1:8100');
    }

    /**
     * Генерация исполнительной сводки на русском по метрикам ERP.
     *
     * @param array<string, mixed> $metricsData
     * @return array{text: string, source: 'ai'|'fallback', model: string|null}
     */
    public function generateExecutiveSummary(array $metricsData): array
    {
        $prompt = $this->buildSummaryPrompt($metricsData);

        try {
            $text = $this->callGenerate($prompt, [
                'temperature' => 0.4,
                'max_new_tokens' => 400,
                'system_prompt' => 'Ты — финансовый аналитик ERP AUTOMETRIA. Сформируй краткую исполнительную сводку для владельца бизнеса на русском языке: выручка, прибыль, маржа, ключевые риски. Без воды.',
            ]);

            return ['text' => $text, 'source' => 'ai', 'model' => $this->modelName()];
        } catch (AiEngineUnavailableException $e) {
            return [
                'text' => $this->fallbackSummary($metricsData),
                'source' => 'fallback',
                'model' => null,
            ];
        }
    }

    /**
     * Перевод естественного языка в структуру фильтров API.
     *
     * @return array{filters: array<string, mixed>, interpretation: string, source: 'ai'|'fallback'}
     */
    public function parseNaturalQuery(string $userPrompt): array
    {
        $prompt = $this->buildNlpPrompt($userPrompt);

        try {
            $raw = $this->callGenerate($prompt, [
                'temperature' => 0.1,
                'max_new_tokens' => 200,
                'system_prompt' => 'Ты преобразуешь запрос пользователя в JSON-фильтры ERP. Ответь ТОЛЬКО валидным JSON без пояснений: {"filters": {...}, "interpretation": "..."}.',
            ]);

            $parsed = $this->extractJson($raw);
            if ($parsed !== null && isset($parsed['filters'])) {
                return [
                    'filters' => $parsed['filters'],
                    'interpretation' => $parsed['interpretation'] ?? $userPrompt,
                    'source' => 'ai',
                ];
            }

            throw new AiEngineUnavailableException('invalid ai json');
        } catch (AiEngineUnavailableException $e) {
            return [
                'filters' => $this->fallbackFilters($userPrompt),
                'interpretation' => 'Эвристический разбор (AI недоступен): '.$userPrompt,
                'source' => 'fallback',
            ];
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function callGenerate(string $prompt, array $options): string
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->post($this->baseUrl.'/v1/generate', array_merge([
                'prompt' => $prompt,
            ], $options));

        if (! $response->successful()) {
            throw new AiEngineUnavailableException('airllm http '.$response->status());
        }

        $body = $response->json();
        $text = $body['text'] ?? '';

        return is_string($text) ? $text : '';
    }

    private function modelName(): ?string
    {
        try {
            $health = Http::timeout(2)->get($this->baseUrl.'/health');
            if ($health->successful()) {
                return $health->json('model');
            }
        } catch (\Throwable) {
            // no-op
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function buildSummaryPrompt(array $metrics): string
    {
        return 'Метрики ERP за период: '.json_encode($metrics, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    private function buildNlpPrompt(string $userPrompt): string
    {
        return 'Запрос: '.$userPrompt."\nПреобразуй в фильтры для API (допустимые ключи: from, to, warehouse_id, product_id, type, status).";
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function fallbackSummary(array $metrics): string
    {
        $revenue = $metrics['revenue'] ?? $metrics['net_revenue'] ?? 0;
        $profit = $metrics['gross_profit'] ?? $metrics['net_profit'] ?? 0;
        $margin = $metrics['margin_pct'] ?? 0;
        $orders = $metrics['orders_count'] ?? 0;

        return sprintf(
            "Сводка (авто, без ИИ):\n— Выручка: %s\n— Валовая прибыль: %s\n— Маржа: %s%%\n— Заказов: %s\nПримечание: локальный AI-движок недоступен, показаны сырые метрики.",
            number_format((float) $revenue, 2, '.', ' '),
            number_format((float) $profit, 2, '.', ' '),
            number_format((float) $margin, 2, '.', ' '),
            (int) $orders,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackFilters(string $userPrompt): array
    {
        $filters = [];
        $lower = mb_strtolower($userPrompt);

        if (preg_match('/вчера/', $lower)) {
            $filters['from'] = now()->subDay()->toDateString();
            $filters['to'] = now()->subDay()->toDateString();
        } elseif (preg_match('/сегодня/', $lower)) {
            $filters['from'] = now()->toDateString();
            $filters['to'] = now()->toDateString();
        } elseif (preg_match('/недел/', $lower)) {
            $filters['from'] = now()->subWeek()->toDateString();
            $filters['to'] = now()->toDateString();
        } elseif (preg_match('/месяц/', $lower)) {
            $filters['from'] = now()->subMonth()->toDateString();
            $filters['to'] = now()->toDateString();
        }

        if (preg_match('/списан/', $lower) || preg_match('/расход/', $lower)) {
            $filters['type'] = 'expense';
        }
        if (preg_match('/продаж/', $lower)) {
            $filters['type'] = 'sale';
        }
        if (preg_match('/стокаут|сток.?аут|дефицит|перезаказ|rop/', $lower)) {
            $filters['severity'] = 'critical';
            $filters['view'] = 'demand_forecast';
        }

        return $filters;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractJson(string $text): ?array
    {
        // Ищем первый JSON-объект в ответе (модель может добавить текст вокруг).
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
