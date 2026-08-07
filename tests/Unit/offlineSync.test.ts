/**
 * AUTOMETRIA ERP — Unit tests for offline sync engine (run via tsx).
 * Covers: idempotency outcome classification (offline -> online -> retry upon error).
 */
import assert from 'node:assert/strict'
import { classifySyncStatus } from '../../resources/js/composables/syncStatus.ts'

let passed = 0
function test(name: string, fn: () => void): void {
  try {
    fn()
    passed++
    console.log(`  ✓ ${name}`)
  } catch (e) {
    console.error(`  ✗ ${name}`)
    console.error(`    ${(e as Error).message}`)
    process.exitCode = 1
  }
}

console.log('offlineSync tests:')

// 1. 201 Created -> synced (idempotent success)
test('201 -> synced', () => {
  assert.equal(classifySyncStatus(201), 'synced')
})

// 2. 200 OK (already processed) -> synced (no duplicate)
test('200 -> synced', () => {
  assert.equal(classifySyncStatus(200), 'synced')
})

// 3. 409 Conflict (idempotency key seen) -> synced (safe dedupe)
test('409 -> synced', () => {
  assert.equal(classifySyncStatus(409), 'synced')
})

// 4. 500 Server Error -> retry (keep in queue, do not fail)
test('500 -> retry', () => {
  assert.equal(classifySyncStatus(500), 'retry')
})

// 5. 429 Too Many Requests -> retry (rate limit, backoff)
test('429 -> retry', () => {
  assert.equal(classifySyncStatus(429), 'retry')
})

// 6. 408 Request Timeout -> retry
test('408 -> retry', () => {
  assert.equal(classifySyncStatus(408), 'retry')
})

// 7. undefined (network error / offline) -> retry
test('undefined -> retry', () => {
  assert.equal(classifySyncStatus(undefined), 'retry')
})

// 8. 400 Bad Request (client error) -> failed (poison pill, move to FAILED)
test('400 -> failed', () => {
  assert.equal(classifySyncStatus(400), 'failed')
})

// 9. 422 Unprocessable -> failed (do not retry bad payload)
test('422 -> failed', () => {
  assert.equal(classifySyncStatus(422), 'failed')
})

// 10. 401 Unauthorized -> failed (config issue, not transient)
test('401 -> failed', () => {
  assert.equal(classifySyncStatus(401), 'failed')
})

console.log(`\n${passed} passed`)
if (process.exitCode === 1) console.log('SOME TESTS FAILED')
else console.log('ALL GREEN')
