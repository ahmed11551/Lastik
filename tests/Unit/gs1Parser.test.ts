/**
 * AUTOMETRIA ERP — Unit tests for gs1Parser (run via tsx).
 * Covers: Chechny Znak DataMatrix, bare EAN-13, composite GS1 with dates/batch/qty,
 * FNC1/GS-delimited form.
 */
import assert from 'node:assert/strict'
import { parseGs1, extractGtin, extractQuantity } from '../../resources/js/autometria/utils/gs1Parser'

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

console.log('gs1Parser tests:')

// 1. Честный Знак DataMatrix (grouped form)
test('parses Chechny Znak DataMatrix (01)(21)(17)(10)', () => {
  const r = parseGs1('(01)04612345678901(21)ABC123456789(17)251231(10)LOT2026')
  assert.equal(r.gtin, '04612345678901')
  assert.equal(r.serial, 'ABC123456789')
  assert.equal(r.batch, 'LOT2026')
  assert.equal(r.expirationDate, '2025-12-31')
})

// 2. Bare EAN-13 (no AI) -> treated as GTIN
test('parses bare EAN-13 as GTIN', () => {
  const r = parseGs1('4601234567890')
  assert.equal(r.gtin, '4601234567890')
})

// 3. Composite GS1 with production(11), expiration(17), batch(10), qty(37)
test('parses composite GS1 with dates, batch and quantity', () => {
  const r = parseGs1('(01)1234567890123(11)200101(17)251231(10)BATCH01(37)12')
  assert.equal(r.gtin, '1234567890123')
  assert.equal(r.productionDate, '2020-01-01')
  assert.equal(r.expirationDate, '2025-12-31')
  assert.equal(r.batch, 'BATCH01')
  assert.equal(r.quantity, '12')
})

// 4. Quantity as fractional string (BCMath-safe, not float)
test('keeps quantity as exact string (no float loss)', () => {
  const r = parseGs1('(01)1234567890123(37)10.500')
  assert.equal(r.quantity, '10.500')
  assert.equal(typeof r.quantity, 'string')
})

test('normalizes comma decimal quantity as string', () => {
  const r = parseGs1('(01)1234567890123(37)10,500')
  assert.equal(r.quantity, '10.500')
  assert.equal(typeof r.quantity, 'string')
})

// 5. FNC1 / GS (\x1d) delimited form
test('parses GS/FNC1 delimited payload', () => {
  const GS = '\u001d'
  const r = parseGs1(`01${GS}04612345678901${GS}21${GS}SERIAL99${GS}17${GS}251231`, GS)
  assert.equal(r.gtin, '04612345678901')
  assert.equal(r.serial, 'SERIAL99')
  assert.equal(r.expirationDate, '2025-12-31')
})

// 6. Invalid date -> null (not crash)
test('invalid YYMMDD yields null date', () => {
  const r = parseGs1('(01)1234567890123(17)999999')
  assert.equal(r.expirationDate, null)
})

// 7. extractGtin / extractQuantity convenience
test('convenience extractors', () => {
  const raw = '(01)04612345678901(37)5'
  assert.equal(extractGtin(raw), '04612345678901')
  assert.equal(extractQuantity(raw), '5')
})

// 8. Unknown AI preserved in fields
test('unknown AI stored in fields', () => {
  const r = parseGs1('(01)04612345678901(90)CUSTOM')
  assert.ok(r.fields.some((f) => f.ai === '90' && f.value === 'CUSTOM'))
})

console.log(`\n${passed} passed`)
if (process.exitCode === 1) console.log('SOME TESTS FAILED')
else console.log('ALL GREEN')
