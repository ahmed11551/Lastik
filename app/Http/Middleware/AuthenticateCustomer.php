<?php

declare(strict_types=1);

namespace Autometria\Http\Middleware;

use Autometria\Services\Portal\CustomerPortalService;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateCustomer
{
    public function __construct(private readonly CustomerPortalService $portal) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?: $request->header('X-Portal-Token');
        $customer = is_string($plain) && $plain !== '' ? $this->portal->resolveToken($plain) : null;

        if ($customer === null) {
            throw new AuthenticationException('Invalid or expired customer portal token.');
        }

        set_current_tenant_id((int) $customer->tenant_id);
        $request->attributes->set('customer', $customer);
        $request->attributes->set('tenant_id', (int) $customer->tenant_id);

        return $next($request);
    }
}
