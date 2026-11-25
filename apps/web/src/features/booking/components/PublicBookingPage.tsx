'use client';

import { useEffect, useMemo, useState } from 'react';
import { AtSign, CalendarClock, Globe, Loader2, MapPin, MessageCircle, Music2, Phone, ShieldCheck } from 'lucide-react';
import { api } from '@/lib/axios';
import { previewStyle, resolveColors } from '@/lib/brand';
import { setCurrencies, type CurrencyInfo } from '@/lib/currency';
import BookingFlow from '@/features/portal/components/BookingFlow';
import PublicClasses from './PublicClasses';
import { ReviewsSection, TrackingConsent, type PublicReviews } from './BookingExtras';
import { channelFromUrl, trackBooking, type Tracking } from '../tracking';
import { portalError, type PortalCatalog, type PortalOverview, type PortalPolicies } from '@/features/portal/portal';

interface PublicInfo {
  captcha_site_key?: string | null;
  name: string;
  code: string;
  open: boolean;
  message: string | null;
  brand: { primary: string | null; accent: string | null; logo: string | null };
  page: {
    title: string;
    description: string | null;
    cover_url: string | null;
    socials: Record<'instagram' | 'facebook' | 'tiktok' | 'whatsapp', string | null>;
    show_policies: boolean;
  };
  vocabulary: Partial<Record<string, string>>;
  currency: CurrencyInfo;
  currencies: CurrencyInfo[];
  contact: { phone: string | null; email: string | null; branches: { id: string; name: string; address: string | null; phone: string | null }[] } | null;
  privacy: { html: string | null };
  portal_signup: boolean;
  document_types: { id: string; name: string; value: string | null; length: number | null; minimum: number | null }[];
  policies: PortalPolicies;
  dependents: { enabled: boolean; required: boolean; items: [] };
  classes?: boolean;
  reviews?: PublicReviews | null;
  tracking?: Tracking;
  business_type?: string | null;
  initials?: string;
  services_online?: number;
  branches?: PublicBranch[];
}

export interface PublicBranch {
  id: string;
  name: string;
  address: string | null;
  phone: string | null;
  map_url: string | null;
  hours: Record<string, { opens: string; closes: string }[]>;
  state: { open: boolean; text: string } | null;
}

const telOf = (value: string) => `tel:${value.replace(/[^\d+]/g, '')}`;

const socialLink = (key: string, value: string) => {
  if (/^https?:\/\//i.test(value)) return value;
  const handle = value.replace(/^@/, '').trim();
  switch (key) {
    case 'instagram': return `https://instagram.com/${handle}`;
    case 'facebook': return `https://facebook.com/${handle}`;
    case 'tiktok': return `https://www.tiktok.com/@${handle}`;
    default: return `https://wa.me/${handle.replace(/\D/g, '').replace(/^9/, '519')}`;
  }
};

const SOCIAL_ICONS = { instagram: AtSign, facebook: Globe, tiktok: Music2, whatsapp: MessageCircle };
const SOCIAL_NAMES = { instagram: 'Instagram', facebook: 'Facebook', tiktok: 'TikTok', whatsapp: 'WhatsApp' };

export default function PublicBookingPage({ slug }: { slug: string }) {
  const [info, setInfo] = useState<PublicInfo | null>(null);
  const [catalog, setCatalog] = useState<PortalCatalog | null>(null);
  const [error, setError] = useState('');
  const [round, setRound] = useState(0);
  const [channel, setChannel] = useState<string | null>(null);

  useEffect(() => { setChannel(channelFromUrl()); }, []);

  useEffect(() => {
    let alive = true;

    api.get<PublicInfo>(`/public/booking/${slug}`)
      .then(async (res) => {
        if (!alive) return;
        setCurrencies(res.data.currencies, res.data.currency?.code);
        setInfo(res.data);
        document.title = `${res.data.page.title} · Reservar cita`;

        if (res.data.open) {
          const cat = await api.get<PortalCatalog>(`/public/booking/${slug}/catalog`);
          if (alive) setCatalog(cat.data);
        }
      })
      .catch((err) => alive && setError(portalError(err, 'No se pudo abrir la página de reservas.')));

    return () => { alive = false; };
  }, [slug]);

  const style = useMemo(
    () => (info ? previewStyle(resolveColors({ primary: info.brand.primary, accent: info.brand.accent }), false) : undefined),
    [info],
  );

  const overview = useMemo<PortalOverview | null>(() => (info ? {
    customer: { id: '', code: '', first_name: '', last_name: '', phone: null, email: null },
    policies: info.policies,
    dependents: info.dependents,
    appointments: [],
  } : null), [info]);

  if (error) {
    return (
      <main className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4">
        <p role="alert" className="max-w-md text-center text-sm text-slate-600 bg-white border border-slate-200 rounded-2xl px-6 py-5">{error}</p>
      </main>
    );
  }

  if (!info || !overview) {
    return (
      <main className="min-h-screen bg-[#F2F2F2] flex items-center justify-center">
        <Loader2 size={22} className="animate-spin text-slate-400" />
      </main>
    );
  }

  const socials = (Object.entries(info.page.socials) as [keyof typeof SOCIAL_ICONS, string | null][]).filter(([, value]) => value);
  const policies = info.policies;
  const branches = info.branches ?? [];
  const firstBranch = branches.find((b) => b.address) ?? branches[0] ?? null;
  const openBranch = branches.find((b) => b.state?.open);
  const hoursState = openBranch?.state ?? branches.find((b) => b.state)?.state ?? null;
  const callNumber = firstBranch?.phone ?? info.contact?.phone ?? null;
  const whatsappNumber = info.page.socials.whatsapp;
  const trust = [
    policies.auto_confirm ? 'Confirmación al instante' : 'Te avisamos cuando se confirme',
    policies.cancel_allowed ? `Cambias o cancelas hasta ${policies.cancel_window_hours} h antes` : null,
    info.open && (info.services_online ?? 0) > 0 ? `${info.services_online} ${info.services_online === 1 ? 'servicio' : 'servicios'} en línea` : null,
    'Tus datos protegidos (Ley 29733)',
  ].filter(Boolean) as string[];
  const actionClass = 'inline-flex items-center justify-center gap-1.5 px-3.5 py-2.5 text-xs sm:text-sm font-bold text-brand-ink bg-white border border-slate-200 rounded-xl hover:border-brand-accent transition-colors';

  return (
    <main className="theme-scope min-h-screen bg-[#F2F2F2] text-slate-800" style={style} data-public-booking={slug}>
      <header className="relative">
        {info.page.cover_url ? (
          <div className="h-40 sm:h-56 w-full bg-slate-200 overflow-hidden">
            <img src={info.page.cover_url} alt="" className="w-full h-full object-cover" />
          </div>
        ) : (
          <div className="h-24 sm:h-32 w-full bg-brand" />
        )}
        <div className="max-w-2xl mx-auto px-4 sm:px-6">
          <div className="-mt-10 sm:-mt-12 bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-3">
            <div className="flex items-center gap-4">
              {info.brand.logo ? (
                <img src={info.brand.logo} alt={info.name} className="w-14 h-14 rounded-2xl object-contain bg-white border border-slate-100 shrink-0" />
              ) : (
                <span data-booking-initials aria-hidden="true" className="w-14 h-14 rounded-2xl bg-brand text-white flex items-center justify-center shrink-0 text-lg font-black tracking-tight">
                  {info.initials || <CalendarClock size={24} />}
                </span>
              )}
              <div className="min-w-0">
                {info.business_type && (
                  <span data-booking-rubro className="inline-block mb-1 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-brand-accent bg-brand-soft/20 rounded-md">{info.business_type}</span>
                )}
                <h1 className="text-xl sm:text-2xl font-black text-brand-ink tracking-tight leading-tight">{info.page.title}</h1>
                {info.page.title !== info.name && <p className="text-xs font-semibold text-slate-500">{info.name}</p>}
              </div>
            </div>
            {info.page.description && <p className="text-sm text-slate-600 whitespace-pre-line">{info.page.description}</p>}
            {(hoursState || firstBranch?.address) && (
              <ul className="space-y-1 text-sm" data-booking-hours>
                {hoursState && (
                  <li className={`flex items-center gap-2 font-semibold ${hoursState.open ? 'text-emerald-700' : 'text-slate-600'}`}>
                    <span className={`w-2 h-2 rounded-full shrink-0 ${hoursState.open ? 'bg-emerald-500' : 'bg-slate-400'}`} aria-hidden="true" />
                    {hoursState.text}
                  </li>
                )}
                {firstBranch?.address && (
                  <li className="flex items-start gap-2 text-slate-600">
                    <MapPin size={14} className="text-brand-accent mt-0.5 shrink-0" />
                    <span>{firstBranch.address}{branches.length > 1 ? ` · y ${branches.length - 1} ${branches.length === 2 ? 'sede más' : 'sedes más'}` : ''}</span>
                  </li>
                )}
              </ul>
            )}
            <div className="grid grid-cols-2 sm:flex sm:flex-wrap gap-2" data-booking-actions>
              {info.open && (
                <a href="#reservar" className="col-span-2 sm:col-span-1 inline-flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover transition-colors">
                  <CalendarClock size={16} /> Reservar ahora
                </a>
              )}
              {whatsappNumber && (
                <a href={socialLink('whatsapp', whatsappNumber)} target="_blank" rel="noopener noreferrer" className={actionClass} data-action="whatsapp"><MessageCircle size={15} className="text-brand-accent" /> WhatsApp</a>
              )}
              {callNumber && (
                <a href={telOf(callNumber)} className={actionClass} data-action="call"><Phone size={15} className="text-brand-accent" /> Llamar</a>
              )}
              {firstBranch?.map_url && (
                <a href={firstBranch.map_url} target="_blank" rel="noopener noreferrer" className={actionClass} data-action="directions"><MapPin size={15} className="text-brand-accent" /> Cómo llegar</a>
              )}
            </div>
            <ul className="flex flex-wrap gap-x-4 gap-y-1.5 pt-1 text-xs text-slate-500" data-booking-trust>
              {trust.map((item) => (
                <li key={item} className="flex items-center gap-1.5"><ShieldCheck size={13} className="text-brand-accent shrink-0" /> {item}</li>
              ))}
            </ul>
            {socials.length > 0 && (
              <div className="flex flex-wrap gap-2" data-socials>
                {socials.map(([key, value]) => {
                  const Icon = SOCIAL_ICONS[key];
                  return (
                    <a key={key} href={socialLink(key, value!)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-brand-ink bg-slate-50 border border-slate-200 rounded-full hover:border-brand-accent transition-colors">
                      <Icon size={14} className="text-brand-accent" /> {SOCIAL_NAMES[key]}
                    </a>
                  );
                })}
              </div>
            )}
          </div>
        </div>
      </header>

      <div id="reservar" className="max-w-2xl mx-auto px-4 sm:px-6 py-5 space-y-4 scroll-mt-4">
        {!info.open ? (
          <section className="bg-white rounded-3xl border border-slate-200/80 p-6 text-center text-sm text-slate-600" data-booking-closed>
            {info.message}
          </section>
        ) : !catalog ? (
          <p className="flex items-center justify-center gap-2 text-sm text-slate-500 py-8"><Loader2 size={16} className="animate-spin" /> Cargando servicios...</p>
        ) : (
          <BookingFlow
            key={round}
            overview={overview}
            catalog={catalog}
            guest={{
              slug,
              vocabulary: info.vocabulary,
              documentTypes: info.document_types,
              privacyHtml: info.privacy?.html ?? null,
              portalSignup: info.portal_signup,
              channel,
              captchaSiteKey: info.captcha_site_key ?? null,
              onStart: () => trackBooking('start', { currency: info.currency?.code ?? null }),
              onBooked: (detail) => {
                trackBooking('complete', { ...detail, currency: info.currency?.code ?? null });
                if (channel === 'link') api.post(`/public/link/${slug}/visit`, { kind: 'booking' }).catch(() => undefined);
              },
              branches: info.branches ?? [],
            }}
            onDone={() => { setRound((value) => value + 1); window.scrollTo({ top: 0, behavior: 'smooth' }); }}
            onExit={() => setRound((value) => value + 1)}
            onDependentAdded={() => undefined}
            onWaitlisted={() => undefined}
          />
        )}

        {info.open && info.classes && (
          <PublicClasses slug={slug} documentTypes={info.document_types} privacyHtml={info.privacy?.html ?? null} dependentLabel={info.vocabulary.dependent ?? 'Dependiente'} />
        )}

        {info.open && info.page.show_policies && (
          <section className="bg-white rounded-3xl border border-slate-200/80 p-5 sm:p-6 space-y-2" data-policies>
            <h2 className="flex items-center gap-2 text-sm font-black text-brand-ink"><ShieldCheck size={16} className="text-brand-accent" /> Antes de reservar</h2>
            <ul className="text-xs text-slate-600 space-y-1.5 list-disc pl-4">
              <li>Puedes reservar desde {policies.min_lead_minutes >= 60 ? `${Math.round(policies.min_lead_minutes / 60)} ${Math.round(policies.min_lead_minutes / 60) === 1 ? 'hora' : 'horas'}` : `${policies.min_lead_minutes} minutos`} antes y hasta {policies.max_days_ahead} días adelante.</li>
              {policies.cancel_allowed
                ? <li>Puedes cambiar o cancelar tu cita desde el portal hasta {policies.cancel_window_hours} horas antes.</li>
                : <li>Para cambiar o cancelar tu cita, comunícate con la sede.</li>}
              {!policies.auto_confirm && <li>Tu cita queda pendiente hasta que se confirme.</li>}
              {policies.miss && policies.miss.action === 'block' && (
                <li>Si no asistes a {policies.miss.limit === 1 ? 'una cita reservada en línea' : `${policies.miss.limit} citas reservadas en línea`}, no podrás volver a reservar por aquí {policies.miss.block_days > 0 ? `durante ${policies.miss.block_days} días` : 'hasta que la sede lo permita'}.</li>
              )}
            </ul>
          </section>
        )}

        <ReviewsSection reviews={info.reviews} company={info.name} />

        {info.contact && (
          <section className="bg-white rounded-3xl border border-slate-200/80 p-5 sm:p-6 space-y-3" data-contact>
            <h2 className="text-sm font-black text-brand-ink">Contacto</h2>
            {info.contact.phone && <p className="flex items-center gap-2 text-sm text-slate-600"><Phone size={14} className="text-brand-accent" /> {info.contact.phone}</p>}
            {info.contact.branches.map((branch) => (
              <div key={branch.id} className="text-sm text-slate-600">
                <p className="font-bold text-slate-700">{branch.name}</p>
                {branch.address && <p className="flex items-start gap-2"><MapPin size={14} className="text-brand-accent mt-0.5 shrink-0" /> {branch.address}</p>}
                {branch.phone && <p className="flex items-center gap-2"><Phone size={14} className="text-brand-accent" /> {branch.phone}</p>}
              </div>
            ))}
          </section>
        )}

        <footer className="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-slate-500 pb-6">
          <a href="/login" className="font-semibold hover:text-brand-ink">¿Ya tienes cuenta? Entra al portal</a>
          <a href={`/reclamaciones/${info.code}`} className="font-semibold hover:text-brand-ink">Libro de Reclamaciones</a>
        </footer>
      </div>
      <TrackingConsent tracking={info.tracking} />
    </main>
  );
}
