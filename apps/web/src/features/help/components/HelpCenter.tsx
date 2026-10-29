'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ArrowUpRight, ChevronRight, CircleHelp, Search } from 'lucide-react';
import { plain, screenText, useHelp, type HelpScreen } from '../useHelp';
import HelpTopicView from './HelpTopicView';
import Select from '@/app/components/common/Select';

export default function HelpCenter() {
  const { enabled, sections, isAdmin } = useHelp();
  const [query, setQuery] = useState('');
  const [selectedHref, setSelectedHref] = useState<string | null>(null);

  useEffect(() => {
    const wanted = new URLSearchParams(window.location.search).get('screen');
    if (wanted) setSelectedHref(wanted);
  }, []);

  const term = plain(query.trim());
  const filtered = sections
    .map((section) => ({ ...section, screens: term ? section.screens.filter((screen) => screenText(screen).includes(term)) : section.screens }))
    .filter((section) => section.screens.length > 0);
  const all = sections.flatMap((section) => section.screens);
  const shown = filtered.flatMap((section) => section.screens);
  const selected: HelpScreen | undefined = shown.find((screen) => screen.href === selectedHref) ?? shown[0] ?? all.find((screen) => screen.href === selectedHref) ?? all[0];
  const noResults = term !== '' && filtered.length === 0;

  if (!enabled) {
    return (
      <div className="max-w-xl mx-auto pt-10 text-center space-y-3" data-help-off>
        <CircleHelp className="w-10 h-10 mx-auto text-slate-300" />
        <h1 className="text-xl font-black text-brand-ink">La autoayuda está apagada</h1>
        <p className="text-sm text-slate-500">
          {isAdmin ? (
            <>Puedes encenderla para todo tu equipo en <Link href="/settings" className="font-semibold text-brand-accent hover:underline">Configuración → Autoayuda</Link>.</>
          ) : 'El administrador de tu empresa la apagó. Si la necesitas, pídele que la encienda.'}
        </p>
      </div>
    );
  }

  return (
    <div className="space-y-6 pb-10" data-help-center>
      <div className="flex items-start gap-3">
        <span className="p-2.5 rounded-xl bg-brand-soft/20 text-brand-ink shrink-0"><CircleHelp size={22} /></span>
        <div>
          <h1 className="text-2xl font-black text-brand-ink tracking-tight">Autoayuda</h1>
          <p className="text-sm text-slate-500 font-medium">Guía de las pantallas de tu menú: para qué sirve cada una, qué hace cada botón y cómo se hacen las tareas de todos los días.</p>
        </div>
      </div>

      <label className="flex items-center gap-2 bg-white px-4 py-2.5 rounded-2xl border border-slate-200/80 shadow-xs focus-within:border-brand-accent transition-colors">
        <Search size={18} className="text-slate-400 shrink-0" />
        <input
          type="text"
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder="¿Qué necesitas hacer? Por ejemplo: cancelar una cita, cobrar, mover"
          aria-label="Buscar en la autoayuda"
          data-help-search
          className="w-full bg-transparent border-none outline-none text-sm text-slate-700 placeholder-slate-400"
        />
      </label>

      {noResults && (
        <p className="lg:hidden p-4 rounded-2xl bg-white border border-slate-200 text-sm text-slate-500" data-help-none>No encontramos nada con “{query.trim()}”. Prueba con otra palabra.</p>
      )}

      {!noResults && (
        <div className="lg:hidden" data-help-select>
          <Select
            ariaLabel="Pantalla"
            clearable={false}
            value={selected?.href ?? ''}
            onChange={(e) => { if (e.target.value) setSelectedHref(String(e.target.value)); }}
            options={filtered.flatMap((section) => section.screens.map((screen) => ({ value: screen.href, label: `${section.title} · ${screen.name}` })))}
          />
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-[22rem_minmax(0,1fr)] gap-6 items-start">
        <nav className="hidden lg:block lg:sticky lg:top-3 lg:max-h-[calc(100dvh-7rem)] lg:overflow-y-auto list-scroll lg:pr-2 space-y-5" aria-label="Pantallas con ayuda" data-help-index>
          {noResults && (
            <p className="p-4 rounded-2xl bg-white border border-slate-200 text-sm text-slate-500">No encontramos nada con “{query.trim()}”. Prueba con otra palabra.</p>
          )}
          {filtered.map((section) => (
            <div key={section.id} className="space-y-2">
              <span className="block px-1 text-xs font-bold text-brand-ink">{section.title}</span>
              <ul className="space-y-2">
                {section.screens.map((screen) => {
                  const Icon = screen.icon;
                  const active = selected?.href === screen.href;
                  return (
                    <li key={screen.href}>
                      <button
                        type="button"
                        data-help-screen={screen.href}
                        aria-current={active ? 'page' : undefined}
                        onClick={() => setSelectedHref(screen.href)}
                        className={`w-full flex items-center gap-3 p-3 rounded-2xl border text-left transition-colors ${
                          active ? 'bg-brand-ink border-brand-ink text-white' : 'bg-white border-slate-200/80 hover:border-brand-accent/50'
                        }`}
                      >
                        <span className={`p-2 rounded-xl shrink-0 ${active ? 'bg-white/15 text-white' : 'bg-slate-50 text-brand-accent'}`}><Icon size={16} /></span>
                        <span className="min-w-0 flex-1">
                          <span className={`block text-sm font-semibold ${active ? 'text-white' : 'text-slate-800'}`}>{screen.name}</span>
                          <span className={`block text-xs truncate ${active ? 'text-white/75' : 'text-slate-500'}`}>{screen.topic?.summary ?? 'Guía en preparación'}</span>
                        </span>
                        <ChevronRight size={16} className={active ? 'text-white/80' : 'text-slate-300'} />
                      </button>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </nav>

        {selected && !noResults && (
          <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 sm:p-6 space-y-5 scroll-mt-4" data-help-detail={selected.href}>
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="flex items-center gap-3 min-w-0">
                <span className="p-2.5 rounded-xl bg-slate-50 text-brand-accent shrink-0"><selected.icon size={20} /></span>
                <h2 className="text-lg font-black text-brand-ink truncate">{selected.name}</h2>
              </div>
              <Link
                href={selected.href}
                data-help-go
                className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 text-sm font-semibold text-brand-accent hover:bg-slate-50 transition-colors"
              >
                Ir a esta pantalla <ArrowUpRight size={15} />
              </Link>
            </div>
            <HelpTopicView screen={selected} wide />
          </div>
        )}
      </div>
    </div>
  );
}
