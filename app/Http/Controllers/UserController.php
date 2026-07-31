<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;

class UserController extends Controller
{
    public function index(): array
    {
        return ['data' => User::all()];
    }

    public function show(User $user): array
    {
        $this->authorize('view', $user);

        return ['data' => $user];
    }
}
