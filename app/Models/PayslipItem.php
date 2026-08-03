<?php

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayslipItem extends TenantModel
{
    public const TYPE_EARNING = 'EARNING';
    public const TYPE_DEDUCTION = 'DEDUCTION';

    protected $table = 'payslip_items';
    protected $fillable = ['tenant_id', 'payslip_id', 'type', 'label', 'amount', 'source_id'];
    protected $casts = ['amount' => 'decimal:2'];

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }
}
