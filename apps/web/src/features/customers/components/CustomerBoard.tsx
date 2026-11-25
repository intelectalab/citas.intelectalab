'use client';

import { useState } from 'react';
import { CalendarCheck, CalendarClock, CalendarPlus, Columns3, Mail, PawPrint, Phone, Star } from 'lucide-react';
import { formatMoney } from '@/lib/currency';
import { SEGMENT_TONES, type CustomerInsights } from '../insights';
import { formatPhone } from '@/lib/phone';
import Select from '@/app/components/common/Select';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { PAGE_SIZE } from '@/app/components/common/Pager';
import { dateOf, formatDate, timeOf } from '@/features/appointments/appointments';
import type { BoardInfo } from '../board';

export interface BoardCustomer {
  id: string;
  first_name: string;
  last_name: string;
  phone?: string | null;
  pipeline_stage_id?: string | null;
  last_visit?: string | null;
  next_visit?: string | null;
  stage_manual_at?: string | null;
  code?: string;
  email?: string | null;
  document_number?: string | null;
  insights?: CustomerInsights | null;
  dependents?: { id: string; name: string }[];
}

interface CustomerBoardProps {
  rows: BoardCustomer[];
  info: BoardInfo;
  clientWord: string;
  onMove: (customerId: string, stageId: string) => void;
  onEditColumns?: () => void;
  onSchedule?: (row: BoardCustomer) => void;
  onWrite?: (row: BoardCustomer) => void;
  currency?: string | null;
  segmentName?: (key: string) => string;
}

const shortDate = (local: string) => formatDate(dateOf(local), { day: 'numeric', month: 'short', year: 'numeric' });

export default function CustomerBoard({ rows, info, clientWord, onMove, onEditColumns, onSchedule, onWrite, currency, segmentName }: CustomerBoardProps) {
  const [shown, setShown] = useState<Record<string, number>>({});
  const [dragging, setDragging] = useState<string | null>(null);
  const [over, setOver] = useState<string | null>(null);

  const stageIds = new Set(info.stages.map((stage) => stage.id));
  const columnOf = (row: BoardCustomer) => (row.pipeline_stage_id && stageIds.has(row.pipeline_stage_id) ? row.pipeline_stage_id : info.default_stage_id);

  const drop = (stageId: string, customerId: string | null) => {
    setOver(null);
    setDragging(null);
    if (!customerId) return;
    const row = rows.find((item) => item.id === customerId);
    if (row && columnOf(row) !== stageId) onMove(customerId, stageId);
  };

  return (
    <div className="border-t border-slate-100" data-customer-board>
      <div className="flex flex-wrap items-center justify-between gap-3 px-4 pt-4">
        <p className="text-xs text-slate-500">
          {info.can_move
            ? `Arrastra una tarjeta a otra columna, o usa "Mover a", para cambiar la etapa. El cambio queda en la página de cada ${clientWord.toLowerCase()}.`
            : 'Puedes ver en qué etapa está cada uno. Para moverlos necesitas permiso para editar.'}
        </p>
        {onEditColumns && (
          <button
            type="button"
            data-action="edit-columns"
            onClick={onEditColumns}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors"
          >
            <Columns3 size={14} className="text-brand-accent" />
            Editar columnas
          </button>
        )}
      </div>

      <div className="overflow-x-auto select-scroll p-4">
        <div className="flex gap-4 w-max min-h-[420px]">
          {info.stages.map((stage) => {
            const cards = rows.filter((row) => columnOf(row) === stage.id);
            const limit = shown[stage.id] ?? PAGE_SIZE;
            const isOver = over === stage.id && dragging !== null;
            const spend = cards.reduce((sum, row) => sum + (row.insights?.spend_12m ?? 0), 0);
            const showSpend = cards.some((row) => row.insights?.spend_12m != null);

            return (
              <section
                key={stage.id}
                data-board-column={stage.id}
                data-column-name={stage.name}
                onDragOver={(e) => { if (!info.can_move) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; if (over !== stage.id) setOver(stage.id); }}
                onDragLeave={(e) => { if (!e.currentTarget.contains(e.relatedTarget as Node | null)) setOver((current) => (current === stage.id ? null : current)); }}
                onDrop={(e) => { e.preventDefault(); drop(stage.id, e.dataTransfer.getData('text/plain') || dragging); }}
                className={`w-72 shrink-0 flex flex-col rounded-2xl border p-3 transition-colors ${isOver ? 'bg-brand-soft/15 border-brand-accent' : 'bg-slate-50 border-slate-200/80'}`}
              >
                <header className="mb-3 space-y-1" style={{ borderTop: `3px solid ${stage.color}` }}>
                  <div className="flex items-center justify-between gap-2 pt-2">
                    <span className="flex items-center gap-2 min-w-0">
                      <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: stage.color }} />
                      <span className="text-sm font-bold text-brand-ink truncate">{stage.name}</span>
                    </span>
                    <span className="px-2 py-0.5 text-xs font-bold text-slate-600 bg-white border border-slate-200 rounded-full" data-column-count>{cards.length}</span>
                  </div>
                  {stage.description && <p className="text-xs text-slate-500">{stage.description}</p>}
                  {showSpend && <p className="text-xs font-semibold text-slate-600" data-column-spend title="Gasto de los últimos 12 meses de las tarjetas de esta columna">{formatMoney(spend, currency)} en 12 meses</p>}
                </header>

                <div className="flex flex-col gap-2.5 flex-1">
                  {cards.length === 0 && (
                    <p className="text-xs text-slate-400 text-center py-6 border border-dashed border-slate-200 rounded-xl">
                      {info.can_move ? 'Suelta aquí una tarjeta.' : 'Nadie en esta etapa.'}
                    </p>
                  )}

                  {cards.slice(0, limit).map((row) => (
                    <article
                      key={row.id}
                      data-board-card={row.id}
                      draggable={info.can_move}
                      onDragStart={(e) => { e.dataTransfer.setData('text/plain', row.id); e.dataTransfer.effectAllowed = 'move'; setDragging(row.id); }}
                      onDragEnd={() => { setDragging(null); setOver(null); }}
                      className={`bg-white border border-slate-200 rounded-xl p-3 space-y-2 shadow-xs transition-all ${info.can_move ? 'cursor-grab active:cursor-grabbing hover:border-brand-accent' : ''} ${dragging === row.id ? 'opacity-50' : ''}`}
                    >
                      <CustomerLink id={row.id} className="block text-sm font-bold text-brand-ink truncate">{`${row.first_name} ${row.last_name}`}</CustomerLink>
                      {row.stage_manual_at && <span data-manual-stage title="Se movió a mano: aquí se queda hasta su próxima cita" className="inline-block text-[10px] font-semibold text-violet-700 bg-violet-50 border border-violet-100 rounded-md px-1.5 py-0.5">Movido a mano</span>}
                      <div className="space-y-1 text-xs text-slate-500">
                        <span className="flex items-center gap-1.5"><Phone size={12} className="text-slate-400 shrink-0" />{formatPhone(row.phone) || 'Sin teléfono'}</span>
                        <span className="flex items-center gap-1.5"><CalendarCheck size={12} className="text-emerald-600 shrink-0" />{row.last_visit ? `Última visita: ${shortDate(row.last_visit)}` : 'Aún sin atenciones'}</span>
                        <span className="flex items-center gap-1.5"><CalendarClock size={12} className="text-sky-600 shrink-0" />
                          {row.next_visit ? <span className="first-letter:uppercase">Próxima cita: {formatDate(dateOf(row.next_visit), { weekday: 'short', day: 'numeric', month: 'short' })}, {timeOf(row.next_visit)}</span> : 'Sin cita agendada'}
                        </span>
                        {row.insights && (
                          <span className="flex items-center gap-1.5" data-card-visits><Star size={12} className="text-amber-500 shrink-0" />{row.insights.visits} {row.insights.visits === 1 ? 'visita' : 'visitas'}{row.insights.spend_12m ? ` · ${formatMoney(row.insights.spend_12m, currency)}` : ''}</span>
                        )}
                        {(row.dependents?.length ?? 0) > 0 && (
                          <span className="flex items-center gap-1.5 truncate"><PawPrint size={12} className="text-amber-600 shrink-0" />{row.dependents!.map((d) => d.name).join(', ')}</span>
                        )}
                      </div>
                      {segmentName && (row.insights?.segments?.length ?? 0) > 0 && (
                        <div className="flex flex-wrap gap-1">
                          {row.insights!.segments.slice(0, 2).map((k) => <span key={k} className={`px-1.5 py-0.5 text-[10px] font-semibold rounded-md ${SEGMENT_TONES[k] ?? 'bg-slate-100 text-slate-600'}`}>{segmentName(k)}</span>)}
                        </div>
                      )}
                      {(onSchedule || onWrite) && (
                        <div className="flex gap-1.5 pt-2 border-t border-slate-100" data-card-actions>
                          {onSchedule && (
                            <button type="button" data-action="schedule" onClick={() => onSchedule(row)} className="flex-1 inline-flex items-center justify-center gap-1 px-2 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-brand-accent transition-colors">
                              <CalendarPlus size={13} className="text-brand-accent" /> Agendar
                            </button>
                          )}
                          {onWrite && (
                            <button type="button" data-action="write" onClick={() => onWrite(row)} className="flex-1 inline-flex items-center justify-center gap-1 px-2 py-1.5 text-xs font-bold text-brand-ink bg-white border border-slate-200 rounded-lg hover:bg-slate-50 hover:border-brand-accent transition-colors">
                              <Mail size={13} className="text-brand-accent" /> Escribir
                            </button>
                          )}
                        </div>
                      )}
                      {info.can_move && info.stages.length > 1 && (
                        <div className="pt-2 border-t border-slate-100" data-move-select>
                          <Select
                            variant="chip"
                            ariaLabel={`Mover a ${row.first_name} a otra etapa`}
                            placeholder="Mover a…"
                            clearable={false}
                            value=""
                            onChange={(e) => { if (e.target.value) onMove(row.id, String(e.target.value)); }}
                            options={info.stages.filter((other) => other.id !== stage.id).map((other) => ({ value: other.id, label: other.name }))}
                          />
                        </div>
                      )}
                    </article>
                  ))}

                  {cards.length > limit && (
                    <button
                      type="button"
                      data-action="show-more"
                      onClick={() => setShown((current) => ({ ...current, [stage.id]: limit + PAGE_SIZE }))}
                      className="text-xs font-bold text-brand-accent hover:text-brand-ink py-1.5 transition-colors"
                    >
                      Ver {Math.min(PAGE_SIZE, cards.length - limit)} más (quedan {cards.length - limit})
                    </button>
                  )}
                </div>
              </section>
            );
          })}
        </div>
      </div>
    </div>
  );
}
