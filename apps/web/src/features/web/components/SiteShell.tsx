import type { Metadata } from 'next';
import SiteHeader from './SiteHeader';
import SiteFooter from './SiteFooter';
import SiteExtras from './SiteExtras';
import { getSite, siteUrl } from '../web';

export async function baseMetadata(): Promise<Metadata> {
  const { site } = await getSite();
  const url = await siteUrl(site);

  return {
    metadataBase: new URL(url),
    title: { default: site.seo_title || site.product_name, template: `%s | ${site.product_name}` },
    description: site.seo_description || site.landing?.description || undefined,
    openGraph: { siteName: site.product_name, locale: 'es_PE', type: 'website' },
    alternates: { types: { 'application/rss+xml': '/blog/rss.xml' } },
  };
}

export default async function SiteShell({ children }: { children: React.ReactNode }) {
  const { site, business_types } = await getSite();

  return (
    <div className="min-h-screen bg-slate-100/70 text-slate-800 font-sans flex flex-col overflow-x-hidden" data-site>
      <SiteHeader site={site} />
      <main className="flex-1">{children}</main>
      <SiteFooter site={site} businessTypes={business_types} />
      <SiteExtras site={site} />
    </div>
  );
}
