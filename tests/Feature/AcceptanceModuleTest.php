<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Module;
use App\Services\ModuleService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.13 / 49.21 — модульность и тестовый модуль.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('49-13-'.uniqid());
});

it('lists modules and can enable then disable demo module without losing settings', function (): void {
    $fx = $this->fx;
    $svc = app(ModuleService::class);

    $list = Module::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->get();

    expect($list)->toHaveCount(1);
    expect($list->first()->slug)->toBe('demo_module');

    $enabled = $svc->enable($fx->tenant->id, 'demo_module', $fx->user->id);
    expect($enabled->status)->toBe(Module::STATUS_ACTIVE);
    expect($enabled->enabled_at)->not->toBeNull();
    expect($enabled->settings['menu']['label'])->toBe('Demo');
    expect($enabled->settings['permissions'])->toContain('demo.view');

    $disabled = $svc->disable($fx->tenant->id, 'demo_module', $fx->user->id);
    expect($disabled->status)->toBe(Module::STATUS_DISABLED);
    expect($disabled->disabled_at)->not->toBeNull();
    // данные сохранены
    expect($disabled->settings['menu']['route'])->toBe('/demo');
    expect($disabled->settings['permissions'])->toContain('demo.view');

    $actions = AuditLog::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->whereIn('action', ['module.enabled', 'module.disabled'])
        ->pluck('action')
        ->all();

    expect($actions)->toContain('module.enabled');
    expect($actions)->toContain('module.disabled');
});
