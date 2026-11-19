import Link from 'next/link';
import { BookOpenText, Mail, MessageCircle, Phone } from 'lucide-react';
import type { SitePayload, WebBusinessType } from '../web';
import CookieSettingsLink from './CookieSettingsLink';

export default function SiteFooter({ site, businessTypes }: { site: SitePayload; businessTypes: WebBusinessType[] }) {
  const year = new Date().getFullYear();

  return (
    <footer className="bg-white border-t border-slate-200/80" data-site-footer>
      <div className="max-w-6xl mx-auto px-4 sm:px-6 py-10 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8 text-sm">
        <div className="space-y-2">
          <span className="block text-base font-black text-brand-ink">{site.product_name}</span>
          {site.landing?.description && <p className="text-slate-500 text-xs leading-relaxed">{site.landing.description}</p>}
          {site.legal_name && <p className="text-xs text-slate-400">{site.legal_name}{site.legal_tax_id ? ` · RUC ${site.legal_tax_id}` : ''}</p>}
        </div>
        <div className="space-y-2">
          <span className="block font-bold text-brand-ink">Rubros</span>
          <ul className="space-y-1.5">
            {businessTypes.slice(0, 6).map((type) => (
              <li key={type.slug}><Link href={`/rubros/${type.slug}`} className="text-slate-500 hover:text-brand-ink">{type.short_name}</Link></li>
            ))}
            <li><Link href="/rubros" className="font-semibold text-brand-accent hover:text-brand-ink">Ver todos</Link></li>
          </ul>
        </div>
        <div className="space-y-2">
          <span className="block font-bold text-brand-ink">Producto</span>
          <ul className="space-y-1.5">
            <li><Link href="/precios" className="text-slate-500 hover:text-brand-ink">Precios</Link></li>
            <li><Link href="/blog" className="text-slate-500 hover:text-brand-ink">Blog</Link></li>
            <li><Link href="/register" className="text-slate-500 hover:text-brand-ink">Prueba gratis</Link></li>
            <li><Link href="/login" className="text-slate-500 hover:text-brand-ink">Iniciar sesión</Link></li>
          </ul>
        </div>
        <div className="space-y-2">
          <span className="block font-bold text-brand-ink">Contacto y legal</span>
          <ul className="space-y-1.5">
            {site.whatsapp && <li><a href={`https://wa.me/${site.whatsapp}?text=${encodeURIComponent(site.whatsapp_message)}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 text-slate-500 hover:text-brand-ink"><MessageCircle size={14} /> WhatsApp</a></li>}
            {site.phone && <li><a href={`tel:${site.phone.replace(/\s/g, '')}`} className="inline-flex items-center gap-1.5 text-slate-500 hover:text-brand-ink"><Phone size={14} /> {site.phone}</a></li>}
            {site.email && <li><a href={`mailto:${site.email}`} className="inline-flex items-center gap-1.5 text-slate-500 hover:text-brand-ink break-all"><Mail size={14} className="shrink-0" /> {site.email}</a></li>}
            <li><Link href="/legal/terminos" className="text-slate-500 hover:text-brand-ink">Términos y condiciones</Link></li>
            <li><Link href="/legal/privacidad" className="text-slate-500 hover:text-brand-ink">Privacidad</Link></li>
            <li><Link href="/legal/cookies" className="text-slate-500 hover:text-brand-ink">Cookies</Link> <CookieSettingsLink /></li>
            <li><Link href="/reclamaciones" data-landing-complaints className="inline-flex items-center gap-1.5 font-semibold text-slate-600 hover:text-brand-ink"><BookOpenText size={14} /> Libro de Reclamaciones</Link></li>
          </ul>
        </div>
      </div>
      <div className="border-t border-slate-100">
        <div className="max-w-6xl mx-auto px-4 sm:px-6 py-4 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500" data-landing-footer>
          <span>© {year} {site.product_name}</span>
          {site.brand?.made_by && (
            site.brand.made_by_url
              ? <a href={site.brand.made_by_url} target="_blank" rel="noreferrer" className="font-semibold text-brand-accent hover:text-brand-ink">{site.brand.made_by}</a>
              : <span className="font-semibold">{site.brand.made_by}</span>
          )}
        </div>
      </div>
    </footer>
  );
}
