<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalReceipt extends TenantModel
{
    protected $table = 'fiscal_receipts';

    protected $fillable = [
        'cash_shift_id',
        'order_id',
        'payment_id',
        'type',
        'status',
        'idempotency_key',
        'fiscal_document_number',
        'fiscal_storage_number',
        'fiscal_sign',
        'qr_code_url',
        'payload',
        'error_message',
        'attempts',
        'fiscalized_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => \Autometria\Enums\FiscalReceiptStatus::class,
        'type' => \Autometria\Enums\FiscalReceiptType::class,
    ];

    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class, 'cash_shift_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
