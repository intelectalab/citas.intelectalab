'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { CalendarDays, Contact, Receipt, Search, Users, Wrench, X } from 'lucide-react';
import { api } from '@/lib/axios';
import { useVocabulary } from '@/hooks/useVocabulary';

interface SearchItem {
  id: string;
  title: string;
  subtitle: string;
  url: string;
}

interface SearchGroup {
  key: 'customers' | 'appointments' | 'sales' | 'services' | 'professionals';
  items: SearchItem[];
}

const ICONS = { customers: Users, appointments: CalendarDays, sales: Receipt, services: Wrench, professionals: Contact };

export const openPath = (router: ReturnType<typeof useRouter>, pathname: string, url: string) => {
  const [path, query = ''] = url.split('?');
  const params = new URLSearchParams(query);

  if (path === '/agenda' && pathname === '/agenda' && params.get('cita')) {
    window.dispatchEvent(new CustomEvent('agenda:open', { detail: params.get('cita') }));
    return;
  }
  if (path === '/agenda' && pathname === '/agenda' && params.get('nueva') === '1') {
    window.dispatchEvent(new CustomEvent('agenda:new'));
    return;
  }
  if (path === '/customers' && pathname === '/customers' && params.get('nuevo') === '1') {
    window.dispatchEvent(new CustomEvent('customers:new'));
    return;
  }
  if (path === pathname && query) {
    window.location.assign(url);
    return;
  }
  router.push(url);
};

export default function GlobalSearch() {
  const router = useRouter();
  const pathname = usePathname();
  const vocab = useVocabulary();
  const inputRef = useRef<HTMLInputElement>(null);
  const boxRef = useRef<HTMLDivElement>(null);
  const [query, setQuery] = useState('');
  const [groups, setGroups] = useState<SearchGroup[]>([]);
  const [open, setOpen] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [failed, setFailed] = useState(false);
  const [active, setActive] = useState(0);

  const labels: Record<SearchGroup['key'], string> = {
    customers: vocab.clients,
    appointments: 'Citas',
    sales: 'Ventas',
    services: 'Servicios',
    professionals: vocab.pros,
  };

  const flat = useMemo(() => groups.flatMap((group) => group.items.map((item) => ({ ...item, group: group.key }))), [groups]);

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        if (window.matchMedia('(min-width: 768px)').matches) {
          inputRef.current?.focus();
          inputRef.current?.select();
        } else {
          setMobileOpen(true);
        }
        setOpen(true);
      }
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, []);

  useEffect(() => {
    if (mobileOpen) setTimeout(() => inputRef.current?.focus(), 0);
  }, [mobileOpen]);

  useEffect(() => {
    if (!open) return;
    const onClick = (event: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(event.target as Node)) {
        setOpen(false);
        setMobileOpen(false);
      }
    };
    document.addEventListener('mousedown', onClick);
    return () => document.removeEventListener('mousedown', onClick);
  }, [open]);

  useEffect(() => {
    const term = query.trim();
    if (term.length < 2) {
      setGroups([]);
      setFailed(false);
      return;
    }
    setLoading(true);
    let alive = true;
    const timer = setTimeout(() => {
      api.get('/search', { params: { q: term } })
        .then((res) => { if (alive) { setGroups(res.data?.groups ?? []); setFailed(false); setActive(0); } })
        .catch(() => { if (alive) { setGroups([]); setFailed(true); } })
        .finally(() => { if (alive) setLoading(false); });
    }, 250);
    return () => { alive = false; clearTimeout(timer); };
  }, [query]);

  const go = (url: string) => {
    setOpen(false);
    setMobileOpen(false);
    setQuery('');
    inputRef.current?.blur();
    openPath(router, pathname, url);
  };

  const onInputKey = (event: React.KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Escape') {
      setOpen(false);
      setMobileOpen(false);
      inputRef.current?.blur();
      return;
    }
    if (!flat.length) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      setActive((index) => (index + 1) % flat.length);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      setActive((index) => (index - 1 + flat.length) % flat.length);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      go(flat[active]?.url ?? flat[0].url);
    }
  };

  const term = query.trim();
  let position = -1;

  const results = open && term.length >= 2 && (
    <div role="listbox" data-search-results className="absolute left-0 right-0 top-full mt-2 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-30 max-h-[70vh] overflow-y-auto">
      {loading && flat.length === 0 ? (
        <p className="px-4 py-3 text-sm text-slate-400">Buscando...</p>
      ) : failed ? (
        <p className="px-4 py-3 text-sm text-rose-600">No se pudo buscar. Inténtalo de nuevo.</p>
      ) : flat.length === 0 ? (
        <p className="px-4 py-3 text-sm text-slate-400">No encontramos «{term}». Prueba con el nombre, el celular o el código.</p>
      ) : (
        groups.map((group) => {
          const Icon = ICONS[group.key] ?? Search;
          return (
            <div key={group.key} data-search-group={group.key} className="py-1.5 border-b border-slate-100 last:border-b-0">
              <span className="block px-4 pt-1 pb-1 text-[11px] font-bold uppercase tracking-wider text-slate-400">{labels[group.key] ?? group.key}</span>
              {group.items.map((item) => {
                position += 1;
                const index = position;
                return (
                  <button
                    key={`${group.key}-${item.id}`}
                    type="button"
                    role="option"
                    aria-selected={index === active}
                    onMouseEnter={() => setActive(index)}
                    onClick={() => go(item.url)}
                    className={`w-full flex items-start gap-3 px-4 py-2 text-left transition-colors ${index === active ? 'bg-slate-50' : 'hover:bg-slate-50'}`}
                  >
                    <Icon size={16} className="mt-0.5 shrink-0 text-slate-400" />
                    <span className="min-w-0">
                      <span className="block text-sm font-semibold text-slate-800 truncate">{item.title}</span>
                      {item.subtitle && <span className="block text-xs text-slate-500 truncate">{item.subtitle}</span>}
                    </span>
                  </button>
                );
              })}
            </div>
          );
        })
      )}
    </div>
  );

  const field = (
    <div className="flex items-center gap-2 bg-gray-100 px-3 py-1.5 rounded-full w-full border border-transparent focus-within:border-brand-accent focus-within:bg-white transition-all">
      <Search size={18} className="text-gray-400 shrink-0" />
      <input
        ref={inputRef}
        type="text"
        data-global-search
        aria-label="Buscar en el sistema"
        placeholder="Buscar clientes, citas, ventas..."
        value={query}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onInputKey}
        className="bg-transparent border-none outline-none text-sm w-full text-gray-700 placeholder-gray-400"
      />
      <kbd className="hidden lg:inline-flex items-center px-1.5 py-0.5 rounded-md border border-slate-200 bg-white text-[10px] font-semibold text-slate-400 shrink-0">Ctrl K</kbd>
    </div>
  );

  return (
    <>
      <div ref={mobileOpen ? undefined : boxRef} className="hidden md:block relative w-96">
        {!mobileOpen && field}
        {!mobileOpen && results}
      </div>

      <button
        type="button"
        aria-label="Buscar"
        data-open-search
        onClick={() => { setMobileOpen(true); setOpen(true); }}
        className="md:hidden p-2 rounded-xl text-slate-600 hover:bg-gray-100"
      >
        <Search size={20} />
      </button>

      {mobileOpen && (
        <div className="md:hidden fixed inset-x-0 top-0 z-40 bg-white border-b border-slate-200 px-4 py-3 shadow-lg">
          <div ref={boxRef} className="relative flex items-center gap-2">
            {field}
            <button type="button" aria-label="Cerrar la búsqueda" onClick={() => { setMobileOpen(false); setOpen(false); }} className="p-2 rounded-xl text-slate-500 hover:bg-gray-100 shrink-0">
              <X size={18} />
            </button>
            {results}
          </div>
        </div>
      )}
    </>
  );
}
