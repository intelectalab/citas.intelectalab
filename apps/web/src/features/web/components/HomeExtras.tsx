import Link from 'next/link';
import { BadgeCheck, CalendarCheck, Handshake, LayoutTemplate, Quote, Share2 } from 'lucide-react';
import type { SiteContent, SiteTestimonial } from '../web';

export function TrustBadges({ badges }: { badges: SiteContent['trust_badges'] }) {
  if (badges.length === 0) return null;

  return (
    <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-10" aria-label="Sellos de confianza" data-trust-badges>
      <ul data-reveal className="flex flex-wrap justify-center gap-2.5">
        {badges.map((badge) => (
          <li key={badge.label} className="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-full px-4 py-2 text-xs sm:text-sm text-slate-600 shadow-xs">
            <BadgeCheck size={16} className="text-brand-accent shrink-0" />
            <span><strong className="text-brand-ink">{badge.label}</strong>{badge.detail ? ` · ${badge.detail}` : ''}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

export function Results({ items }: { items: SiteContent['results'] }) {
  if (items.length === 0) return null;

  return (
    <section className="bg-brand text-white py-10 sm:py-14" data-results>
      <dl className="max-w-6xl mx-auto px-4 sm:px-6 grid grid-cols-2 lg:grid-cols-4 gap-6 text-center">
        {items.map((item, index) => (
          <div key={item.label} data-reveal style={{ ['--d' as string]: `${index * 90}ms` }} className="space-y-1">
            <dt className="sr-only">{item.label}</dt>
            <dd className="text-3xl sm:text-4xl font-black tracking-tight text-brand-soft tabular-nums">{item.value}</dd>
            <dd className="text-sm text-white/80">{item.label}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}

const STEPS = [
  { icon: LayoutTemplate, title: 'Elige tu rubro', text: 'Tu cuenta nace con los servicios, las palabras y las reglas de tu tipo de negocio.' },
  { icon: CalendarCheck, title: 'Pon tus horarios', text: 'Agrega a tu equipo, sus horarios y tus precios. Lo cambias cuando quieras.' },
  { icon: Share2, title: 'Comparte tu enlace', text: 'Pégalo en Instagram o WhatsApp y empieza a recibir reservas.' },
];

export function ThreeSteps({ allowSignup }: { allowSignup: boolean }) {
  return (
    <section className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-16 space-y-8" data-three-steps>
      <div data-reveal className="text-center space-y-2 max-w-2xl mx-auto">
        <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">Empieza hoy, en tres pasos</h2>
        <p className="text-slate-500 text-sm">Sin instalar nada y sin tarjeta.</p>
      </div>
      <ol className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {STEPS.map((step, index) => (
          <li key={step.title} data-reveal style={{ ['--d' as string]: `${index * 110}ms` }} className="site-lift relative bg-white rounded-2xl border border-slate-200 p-5 overflow-hidden">
            <span className="absolute -right-3 -top-3 w-16 h-16 rounded-full border-[6px] border-brand-soft/30" aria-hidden="true" />
            <span className="w-10 h-10 rounded-xl bg-brand text-brand-soft flex items-center justify-center"><step.icon size={19} /></span>
            <span className="block mt-3 font-bold text-brand-ink">{step.title}</span>
            <span className="block mt-1 text-sm text-slate-500">{step.text}</span>
          </li>
        ))}
      </ol>
      {allowSignup && (
        <p className="text-center"><Link href="/register" data-cta="steps" className="inline-flex items-center gap-2 bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-xl text-sm shadow-md">Crear mi cuenta gratis</Link></p>
      )}
    </section>
  );
}

export function Testimonials({ items, title = 'Lo que dicen quienes ya lo usan' }: { items: SiteTestimonial[]; title?: string }) {
  if (items.length === 0) return null;

  return (
    <section className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-16 space-y-8" data-testimonials>
      <h2 data-reveal className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight text-center">{title}</h2>
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {items.slice(0, 6).map((item, index) => (
          <figure key={`${item.name}-${index}`} data-reveal style={{ ['--d' as string]: `${(index % 3) * 90}ms` }} className="bg-white rounded-2xl border border-slate-200 p-5 flex flex-col gap-3">
            <Quote size={22} className="text-brand-soft" aria-hidden="true" />
            <blockquote className="text-sm text-slate-700 leading-relaxed flex-1">{item.text}</blockquote>
            {item.result && <span className="self-start text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-full px-2.5 py-0.5">{item.result}</span>}
            <figcaption className="text-sm">
              <span className="block font-bold text-brand-ink">{item.name}</span>
              {item.business && <span className="block text-xs text-slate-500">{item.business}</span>}
            </figcaption>
          </figure>
        ))}
      </div>
    </section>
  );
}

export function Partners({ partners, allowContact }: { partners: SiteContent['partners']; allowContact: boolean }) {
  if (!partners) return null;

  return (
    <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-14" id="partners" data-partners>
      <div data-reveal className="bg-white rounded-3xl border border-slate-200 p-6 sm:p-8 grid grid-cols-1 md:grid-cols-[auto_1fr] gap-5 items-start">
        <span className="w-12 h-12 rounded-2xl bg-brand-soft/25 text-brand-ink flex items-center justify-center"><Handshake size={22} /></span>
        <div className="space-y-2 min-w-0">
          <h2 className="text-xl sm:text-2xl font-black text-brand-ink tracking-tight">{partners.title}</h2>
          {partners.text && <p className="text-sm text-slate-600">{partners.text}</p>}
          {partners.conditions && (
            <details className="group">
              <summary className="cursor-pointer text-sm font-bold text-brand-accent hover:text-brand-ink list-none">Ver condiciones</summary>
              <p className="mt-2 text-sm text-slate-600 whitespace-pre-line">{partners.conditions}</p>
            </details>
          )}
          {allowContact && <Link href="/#demo" className="inline-block pt-1 text-sm font-bold text-brand-ink underline underline-offset-4 hover:text-brand-accent">Quiero ser partner</Link>}
        </div>
      </div>
    </section>
  );
}
