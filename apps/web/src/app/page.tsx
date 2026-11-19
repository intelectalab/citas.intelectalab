import type { Metadata } from 'next';
import Link from 'next/link';
import { ArrowRight, BellRing, CalendarCheck, Check, ClipboardList, Globe, Package, Receipt, Sparkles, X } from 'lucide-react';
import { LookIcon } from '@/features/platform/lookIcons';
import PricingPlans from '@/features/web/components/PricingPlans';
import ContactForm from '@/features/web/components/ContactForm';
import { BusinessTypeGrid, Faq, PostCards, SignupBand } from '@/features/web/components/Sections';
import SiteShell, { baseMetadata } from '@/features/web/components/SiteShell';
import { getSite, jsonLd, offers, siteUrl } from '@/features/web/web';

export const revalidate = 60;

export async function generateMetadata(): Promise<Metadata> {
  const { site } = await getSite();
  const title = site.seo_title || `${site.product_name}: agenda, clientes y caja para tu negocio`;
  const description = site.seo_description || site.landing?.description || 'Agenda en línea, recordatorios, fichas, caja y reportes para negocios de servicios. Prueba gratis sin tarjeta.';

  return { ...(await baseMetadata()), title: { absolute: title }, description, alternates: { canonical: '/' }, openGraph: { title, description, url: '/' } };
}

const FEATURES = [
  { icon: CalendarCheck, title: 'Agenda y reservas en línea', text: 'Tus clientes reservan solos a cualquier hora, sin cruces de horario.' },
  { icon: BellRing, title: 'Recordatorios automáticos', text: 'Avisos por correo y WhatsApp para que falten menos.' },
  { icon: Receipt, title: 'Caja y comprobantes', text: 'Cobra, emite boletas y facturas y cierra la caja del día.' },
  { icon: ClipboardList, title: 'Fichas y antecedentes', text: 'La historia de cada cliente o paciente, a mano y segura.' },
  { icon: Package, title: 'Paquetes y membresías', text: 'Vende sesiones por adelantado y planes mensuales.' },
  { icon: Globe, title: 'Tu página de reservas', text: 'Con tus servicios, horarios y redes, lista para compartir.' },
];

const COMPARE = [
  ['Las citas se anotan en un cuaderno o en el chat', 'Agenda en línea con horarios reales de cada profesional'],
  ['Hay que recordar a cada cliente uno por uno', 'Recordatorios que salen solos'],
  ['La caja se cuadra a mano al final del día', 'Cada cobro queda registrado con su comprobante'],
  ['Nadie sabe cuánto vendió cada profesional', 'Reportes y comisiones al día'],
];

export default async function HomePage() {
  const { site, plans, business_types, posts } = await getSite();
  const landing = site.landing;
  const url = await siteUrl(site);
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

      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 md:py-20 grid grid-cols-1 md:grid-cols-2 gap-10 md:gap-12 items-center" data-site-hero>
        <div className="space-y-6 min-w-0">
          {landing?.tag && (
            <span className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-soft/15 border border-brand-soft/30 text-brand-ink text-xs font-bold" data-landing-tag>
              <Sparkles className="w-3.5 h-3.5 text-brand-accent" /> {landing.tag}
            </span>
          )}
          <h1 className="text-3xl sm:text-5xl font-black text-brand-ink tracking-tight leading-tight break-words" data-landing-title>
            {landing?.title || site.product_name}{landing?.highlight && <> <span className="text-brand-accent">{landing.highlight}</span></>}
          </h1>
          {landing?.description && <p className="text-slate-500 text-sm sm:text-base font-medium leading-relaxed">{landing.description}</p>}
          <div className="flex flex-wrap items-center gap-3 pt-2">
            <Link href="#planes" data-cta="hero" className="bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-xl text-sm shadow-md inline-flex items-center gap-2">
              {landing?.primary_button || 'Prueba gratis'} <ArrowRight className="w-4 h-4 text-brand-soft" />
            </Link>
            <Link href="/precios" className="bg-white hover:bg-slate-50 text-brand-ink border border-slate-200 font-bold py-3 px-6 rounded-xl text-sm">Ver precios</Link>
          </div>
          <p className="text-xs text-slate-500 flex flex-wrap gap-x-4 gap-y-1">
            <span className="inline-flex items-center gap-1"><Check size={14} className="text-emerald-600" /> Sin tarjeta</span>
            <span className="inline-flex items-center gap-1"><Check size={14} className="text-emerald-600" /> Lista en minutos</span>
            <span className="inline-flex items-center gap-1"><Check size={14} className="text-emerald-600" /> Soporte en español</span>
          </p>
        </div>

        {landing?.image_url ? (
          <img src={landing.image_url} alt="" className="w-full max-h-[26rem] object-cover rounded-3xl border border-slate-200 shadow-xl" data-landing-image />
        ) : (
          <div className="space-y-3" data-landing-cards>
            {(landing?.cards?.length ? landing.cards : [{ icon: 'calendar', title: 'Agenda del día', text: 'Citas confirmadas, en espera y atendidas de un vistazo.' }, { icon: 'wallet', title: 'Caja al día', text: 'Cobros, comprobantes y cierre sin hojas sueltas.' }, { icon: 'users', title: 'Clientes que vuelven', text: 'Recordatorios, paquetes y campañas.' }]).map((card, index) => (
              <div key={index} className="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm flex items-start gap-4">
                <span className="p-2.5 rounded-xl bg-brand-soft/20 text-brand-accent shrink-0"><LookIcon name={card.icon} className="w-5 h-5" /></span>
                <span className="min-w-0">
                  <span className="block font-bold text-brand-ink">{card.title}</span>
                  {card.text && <span className="block text-sm text-slate-500 mt-0.5">{card.text}</span>}
                </span>
              </div>
            ))}
          </div>
        )}
      </section>

      <section className="bg-white border-y border-slate-200/80 py-14 sm:py-20" id="rubros">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 space-y-8">
          <div className="text-center space-y-2 max-w-2xl mx-auto">
            <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">{site.texts.rubros_title}</h2>
            {site.texts.rubros_text && <p className="text-slate-500 text-sm">{site.texts.rubros_text}</p>}
          </div>
          <BusinessTypeGrid types={business_types} />
        </div>
      </section>

      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-14 sm:py-20 space-y-8">
        <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight text-center">Todo tu negocio en un solo lugar</h2>
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {FEATURES.map((feature) => (
            <div key={feature.title} className="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs">
              <feature.icon className="w-6 h-6 text-brand-accent" />
              <h3 className="mt-3 font-bold text-brand-ink">{feature.title}</h3>
              <p className="mt-1 text-sm text-slate-500">{feature.text}</p>
            </div>
          ))}
        </div>

        <div className="bg-white rounded-2xl border border-slate-200 overflow-hidden" data-compare>
          <div className="grid grid-cols-2 text-xs sm:text-sm font-bold">
            <span className="px-4 py-3 bg-slate-50 text-slate-500">Con cuaderno o WhatsApp</span>
            <span className="px-4 py-3 bg-brand-soft/15 text-brand-ink">Con {site.product_name}</span>
          </div>
          {COMPARE.map(([before, after]) => (
            <div key={before} className="grid grid-cols-2 border-t border-slate-100 text-xs sm:text-sm">
              <span className="px-4 py-3 text-slate-500 flex gap-2"><X size={15} className="text-rose-500 shrink-0 mt-0.5" /> {before}</span>
              <span className="px-4 py-3 text-slate-700 flex gap-2"><Check size={15} className="text-emerald-600 shrink-0 mt-0.5" /> {after}</span>
            </div>
          ))}
        </div>
      </section>

      <section className="bg-white border-y border-slate-200/80 py-14 sm:py-20" id="planes">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 space-y-8">
          <div className="text-center space-y-2 max-w-2xl mx-auto">
            <h2 className="text-2xl sm:text-4xl font-black text-brand-ink tracking-tight">{landing?.plans_title || site.texts.pricing_title}</h2>
            {(landing?.plans_text || site.texts.pricing_text) && <p className="text-slate-500 text-sm">{landing?.plans_text || site.texts.pricing_text}</p>}
          </div>
          <PricingPlans plans={plans} allowSignup={site.allow_signup} />
          <p className="text-center"><Link href="/precios" className="text-sm font-bold text-brand-accent hover:text-brand-ink">Comparar planes en detalle →</Link></p>
        </div>
      </section>

      {site.faq.length > 0 && (
        <section className="max-w-3xl mx-auto px-4 sm:px-6 py-14 sm:py-20 space-y-6">
          <h2 className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight text-center">Preguntas frecuentes</h2>
          <Faq items={site.faq} />
        </section>
      )}

      {posts.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-14 space-y-6">
          <div className="flex items-end justify-between gap-3">
            <h2 className="text-2xl font-black text-brand-ink tracking-tight">Del blog</h2>
            <Link href="/blog" className="text-sm font-bold text-brand-accent hover:text-brand-ink">Ver todo</Link>
          </div>
          <PostCards posts={posts} />
        </section>
      )}

      <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        <SignupBand site={site} />
        {site.contact_form && <ContactForm />}
      </section>
    </SiteShell>
  );
}
