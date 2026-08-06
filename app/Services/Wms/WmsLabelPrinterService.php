<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Wms;

use Autometria\Models\Wms\StockBatch;
use Autometria\Models\Wms\WarehouseBin;

/**
 * ZPL / TSPL label templates for warehouse bins and stock batches (thermo printers).
 */
final class WmsLabelPrinterService
{
    /**
     * Zebra ZPL bin label (~50×30 mm).
     */
    public function binLabelZpl(WarehouseBin $bin, string $tenantSlug = 'AUTOMETRIA'): string
    {
        $code = $this->sanitize($bin->code);
        $zone = $this->sanitize($bin->zone);
        $slug = $this->sanitize($tenantSlug);

        return implode("\n", [
            '^XA',
            '^CF0,30',
            "^FO30,20^FD{$slug}^FS",
            '^CF0,40',
            "^FO30,60^FD BIN {$code}^FS",
            '^CF0,28',
            "^FO30,120^FD ZONE {$zone}^FS",
            "^FO30,170^BY2^BCN,80,Y,N,N^FD{$code}^FS",
            '^XZ',
        ])."\n";
    }

    /**
     * TSPL (TSC) bin label.
     */
    public function binLabelTspl(WarehouseBin $bin, string $tenantSlug = 'AUTOMETRIA'): string
    {
        $code = $this->sanitize($bin->code);
        $zone = $this->sanitize($bin->zone);
        $slug = $this->sanitize($tenantSlug);

        return implode("\n", [
            'SIZE 50 mm,30 mm',
            'GAP 2 mm,0',
            'DIRECTION 1',
            'CLS',
            "TEXT 30,20,\"3\",0,1,1,\"{$slug}\"",
            "TEXT 30,60,\"4\",0,1,1,\"BIN {$code}\"",
            "TEXT 30,110,\"2\",0,1,1,\"ZONE {$zone}\"",
            "BARCODE 30,150,\"128\",60,1,0,2,2,\"{$code}\"",
            'PRINT 1',
        ])."\n";
    }

    /**
     * ZPL batch / lot label.
     */
    public function batchLabelZpl(StockBatch $batch, ?WarehouseBin $bin = null): string
    {
        $lot = $this->sanitize((string) ($batch->batch_number ?: $batch->id));
        $serial = $this->sanitize((string) ($batch->serial_number ?: '-'));
        $binCode = $this->sanitize((string) ($bin?->code ?? $batch->warehouse_bin_id ?? '-'));
        $exp = $batch->expiration_date?->format('Y-m-d') ?? '-';
        $qty = $this->sanitize((string) $batch->quantity);

        return implode("\n", [
            '^XA',
            '^CF0,28',
            "^FO30,20^FD LOT {$lot}^FS",
            "^FO30,55^FD SN {$serial}^FS",
            "^FO30,90^FD BIN {$binCode}^FS",
            "^FO30,125^FD EXP {$exp}  QTY {$qty}^FS",
            "^FO30,170^BY2^BCN,70,Y,N,N^FD{$lot}^FS",
            '^XZ',
        ])."\n";
    }

    private function sanitize(string $value): string
    {
        return str_replace(['^', '~', "\n", "\r", '"'], '', $value);
    }
}
