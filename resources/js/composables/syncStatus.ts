/**
 * AUTOMETRIA ERP — Pure sync-status classifier (no IO, framework-agnostic).
 * Shared by useOfflineSync and unit tests.
 */

export type SyncOutcome = 'synced' | 'retry' | 'failed'

/**
 * Map an HTTP status (or absence thereof) to a sync outcome.
 *  - synced: 200/201/409 (idempotent success — never duplicate)
 *  - failed: 4xx except 408/429 (poison pill → move to FAILED)
 *  - retry: network error (undefined), 408, 429, 5xx (transient → keep queue)
 */
export function classifySyncStatus(status: number | undefined): SyncOutcome {
  if (status === 200 || status === 201 || status === 409) return 'synced'
  if (status && status >= 400 && status < 500 && status !== 408 && status !== 429) {
    return 'failed'
  }
  return 'retry'
}
