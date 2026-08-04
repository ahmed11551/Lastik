<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * DemoAuthController — авторизация в 1 клик для демо-стенда.
 * Работает ТОЛЬКО при DEMO_MODE=true (config/app.php + .env DEMO_MODE=1).
 * В прод-среде возвращает 403. Не трогает парольную логику реального AuthController.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

final class DemoAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        if (! Config::get('app.demo_mode', false)) {
            return response()->json(['message' => 'Demo mode disabled'], 403);
        }

        $email = (string) $request->input('email', 'admin@demo.local');

        $user = User::query()->withoutGlobalScopes()
            ->where('email', $email)
            ->first();

        if ($user === null) {
            return response()->json(['message' => 'Demo user not found. Run: php artisan db:seed --class=DemoSeeder'], 404);
        }

        $token = $user->createToken('demo')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->only('id', 'name', 'email', 'role_id'),
        ]);
    }
}
