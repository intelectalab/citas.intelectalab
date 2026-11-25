'use client';

import { useEffect, useMemo, useState } from 'react';
import { AtSign, CalendarClock, ExternalLink, Gift, Globe, Loader2, MapPin, MessageCircle, Music2, Phone, Tag } from 'lucide-react';
import { money } from '@/features/sales/sales';
import { api } from '@/lib/axios';
import { previewStyle, resolveColors } from '@/lib/brand';
import { portalError } from '@/features/portal/portal';
import { ReviewsSection, TrackingConsent, type PublicReviews } from './BookingExtras';
import type { Tracking } from '../tracking';

interface LinkData {
  name: string;
  code: string;
  title: string;
  bio: string | null;
  brand: { primary: string | null; accent: string | null; logo: string | null };
  cover_url: string | null;
  open: boolean;
  booking_url: string;
  whatsapp: string | null;
  socials: Record<'instagram' | 'facebook' | 'tiktok', string | null>;
  links: { label: string; url: string }[];
  branches: { id: string; name: string; address: string | null; phone: string | null; map_url: string | null }[];
  reviews: PublicReviews | null;
  tracking: Tracking;
  promotions?: { id: string; name: string; description: string | null; price: number; currency: string; sessions: number; savings: number; savings_percent: number; validity_days: number | null }[];
  gift_cards?: boolean;
  phone?: string | null;
  business_type?: string | null;
}

const SOCIAL = {
  instagram: { label: 'Instagram', icon: AtSign, url: (h: string) => `https://instagram.com/${h}` },
  facebook: { label: 'Facebook', icon: Globe, url: (h: string) => `https://facebook.com/${h}` },
  tiktok: { label: 'TikTok', icon: Music2, url: (h: string) => `https://www.tiktok.com/@${h}` },
};

const big = 'w-full inline-flex items-center justify-center gap-2 px-5 py-3.5 text-sm font-bold rounded-2xl transition-colors';

export default function LinkPageView({ slug }: { slug: string }) {
  const [data, setData] = useState<LinkData | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get<LinkData>(`/public/link/${slug}`)
      .then((res) => {
        setData(res.data);
        try {
          const key = `link_visit_${slug}`;
          if (!sessionStorage.getItem(key)) {
            sessionStorage.setItem(key, '1');
            api.post(`/public/link/${slug}/visit`).catch(() => undefined);
          }
        } catch {
          api.post(`/public/link/${slug}/visit`).catch(() => undefined);
        }
      })
      .catch((err) => setError(portalError(err, 'No se pudo abrir esta página.')));
  }, [slug]);

  const style = useMemo(() => (data ? previewStyle(resolveColors({ primary: data.brand.primary, accent: data.brand.accent }), false) : undefined), [data]);

  if (!data) {
    return (
      <main className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4">
        {error ? <p role="alert" className="max-w-md text-center text-sm text-slate-600 bg-white border border-slate-200 rounded-2xl px-6 py-5">{error}</p> : <Loader2 size={22} className="animate-spin text-slate-400" />}
      </main>
    );
  }

  const whatsapp = data.whatsapp ? `https://wa.me/${data.whatsapp.length === 9 ? `51${data.whatsapp}` : data.whatsapp}` : null;
  const socials = (Object.entries(data.socials) as [keyof typeof SOCIAL, string | null][]).filter(([, v]) => v);

  return (
    <main className="theme-scope min-h-screen bg-[#F2F2F2] text-slate-800 px-4 py-8" style={style} data-link-page={slug}>
      <div className="max-w-md mx-auto space-y-4">
        <div className="text-center space-y-3">
          {data.brand.logo ? (
            <img src={data.brand.logo} alt={data.name} className="w-20 h-20 mx-auto rounded-3xl object-contain bg-white border border-slate-100" />
          ) : (
            <span className="w-20 h-20 mx-auto rounded-3xl bg-brand text-white flex items-center justify-center"><CalendarClock size={30} /></span>
          )}
          {data.business_type && <span className="inline-block px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand-accent bg-brand-soft/20 rounded-md">{data.business_type}</span>}
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">{data.title}</h1>
          {data.bio && <p className="text-sm text-slate-600 whitespace-pre-line">{data.bio}</p>}
        </div>

        {data.open && (
          <a href={data.booking_url} data-link-book className={`${big} bg-brand text-white hover:bg-brand-hover`}><CalendarClock size={18} /> Reservar cita</a>
        )}
        {whatsapp && (
          <a href={whatsapp} target="_blank" rel="noopener noreferrer" data-link-whatsapp className={`${big} bg-white text-brand-ink border border-slate-200 hover:border-brand-accent`}><MessageCircle size={18} /> Escribir por WhatsApp</a>
        )}
        {(data.phone || data.branches[0]?.phone) && (
          <a href={`tel:${String(data.branches[0]?.phone ?? data.phone).replace(/[^\d+]/g, '')}`} data-link-call className={`${big} bg-white text-brand-ink border border-slate-200 hover:border-brand-accent`}><Phone size={17} /> Llamar</a>
        )}
        {data.links.map((link) => (
          <a key={link.url} href={link.url} target="_blank" rel="noopener noreferrer" className={`${big} bg-white text-brand-ink border border-slate-200 hover:border-brand-accent`}><ExternalLink size={16} /> {link.label}</a>
        ))}

        {socials.length > 0 && (
          <div className="flex flex-wrap justify-center gap-2">
            {socials.map(([key, value]) => {
              const item = SOCIAL[key];
              const Icon = item.icon;
              const href = /^https?:\/\//i.test(value!) ? value! : item.url(value!.replace(/^@/, '').trim());
              return (
                <a key={key} href={href} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-full hover:border-brand-accent">
                  <Icon size={14} className="text-brand-accent" /> {item.label}
                </a>
              );
            })}
          </div>
        )}

        {(data.promotions ?? []).length > 0 && (
          <section className="space-y-2" data-link-promotions>
            <h2 className="flex items-center gap-1.5 text-sm font-black text-brand-ink"><Tag size={15} className="text-brand-accent" /> Promociones</h2>
            {data.promotions!.map((promo) => (
              <a key={promo.id} href="/login" className="block bg-white rounded-2xl border border-slate-200/80 p-4 hover:border-brand-accent transition-colors" data-link-promo={promo.name}>
                <span className="flex items-start justify-between gap-3">
                  <span className="min-w-0">
                    <span className="block text-sm font-bold text-slate-800">{promo.name}</span>
                    <span className="block text-xs text-slate-500">
                      {promo.sessions} {promo.sessions === 1 ? 'sesión' : 'sesiones'}
                      {promo.validity_days ? ` · válido ${promo.validity_days} días` : ''}
                      {promo.savings_percent > 0 ? ` · ahorras ${promo.savings_percent} %` : ''}
                    </span>
                  </span>
                  <span className="text-sm font-black text-brand-ink shrink-0">{money(promo.price, promo.currency)}</span>
                </span>
                <span className="block text-[11px] text-brand-accent font-semibold mt-1.5">Cómpralo en tu portal</span>
              </a>
            ))}
          </section>
        )}

        {data.gift_cards && (
          <section className="bg-white rounded-3xl border border-slate-200/80 p-4 flex items-start gap-3" data-link-gift-cards>
            <span className="p-2 rounded-xl bg-brand-soft/20 text-brand-ink shrink-0"><Gift size={17} /></span>
            <span className="text-sm text-slate-600">
              <span className="block font-bold text-slate-800">Regala una gift card</span>
              Pídela en la sede{whatsapp ? ' o escríbenos por WhatsApp' : ''} y elige el monto.
            </span>
          </section>
        )}

        {data.branches.length > 0 && (
          <section className="bg-white rounded-3xl border border-slate-200/80 p-5 space-y-3" data-link-branches>
            {data.branches.map((branch) => (
              <div key={branch.id} className="text-sm text-slate-600">
                <p className="font-bold text-slate-700">{branch.name}</p>
                {branch.address && (
                  branch.map_url
                    ? <a href={branch.map_url} target="_blank" rel="noopener noreferrer" className="flex items-start gap-2 hover:text-brand-accent"><MapPin size={14} className="text-brand-accent mt-0.5 shrink-0" /> {branch.address}</a>
                    : <p className="flex items-start gap-2"><MapPin size={14} className="text-brand-accent mt-0.5 shrink-0" /> {branch.address}</p>
                )}
              </div>
            ))}
          </section>
        )}

        <ReviewsSection reviews={data.reviews} company={data.name} narrow />

        <p className="text-center text-xs text-slate-500 pt-4"><a href={`/reclamaciones/${data.code}`} className="font-semibold hover:text-brand-ink">Libro de Reclamaciones</a></p>
      </div>
      <TrackingConsent tracking={data.tracking} />
    </main>
  );
}
