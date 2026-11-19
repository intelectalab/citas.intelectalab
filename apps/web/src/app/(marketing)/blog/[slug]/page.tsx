import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ArrowLeft, Clock } from 'lucide-react';
import { PostCards, SignupBand } from '@/features/web/components/Sections';
import { getSite, jsonLd, shortDate, siteUrl, webGet, type WebPost } from '@/features/web/web';

export const revalidate = 60;

interface Payload {
  post: WebPost;
  related: WebPost[];
}

const load = (slug: string) => webGet<Payload>(`/web/blog/${encodeURIComponent(slug)}`);

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await load(slug);
  if (!data) return { title: 'Artículo no encontrado', robots: { index: false } };
  const { post } = data;
  const title = post.seo_title || post.title;
  const description = post.seo_description || post.excerpt || undefined;

  return {
    title: { absolute: title },
    description,
    alternates: { canonical: `/blog/${post.slug}` },
    openGraph: { type: 'article', title, description, url: `/blog/${post.slug}`, publishedTime: post.published_at ?? undefined, modifiedTime: post.updated_at, images: post.cover_url ? [{ url: post.cover_url, width: 1200, height: 630 }] : undefined },
    twitter: { card: post.cover_url ? 'summary_large_image' : 'summary', title, description },
  };
}

export default async function PostPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const [data, { site }] = await Promise.all([load(slug), getSite()]);
  if (!data) notFound();

  const { post, related } = data;
  const url = await siteUrl(site);
  const signup = post.business_type ? `/register?rubro=${post.business_type}` : '/register';
  const schema = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: post.title,
    description: post.seo_description || post.excerpt,
    datePublished: post.published_at,
    dateModified: post.updated_at,
    image: post.cover_url ?? undefined,
    author: { '@type': post.author ? 'Person' : 'Organization', name: post.author || site.product_name },
    publisher: { '@type': 'Organization', name: site.legal_name || site.product_name },
    mainEntityOfPage: `${url}/blog/${post.slug}`,
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={jsonLd(schema)} />
      <article className="max-w-3xl mx-auto px-4 sm:px-6 py-10 sm:py-14 space-y-6" data-blog-post={post.slug}>
        <Link href="/blog" className="inline-flex items-center gap-1.5 text-sm font-bold text-brand-accent hover:text-brand-ink"><ArrowLeft size={16} /> Blog</Link>
        <header className="space-y-3">
          {post.business_type_name && post.business_type && <Link href={`/rubros/${post.business_type}`} className="text-xs font-bold text-brand-accent">{post.business_type_name}</Link>}
          <h1 className="text-3xl sm:text-4xl font-black text-brand-ink tracking-tight leading-tight break-words">{post.title}</h1>
          {post.excerpt && <p className="text-slate-500 text-base">{post.excerpt}</p>}
          <p className="text-xs text-slate-400 flex flex-wrap items-center gap-x-2"><span>{post.author || site.product_name}</span> · <span>{shortDate(post.published_at)}</span> · <span className="inline-flex items-center gap-1"><Clock size={12} /> {post.reading_minutes} min de lectura</span></p>
        </header>
        {post.cover_url && <img src={post.cover_url} alt="" className="w-full rounded-2xl border border-slate-200 aspect-[1200/630] object-cover" />}
        <div className="rich-text text-slate-700 text-base" dangerouslySetInnerHTML={{ __html: post.body ?? '' }} />
        <aside className="bg-brand-soft/15 border border-brand-soft/30 rounded-2xl p-5 flex flex-col sm:flex-row sm:items-center justify-between gap-3" data-post-signup>
          <span className="font-bold text-brand-ink">¿Quieres probarlo en tu negocio? Es gratis y sin tarjeta.</span>
          <Link href={signup} data-cta="post" className="shrink-0 inline-flex justify-center bg-brand hover:bg-brand-hover text-white font-bold text-sm px-5 py-2.5 rounded-xl">Crear mi cuenta gratis</Link>
        </aside>
      </article>

      {related.length > 0 && (
        <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-12 space-y-5">
          <h2 className="text-xl font-black text-brand-ink tracking-tight">Sigue leyendo</h2>
          <PostCards posts={related} />
        </section>
      )}
      <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
        <SignupBand site={site} rubro={post.business_type ?? undefined} />
      </section>
    </>
  );
}
