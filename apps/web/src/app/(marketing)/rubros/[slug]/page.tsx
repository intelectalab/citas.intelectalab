import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ArrowRight, BellRing, CalendarCheck, Check, ClipboardList, Clock, FileText, Sparkles, Video, X } from 'lucide-react';
import PricingPlans from '@/features/web/components/PricingPlans';
import ContactForm from '@/features/web/components/ContactForm';
import { Faq, PostCards, SignupBand } from '@/features/web/components/Sections';
import { Testimonials } from '@/features/web/components/HomeExtras';
import { clip, getSite, jsonLd, money, offers, siteUrl, webGet, type SiteTestimonial, type WebBusinessType, type WebPost } from '@/features/web/web';
import { MODULE_LABELS } from '@/lib/modules';

export const revalidate = 60;

interface Payload {
  business_type: WebBusinessType;
  others: WebBusinessType[];
  posts: WebPost[];
  testimonials?: SiteTestimonial[];
}

const load = (slug: string) => webGet<Payload>(`/web/rubros/${encodeURIComponent(slug)}`);

const lower = (text: string | undefined, fallback: string) => (text || fallback).toLowerCase();

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const [data, { site }] = await Promise.all([load(slug), getSite()]);
  if (!data) return { title: 'Rubro no encontrado', robots: { index: false } };
  const type = data.business_type;
  const clients = lower(type.vocabulary.clients, 'clientes');
  const title = `Software para ${type.short_name.toLowerCase()}: agenda y ${clients}`;
  const description = clip(`${type.description ?? ''} Agenda en línea, recordatorios, fichas y caja para tu ${type.short_name.toLowerCase()} con ${site.product_name}. Prueba gratis.`);

  return { title, description, alternates: { canonical: `/rubros/${type.slug}` }, openGraph: { title, description, url: `/rubros/${type.slug}` } };
}

export default async function BusinessTypePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [data, { site, plans }] = await Promise.all([load(slug), getSite()]);
  if (!data) notFound();

  const type = data.business_type;
  const url = await siteUrl(site);
  const client = lower(type.vocabulary.client, 'cliente');
  const clients = lower(type.vocabulary.clients, 'clientes');
  const pros = lower(type.vocabulary.pros, 'profesionales');
  const dependents = type.vocabulary.dependents ? type.vocabulary.dependents.toLowerCase() : null;
  const off = new Set(type.modules_off ?? []);
  const highlights = ['booking', 'records', 'packages', 'memberships', 'classes', 'walkin', 'inventory', 'quotes', 'marketing', 'commissions'].filter((key) => !off.has(key) && MODULE_LABELS[key]);
  const services = (type.categories ?? []).flatMap((category) => category.services.map((service) => ({ ...service, category: category.name }))).slice(0, 8);
  const accent = type.color ?? undefined;
  const content = type.content ?? null;
  const recommended = content?.plan ? plans.find((plan) => plan.slug === content.plan) ?? null : null;

  const steps = [
    { icon: CalendarCheck, title: `Tus ${clients} reservan solos`, text: `Eligen servicio, ${pros} y horario desde tu página, a cualquier hora.` },
    { icon: BellRing, title: 'Menos inasistencias', text: `Cada ${client} recibe su recordatorio y confirma con un toque.` },
    { icon: ClipboardList, title: dependents ? `La ficha de cada ${client} y sus ${dependents}` : `La ficha de cada ${client}`, text: 'Antecedentes, notas y consentimientos en un solo lugar, siempre a mano.' },
  ];

  const schema = [
    {
      '@context': 'https://schema.org',
      '@type': 'SoftwareApplication',
      name: `${site.product_name} para ${type.short_name}`,
      applicationCategory: 'BusinessApplication',
      operatingSystem: 'Web',
      description: type.description,
      url: `${url}/rubros/${type.slug}`,
      offers: offers(plans, url),
    },
    ...(content?.faq.length ? [{ '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: content.faq.map((item) => ({ '@type': 'Question', name: item.q, acceptedAnswer: { '@type': 'Answer', text: item.a } })) }] : []),
    {
      '@context': 'https://schema.org',
      '@type': 'BreadcrumbList',
      itemListElement: [
        { '@type': 'ListItem', position: 1, name: 'Inicio', item: url },
        { '@type': 'ListItem', position: 2, name: 'Rubros', item: `${url}/rubros` },
        { '@type': 'ListItem', position: 3, name: type.name, item: `${url}/rubros/${type.slug}` },
      ],
    },
  ];

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={jsonLd(schema)} />

      <section className="border-b border-slate-200/80" style={{ background: accent ? `color-mix(in srgb, ${accent} 8%, white)` : undefined }} data-business-hero={type.slug}>
        <div className="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16 space-y-5">
          <nav aria-label="Ruta" className="text-xs text-slate-500"><Link href="/" className="hover:text-brand-ink">Inicio</Link> / <Link href="/rubros" className="hover:text-brand-ink">Rubros</Link> / <span aria-current="page">{type.short_name}</span></nav>
          <h1 className="text-3xl sm:text-5xl font-black text-brand-ink tracking-tight leading-tight max-w-3xl">
            {content?.h1 ?? <>El sistema para tu {type.short_name.toLowerCase()} que tus {clients} <span style={{ color: accent }}>van a notar</span></>}
          </h1>
          {content?.sub ? <p className="text-slate-600 text-sm sm:text-base max-w-2xl">{content.sub}</p> : type.description && <p className="text-slate-600 text-sm sm:text-base max-w-2xl">{type.description} Agenda, recordatorios, fichas y caja pensados para cómo trabajas.</p>}
          {recommended && (
            <p className="inline-flex flex-wrap items-center gap-2 bg-white/80 border border-slate-200 rounded-full px-3.5 py-1.5 text-xs text-slate-600" data-recommended-plan>
              <Sparkles size={14} style={{ color: accent }} /> Plan recomendado: <strong className="text-brand-ink">{recommended.name}</strong>{recommended.price_monthly > 0 ? ` · ${money(recommended.price_monthly, recommended.currency_symbol ?? 'S/')} al mes` : ''}
            </p>
          )}
          <div className="flex flex-wrap gap-3">
            {site.allow_signup && (
              <Link href={`/register?rubro=${type.slug}`} data-cta="business" className="inline-flex items-center gap-2 bg-brand hover:bg-brand-hover text-white font-bold text-sm px-5 py-3 rounded-xl shadow-md">
                Probar gratis para {type.short_name.toLowerCase()} <ArrowRight size={16} className="text-brand-soft" />
              </Link>
            )}
            <Link href="/precios" className="bg-white hover:bg-slate-50 text-brand-ink border border-slate-200 font-bold text-sm px-5 py-3 rounded-xl">Ver precios</Link>
          </div>
        </div>
      </section>

      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16 grid grid-cols-1 md:grid-cols-3 gap-4">
        {steps.map((step) => (
          <div key={step.title} className="bg-white rounded-2xl border border-slate-200 p-5">
            <step.icon className="w-6 h-6" style={{ color: accent }} />
            <h2 className="mt-3 font-bold text-brand-ink">{step.title}</h2>
            <p className="mt-1 text-sm text-slate-500">{step.text}</p>
          </div>
        ))}
      </section>

      {content && content.pains.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-12 space-y-5" data-before-after>
          <h2 className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight">Antes y después</h2>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
            {content.pains.map((pain) => (
              <div key={pain.before} className="bg-white rounded-2xl border border-slate-200 overflow-hidden">
                <p className="flex items-start gap-2 px-4 py-3 text-sm text-slate-500 bg-slate-50 border-b border-slate-100"><X size={15} className="text-rose-500 shrink-0 mt-0.5" /> <span><span className="sr-only">Antes: </span>{pain.before}</span></p>
                <p className="flex items-start gap-2 px-4 py-3 text-sm font-semibold text-brand-ink"><Check size={15} className="text-emerald-600 shrink-0 mt-0.5" /> <span><span className="sr-only">Ahora: </span>{pain.after}</span></p>
              </div>
            ))}
          </div>
        </section>
      )}

      {content && content.feats.length > 0 && (
        <section className="bg-white border-y border-slate-200/80 py-12 sm:py-16" data-business-benefits>
          <div className="max-w-6xl mx-auto px-4 sm:px-6 space-y-6">
            <h2 className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight">Lo que tu {type.short_name.toLowerCase()} necesita</h2>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
              {content.feats.map((feat) => (
                <div key={feat.title} className="rounded-2xl border border-slate-200 p-5">
                  <span className="block w-8 h-1 rounded-full mb-3" style={{ background: accent }} aria-hidden="true" />
                  <h3 className="font-bold text-brand-ink">{feat.title}</h3>
                  <p className="mt-1 text-sm text-slate-500">{feat.text}</p>
                </div>
              ))}
            </div>
          </div>
        </section>
      )}

      {type.record && type.record.fields.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 grid grid-cols-1 lg:grid-cols-2 gap-6 items-center" data-business-record>
          <div className="space-y-3">
            <h2 className="text-2xl font-black text-brand-ink tracking-tight">La ficha de atención ya viene incluida</h2>
            <p className="text-sm text-slate-500">Tu cuenta trae lista la ficha «{type.record.name}» con los datos que se anotan en cada atención. Puedes agregar o quitar campos cuando quieras.</p>
          </div>
          <div className="bg-white rounded-2xl border border-slate-200 p-5 space-y-2">
            <p className="flex items-center gap-2 text-sm font-bold text-brand-ink"><FileText size={16} style={{ color: accent }} /> {type.record.name}</p>
            <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {type.record.fields.map((field) => (
                <li key={field} className="text-sm text-slate-600 bg-slate-50 border border-slate-100 rounded-lg px-3 py-2">{field}</li>
              ))}
            </ul>
          </div>
        </section>
      )}

      {services.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-12 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
          <div className="space-y-3">
            <h2 className="text-2xl font-black text-brand-ink tracking-tight">Empiezas con tu catálogo listo</h2>
            <p className="text-sm text-slate-500">Al crear tu cuenta ya tienes servicios de ejemplo de {type.short_name.toLowerCase()}, con duración y precio sugeridos. Los cambias cuando quieras.</p>
            {highlights.length > 0 && (
              <ul className="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-2">
                {highlights.map((key) => (
                  <li key={key} className="flex items-center gap-2 text-sm text-slate-700"><Check size={15} className="text-emerald-600 shrink-0" /> {MODULE_LABELS[key]}</li>
                ))}
              </ul>
            )}
          </div>
          <ul className="bg-white rounded-2xl border border-slate-200 divide-y divide-slate-100" data-example-services>
            {services.map((service) => (
              <li key={`${service.category}-${service.name}`} className="px-4 py-3 flex items-center justify-between gap-3 text-sm">
                <span className="min-w-0">
                  <span className="block font-semibold text-slate-800">{service.name}</span>
                  <span className="text-xs text-slate-400 inline-flex items-center gap-1">{service.category} · <Clock size={11} /> {service.minutes} min{service.virtual && <> · <Video size={11} /> en línea</>}</span>
                </span>
                {service.price > 0 && <span className="font-bold text-brand-ink shrink-0">{money(service.price)}</span>}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="bg-white border-y border-slate-200/80 py-12 sm:py-16">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 space-y-6">
          <h2 className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight text-center">Planes para {type.short_name.toLowerCase()}</h2>
          <PricingPlans plans={plans} allowSignup={site.allow_signup} recommended={content?.plan ?? null} recommendedLabel={`Recomendado para ${type.short_name.toLowerCase()}`} />
        </div>
      </section>

      <Testimonials items={data.testimonials ?? []} title={`Lo que dicen en ${type.short_name.toLowerCase()}`} />

      {content && content.faq.length > 0 && (
        <section className="max-w-3xl mx-auto px-4 sm:px-6 py-12 space-y-5" data-business-faq>
          <h2 className="text-2xl font-black text-brand-ink tracking-tight text-center">Preguntas de {type.short_name.toLowerCase()}</h2>
          <Faq items={content.faq} />
        </section>
      )}

      {data.posts.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 space-y-5">
          <h2 className="text-2xl font-black text-brand-ink tracking-tight">Ideas para {type.short_name.toLowerCase()}</h2>
          <PostCards posts={data.posts} />
        </section>
      )}

      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">
        <SignupBand site={site} rubro={type.slug} />
        {site.contact_form && <ContactForm businessType={type.slug} />}
      </section>

      {data.others.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
          <h2 className="text-sm font-bold text-slate-500 mb-3">Otros rubros</h2>
          <div className="flex flex-wrap gap-2">
            {data.others.map((other) => (
              <Link key={other.slug} href={`/rubros/${other.slug}`} className="px-3 py-1.5 rounded-full bg-white border border-slate-200 text-sm font-semibold text-slate-600 hover:text-brand-ink">{other.short_name}</Link>
            ))}
          </div>
        </section>
      )}
    </>
  );
}
