<?php

use Illuminate\Support\Facades\Broadcast;

/*
 * Private tenant channels for Reverb (POS / WMS live updates).
 */
Broadcast::channel('tenant.{tenantId}.stock', function ($user, int $tenantId) {
    return (int) ($user->tenant_id ?? 0) === $tenantId;
});

Broadcast::channel('tenant.{tenantId}.fiscal', function ($user, int $tenantId) {
    return (int) ($user->tenant_id ?? 0) === $tenantId;
});
