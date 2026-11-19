import { getSite, siteUrl, webGet } from '@/features/web/web';

export const revalidate = 300;

interface SitemapPayload {
  posts: { slug: string; title: string; excerpt: string | null; seo_description: string | null; published_at: string; updated_at: string }[];
}

const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

export async function GET() {
  const [{ site }, data] = await Promise.all([getSite(), webGet<SitemapPayload>('/web/sitemap')]);
  const url = await siteUrl(site);
  const items = (data?.posts ?? []).map((post) => `    <item>
      <title>${escape(post.title)}</title>
      <link>${url}/blog/${post.slug}</link>
      <guid isPermaLink="true">${url}/blog/${post.slug}</guid>
      <pubDate>${new Date(post.published_at).toUTCString()}</pubDate>
      <description>${escape(post.seo_description || post.excerpt || '')}</description>
    </item>`).join('\n');

  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
  <channel>
    <title>${escape(`Blog de ${site.product_name}`)}</title>
    <link>${url}/blog</link>
    <atom:link href="${url}/blog/rss.xml" rel="self" type="application/rss+xml" />
    <description>${escape('Ideas prácticas para negocios de servicios.')}</description>
    <language>es-PE</language>
${items}
  </channel>
</rss>
`;

  return new Response(xml, { headers: { 'Content-Type': 'application/rss+xml; charset=utf-8' } });
}
