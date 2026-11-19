import type { PublicPlan } from '@/features/subscriptions/components/PlanCard';

export interface SiteFaq {
  q: string;
  a: string;
}

export interface SitePayload {
  product_name: string;
  allow_signup: boolean;
  url: string | null;
  phone: string | null;
  whatsapp: string | null;
  whatsapp_message: string;
  email: string | null;
  seo_title: string | null;
  seo_description: string | null;
  texts: { rubros_title: string; rubros_text: string; pricing_title: string; pricing_text: string; cta_title: string; cta_text: string };
  contact_form: boolean;
  faq: SiteFaq[];
  analytics: { ga4_id: string | null; meta_pixel_id: string | null };
  cookie_banner: boolean;
  cookie_text: string;
  legal_name: string | null;
  legal_tax_id: string | null;
  landing: {
    tag?: string | null;
    title?: string;
    highlight?: string | null;
    description?: string | null;
    primary_button?: string;
    secondary_button?: string;
    plans_title?: string;
    plans_text?: string | null;
    image_url?: string | null;
    cards?: { icon: string; title: string; text?: string | null }[];
  } | null;
  brand: { logo_url?: string | null; short_name?: string | null; made_by?: string | null; made_by_url?: string | null } | null;
}

export interface WebPlan extends PublicPlan {
  slug: string;
  tagline?: string | null;
  price_yearly: number;
  currency: string;
  updated_at?: string;
}

export interface WebBusinessType {
  slug: string;
  name: string;
  short_name: string;
  description: string | null;
  icon: string | null;
  color: string | null;
  vocabulary: Record<string, string>;
  updated_at?: string;
  categories?: { name: string; services: { name: string; minutes: number; price: number; virtual: boolean; kind: string }[] }[];
  modules_off?: string[];
  dependents?: Record<string, unknown>;
}

export interface WebPost {
  id: string;
  slug: string;
  title: string;
  excerpt: string | null;
  business_type: string | null;
  business_type_name: string | null;
  seo_title: string | null;
  seo_description: string | null;
  cover_url: string | null;
  status: 'draft' | 'published';
  published_at: string | null;
  updated_at: string;
  author: string | null;
  reading_minutes: number;
  body: string | null;
}

export interface SiteData {
  site: SitePayload;
  plans: WebPlan[];
  business_types: WebBusinessType[];
  posts: WebPost[];
}

export const API_URL = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000/api';

const SERVER_API = process.env.API_INTERNAL_URL || API_URL;

export async function webGet<T>(path: string): Promise<T | null> {
  try {
    const res = await fetch(`${SERVER_API}${path}`, { headers: { Accept: 'application/json' }, next: { revalidate: 60 } });
    if (!res.ok) return null;
    return (await res.json()) as T;
  } catch {
    return null;
  }
}

const EMPTY_SITE: SitePayload = {
  product_name: 'CoreERP',
  allow_signup: true,
  url: null,
  phone: null,
  whatsapp: null,
  whatsapp_message: '',
  email: null,
  seo_title: null,
  seo_description: null,
  texts: { rubros_title: 'Hecho para tu rubro', rubros_text: '', pricing_title: 'Precios', pricing_text: '', cta_title: 'Prueba gratis', cta_text: '' },
  contact_form: false,
  faq: [],
  analytics: { ga4_id: null, meta_pixel_id: null },
  cookie_banner: true,
  cookie_text: '',
  legal_name: null,
  legal_tax_id: null,
  landing: null,
  brand: null,
};

export async function getSite(): Promise<SiteData> {
  const data = await webGet<SiteData>('/web/site');
  return data ?? { site: EMPTY_SITE, plans: [], business_types: [], posts: [] };
}

export async function siteUrl(site?: SitePayload | null): Promise<string> {
  const fixed = site?.url || process.env.NEXT_PUBLIC_SITE_URL;
  if (fixed) return fixed.replace(/\/$/, '');
  try {
    const { headers } = await import('next/headers');
    const list = await headers();
    const host = list.get('x-forwarded-host') ?? list.get('host');
    if (host) return `${list.get('x-forwarded-proto') ?? (host.startsWith('localhost') ? 'http' : 'https')}://${host}`;
  } catch {}
  return 'http://localhost:3000';
}

export function jsonLd(data: unknown): { __html: string } {
  return { __html: JSON.stringify(data).replace(/</g, '\\u003c') };
}

export function yearlySaving(plan: WebPlan): number {
  return Math.max(0, Math.round(plan.price_monthly * 12 - plan.price_yearly));
}

export function money(value: number, symbol = 'S/'): string {
  return `${symbol} ${Number.isInteger(value) ? value : value.toFixed(2)}`;
}

export function clip(text: string, max = 160): string {
  const clean = text.replace(/\s+/g, ' ').trim();
  if (clean.length <= max) return clean;
  const cut = clean.slice(0, max - 1);
  return `${cut.slice(0, cut.lastIndexOf(' ') > 80 ? cut.lastIndexOf(' ') : cut.length).replace(/[,.;:]$/, '')}…`;
}

export function pageTitle(site: SitePayload, title: string): string {
  return `${title} | ${site.product_name}`;
}

export function offers(plans: WebPlan[], url: string) {
  return plans.map((plan) => ({
    '@type': 'Offer',
    name: plan.name,
    price: plan.price_monthly,
    priceCurrency: plan.currency,
    url: `${url}/precios`,
    category: 'Suscripción mensual',
  }));
}

export function shortDate(iso: string | null): string {
  if (!iso) return '';
  return new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'America/Lima' });
}
