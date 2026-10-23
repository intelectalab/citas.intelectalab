'use client';

import { useEffect, useMemo, useRef, useState } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { ChevronDown, Search } from 'lucide-react';
import Floating from '@/app/components/common/Floating';
import { matchesPath, readRecent, useMenu, type MenuItem } from '@/app/components/layout/menu';

const RECENT_SHOWN = 3;

const plain = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

interface Section {
  title: string;
  items: MenuItem[];
}

export default function ScreenJump({ anchorRef }: { anchorRef: React.RefObject<HTMLElement | null> }) {
  const router = useRouter();
  const pathname = usePathname();
  const { groups, settings, userId } = useMenu();
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const [active, setActive] = useState(0);
  const [recent, setRecent] = useState<string[]>([]);
  const inputRef = useRef<HTMLInputElement>(null);
  const listRef = useRef<HTMLDivElement>(null);

  const all = useMemo(() => [...groups.flatMap((g) => g.items), ...(settings ? [settings] : [])], [groups, settings]);

  const sections: Section[] = useMemo(() => {
    const term = plain(query.trim());
    if (term) {
      const found = all.filter((item) => plain(item.name).includes(term));
      return found.length ? [{ title: 'Resultados', items: found }] : [];
    }
    const recentItems = recent
      .map((href) => all.find((item) => item.href === href))
      .filter((item): item is MenuItem => Boolean(item) && !matchesPath(item!.href, pathname))
      .slice(0, RECENT_SHOWN);
    return [
      ...(recentItems.length ? [{ title: 'Usadas recientemente', items: recentItems }] : []),
      ...groups.map(({ group, items }) => ({ title: group.groupName ?? 'Inicio', items })),
      ...(settings ? [{ title: 'Configuración', items: [settings] }] : []),
    ];
  }, [query, all, recent, groups, settings, pathname]);

  const flat = sections.flatMap((section) => section.items);

  useEffect(() => {
    if (!open) return;
    setQuery('');
    setActive(0);
    setRecent(readRecent(userId));
    const timer = window.setTimeout(() => inputRef.current?.focus(), 0);
    return () => window.clearTimeout(timer);
  }, [open, userId]);

  useEffect(() => { setActive(0); }, [query]);

  useEffect(() => {
    listRef.current?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
  }, [active]);

  const go = (item: MenuItem) => {
    setOpen(false);
    if (pathname !== item.href) router.push(item.href);
  };

  const onKey = (event: React.KeyboardEvent) => {
    if (event.key === 'ArrowDown') { event.preventDefault(); setActive((i) => Math.min(i + 1, flat.length - 1)); }
    if (event.key === 'ArrowUp') { event.preventDefault(); setActive((i) => Math.max(i - 1, 0)); }
    if (event.key === 'Enter' && flat[active]) { event.preventDefault(); go(flat[active]); }
  };

  let index = -1;

  return (
    <>
      <button
        type="button"
        aria-label="Ir a otra pantalla"
        title="Ir a otra pantalla"
        aria-expanded={open}
        aria-haspopup="listbox"
        data-screen-jump
        onClick={() => setOpen((value) => !value)}
        className="p-1 rounded-lg text-slate-400 hover:text-[#022340] hover:bg-slate-100 transition-colors"
      >
        <ChevronDown size={20} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      <Floating
        anchorRef={anchorRef}
        open={open}
        onClose={() => setOpen(false)}
        role="dialog"
        className="w-72 bg-white border border-slate-200 rounded-xl shadow-xl overflow-hidden flex flex-col animate-in fade-in duration-150"
      >
        <div className="p-2.5 border-b border-slate-100">
          <div className="flex items-center gap-2 px-3 py-2 border border-slate-200 rounded-lg focus-within:border-[#022340] focus-within:ring-2 focus-within:ring-[#022340]/10">
            <Search size={15} className="text-[#3F768C] shrink-0" />
            <input
              ref={inputRef}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={onKey}
              placeholder="Buscar pantalla"
              aria-label="Buscar pantalla"
              data-screen-search
              className="w-full text-sm text-slate-800 outline-none bg-transparent"
            />
          </div>
        </div>
        <div ref={listRef} role="listbox" className="min-h-0 max-h-80 overflow-y-auto select-scroll py-1" data-screen-list>
          {flat.length === 0 && <p className="px-4 py-3 text-sm text-slate-400">No hay ninguna pantalla con ese nombre.</p>}
          {sections.map((section) => (
            <div key={section.title} data-screen-section={section.title}>
              <span className="block px-4 pt-2.5 pb-1 text-xs font-bold text-[#022340]">{section.title}</span>
              {section.items.map((item) => {
                index += 1;
                const position = index;
                const Icon = item.icon;
                const current = matchesPath(item.href, pathname);
                return (
                  <div
                    key={`${section.title}-${item.href}`}
                    role="option"
                    aria-selected={current}
                    data-active={position === active}
                    data-screen-option={item.name}
                    onMouseEnter={() => setActive(position)}
                    onClick={() => go(item)}
                    className={`flex items-center gap-2.5 mx-1.5 px-3 py-2 rounded-lg text-sm cursor-pointer transition-colors ${
                      position === active ? 'bg-slate-100 text-[#022340]' : 'text-slate-700'
                    } ${current ? 'font-semibold' : ''}`}
                  >
                    <Icon size={15} className={current ? 'text-[#3F768C]' : 'text-slate-400'} />
                    <span className="truncate">{item.name}</span>
                    {current && <span className="ml-auto text-[11px] font-medium text-slate-400">Aquí estás</span>}
                  </div>
                );
              })}
            </div>
          ))}
        </div>
      </Floating>
    </>
  );
}
