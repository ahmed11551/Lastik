<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Procurement;

use Autometria\Models\PurchaseOrderDraft;
use Autometria\Models\Supplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Supplier dispatching: Email (HTML + CSV), CSV export, Telegram (optional).
 */
final class SupplierDispatchService
{
    /**
     * Render a printable HTML purchase order.
     */
    public function renderHtml(PurchaseOrderDraft $draft): string
    {
        $draft->load('supplier', 'items.product');
        $rows = '';
        foreach ($draft->items as $item) {
            $rows .= '<tr>'
                .'<td>'.htmlspecialchars((string) ($item->product?->name ?? $item->product_id)).'</td>'
                .'<td>'.htmlspecialchars((string) $item->suggested_qty).'</td>'
                .'<td>'.htmlspecialchars((string) $item->unit_cost).'</td>'
                .'<td>'.htmlspecialchars((string) $item->subtotal).'</td>'
                .'</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Заказ поставщику #'
            .$draft->id.'</title></head><body>'
            .'<h2>Заказ поставщику № '.$draft->id.'</h2>'
            .'<p>Поставщик: '.htmlspecialchars((string) ($draft->supplier?->name ?? '')).'</p>'
            .'<p>Сумма: '.htmlspecialchars((string) $draft->total_amount).' '.$draft->currency.'</p>'
            .'<table border="1" cellpadding="6" cellspacing="0">'
            .'<thead><tr><th>Товар</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table>'
            .'<p>'.htmlspecialchars((string) ($draft->notes ?? '')).'</p>'
            .'</body></html>';
    }

    /**
     * Generate native CSV for the draft.
     */
    public function exportToCsv(int $draftId): string
    {
        $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()
            ->with('items.product')->findOrFail($draftId);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['product_id', 'product_name', 'suggested_qty', 'unit_cost', 'subtotal']);
        foreach ($draft->items as $item) {
            fputcsv($handle, [
                $item->product_id,
                $item->product?->name ?? '',
                $item->suggested_qty,
                $item->unit_cost,
                $item->subtotal,
            ]);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Send the draft to the supplier by email (HTML body + CSV attachment).
     */
    public function sendByEmail(int $draftId): bool
    {
        $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()
            ->with('supplier')->findOrFail($draftId);

        $supplier = $draft->supplier;
        if (! $supplier instanceof Supplier || empty($supplier->email)) {
            return false;
        }

        $html = $this->renderHtml($draft);
        $csv = $this->exportToCsv($draftId);
        $filename = 'purchase_order_'.$draft->id.'.csv';

        Mail::to($supplier->email)->send(
            new \Autometria\Mail\PurchaseOrderDraftMail($html, $csv, $filename)
        );

        $draft->update(['status' => 'sent']);

        return true;
    }

    /**
     * Optional Telegram dispatch via Bot API (no-op if not configured).
     */
    public function sendByTelegram(int $draftId): bool
    {
        $token = (string) config('services.telegram.bot_token', '');
        $chatId = (string) config('services.telegram.purchase_chat_id', '');
        if ($token === '' || $chatId === '') {
            return false;
        }

        $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()
            ->with('supplier', 'items.product')->findOrFail($draftId);

        $lines = ["📦 Заказ поставщику № {$draft->id}"];
        $lines[] = 'Поставщик: '.($draft->supplier?->name ?? '');
        foreach ($draft->items as $item) {
            $lines[] = '- '.($item->product?->name ?? $item->product_id).': '.$item->suggested_qty.' × '.$item->unit_cost;
        }
        $lines[] = 'Сумма: '.$draft->total_amount.' '.$draft->currency;

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'text' => implode("\n", $lines),
            'parse_mode' => 'UTF-8',
        ]);

        if ($response->successful()) {
            $draft->update(['status' => 'sent']);

            return true;
        }

        return false;
    }
}
