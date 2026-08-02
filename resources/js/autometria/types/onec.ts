/**
 * AUTOMETRIA ERP — 1C / CommerceML sync types
 */

export type OneCSyncStatus = 'pending' | 'processing' | 'completed' | 'failed'

export type OneCChannel = 'auto_1c' | 'manual_upload'

export type OneCFileType = 'import' | 'offers' | 'auto'

export type OneCSyncOptions = {
  update_stocks: boolean
  update_prices: boolean
  create_products: boolean
}

export type OneCCredentials = {
  login: string
  password_set: boolean
  password_hint?: string | null
  /** Returned only once after reset */
  password?: string | null
  exchange_url: string
  options: OneCSyncOptions
}

export type OneCSyncObjects = {
  categories: number
  products: number
  offers: number
  processed: number
  conflicts: number
  skipped: number
}

export type OneCSyncLog = {
  id: number
  source: string
  channel: OneCChannel | string
  file_name?: string | null
  status: OneCSyncStatus | string
  summary?: Record<string, unknown> | null
  objects: OneCSyncObjects
  error_message?: string | null
  errors?: unknown[]
  created_at?: string | null
  updated_at?: string | null
}

export type OneCUploadProgress = {
  jobId: number | null
  fileName: string
  fileType: OneCFileType
  phase: 'idle' | 'uploading' | 'pending' | 'processing' | 'completed' | 'failed'
  percent: number
  processedSkus: number
  error?: string | null
}

export type OneCLogsMeta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type OneCIntegrationState = 'synced' | 'syncing' | 'error' | 'idle'
