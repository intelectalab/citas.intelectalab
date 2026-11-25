export type AnnouncementKind = 'news' | 'maintenance' | 'important';

export interface Announcement {
  id: string;
  title: string;
  body: string;
  kind: AnnouncementKind;
  kind_label: string;
  audience: 'all' | 'plans' | 'business_types';
  audience_label: string;
  plan_ids: string[];
  business_type_ids: string[];
  starts_at: string;
  ends_at: string | null;
  state: 'scheduled' | 'live' | 'ended';
  published?: boolean;
  link_url?: string | null;
  link_label?: string | null;
  reach: number;
  dismissals: number;
}

export interface FeedItem {
  id: string;
  title: string;
  body: string;
  kind: AnnouncementKind;
  kind_label: string;
  starts_at: string;
  ends_at: string | null;
  link_url?: string | null;
  link_label?: string | null;
  dismissed?: boolean;
}

export const KIND_TONE: Record<AnnouncementKind, string> = {
  news: 'bg-sky-50 text-sky-700',
  maintenance: 'bg-amber-50 text-amber-800',
  important: 'bg-rose-50 text-rose-700',
};

export const KIND_CARD: Record<AnnouncementKind, string> = {
  news: 'border-sky-200 bg-sky-50/60',
  maintenance: 'border-amber-200 bg-amber-50/70',
  important: 'border-rose-200 bg-rose-50/60',
};
