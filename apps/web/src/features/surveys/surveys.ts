export interface SurveyConfig {
  enabled: boolean;
  delay_hours: number;
  google_min: number;
  publish_reviews: boolean;
  valid_days: number;
  google_url: string | null;
}

export interface Survey {
  id: string;
  status: 'scheduled' | 'sent' | 'answered' | 'skipped' | 'expired';
  status_label: string;
  skip_reason: string | null;
  score: number | null;
  comment: string | null;
  publish_consent: boolean;
  published: boolean;
  hidden: boolean;
  reply: string | null;
  google_invited: boolean;
  google_clicked: boolean;
  follow_up: 'pending' | 'contacted' | 'resolved' | null;
  follow_up_label: string | null;
  follow_up_note: string | null;
  follow_up_at: string | null;
  follow_up_by: string | null;
  send_at: string | null;
  sent_at: string | null;
  answered_at: string | null;
  expires_at: string | null;
  appointment: { id: string; code: string | null; starts_at: string | null };
  customer: { id: string; name: string; phone: string | null };
  professional: { id: string; name: string | null } | null;
  service: { id: string; name: string | null } | null;
  branch: { id: string; name: string | null } | null;
}

export interface ScoreStats {
  answered: number;
  promoters: number;
  passives: number;
  detractors: number;
  average: number | null;
  nps: number | null;
}

export interface SurveySummary extends ScoreStats {
  delivered: number;
  skipped: number;
  scheduled: number;
  response_rate: number | null;
  pending: number;
  google_invited: number;
  google_clicked: number;
  published: number;
}

export interface Breakdown extends ScoreStats {
  id: string | null;
  name: string;
}

export const scoreTone = (score: number | null) =>
  score === null ? 'bg-slate-100 text-slate-600' : score >= 9 ? 'bg-emerald-50 text-emerald-700' : score >= 7 ? 'bg-amber-50 text-amber-800' : 'bg-rose-50 text-rose-700';

export const FOLLOW_TONE: Record<string, string> = {
  pending: 'bg-rose-50 text-rose-700',
  contacted: 'bg-amber-50 text-amber-800',
  resolved: 'bg-emerald-50 text-emerald-700',
};

export const npsText = (value: number | null) => (value === null ? '—' : `${value > 0 ? '+' : ''}${value}`);

export const shortDate = (value: string | null) => {
  if (!value) return '—';
  const [day, time] = value.split('T');
  const [y, m, d] = day.split('-');
  return `${d}/${m}/${y}${time ? ` ${time}` : ''}`;
};
