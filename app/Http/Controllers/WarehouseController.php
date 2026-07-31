<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Warehouse;

class WarehouseController extends Controller
{
    public function index(): array
    {
        return ['data' => Warehouse::all()];
    }
}
