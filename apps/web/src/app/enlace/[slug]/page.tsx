import type { Metadata } from 'next';
import LinkPageView from '@/features/booking/components/LinkPageView';
import { jsonLd, siteUrl, webGet } from '@/features/web/web';

interface LinkSeo {
  name: string;
  title: string;
  bio: string | null;
  cover_url: string | null;
  phone: string | null;
  business_type: string | null;
  brand: { logo: string | null };
  branches: { name: string; address: string | null; phone: string | null }[];
  socials: Record<string, string | null>;
}

const load = (slug: string) => webGet<LinkSeo>(`/public/link/${slug}`);

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await load(slug);
  if (!data) return { title: 'Reserva en línea' };
  const description = (data.bio || `Reserva tu cita en ${data.name} en línea.`).slice(0, 160);

  return {
    title: `${data.title} · Reserva en línea`,
    description,
    alternates: { canonical: `/enlace/${slug}` },
    openGraph: { title: data.title, description, images: data.cover_url ? [data.cover_url] : data.brand.logo ? [data.brand.logo] : undefined, type: 'website' },
  };
}

export default async function Page({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const data = await load(slug);
  const base = await siteUrl();
  const branch = data?.branches.find((b) => b.address) ?? null;
  const schema = data ? {
    '@context': 'https://schema.org',
    '@type': 'LocalBusiness',
    name: data.name,
    description: data.bio ?? undefined,
    url: `${base}/enlace/${slug}`,
    image: data.cover_url ?? data.brand.logo ?? undefined,
    telephone: branch?.phone ?? data.phone ?? undefined,
    address: branch?.address ? { '@type': 'PostalAddress', streetAddress: branch.address, addressCountry: 'PE' } : undefined,
    sameAs: Object.values(data.socials).filter((v): v is string => Boolean(v && /^https?:\/\//i.test(v))),
    potentialAction: { '@type': 'ReserveAction', target: `${base}/reservar/${slug}` },
  } : null;

  return (
    <>
      {schema && <script type="application/ld+json" dangerouslySetInnerHTML={jsonLd(schema)} />}
      <LinkPageView slug={slug} />
    </>
  );
}
