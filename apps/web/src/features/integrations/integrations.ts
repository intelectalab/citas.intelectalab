export type Level = 'none' | 'read' | 'write';

export interface ApiResource {
  key: string;
  label: string;
  levels: Exclude<Level, 'none'>[];
  hint: string;
}

export interface ApiKey {
  id: string;
  name: string;
  prefix: string;
  scopes: Record<string, Exclude<Level, 'none'>>;
  created_by: string | null;
  created_at: string | null;
  last_used_at: string | null;
  last_used_ip: string | null;
  revoked_at: string | null;
  revoked_by: string | null;
}

export interface Webhook {
  id: string;
  url: string;
  description: string | null;
  events: string[];
  active: boolean;
  created_at: string;
  last: { status: DeliveryStatus; status_label: string; at: string } | null;
}

export type DeliveryStatus = 'pending' | 'retrying' | 'sent' | 'failed';

export interface Delivery {
  id: string;
  event: string;
  event_label: string;
  event_id: string;
  status: DeliveryStatus;
  status_label: string;
  attempts: number;
  response_code: number | null;
  response_body: string | null;
  error: string | null;
  duration_ms: number | null;
  payload: unknown;
  next_attempt_at: string | null;
  delivered_at: string | null;
  created_at: string;
}

export interface ApiOverview {
  ready: boolean;
  message?: string;
  base_url: string;
  per_minute: number;
  resources: ApiResource[];
  levels: Record<Level, string>;
  events: { key: string; label: string }[];
  keys: ApiKey[];
  webhooks: Webhook[];
  max_attempts: number;
}

export interface CalendarFeed {
  id: string;
  detail: 'service' | 'first_name' | 'full';
  url: string;
  webcal_url: string;
  last_accessed_at: string | null;
  created_at: string;
}

export interface CalendarOwner {
  id: string;
  name: string;
  feed: CalendarFeed | null;
}

export interface CalendarPayload {
  details: Record<CalendarFeed['detail'], string>;
  professionals: CalendarOwner[];
  branches: CalendarOwner[];
}

export const DELIVERY_TONES: Record<DeliveryStatus, string> = {
  pending: 'bg-slate-100 text-slate-600',
  retrying: 'bg-amber-50 text-amber-800',
  sent: 'bg-emerald-50 text-emerald-700',
  failed: 'bg-rose-50 text-rose-700',
};

export const copyText = async (text: string): Promise<boolean> => {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
};
