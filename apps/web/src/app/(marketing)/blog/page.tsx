import type { Metadata } from 'next';
import Link from 'next/link';
import { Rss } from 'lucide-react';
import { PostCards, SignupBand } from '@/features/web/components/Sections';
import { clip, getSite, webGet, type WebBusinessType, type WebPost } from '@/features/web/web';

export const revalidate = 60;

interface Payload {
  items: WebPost[];
  business_types: WebBusinessType[];
}

export async function generateMetadata({ searchParams }: { searchParams: Promise<{ rubro?: string }> }): Promise<Metadata> {
  const { site } = await getSite();
  const { rubro } = await searchParams;
  const title = 'Blog: ideas para hacer crecer tu negocio';
  const description = clip(`Consejos prácticos de agenda, clientes, ventas y marketing para negocios de servicios, del equipo de ${site.product_name}.`);

  return { title, description, alternates: { canonical: '/blog' }, robots: rubro ? { index: false, follow: true } : undefined, openGraph: { title, description, url: '/blog' } };
}

export default async function BlogPage({ searchParams }: { searchParams: Promise<{ rubro?: string }> }) {
  const { rubro } = await searchParams;
  const [{ site }, data] = await Promise.all([getSite(), webGet<Payload>(`/web/posts${rubro ? `?rubro=${encodeURIComponent(rubro)}` : ''}`)]);
  const posts = data?.items ?? [];
  const types = (data?.business_types ?? []);

  return (
    <>
      <section className="max-w-6xl mx-auto px-4 sm:px-6 py-12 sm:py-16 space-y-6">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div className="space-y-2 max-w-2xl">
            <h1 className="text-3xl sm:text-5xl font-black text-brand-ink tracking-tight">Blog</h1>
            <p className="text-slate-500 text-sm sm:text-base">Ideas prácticas para llenar tu agenda, cuidar a tus clientes y ordenar tu caja.</p>
          </div>
          <a href="/blog/rss.xml" className="inline-flex items-center gap-1.5 text-sm font-bold text-brand-accent hover:text-brand-ink"><Rss size={15} /> RSS</a>
        </div>

        <nav className="flex flex-wrap gap-2" aria-label="Filtrar por rubro">
          <Link href="/blog" className={`px-3 py-1.5 rounded-full border text-sm font-semibold ${!rubro ? 'bg-brand text-white border-transparent' : 'bg-white border-slate-200 text-slate-600 hover:text-brand-ink'}`}>Todos</Link>
          {types.map((type) => (
            <Link key={type.slug} href={`/blog?rubro=${type.slug}`} className={`px-3 py-1.5 rounded-full border text-sm font-semibold ${rubro === type.slug ? 'bg-brand text-white border-transparent' : 'bg-white border-slate-200 text-slate-600 hover:text-brand-ink'}`}>{type.short_name}</Link>
          ))}
        </nav>

        {posts.length ? <PostCards posts={posts} /> : <p className="text-sm text-slate-500 bg-white border border-slate-200 rounded-2xl p-6" data-blog-empty>Pronto publicaremos artículos{rubro ? ' para este rubro' : ''}.</p>}
      </section>
      <section className="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
        <SignupBand site={site} />
      </section>
    </>
  );
}
