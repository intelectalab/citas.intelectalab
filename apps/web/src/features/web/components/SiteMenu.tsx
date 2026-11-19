'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Menu, X } from 'lucide-react';

export default function SiteMenu({ items }: { items: { href: string; label: string }[] }) {
  const [open, setOpen] = useState(false);
  const pathname = usePathname();

  useEffect(() => { setOpen(false); }, [pathname]);

  return (
    <div className="md:hidden">
      <button type="button" aria-label={open ? 'Cerrar menú' : 'Abrir menú'} aria-expanded={open} onClick={() => setOpen(!open)} className="p-2 rounded-lg text-slate-600 hover:bg-slate-100 cursor-pointer" data-site-menu>
        {open ? <X size={20} /> : <Menu size={20} />}
      </button>
      {open && (
        <nav className="absolute left-0 right-0 top-full bg-white border-b border-slate-200 shadow-lg px-4 py-3 flex flex-col" aria-label="Menú del sitio">
          {[...items, { href: '/login', label: 'Iniciar sesión' }].map((item) => (
            <Link key={item.href} href={item.href} className="py-2.5 text-sm font-bold text-slate-700 border-b border-slate-100 last:border-0">{item.label}</Link>
          ))}
        </nav>
      )}
    </div>
  );
}
