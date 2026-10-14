'use client';

import { Fragment, useState } from 'react';
import { ChevronDown, Minus } from 'lucide-react';
import Switch from '@/app/components/common/Switch';
import { Pager, usePaged } from '@/app/components/common/Pager';
import { ACTION_LABELS, ACTION_ORDER, type GridAction, type GridModule } from '../access';

interface PermissionGridProps {
  grid: GridModule[];
  // En la página de una persona se marca lo agregado y lo quitado respecto de su rol.
  mode: 'user' | 'role';
  canEdit: boolean;
  // Permiso que se está guardando: su interruptor espera.
  busy?: string;
  onToggle: (action: GridAction) => void;
  empty?: string;
}

// La rejilla de permisos: una fila por pantalla y un interruptor por acción.
// Tocar el nombre de la pantalla explica con palabras qué deja hacer cada uno.
export default function PermissionGrid({ grid, mode, canEdit, busy, onToggle, empty = 'Nada que mostrar.' }: PermissionGridProps) {
  const { rows, page, pages, setPage } = usePaged(grid);
  const [open, setOpen] = useState<string | null>(null);

  if (grid.length === 0) return <p className="px-6 py-5 text-sm text-slate-400">{empty}</p>;

  // Permisos especiales (forzar horario, acceso de emergencia...): van en su propia columna.
  const hasSpecial = grid.some((module) => module.actions.some((a) => a.special));

  // Un interruptor con su marca; el especial lleva su nombre debajo.
  const cell = (action: GridAction, caption?: string) => (
    <span className="inline-flex flex-col items-center gap-1" data-perm={action.name} data-on={action.on ? '1' : '0'} title={action.hint}>
      <Switch checked={action.on} disabled={!canEdit || busy === action.name} label={action.hint} onChange={() => onToggle(action)} />
      {caption && <span className="text-[11px] font-semibold text-slate-600 leading-tight max-w-28">{caption}</span>}
      {mode === 'user' && action.override === true && (
        <span data-mark="granted" className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-emerald-50 text-emerald-700 whitespace-nowrap">Agregado</span>
      )}
      {mode === 'user' && action.override === false && (
        <span data-mark="revoked" className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-rose-50 text-rose-700 whitespace-nowrap">Quitado</span>
      )}
    </span>
  );

  return (
    <div data-permission-grid>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-y border-slate-100 bg-slate-50/70 text-xs font-semibold text-slate-500">
              <th className="text-left font-semibold px-6 py-2.5 w-72">Pantalla</th>
              {ACTION_ORDER.map((action) => (
                <th key={action} className="font-semibold px-3 py-2.5 text-center">{ACTION_LABELS[action]}</th>
              ))}
              {hasSpecial && <th className="font-semibold px-3 py-2.5 text-center">Especial</th>}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((module) => {
              const expanded = open === module.key;
              const special = module.actions.find((a) => a.special);

              return (
                <Fragment key={module.key}>
                  <tr data-module={module.key} className="hover:bg-slate-50/60 transition-colors">
                    <td className="px-6 py-3">
                      <button
                        type="button"
                        data-explain={module.key}
                        aria-expanded={expanded}
                        title="Ver qué permite cada uno"
                        onClick={() => setOpen(expanded ? null : module.key)}
                        className="inline-flex items-center gap-1.5 text-left font-semibold text-[#022340] hover:text-[#3F768C] transition-colors"
                      >
                        {module.label}
                        <ChevronDown size={14} className={`text-slate-400 transition-transform ${expanded ? 'rotate-180' : ''}`} />
                      </button>
                    </td>
                    {ACTION_ORDER.map((key) => {
                      const action = module.actions.find((a) => a.action === key);

                      if (!action) {
                        return (
                          <td key={key} className="px-3 py-3 text-center text-slate-300" title="Esta pantalla no tiene esa acción">
                            <Minus size={14} className="inline" />
                          </td>
                        );
                      }

                      return <td key={key} className="px-3 py-3 text-center align-middle">{cell(action)}</td>;
                    })}
                    {hasSpecial && (
                      <td className="px-3 py-3 text-center align-middle">
                        {special ? cell(special, special.label) : <span className="text-slate-300" title="Esta pantalla no tiene un permiso especial"><Minus size={14} className="inline" /></span>}
                      </td>
                    )}
                  </tr>
                  {expanded && (
                    <tr data-explained={module.key} className="bg-slate-50/60">
                      <td colSpan={hasSpecial ? 6 : 5} className="px-6 py-3">
                        <ul className="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                          {module.actions.map((action) => (
                            <li key={action.name} className="flex items-start gap-2 text-xs text-slate-600">
                              <span className={`mt-1 w-1.5 h-1.5 rounded-full shrink-0 ${action.on ? 'bg-emerald-500' : 'bg-slate-300'}`} />
                              <span><span className="font-bold text-slate-700">{action.label}:</span> {action.hint.charAt(0).toLowerCase() + action.hint.slice(1)}.</span>
                            </li>
                          ))}
                        </ul>
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
      </div>
      {pages > 1 && <Pager page={page} pages={pages} onChange={setPage} className="px-6 py-3 border-t border-slate-100" />}
    </div>
  );
}
