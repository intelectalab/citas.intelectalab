import type { Metadata } from 'next';
import { Check, Minus } from 'lucide-react';
import PricingPlans from '@/features/web/components/PricingPlans';
import { Faq, SignupBand } from '@/features/web/components/Sections';
import { clip, getSite, jsonLd, siteUrl, type WebPlan } from '@/features/web/web';
import { MODULE_LABELS, OPTIONAL_MODULE_KEYS } from '@/lib/modules';

export const revalidate = 60;

export async function generateMetadata(): Promise<Metadata> {
  const { site, plans } = await getSite();
  const from = plans.filter((plan) => plan.price_monthly > 0).sort((a, b) => a.price_monthly - b.price_monthly)[0];
  const title = `Precios y planes${from ? ` desde ${from.currency_symbol ?? 'S/'} ${from.price_monthly} al mes` : ''}`;
  const description = clip(`${site.texts.pricing_text || 'Planes por mes o por año.'} Compara ${plans.map((plan) => plan.name).join(', ')} y empieza con la prueba gratis.`);

  return { title, description, alternates: { canonical: '/precios' }, openGraph: { title, description, url: '/precios' } };
}

const LIMITS: { key: string; label: string; unit?: string }[] = [
  { key: 'professionals', label: 'Profesionales' },
  { key: 'users', label: 'Cuentas del equipo' },
  { key: 'branches', label: 'Sedes' },
  { key: 'appointments_month', label: 'Citas al mes' },
  { key: 'whatsapp_month', label: 'Mensajes de WhatsApp al mes' },
];

const BILLING_FAQ = [
  { q: '¿Cuándo se me cobra?', a: 'Durante la prueba gratis no se cobra nada. Al activar tu plan se cobra el primer periodo, por mes o por año, y luego se renueva solo.' },
  { q: '¿Cuánto ahorro pagando el año?', a: 'El precio anual sale más barato que pagar doce meses: la diferencia se muestra en cada plan al elegir «Anual».' },
  { q: '¿Puedo pagar por transferencia, Yape o Plin?', a: 'Sí, en el pago anual. El pago mensual se hace con tarjeta y cobro automático.' },
  { q: '¿Tengo un cupón, dónde lo uso?', a: 'Al crear tu cuenta, en el campo «Cupón», o después en Mi empresa → Tu plan. El descuento se aplica en tus primeros cobros.' },
  { q: '¿Emiten comprobante?', a: 'Sí, cada pago tiene su constancia y puedes descargarla desde Mi empresa.' },
];

function cell(plan: WebPlan, key: string) {
  const value = plan.limits?.[key];
  if (value === undefined || value === null || value === 0) return <Minus size={16} className="mx-auto text-slate-300" aria-label="No incluye" />;
  if (value < 0) return 'Sin límite';
  return value.toLocaleString('es-PE');
}

export default async function PricingPage() {
  const { site, plans } = await getSite();
  const url = await siteUrl(site);
  const included = (plan: WebPlan) => new Set(Object.values(plan.modules ?? {}).flat());
  const modules = OPTIONAL_MODULE_KEYS.filter((key) => plans.some((plan) => included(plan).has(key)));
  const schema = plans.map((plan) => ({
    '@context': 'https://schema.org',
    '@type': 'Product',
    name: `${site.product_name} ${plan.name}`,
    description: plan.description || plan.tagline || undefined,
    brand: { '@type': 'Brand', name: site.product_name },
    offers: [
      { '@type': 'Offer', price: plan.price_monthly, priceCurrency: plan.currency, url: `${url}/precios`, availability: 'https://schema.org/InStock', priceSpecification: { '@type': 'UnitPriceSpecification', price: plan.price_monthly, priceCurrency: plan.currency, unitText: 'MONTH' } },
      ...(plan.price_yearly > 0 ? [{ '@type': 'Offer', price: plan.price_yearly, priceCurrency: plan.currency, url: `${url}/precios`, availability: 'https://schema.org/InStock', priceSpecification: { '@type': 'UnitPriceSpecification', price: plan.price_yearly, priceCurrency: plan.currency, unitText: 'ANN' } }] : []),
    ],
  }));
  const faqSchema = { '@context': 'https://schema.org', '@type': 'FAQPage', mainEntity: BILLING_FAQ.map((item) => ({ '@type': 'Question', name: item.q, acceptedAnswer: { '@type': 'Answer', text: item.a } })) };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={jsonLd([...schema, faqSchema])} />
      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16 space-y-8">
        <div className="text-center space-y-3 max-w-2xl mx-auto">
          <h1 className="text-3xl sm:text-5xl font-black text-brand-ink tracking-tight">{site.texts.pricing_title}</h1>
          {site.texts.pricing_text && <p className="text-slate-500 text-sm sm:text-base">{site.texts.pricing_text}</p>}
        </div>
        <PricingPlans plans={plans} allowSignup={site.allow_signup} />
      </section>

      {plans.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-14 space-y-4">
          <h2 className="text-2xl font-black text-brand-ink tracking-tight">Compara los planes</h2>
          <div className="bg-white rounded-2xl border border-slate-200 overflow-x-auto" data-compare-plans>
            <table className="w-full min-w-[560px] text-sm">
              <thead>
                <tr className="bg-slate-50 text-left">
                  <th className="px-4 py-3 font-bold text-slate-500">Incluye</th>
                  {plans.map((plan) => <th key={plan.id} className={`px-4 py-3 font-bold text-center ${plan.is_popular ? 'text-brand-accent' : 'text-brand-ink'}`}>{plan.name}</th>)}
                </tr>
              </thead>
              <tbody>
                {LIMITS.filter((limit) => plans.some((plan) => plan.limits?.[limit.key])).map((limit) => (
                  <tr key={limit.key} className="border-t border-slate-100">
                    <td className="px-4 py-2.5 text-slate-600">{limit.label}</td>
                    {plans.map((plan) => <td key={plan.id} className="px-4 py-2.5 text-center font-semibold text-slate-800">{cell(plan, limit.key)}</td>)}
                  </tr>
                ))}
                {modules.map((key) => (
                  <tr key={key} className="border-t border-slate-100">
                    <td className="px-4 py-2.5 text-slate-600">{MODULE_LABELS[key]}</td>
                    {plans.map((plan) => (
                      <td key={plan.id} className="px-4 py-2.5 text-center">
                        {included(plan).has(key) ? <Check size={16} className="mx-auto text-emerald-600" aria-label="Incluye" /> : <Minus size={16} className="mx-auto text-slate-300" aria-label="No incluye" />}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
      )}

      <section className="max-w-3xl mx-auto px-4 sm:px-6 pb-14 space-y-5">
        <h2 className="text-2xl font-black text-brand-ink tracking-tight text-center">Preguntas sobre pagos</h2>
        <Faq items={BILLING_FAQ} />
      </section>

      <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
        <SignupBand site={site} />
      </section>
    </>
  );
}
