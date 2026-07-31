<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! auth()->attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $user = auth()->user();

        $token = $user->createToken('default')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->only('id', 'name', 'email', 'role_id'),
        ]);
    }
}
