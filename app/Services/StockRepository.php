<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Stock;

class StockRepository
{
    public function findActive(Stock $stock): ?Stock
    {
        return $stock->first();
    }
}
