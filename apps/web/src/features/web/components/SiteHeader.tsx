import Link from 'next/link';
import { Building2 } from 'lucide-react';
import type { SitePayload } from '../web';
import SiteMenu from './SiteMenu';

export const NAV = [
  { href: '/rubros', label: 'Rubros' },
  { href: '/precios', label: 'Precios' },
  { href: '/blog', label: 'Blog' },
];

export default function SiteHeader({ site }: { site: SitePayload }) {
  const brand = site.brand;

  return (
    <header className="w-full bg-white border-b border-slate-200/80 sticky top-0 z-40" data-site-header>
      <div className="max-w-6xl mx-auto px-4 sm:px-6 py-3.5 flex items-center justify-between gap-3">
        <Link href="/" className="flex items-center gap-2.5 min-w-0" aria-label={`${site.product_name}, inicio`}>
          {brand?.logo_url ? (
            <img src={brand.logo_url} alt={site.product_name} className="h-9 max-w-36 object-contain" />
          ) : (
            <>
              <span className="min-w-9 h-9 px-1.5 rounded-xl bg-brand text-brand-soft flex items-center justify-center font-bold text-xs">
                {brand?.short_name ?? <Building2 className="w-5 h-5" />}
              </span>
              <span className="text-lg font-black tracking-tight text-brand-ink truncate">{site.product_name}</span>
            </>
          )}
        </Link>

        <nav className="hidden md:flex items-center gap-6" aria-label="Principal">
          {NAV.map((item) => (
            <Link key={item.href} href={item.href} className="text-sm font-bold text-slate-600 hover:text-brand-ink">{item.label}</Link>
          ))}
        </nav>

        <div className="flex items-center gap-2 sm:gap-3 shrink-0">
          <Link href="/login" className="hidden sm:inline text-sm font-bold text-slate-600 hover:text-brand-ink whitespace-nowrap">Iniciar sesión</Link>
          {site.allow_signup && (
            <Link href="/register" data-cta="header" className="bg-brand hover:bg-brand-hover text-white font-bold text-xs sm:text-sm px-3.5 sm:px-4 py-2 rounded-xl shadow-sm whitespace-nowrap">Prueba gratis</Link>
          )}
          <SiteMenu items={NAV} />
        </div>
      </div>
    </header>
  );
}
