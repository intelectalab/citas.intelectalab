import type { Metadata } from 'next';
import { BusinessTypeGrid, SignupBand } from '@/features/web/components/Sections';
import { clip, getSite, jsonLd, siteUrl } from '@/features/web/web';

export const revalidate = 60;

export async function generateMetadata(): Promise<Metadata> {
  const { site, business_types } = await getSite();
  const title = 'Software para tu rubro';
  const description = clip(`${site.texts.rubros_text} ${business_types.map((type) => type.short_name).join(', ')}.`);

  return { title, description, alternates: { canonical: '/rubros' }, openGraph: { title, description, url: '/rubros' } };
}

export default async function BusinessTypesPage() {
  const { site, business_types } = await getSite();
  const url = await siteUrl(site);
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'ItemList',
    name: `Rubros de ${site.product_name}`,
    itemListElement: business_types.map((type, index) => ({ '@type': 'ListItem', position: index + 1, name: type.name, url: `${url}/rubros/${type.slug}` })),
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={jsonLd(schema)} />
      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16 space-y-8">
        <div className="max-w-2xl space-y-3">
          <h1 className="text-3xl sm:text-5xl font-black text-brand-ink tracking-tight">{site.texts.rubros_title}</h1>
          {site.texts.rubros_text && <p className="text-slate-500 text-sm sm:text-base">{site.texts.rubros_text}</p>}
        </div>
        <BusinessTypeGrid types={business_types} />
      </section>
      <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
        <SignupBand site={site} />
      </section>
    </>
  );
}
