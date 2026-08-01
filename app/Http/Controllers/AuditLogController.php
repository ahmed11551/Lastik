<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\AuditLog;
use Autometria\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));
        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));
        $likeOp = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query = AuditLog::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($category !== '' && strtolower($category) !== 'all') {
            $query->where('action', $likeOp, $category.'.%');
        }

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like, $likeOp, $q): void {
                $builder
                    ->where('action', $likeOp, $like)
                    ->orWhere('object_type', $likeOp, $like)
                    ->orWhere('reason', $likeOp, $like)
                    ->orWhere('ip', $likeOp, $like);

                if (ctype_digit($q)) {
                    $builder->orWhere('object_id', (int) $q)
                        ->orWhere('user_id', (int) $q);
                }
            });
        }

        $paginator = $query->paginate($perPage);
        $userIds = collect($paginator->items())->pluck('user_id')->filter()->unique()->values();
        $users = User::query()->withoutGlobalScopes()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email', 'role_id'])
            ->keyBy('id');

        $data = collect($paginator->items())->map(function (AuditLog $log) use ($users): array {
            $user = $users->get($log->user_id);
            $action = (string) $log->action;
            $category = strtoupper(strtok($action, '.') ?: 'SYSTEM');

            return [
                'id' => 'aud-'.$log->id,
                'raw_id' => $log->id,
                'ts' => optional($log->created_at)?->timezone(config('app.timezone'))->format('d.m H:i:s'),
                'created_at' => optional($log->created_at)?->toIso8601String(),
                'action' => $action,
                'category' => $category,
                'entity' => (string) ($log->object_type ?: '—'),
                'entityId' => (string) ($log->object_id ?? '—'),
                'user' => $user?->name ?? 'system',
                'role' => (string) ($user?->role_id ?? '—'),
                'ip' => (string) ($log->ip ?: '—'),
                'details' => $this->summarize($log),
                'reason' => (string) ($log->reason ?: '—'),
                'severity' => $this->severityFor($action),
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function summarize(AuditLog $log): string
    {
        $new = is_array($log->new) ? $log->new : [];
        if (isset($new['message']) && is_string($new['message'])) {
            return $new['message'];
        }
        if ($new !== []) {
            return json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        return (string) ($log->action ?: '—');
    }

    private function severityFor(string $action): string
    {
        $a = strtolower($action);
        if (str_contains($a, 'cancel') || str_contains($a, 'correct') || str_contains($a, 'conflict')) {
            return 'warning';
        }
        if (str_contains($a, 'support') || str_contains($a, 'denied') || str_contains($a, 'fail')) {
            return 'danger';
        }
        if (str_contains($a, 'close')) {
            return 'closed';
        }
        if (str_contains($a, 'open') || str_contains($a, 'created') || str_contains($a, 'accept')) {
            return 'success';
        }

        return 'info';
    }
}
