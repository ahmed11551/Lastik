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
        'operation',
        'status',
        'idempotency_key',
        'driver_request_id',
        'total_amount',
        'payload_snapshot',
        'fn_number',
        'fd_number',
        'fp_value',
        'qr_code_url',
        'locked_at',
        'attempt',
        'last_error',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'total_amount' => 'decimal:2',
        'fd_number' => 'integer',
        'status' => \Autometria\Enums\FiscalReceiptStatus::class,
        'operation' => \Autometria\Enums\FiscalReceiptType::class,
        'locked_at' => 'immutable_datetime',
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
