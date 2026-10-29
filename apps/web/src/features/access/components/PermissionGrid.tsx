'use client';

import { useState } from 'react';
import { ChevronDown, Construction, Sparkles } from 'lucide-react';
import Switch from '@/app/components/common/Switch';
import { titleOf, type GridAction, type GridModule } from '../access';

interface PermissionGridProps {
  grid: GridModule[];
  mode: 'user' | 'role';
  canEdit: boolean;
  busy?: string;
  onToggle: (action: GridAction) => void;
  empty?: string;
}

const ORDER = ['view', 'create', 'edit', 'delete'];

export default function PermissionGrid({ grid, mode, canEdit, busy, onToggle, empty = 'Nada que mostrar.' }: PermissionGridProps) {
  const [open, setOpen] = useState<string | null>(null);

  if (grid.length === 0) return <p className="px-6 py-5 text-sm text-slate-400">{empty}</p>;

  const sections = grid.reduce<{ label: string; modules: GridModule[] }[]>((list, module) => {
    const label = module.section_label ?? 'Otras';
    const last = list[list.length - 1];
    if (last && last.label === label) last.modules.push(module);
    else list.push({ label, modules: [module] });
    return list;
  }, []);

  const sorted = (actions: GridAction[]) => [...actions].sort((a, b) => {
    const ia = a.special ? 9 : ORDER.indexOf(a.action);
    const ib = b.special ? 9 : ORDER.indexOf(b.action);
    return ia - ib;
  });

  return (
    <div data-permission-grid>
      {sections.map((section) => (
        <div key={section.label} data-section={section.label}>
          <div className="px-6 py-2 bg-slate-50/80 border-y border-slate-100 text-xs font-bold text-slate-500">{section.label}</div>
          <ul className="divide-y divide-slate-100">
            {section.modules.map((module) => {
              const expanded = open === module.key;
              const screens = module.screens ?? [];
              const menuText = screens.length > 1 ? `Un mismo permiso para ${screens.length === 2 ? "las 2" : `las ${screens.length}`} opciones del menú.` : '';

              return (
                <li key={module.key} data-module={module.key} className="px-6 py-3.5 scroll-mt-24 transition-colors hover:bg-slate-50/60">
                  <div className="flex flex-col lg:flex-row lg:items-start gap-3">
                    <div className="lg:w-64 shrink-0">
                      <button
                        type="button"
                        data-explain={module.key}
                        aria-expanded={expanded}
                        title="Ver qué permite cada uno"
                        onClick={() => setOpen(expanded ? null : module.key)}
                        className="inline-flex items-center gap-1.5 text-left text-sm font-semibold text-brand-ink hover:text-brand-accent transition-colors"
                      >
                        {titleOf(module)}
                        <ChevronDown size={14} className={`text-slate-400 transition-transform ${expanded ? 'rotate-180' : ''}`} />
                      </button>
                      {module.building && (
                        <span className="ml-2 inline-flex items-center gap-1 px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-amber-100 text-amber-800 align-middle"><Construction size={10} /> En construcción</span>
                      )}
                      {menuText && <span className="block text-xs text-slate-500 mt-0.5">{menuText}</span>}
                      {module.note && <span className="block text-xs text-slate-400 mt-0.5">{module.note}</span>}
                    </div>

                    <div className="flex-1 flex flex-wrap gap-2">
                      {sorted(module.actions).map((action) => (
                        <div
                          key={action.name}
                          data-perm={action.name}
                          data-on={action.on ? '1' : '0'}
                          title={action.hint}
                          className={`flex items-center gap-2 rounded-xl border px-2.5 py-1.5 ${action.special ? 'border-amber-200 bg-amber-50/60' : action.on ? 'border-emerald-200 bg-emerald-50/50' : 'border-slate-200 bg-white'}`}
                        >
                          <Switch checked={action.on} disabled={!canEdit || busy === action.name} label={action.hint} onChange={() => onToggle(action)} />
                          <span className="text-xs font-semibold text-slate-700 leading-tight">
                            {action.special && <Sparkles size={11} className="inline mr-1 text-amber-600 -mt-0.5" />}
                            {action.short ?? action.label}
                          </span>
                          {mode === 'user' && action.override === true && (
                            <span data-mark="granted" className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-emerald-100 text-emerald-700 whitespace-nowrap">Agregado</span>
                          )}
                          {mode === 'user' && action.override === false && (
                            <span data-mark="revoked" className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-rose-50 text-rose-700 whitespace-nowrap">Quitado</span>
                          )}
                        </div>
                      ))}
                    </div>
                  </div>

                  {expanded && (
                    <ul data-explained={module.key} className="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 rounded-xl bg-slate-50 px-4 py-3">
                      {sorted(module.actions).map((action) => (
                        <li key={action.name} className="flex items-start gap-2 text-xs text-slate-600">
                          <span className={`mt-1 w-1.5 h-1.5 rounded-full shrink-0 ${action.on ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                          <span><span className="font-bold text-slate-700">{action.short ?? action.label}:</span> {action.hint.charAt(0).toLowerCase() + action.hint.slice(1)}.</span>
                        </li>
                      ))}
                    </ul>
                  )}
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </div>
  );
}
