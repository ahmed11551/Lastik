/**
 * Client-side GS1 DataMatrix helpers (Честный Знак)
 */
export function looksLikeDataMatrix(raw: string): boolean {
  const c = String(raw || '').replace(/\u001d/g, '').trim()
  return /^01\d{14}21.+/u.test(c)
}
