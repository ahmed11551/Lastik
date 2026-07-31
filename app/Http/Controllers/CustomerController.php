<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;

class CustomerController extends Controller
{
    public function index(): array
    {
        return ['data' => Customer::all()];
    }
}
