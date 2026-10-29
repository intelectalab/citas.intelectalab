'use client';

import { Ban, Construction, LayoutList, Sparkles } from 'lucide-react';
import { entersModule, levelOf, specialsOf, titleOf, type AdminOnlyScreen, type GridModule } from '../access';

interface AccessSummaryProps {
  grid: GridModule[];
  adminOnly?: AdminOnlyScreen[];
  everything?: boolean;
  who: string;
}

const cardTitle = 'flex items-center gap-2 text-sm font-extrabold text-brand-ink';

const jump = (key: string) => {
  const row = document.querySelector(`[data-module="${key}"]`);
  row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  row?.classList.add('ring-2', 'ring-brand-soft');
  window.setTimeout(() => row?.classList.remove('ring-2', 'ring-brand-soft'), 1600);
};

export default function AccessSummary({ grid, adminOnly = [], everything = false, who }: AccessSummaryProps) {
  const screens = grid.filter((m) => (m.screens ?? []).length > 0);
  const sections = screens.reduce<{ label: string; items: { module: GridModule; name: string }[] }[]>((list, module) => {
    if (!everything && !entersModule(module)) return list;
    const label = module.section_label ?? 'Otras';
    let section = list.find((s) => s.label === label);
    if (!section) { section = { label, items: [] }; list.push(section); }
    (module.screens ?? []).forEach((name) => section!.items.push({ module, name }));
    return list;
  }, []);

  adminOnly.forEach((item) => {
    if (!everything) return;
    let section = sections.find((s) => s.label === item.section_label);
    if (!section) { section = { label: item.section_label, items: [] }; sections.push(section); }
    const entry = { module: { key: '', label: item.name, actions: [], building: true } as GridModule, name: item.name };
    const at = item.before ? section.items.findIndex((i) => i.module.key === item.before) : -1;
    if (at >= 0) section.items.splice(at, 0, entry); else section.items.push(entry);
  });

  const hidden = screens.filter((m) => !everything && !entersModule(m)).flatMap((m) => (m.screens ?? []).map((name) => ({ module: m, name })));
  const specials = specialsOf(grid);
  const total = sections.reduce((sum, s) => sum + s.items.length, 0) + 1;

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="enters">
      <div>
        <h2 className={cardTitle}><LayoutList className="w-4 h-4 text-emerald-600" /> Lo que ve en su menú</h2>
        <p className="text-xs text-slate-500 mt-1">{total} {total === 1 ? 'opción' : 'opciones'}, igual que en el menú de {who}. Toca una para ver sus permisos.</p>
        <div className="mt-3 space-y-2.5" data-list="enters">
          <div className="flex flex-wrap items-center gap-1.5">
            <span className="w-32 shrink-0 text-[11px] font-bold text-slate-400">Inicio</span>
            <span className="px-2.5 py-1 text-xs font-semibold bg-brand-soft/20 text-brand-ink rounded-lg">Inicio</span>
          </div>
          {sections.map((section) => (
            <div key={section.label} className="flex flex-wrap items-start gap-1.5" data-menu-section={section.label}>
              <span className="w-32 shrink-0 pt-1 text-[11px] font-bold text-slate-400">{section.label}</span>
              <div className="flex-1 flex flex-wrap gap-1.5">
                {section.items.map(({ module, name }) => {
                  const level = everything ? 'todo' : levelOf(module);
                  return module.key ? (
                    <button key={`${module.key}-${name}`} type="button" data-menu-item={name} onClick={() => jump(module.key)}
                      className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold bg-brand-soft/20 text-brand-ink rounded-lg hover:bg-brand-soft/40 transition-colors">
                      {name}
                      {module.building && <Construction size={11} className="text-amber-700" />}
                      {level && <span className="font-normal text-slate-500">· {level}</span>}
                    </button>
                  ) : (
                    <span key={`admin-${name}`} data-menu-item={name} className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold bg-amber-50 text-amber-800 rounded-lg" title="En construcción: solo la ve el administrador">
                      {name} <Construction size={11} />
                    </span>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      </div>

      {hidden.length > 0 && (
        <div className="pt-4 border-t border-slate-100">
          <h2 className={cardTitle}><Ban className="w-4 h-4 text-slate-400" /> No le aparecen</h2>
          <div className="flex flex-wrap gap-1.5 mt-3" data-list="not-enters">
            {hidden.map(({ module, name }) => (
              <button key={`${module.key}-${name}`} type="button" onClick={() => jump(module.key)} className="px-2.5 py-1 text-xs font-medium bg-slate-100 text-slate-500 rounded-lg hover:bg-slate-200 transition-colors">{name}</button>
            ))}
          </div>
          {!everything && adminOnly.length > 0 && (
            <p className="text-xs text-slate-400 mt-2">En construcción, solo para el administrador: {adminOnly.map((a) => a.name).join(', ')}.</p>
          )}
        </div>
      )}

      {specials.length > 0 && (
        <div className="pt-4 border-t border-slate-100">
          <h2 className={cardTitle}><Sparkles className="w-4 h-4 text-amber-600" /> Permisos especiales</h2>
          <ul className="mt-3 grid grid-cols-1 md:grid-cols-2 gap-2" data-list="specials">
            {specials.map(({ module, action }) => (
              <li key={action.name}>
                <button type="button" onClick={() => jump(module.key)} data-special={action.name} data-on={action.on ? '1' : '0'}
                  className={`w-full text-left flex items-start gap-2 rounded-xl border px-3 py-2 transition-colors ${action.on ? 'border-amber-200 bg-amber-50/60 hover:bg-amber-50' : 'border-slate-200 hover:bg-slate-50'}`}>
                  <span className={`mt-1.5 w-2 h-2 rounded-full shrink-0 ${action.on ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                  <span className="min-w-0">
                    <span className={`block text-xs font-bold ${action.on ? 'text-slate-800' : 'text-slate-500'}`}>{action.short ?? action.label} <span className="font-normal text-slate-400">· {titleOf(module)}</span></span>
                    <span className="block text-[11px] text-slate-500">{action.on ? 'Puede' : 'No puede'}: {action.hint.charAt(0).toLowerCase() + action.hint.slice(1)}.</span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}
