/**
 * Re-export — audit / docs path `services/gs1Parser`.
 * Canonical implementation: `@/autometria/utils/gs1Parser`.
 */
export {
  parseGs1,
  extractGtin,
  extractQuantity,
  normalizeQuantityString,
  type Gs1Field,
  type ParsedGs1,
} from '../autometria/utils/gs1Parser'
