'use client';

import { useEffect, useRef, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { CalendarPlus, ListOrdered, Plus, ShoppingCart, UserPlus, Hourglass } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { openPath } from './GlobalSearch';

export default function QuickCreate() {
  const { user, can, hasModule } = useAuth();
  const vocab = useVocabulary();
  const router = useRouter();
  const pathname = usePathname();
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onClick = (event: MouseEvent) => {
      if (ref.current && !ref.current.contains(event.target as Node)) setOpen(false);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setOpen(false);
    };
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  if (!user?.tenant) return null;

  const actions = [
    { key: 'appointment', label: 'Nueva cita', icon: CalendarPlus, url: '/agenda?nueva=1', allowed: hasModule('agenda') && can('agenda.create') },
    { key: 'customer', label: `${vocab.pick('client', 'Nuevo', 'Nueva')} ${vocab.client.toLowerCase()}`, icon: UserPlus, url: '/customers?nuevo=1', allowed: hasModule('crm') && can('customers.create') },
    { key: 'sale', label: 'Nueva venta', icon: ShoppingCart, url: '/pos', allowed: hasModule('pos') && can('pos.create') },
    { key: 'walkin', label: 'Anotar en la cola sin cita', icon: ListOrdered, url: '/walkin', allowed: hasModule('walkin') && can('walkin.create') },
    { key: 'waitlist', label: 'Anotar en lista de espera', icon: Hourglass, url: '/waitlist', allowed: hasModule('agenda') && can('waitlist.create') && user.tenant.rules?.['waitlist.enabled'] !== false },
  ].filter((action) => action.allowed);

  if (actions.length === 0) return null;

  return (
    <div ref={ref} className="relative">
      <button
        type="button"
        data-quick-create
        aria-haspopup="menu"
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
        className="inline-flex items-center gap-1.5 px-2.5 sm:px-3.5 py-2 rounded-xl bg-brand text-white text-sm font-semibold hover:bg-brand-hover shadow-sm transition-colors"
      >
        <Plus size={16} className="stroke-[2.5]" />
        <span className="hidden sm:inline">Crear</span>
      </button>
      {open && (
        <div role="menu" className="absolute right-0 top-full mt-2 w-64 max-w-[calc(100vw-1.5rem)] bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-30 py-1.5">
          {actions.map((action) => (
            <button
              key={action.key}
              type="button"
              role="menuitem"
              data-create={action.key}
              onClick={() => { setOpen(false); openPath(router, pathname, action.url); }}
              className="w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors text-left"
            >
              <action.icon size={16} className="text-slate-400" /> {action.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
