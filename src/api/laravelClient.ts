/**
 * Thin Laravel API client for the React shell.
 * Set VITE_API_BASE (e.g. /api/v1) to talk to Laravel.
 * When unset, helpers no-op / return null so Express mock keeps working.
 */

const base = (): string => {
  const raw = (import.meta as ImportMeta & { env?: Record<string, string> }).env?.VITE_API_BASE ?? '';
  return raw.replace(/\/$/, '');
};

export const isLaravelApiEnabled = (): boolean => base().length > 0;

type ApiContext = {
  tenantId?: string | number | null;
  tenantSlug?: string | null;
  locationId?: string | number | null;
};

let apiContext: ApiContext = {};

/** Call from AppContext whenever active tenant/location changes. */
export function setLaravelContext(ctx: ApiContext): void {
  apiContext = { ...ctx };
}

export function getLaravelContext(): ApiContext {
  return { ...apiContext };
}

export type LaravelValidationErrors = Record<string, string[]>;

export class LaravelApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  readonly validationErrors: LaravelValidationErrors | null;

  constructor(status: number, message: string, body: unknown = null) {
    super(message);
    this.name = 'LaravelApiError';
    this.status = status;
    this.body = body;
    this.validationErrors =
      status === 422 && body && typeof body === 'object' && 'errors' in (body as object)
        ? ((body as { errors: LaravelValidationErrors }).errors ?? null)
        : null;
  }

  get isUnauthenticated(): boolean {
    return this.status === 401;
  }

  get isForbidden(): boolean {
    return this.status === 403;
  }

  get isValidation(): boolean {
    return this.status === 422;
  }

  /** Short RU message for UI banners. */
  toUserMessage(): string {
    if (this.status === 401) return 'Сессия истекла — войдите снова (401).';
    if (this.status === 403) return 'Недостаточно прав для этого действия (403).';
    if (this.status === 422) {
      const first = this.validationErrors
        ? Object.values(this.validationErrors).flat()[0]
        : null;
      return first ? `Ошибка валидации: ${first}` : 'Ошибка валидации (422).';
    }
    return this.message || `Ошибка API (${this.status}).`;
  }
}

export type LaravelSearchResult = {
  query: string;
  customers: Array<{ id: number; name: string | null; phone: string | null }>;
  vehicles: Array<{ id: number; plate: string | null; brand: string | null; model: string | null }>;
  orders: Array<{ id: number; number: string | null; status: string | null; total: number | string | null }>;
};

export type LaravelTvBoard = {
  location_id: number | null;
  columns: {
    queue: Array<Record<string, unknown>>;
    in_progress: Array<Record<string, unknown>>;
    ready: Array<Record<string, unknown>>;
  };
};

async function parseBody(res: Response): Promise<unknown> {
  const text = await res.text();
  if (!text) return null;
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

function messageFromBody(status: number, body: unknown): string {
  if (body && typeof body === 'object') {
    const o = body as { message?: string; error?: string };
    if (typeof o.message === 'string' && o.message) return o.message;
    if (typeof o.error === 'string' && o.error) return o.error;
  }
  if (typeof body === 'string' && body.trim()) return body.slice(0, 200);
  return `Laravel API ${status}`;
}

async function laravelFetch<T>(path: string, init?: RequestInit): Promise<T | null> {
  const root = base();
  if (!root) return null;

  const headers = new Headers(init?.headers);
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');
  if (!headers.has('X-Requested-With')) headers.set('X-Requested-With', 'XMLHttpRequest');
  if (!headers.has('Content-Type') && init?.body) headers.set('Content-Type', 'application/json');

  if (apiContext.tenantId != null && apiContext.tenantId !== '' && !headers.has('X-Tenant-ID')) {
    headers.set('X-Tenant-ID', String(apiContext.tenantId));
  }
  if (apiContext.tenantSlug && !headers.has('X-Tenant-Slug')) {
    headers.set('X-Tenant-Slug', String(apiContext.tenantSlug));
  }
  if (apiContext.locationId != null && apiContext.locationId !== '' && !headers.has('X-Location-ID')) {
    headers.set('X-Location-ID', String(apiContext.locationId));
  }

  const res = await fetch(`${root}${path.startsWith('/') ? path : `/${path}`}`, {
    credentials: 'include',
    ...init,
    headers,
  });

  if (!res.ok) {
    const body = await parseBody(res);
    throw new LaravelApiError(res.status, messageFromBody(res.status, body), body);
  }

  const json = await res.json();
  return (json?.data ?? json) as T;
}

export async function searchLaravel(q: string): Promise<LaravelSearchResult | null> {
  if (!isLaravelApiEnabled() || q.trim().length < 2) return null;
  return laravelFetch<LaravelSearchResult>(`/search?q=${encodeURIComponent(q.trim())}`);
}

export async function fetchTvBoard(locationId?: string | number | null): Promise<LaravelTvBoard | null> {
  if (!isLaravelApiEnabled()) return null;
  const qs = locationId != null && locationId !== '' ? `?location_id=${encodeURIComponent(String(locationId))}` : '';
  return laravelFetch<LaravelTvBoard>(`/tv/board${qs}`);
}
