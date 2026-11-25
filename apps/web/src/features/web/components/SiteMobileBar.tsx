'use client';

import { useEffect } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Home, MessageCircle, Rocket, Tags } from 'lucide-react';

export default function SiteMobileBar({ allowSignup, whatsapp, whatsappMessage }: { allowSignup: boolean; whatsapp: string | null; whatsappMessage: string }) {
  const pathname = usePathname();

  useEffect(() => {
    const media = window.matchMedia('(max-width: 767px)');
    const apply = () => document.documentElement.style.setProperty('--site-bar', media.matches ? '4.25rem' : '0px');
    apply();
    media.addEventListener('change', apply);
    return () => {
      media.removeEventListener('change', apply);
      document.documentElement.style.removeProperty('--site-bar');
    };
  }, []);

  const item = (active: boolean) => `flex-1 flex flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-bold ${active ? 'text-brand-ink' : 'text-slate-500'}`;

  return (
    <>
      <div className="md:hidden h-[4.25rem]" aria-hidden="true" />
      <nav className="md:hidden fixed inset-x-0 bottom-0 z-40 bg-white/95 backdrop-blur border-t border-slate-200 flex items-stretch px-2 pb-[env(safe-area-inset-bottom)]" aria-label="Accesos rápidos" data-site-mobile-bar>
        <Link href="/" className={item(pathname === '/')} aria-current={pathname === '/' ? 'page' : undefined}><Home size={19} /> Inicio</Link>
        <Link href="/precios" className={item(pathname.startsWith('/precios'))} aria-current={pathname.startsWith('/precios') ? 'page' : undefined}><Tags size={19} /> Precios</Link>
        {whatsapp && (
          <a href={`https://wa.me/${whatsapp}?text=${encodeURIComponent(whatsappMessage)}`} target="_blank" rel="noopener noreferrer" className={item(false)}><MessageCircle size={19} /> WhatsApp</a>
        )}
        {allowSignup && (
          <Link href="/register" data-cta="mobile-bar" className="flex-1 flex flex-col items-center justify-center gap-0.5 my-1.5 mx-1 rounded-xl bg-brand text-white text-[11px] font-bold"><Rocket size={18} /> Prueba gratis</Link>
        )}
      </nav>
    </>
  );
}
