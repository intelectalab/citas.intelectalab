import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { getSite, shortDate, webGet } from '@/features/web/web';

export const revalidate = 60;

const PAGES: Record<string, string> = {
  terminos: 'Términos y condiciones',
  privacidad: 'Política de privacidad',
  cookies: 'Política de cookies',
};

interface Payload {
  title: string;
  html: string;
  updated_at: string | null;
}

export async function generateMetadata({ params }: { params: Promise<{ page: string }> }): Promise<Metadata> {
  const { page } = await params;
  const { site } = await getSite();
  if (!PAGES[page]) return { title: 'Página no encontrada', robots: { index: false } };

  return { title: PAGES[page], description: `${PAGES[page]} de ${site.product_name}.`, alternates: { canonical: `/legal/${page}` } };
}

export default async function LegalPage({ params }: { params: Promise<{ page: string }> }) {
  const { page } = await params;
  if (!PAGES[page]) notFound();
  const data = await webGet<Payload>(`/web/legal/${page}`);
  if (!data) notFound();

  return (
    <article className="max-w-3xl mx-auto px-4 sm:px-6 py-10 sm:py-14 space-y-6" data-legal={page}>
      <nav className="flex flex-wrap gap-2 text-sm" aria-label="Páginas legales">
        {Object.entries(PAGES).map(([key, label]) => (
          <Link key={key} href={`/legal/${key}`} className={`px-3 py-1.5 rounded-full border font-semibold ${key === page ? 'bg-brand text-white border-transparent' : 'bg-white border-slate-200 text-slate-600 hover:text-brand-ink'}`}>{label}</Link>
        ))}
        <Link href="/reclamaciones" className="px-3 py-1.5 rounded-full border bg-white border-slate-200 text-slate-600 hover:text-brand-ink font-semibold">Libro de Reclamaciones</Link>
      </nav>
      <h1 className="text-3xl sm:text-4xl font-black text-brand-ink tracking-tight">{data.title}</h1>
      {data.updated_at && <p className="text-xs text-slate-400">Actualizado el {shortDate(data.updated_at)}</p>}
      <div className="rich-text text-slate-700 bg-white border border-slate-200 rounded-2xl p-5 sm:p-8" dangerouslySetInnerHTML={{ __html: data.html }} />
    </article>
  );
}
