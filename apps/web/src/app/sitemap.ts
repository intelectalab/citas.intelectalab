import type { MetadataRoute } from 'next';
import { getSite, siteUrl, webGet } from '@/features/web/web';

export const revalidate = 300;

interface Payload {
  plans_updated_at: string | null;
  business_types: { slug: string; updated_at: string | null }[];
  posts: { slug: string; published_at: string; updated_at: string }[];
  legal_updated_at: string | null;
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [{ site }, data] = await Promise.all([getSite(), webGet<Payload>('/web/sitemap')]);
  const url = await siteUrl(site);
  const date = (value: string | null | undefined) => (value ? new Date(value) : undefined);

  return [
    { url, changeFrequency: 'weekly', priority: 1 },
    { url: `${url}/precios`, lastModified: date(data?.plans_updated_at), changeFrequency: 'weekly', priority: 0.9 },
    { url: `${url}/rubros`, changeFrequency: 'monthly', priority: 0.8 },
    ...(data?.business_types ?? []).map((type) => ({ url: `${url}/rubros/${type.slug}`, lastModified: date(type.updated_at), changeFrequency: 'monthly' as const, priority: 0.8 })),
    { url: `${url}/blog`, changeFrequency: 'weekly', priority: 0.6 },
    ...(data?.posts ?? []).map((post) => ({ url: `${url}/blog/${post.slug}`, lastModified: date(post.updated_at), changeFrequency: 'monthly' as const, priority: 0.6 })),
    ...['terminos', 'privacidad', 'cookies'].map((page) => ({ url: `${url}/legal/${page}`, lastModified: date(data?.legal_updated_at), changeFrequency: 'yearly' as const, priority: 0.2 })),
    { url: `${url}/reclamaciones`, changeFrequency: 'yearly', priority: 0.2 },
    { url: `${url}/register`, changeFrequency: 'monthly', priority: 0.7 },
  ];
}
