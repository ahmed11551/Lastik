<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockConflict extends TenantModel
{
    protected $table = 'stock_conflicts';

    protected $fillable = [
        'stock_id',
        'import_job_id',
        'reason',
        'message',
        'detail',
        'resolved',
    ];

    protected $casts = [
        'resolved' => 'boolean',
    ];

    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }
}
