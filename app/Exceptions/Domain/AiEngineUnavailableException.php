<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Exceptions\Domain
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Exceptions\Domain;

/**
 * Локальный AI-движок (AirLLM) недоступен: сервер не запущен,
 * таймаут запроса (>5с) или невалидный ответ. Bridge переходит
 * в fallback-режим без ИИ.
 */
final class AiEngineUnavailableException extends \RuntimeException
{
}
