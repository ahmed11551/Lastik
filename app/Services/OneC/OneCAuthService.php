<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Services\OneC
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\OneC;

use Illuminate\Support\Facades\Hash;

/**
 * Аутентификация 1С по протоколу CommerceML.
 *
 * 1С передаёт HTTP Basic Auth (логин/пароль сервисного аккаунта из
 * config('services.one_c')). При успехе генерируется сессионный токен,
 * который 1С передаёт через cookie `1c_session` в последующих запросах.
 */
final class OneCAuthService
{
    public function __construct(
        private readonly ?string $login = null,
        private readonly ?string $passwordHash = null,
    ) {}

    /**
     * Проверить Basic Auth из запроса. Возвращает сессионный токен или null.
     */
    public function authenticate(string $user, string $pass): ?string
    {
        $login = $this->login ?? (string) config('services.one_c.login');
        $passwordHash = $this->passwordHash ?? (string) config('services.one_c.password');

        if ($login === '' || $passwordHash === '') {
            return null;
        }

        $ok = false;
        if ($passwordHash === $pass) {
            // Совпадение plaintext (упрощённый режим / тесты).
            $ok = true;
        } elseif (str_starts_with($passwordHash, '$2y$') || str_starts_with($passwordHash, '$argon')) {
            // Bcrypt/Argon2 хэш.
            $ok = Hash::check($pass, $passwordHash);
        }

        if ($user !== $login || ! $ok) {
            return null;
        }

        return (string) \Illuminate\Support\Str::random(40);
    }

    /**
     * Проверить переданный в cookie сессионный токен (для режимов после checkauth).
     */
    public function validateSession(?string $token): bool
    {
        return $token !== null && $token !== '' && strlen($token) >= 32;
    }
}
