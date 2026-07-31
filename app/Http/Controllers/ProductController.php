<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ProductService;

class ProductController extends Controller
{
    public function index(): array
    {
        return ['data' => ProductService::all()];
    }
}
