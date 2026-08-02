/**
 * AUTOMETRIA ERP — 1C / CommerceML API client
 */
import { apiGet, apiPost, apiPut, apiUpload } from './client'
import type {
  OneCCredentials,
  OneCLogsMeta,
  OneCSyncLog,
  OneCSyncOptions,
  OneCFileType,
} from '../types/onec'

export async function apiFetchOneCCredentials(): Promise<OneCCredentials> {
  const payload = await apiGet('/1c/credentials')
  return (payload?.data || payload) as OneCCredentials
}

export async function apiResetOneCCredentials(): Promise<OneCCredentials> {
  const payload = await apiPost('/1c/credentials/reset', {})
  return (payload?.data || payload) as OneCCredentials
}

export async function apiUpdateOneCOptions(options: OneCSyncOptions): Promise<OneCSyncOptions> {
  const payload = await apiPut('/1c/options', options)
  return (payload?.data?.options || options) as OneCSyncOptions
}

export async function apiFetchOneCLogs(
  page = 1,
  filters: { status?: string; channel?: string; per_page?: number } = {},
): Promise<{ data: OneCSyncLog[]; meta: OneCLogsMeta }> {
  const payload = await apiGet('/1c/logs', {
    params: {
      page,
      per_page: filters.per_page ?? 20,
      status: filters.status || undefined,
      channel: filters.channel || undefined,
    },
    silent: true,
  })
  return {
    data: Array.isArray(payload?.data) ? payload.data : [],
    meta: payload?.meta || {
      current_page: page,
      last_page: 1,
      per_page: filters.per_page ?? 20,
      total: 0,
    },
  }
}

export async function apiUploadOneCXml(
  file: File,
  type: OneCFileType = 'auto',
  onUploadProgress?: (percent: number) => void,
): Promise<OneCSyncLog> {
  const fd = new FormData()
  fd.append('file', file)
  fd.append('type', type)
  const payload = await apiUpload('/1c/manual-upload', fd, {
    onUploadProgress: (e: { loaded?: number; total?: number }) => {
      if (!onUploadProgress || !e.total) return
      onUploadProgress(Math.min(99, Math.round((e.loaded / e.total) * 100)))
    },
  })
  return (payload?.data || payload) as OneCSyncLog
}
