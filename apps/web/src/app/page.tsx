import type { Metadata } from 'next';
import Link from 'next/link';
import { ArrowRight, BellRing, Check, Globe, HeartHandshake, Receipt, Sparkles, Stethoscope } from 'lucide-react';
import PricingPlans from '@/features/web/components/PricingPlans';
import HeroLive from '@/features/web/components/HeroLive';
import FeatureBento from '@/features/web/components/FeatureBento';
import ContactForm from '@/features/web/components/ContactForm';
import { BusinessTypeGrid, Faq, PostCards, SignupBand } from '@/features/web/components/Sections';
import { Partners, Results, Testimonials, ThreeSteps, TrustBadges } from '@/features/web/components/HomeExtras';
import { HeroVideoButton, VideoChapters } from '@/features/web/components/SiteVideo';
import RubroTabs from '@/features/web/components/RubroTabs';
import SiteShell, { baseMetadata } from '@/features/web/components/SiteShell';
import { EMPTY_CONTENT, getSite, jsonLd, money, offers, siteUrl } from '@/features/web/web';

export const revalidate = 60;

export async function generateMetadata(): Promise<Metadata> {
  const { site } = await getSite();
  const title = site.seo_title || `${site.product_name}: agenda, clientes y caja para tu negocio`;
  const description = site.seo_description || site.landing?.description || 'Agenda en línea, recordatorios, fichas, caja y reportes para negocios de servicios. Prueba gratis sin tarjeta.';

  return { ...(await baseMetadata()), title: { absolute: title }, description, alternates: { canonical: '/' }, openGraph: { title, description, url: '/' } };
}

const JOURNEY = [
  { icon: Globe, when: 'Domingo, 9:40 pm', step: 'Ana reserva', text: 'Entra a tu página con el negocio cerrado, elige servicio, profesional y hora.' },
  { icon: BellRing, when: 'Lunes, 10:00 am', step: 'Le llega el recordatorio', text: 'Por correo o WhatsApp, y confirma con un toque.' },
  { icon: Stethoscope, when: 'Martes, 4:00 pm', step: 'La atiendes', text: 'Con su ficha y sus antecedentes a la mano.' },
  { icon: Receipt, when: 'Martes, 4:50 pm', step: 'Pasa por caja', text: 'Cobras y sale su boleta o factura al momento.' },
  { icon: HeartHandshake, when: 'Un mes después', step: 'Vuelve', text: 'Su paquete, su membresía o una campaña la traen de nuevo.' },
];

const DEFAULT_CARDS = [
  { icon: 'calendar', title: 'Agenda del día', text: 'Citas confirmadas, en espera y atendidas de un vistazo.' },
  { icon: 'wallet', title: 'Caja al día', text: 'Cobros, comprobantes y cierre sin hojas sueltas.' },
  { icon: 'users', title: 'Clientes que vuelven', text: 'Recordatorios, paquetes y campañas.' },
];

export default async function HomePage() {
  const { site, plans, business_types, posts, rubro_groups } = await getSite();
  const content = site.content ?? EMPTY_CONTENT;
  const paid = plans.filter((plan) => plan.price_monthly > 0).sort((a, b) => a.price_monthly - b.price_monthly);
  const cheapest = paid[0] ?? null;
  const landing = site.landing;
  const url = await siteUrl(site);
  const trialDays = Math.max(0, ...plans.map((plan) => (plan.trial_config?.has_trial === false ? 0 : plan.trial_config?.trial_days ?? 0)));
  const schema = [
    {
      '@context': 'https://schema.org',
      '@type': 'SoftwareApplication',
      name: site.product_name,
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Web',
      url,
      description: site.seo_description || landing?.description,
      offers: offers(plans, url),
    },
    { '@context': 'https://schema.org', '@type': 'Organization', name: site.legal_name || site.product_name, url, ...(site.email ? { email: site.email } : {}), ...(site.phone ? { telephone: site.phone } : {}) },
    ...(site.faq.length ? [{ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: site.faq.map((item) => ({ '@type': 'Question', name: item.q, acceptedAnswer: { '@type': 'Answer', text: item.a } })) }] : []),
  ];

  return (
    <SiteShell>
      <script type="application/ld+json" dangerouslySetInnerHTML={jsonLd(schema)} />

      <div className="relative overflow-hidden">
        <div className="absolute inset-0 site-hours [mask-image:radial-gradient(ellipse_at_top_right,black,transparent_70%)]" aria-hidden="true" />
        <section className="relative max-w-6xl mx-auto px-4 sm:px-6 py-12 md:py-20 grid grid-cols-1 md:grid-cols-2 gap-12 items-center" data-site-hero>
          <div className="space-y-6 min-w-0">
            {landing?.tag && (
              <span data-reveal className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/80 border border-brand-soft/40 text-brand-ink text-xs font-bold shadow-xs" data-landing-tag>
                <Sparkles className="w-3.5 h-3.5 text-brand-accent" /> {landing.tag}
              </span>
            )}
            <h1 data-reveal style={{ ['--d' as string]: '80ms' }} className="text-3xl sm:text-5xl font-black text-brand-ink tracking-tight leading-tight break-words" data-landing-title>
              {landing?.title || site.product_name}{landing?.highlight && <> <span className="site-underline text-brand-accent">{landing.highlight}</span></>}
            </h1>
            {landing?.description && <p data-reveal style={{ ['--d' as string]: '160ms' }} className="text-slate-500 text-sm sm:text-base font-medium leading-relaxed">{landing.description}</p>}
            <div data-reveal style={{ ['--d' as string]: '240ms' }} className="flex flex-wrap items-center gap-3 pt-2">
              <Link href="#planes" data-cta="hero" className="group bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-xl text-sm shadow-md inline-flex items-center gap-2">
                {landing?.primary_button || 'Prueba gratis'} <ArrowRight className="w-4 h-4 text-brand-soft transition-transform group-hover:translate-x-1" />
              </Link>
              <Link href="/precios" className="bg-white hover:bg-slate-50 text-brand-ink border border-slate-200 font-bold py-3 px-6 rounded-xl text-sm">Ver precios</Link>
              {content.hero_video && <HeroVideoButton url={content.hero_video} />}
            </div>
            <p data-reveal style={{ ['--d' as string]: '300ms' }} className="text-xs text-slate-500 flex flex-wrap gap-x-4 gap-y-1">
              <span className="inline-flex items-center gap-1"><Check size={14} className="text-emerald-600" /> Sin tarjeta</span>
              <span className="inline-flex items-center gap-1"><Check size={14} className="text-emerald-600" /> Lista en minutos</span>
              <span className="inline-flex items-center gap-1"><Check size={14} className="text-emerald-600" /> Soporte en español</span>
            </p>
            <dl data-reveal style={{ ['--d' as string]: '380ms' }} className="flex flex-wrap gap-2 pt-2" data-landing-stats>
              <div className="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-full pl-1.5 pr-3.5 py-1.5 shadow-xs">
                <dt className="sr-only">Rubros</dt>
                <dd className="min-w-8 h-8 px-1.5 rounded-full bg-brand text-white text-sm font-black flex items-center justify-center tabular-nums" data-count={business_types.length}>{business_types.length}</dd>
                <dd className="text-xs font-semibold text-slate-600">rubros listos para empezar</dd>
              </div>
              {trialDays > 0 && (
                <div className="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-full pl-1.5 pr-3.5 py-1.5 shadow-xs">
                  <dt className="sr-only">Prueba</dt>
                  <dd className="min-w-8 h-8 px-1.5 rounded-full bg-brand-soft/30 text-brand-ink text-sm font-black flex items-center justify-center tabular-nums" data-count={trialDays}>{trialDays}</dd>
                  <dd className="text-xs font-semibold text-slate-600">días de prueba gratis</dd>
                </div>
              )}
              {cheapest && (
                <div className="inline-flex items-center gap-2 bg-white border border-slate-200 rounded-full pl-3.5 pr-3.5 py-1.5 shadow-xs" data-landing-from>
                  <dt className="sr-only">Precio</dt>
                  <dd className="text-xs font-semibold text-slate-600">Planes desde <strong className="text-brand-ink text-sm font-black">{money(cheapest.price_monthly, cheapest.currency_symbol ?? 'S/')}</strong> al mes</dd>
                </div>
              )}
            </dl>
          </div>

          <div data-reveal style={{ ['--d' as string]: '200ms' }} className="min-w-0">
            {landing?.image_url ? (
              <img src={landing.image_url} alt="" className="w-full max-h-[26rem] object-cover rounded-3xl border border-slate-200 shadow-2xl" data-landing-image />
            ) : (
              <HeroLive cards={landing?.cards?.length ? landing.cards : DEFAULT_CARDS} />
            )}
          </div>
        </section>
      </div>

      <TrustBadges badges={content.trust_badges} />

      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-20 space-y-10" id="como-funciona" data-journey>
        <div data-reveal className="text-center space-y-2 max-w-2xl mx-auto">
          <span className="text-xs font-bold text-brand-accent">Cómo funciona</span>
          <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">La historia de una cita</h2>
          <p className="text-slate-500 text-sm">Desde que Ana reserva hasta que vuelve, todo queda anotado solo. Nadie vuelve a escribir su nombre, el servicio ni el precio.</p>
        </div>
        <ol data-reveal className="relative grid grid-cols-1 lg:grid-cols-5 gap-4 lg:gap-3">
          <span className="hidden lg:block absolute top-[1.15rem] left-[8%] right-[8%] border-t-2 border-dashed border-brand-soft site-step-line" aria-hidden="true" />
          <span className="lg:hidden absolute top-4 bottom-4 left-[1.15rem] border-l-2 border-dashed border-brand-soft" aria-hidden="true" />
          {JOURNEY.map((item, index) => (
            <li key={item.step} data-reveal style={{ ['--d' as string]: `${index * 120}ms` }} className="relative flex lg:flex-col gap-4 lg:gap-3 items-start">
              <span className="relative z-10 inline-flex items-center gap-1.5 rounded-full bg-white border-2 border-brand-soft pl-1 pr-3 py-1 text-[11px] font-bold text-brand-ink whitespace-nowrap shadow-xs">
                <span className="w-6 h-6 rounded-full bg-brand text-brand-soft flex items-center justify-center"><item.icon className="w-3.5 h-3.5" /></span>
                {item.when}
              </span>
              <span className="site-lift block flex-1 w-full bg-white rounded-2xl border border-slate-200 p-4">
                <span className="block font-bold text-brand-ink">{item.step}</span>
                <span className="block mt-1 text-sm text-slate-500">{item.text}</span>
              </span>
            </li>
          ))}
        </ol>
      </section>

      <section className="bg-white border-y border-slate-200/80 py-14 sm:py-20" id="rubros">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 space-y-8">
          <div data-reveal className="text-center space-y-2 max-w-2xl mx-auto">
            <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">{site.texts.rubros_title}</h2>
            {site.texts.rubros_text && <p className="text-slate-500 text-sm">{site.texts.rubros_text}</p>}
          </div>
          {(rubro_groups ?? []).length > 1 && business_types.some((t) => t.group) ? (
            <>
              <RubroTabs types={business_types} groups={rubro_groups ?? []} />
              <p className="text-center"><Link href="/rubros" className="text-sm font-bold text-brand-accent hover:text-brand-ink">Ver todos los rubros →</Link></p>
            </>
          ) : (
            <BusinessTypeGrid types={business_types} />
          )}
        </div>
      </section>

      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-20 space-y-8 scroll-mt-20" id="funciones">
        <div data-reveal className="text-center space-y-2 max-w-2xl mx-auto">
          <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">Todo tu negocio en un solo lugar</h2>
          <p className="text-slate-500 text-sm">La agenda, los avisos, la caja y las fichas trabajan juntas. Lo que pasa en una se ve en las demás.</p>
        </div>
        <FeatureBento />
      </section>

      {content.video && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-14 sm:pb-20 space-y-6" id="video">
          <h2 data-reveal className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight text-center">{content.video.title || `Mira ${site.product_name} en acción`}</h2>
          <div data-reveal><VideoChapters video={content.video} /></div>
        </section>
      )}

      <Results items={content.results} />

      <Testimonials items={content.testimonials} />

      <section className="bg-white border-y border-slate-200/80 py-14 sm:py-20" id="planes">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 space-y-8">
          <div data-reveal className="text-center space-y-2 max-w-2xl mx-auto">
            <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">{landing?.plans_title || site.texts.pricing_title}</h2>
            {(landing?.plans_text || site.texts.pricing_text) && <p className="text-slate-500 text-sm">{landing?.plans_text || site.texts.pricing_text}</p>}
          </div>
          <div data-reveal style={{ ['--d' as string]: '120ms' }}><PricingPlans plans={plans} allowSignup={site.allow_signup} /></div>
          <p className="text-center"><Link href="/precios" className="text-sm font-bold text-brand-accent hover:text-brand-ink">Comparar planes en detalle →</Link></p>
        </div>
      </section>

      {site.faq.length > 0 && (
        <section className="max-w-3xl mx-auto px-4 sm:px-6 py-14 sm:py-20 space-y-6">
          <h2 data-reveal className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight text-center">Preguntas frecuentes</h2>
          <div data-reveal style={{ ['--d' as string]: '100ms' }}><Faq items={site.faq} /></div>
        </section>
      )}

      <Partners partners={content.partners} allowContact={site.contact_form} />

      <ThreeSteps allowSignup={site.allow_signup} />

      {posts.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-14 space-y-6">
          <div data-reveal className="flex items-end justify-between gap-3">
            <h2 className="text-2xl font-black text-brand-ink tracking-tight">Del blog</h2>
            <Link href="/blog" className="text-sm font-bold text-brand-accent hover:text-brand-ink">Ver todo</Link>
          </div>
          <div data-reveal style={{ ['--d' as string]: '100ms' }}><PostCards posts={posts} /></div>
        </section>
      )}

      <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16 grid grid-cols-1 lg:grid-cols-2 gap-6 items-stretch scroll-mt-20" id="demo">
        <div data-reveal className="flex"><SignupBand site={site} /></div>
        {site.contact_form && <div data-reveal style={{ ['--d' as string]: '120ms' }} className="flex"><ContactForm /></div>}
      </section>
    </SiteShell>
  );
}
