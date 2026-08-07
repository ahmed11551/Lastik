<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Console\Commands;

use Autometria\Services\Ai\AirLlmBridgeService;
use Autometria\Services\Analytics\AnalyticsReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * artisan ai:summary — ежедневная ИИ-сводка в Telegram-канал владельца.
 */
final class GenerateAiSummaryCommand extends Command
{
    protected $signature = 'ai:summary
        {--date= : дата (Y-m-d), по умолчанию вчера}
        {--tenant= : ID тенанта, иначе все активные}
        {--dry-run : только вывод в консоль, без отправки в Telegram}';

    protected $description = 'Сформировать и отправить ИИ-сводку за день в Telegram';

    public function handle(AnalyticsReportService $analytics, AirLlmBridgeService $bridge): int
    {
        $date = $this->option('date') ?: now()->subDay()->toDateString();
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $tenantIds = $tenantId !== null ? [$tenantId] : \Autometria\Models\Tenant::query()->where('is_active', true)->pluck('id')->all();

        foreach ($tenantIds as $tid) {
            $metrics = $analytics->getDashboardSummary($tid, $date, $date, null);
            $result = $bridge->generateExecutiveSummary($metrics);

            $this->info("[{$tid}] {$date} — source={$result['source']}");
            $this->line($result['text']);

            if ($this->option('dry-run')) {
                continue;
            }

            $this->sendToTelegram($result['text']);
        }

        return self::SUCCESS;
    }

    private function sendToTelegram(string $text): void
    {
        $token = (string) config('services.telegram.bot_token', '');
        $chatId = (string) config('services.telegram.ai_chat_id', config('services.telegram.purchase_chat_id', ''));

        if ($token === '' || $chatId === '') {
            $this->warn('Telegram не настроен (services.telegram.bot_token / ai_chat_id). Пропуск отправки.');

            return;
        }

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => "📊 AUTOMETRIA AI-сводка\n\n".$text,
            'parse_mode' => 'UTF-8',
        ]);

        if ($response->successful()) {
            $this->info('Отправлено в Telegram.');
        } else {
            $this->error('Ошибка отправки в Telegram: '.$response->status());
        }
    }
}
