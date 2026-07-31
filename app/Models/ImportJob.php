<?php

declare(strict_types=1);

namespace App\Models;

class ImportJob extends TenantModel
{
    protected $table = 'import_jobs';

    protected $fillable = [
        'source',
        'status',
        'summary',
        'errors',
        'created_by',
    ];

    protected $casts = [
        'summary' => 'array',
        'errors' => 'array',
        'status' => 'string',
    ];
}
