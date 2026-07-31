<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Vehicle;

class VehicleController extends Controller
{
    public function index(): array
    {
        return ['data' => Vehicle::all()];
    }
}
