'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, ArrowLeftRight, CheckCircle2, Edit2, Eye, Lock, Plus, Unlock, Wallet } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf, type CashBoardData, type CashRegister, type CashSession } from '@/features/sales/sales';
import { CashMovementPanel, CashSessionPanel, CloseCashPanel, OpenCashPanel, RegisterPanel } from './CashPanels';

const ghostButton = 'flex items-center gap-1.5 px-3 py-2 text-sm font-medium border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors';
const iconButton = 'p-1.5 text-slate-400 rounded-lg hover:text-[#022340] hover:bg-slate-100 transition-colors';

const squared = (session: CashSession) => (session.difference ?? 0) === 0;

export default function CashBoard() {
  const [data, setData] = useState<CashBoardData | null>(null);
  const [error, setError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ branch_id: '', register_id: '' });
  const [activeCard, setActiveCard] = useState('');

  const [opening, setOpening] = useState<CashRegister | null>(null);
  const [moving, setMoving] = useState<CashRegister | null>(null);
  const [closing, setClosing] = useState<CashRegister | null>(null);
  const [viewing, setViewing] = useState<string | null>(null);
  const [registerOpen, setRegisterOpen] = useState(false);
  const [editing, setEditing] = useState<CashRegister | null>(null);

  const load = useCallback(() => {
    api.get('/cash')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la caja.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const registers = data?.registers ?? [];
  const sessions = data?.sessions ?? [];
  const canManage = Boolean(data?.can_manage);
  const canOperate = Boolean((data as { can_operate?: boolean } | null)?.can_operate);
  const term = searchTerm.trim().toLowerCase();

  const rows = sessions.filter((session) =>
    (!filters.branch_id || session.branch_id === filters.branch_id) &&
    (!filters.register_id || session.register_id === filters.register_id) &&
    (activeCard !== 'difference' || !squared(session)) &&
    (activeCard !== 'squared' || squared(session)) &&
    (!term || `${session.register_name ?? ''} ${session.branch_name ?? ''} ${session.opened_by ?? ''} ${session.closed_by ?? ''}`.toLowerCase().includes(term)),
  );

  const branchOptions = [...new Map(registers.map((r) => [r.branch_id, r.branch_name ?? 'Sede'])).entries()].map(([value, label]) => ({ value, label }));

  const filterConfigs: FilterConfig[] = [
    ...(branchOptions.length > 1 ? [{ id: 'branch_id', label: 'Sede', type: 'select' as const, options: branchOptions }] : []),
    ...(registers.length > 1 ? [{ id: 'register_id', label: 'Caja', type: 'select' as const, options: registers.map((r) => ({ value: r.id, label: branchOptions.length > 1 ? `${r.name} · ${r.branch_name ?? ''}` : r.name })) }] : []),
  ];

  const toggleCard = (id: string) => setActiveCard((current) => (current === id ? '' : id));

  const metricCards: MetricCardItem[] = [
    {
      title: 'Turnos cerrados',
      value: sessions.length,
      icon: <Lock size={18} />,
      iconColorClass: 'text-[#3F768C]',
      borderColorClass: !activeCard ? 'border-[#3F768C] ring-2 ring-[#3F768C]/20' : 'hover:border-slate-300',
      onClick: () => setActiveCard(''),
    },
    {
      title: 'Cuadraron',
      value: sessions.filter(squared).length,
      icon: <CheckCircle2 size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: activeCard === 'squared' ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-slate-300',
      onClick: () => toggleCard('squared'),
    },
    {
      title: 'Con diferencia',
      value: sessions.filter((s) => !squared(s)).length,
      icon: <AlertTriangle size={18} />,
      iconColorClass: 'text-rose-600',
      borderColorClass: activeCard === 'difference' ? 'border-rose-500 ring-2 ring-rose-500/20' : 'hover:border-slate-300',
      onClick: () => toggleCard('difference'),
    },
  ];

  const columns: Column<CashSession>[] = [
    {
      header: 'Caja',
      cell: (session) => (
        <div key={`register-${session.id}`}>
          <button type="button" data-view={session.id} onClick={() => setViewing(session.id)} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left`}>
            {session.register_name ?? 'Caja'}
          </button>
          <Link href={`/branches/${session.branch_id}`} className="block text-xs text-slate-400 hover:text-[#3F768C] hover:underline underline-offset-2 transition-colors">
            {session.branch_name ?? '—'}
          </Link>
        </div>
      ),
    },
    {
      header: 'Apertura',
      cell: (session) => (
        <div key={`opened-${session.id}`}>
          <span className="block text-sm text-slate-700">{momentOf(session.opened_at)}</span>
          <span className="block text-xs text-slate-400">{session.opened_by ?? '—'} · {money(session.opening_amount)}</span>
        </div>
      ),
    },
    {
      header: 'Cierre',
      cell: (session) => (
        <div key={`closed-${session.id}`}>
          <span className="block text-sm text-slate-700">{momentOf(session.closed_at)}</span>
          <span className="block text-xs text-slate-400">{session.closed_by ?? '—'}</span>
        </div>
      ),
    },
    {
      header: 'Debía haber',
      cell: (session) => <span key={`expected-${session.id}`} className="text-sm text-slate-700 tabular-nums">{money(session.expected_amount)}</span>,
    },
    {
      header: 'Contado',
      cell: (session) => <span key={`counted-${session.id}`} className="text-sm font-semibold text-slate-800 tabular-nums">{money(session.closing_amount)}</span>,
    },
    {
      header: 'Resultado',
      cell: (session) => (
        <span
          key={`result-${session.id}`}
          data-result={squared(session) ? 'squared' : 'difference'}
          title={session.closing_notes ?? undefined}
          className={`px-2.5 py-1 text-xs rounded-full font-medium whitespace-nowrap ${squared(session) ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600'}`}
        >
          {squared(session) ? 'Cuadró' : `${(session.difference ?? 0) < 0 ? 'Faltaron' : 'Sobraron'} ${money(Math.abs(session.difference ?? 0))}`}
        </span>
      ),
    },
    {
      header: 'Acciones',
      cell: (session) => (
        <button key={`actions-${session.id}`} title="Ver el turno" onClick={() => setViewing(session.id)} className={iconButton}>
          <Eye size={16} />
        </button>
      ),
    },
  ];

  const openCount = registers.filter((r) => r.session).length;

  return (
    <div className="space-y-6 mb-8">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#022340]">Caja</h1>
          <p className="text-sm text-slate-500 mt-1">
            {registers.length === 0
              ? 'El efectivo de cada turno: apertura, cobros, gastos y cierre con arqueo.'
              : `${openCount} de ${registers.length} ${registers.length === 1 ? 'caja abierta' : 'cajas abiertas'}. Cada turno se cierra contando el efectivo.`}
          </p>
        </div>
        {canManage && (
          <button type="button" data-new-register onClick={() => { setEditing(null); setRegisterOpen(true); }} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors">
            <Plus size={16} className="stroke-[2.5]" /> Nueva caja
          </button>
        )}
      </div>

      {error && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>}

      <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" data-registers>
        {registers.map((register) => {
          const session = register.session;
          const inactive = register.status !== 'active';

          return (
            <article
              key={register.id}
              data-register={register.name}
              data-register-state={session ? 'open' : inactive ? 'inactive' : 'closed'}
              className={`rounded-2xl rounded-tr-[2rem] border p-5 flex flex-col gap-4 ${session ? 'bg-[#022340] border-[#022340] text-white' : 'bg-white border-slate-200/80'} ${inactive ? 'opacity-60' : ''}`}
            >
              <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3 min-w-0">
                  <span className={`p-2.5 rounded-xl shrink-0 ${session ? 'bg-white/10 text-[#84D0D9]' : 'bg-[#84D0D9]/20 text-[#022340]'}`}><Wallet size={18} /></span>
                  <div className="min-w-0">
                    <span className={`block text-sm font-bold truncate ${session ? 'text-white' : 'text-[#022340]'}`}>{register.name}</span>
                    <Link href={`/branches/${register.branch_id}`} className={`block text-xs truncate hover:underline underline-offset-2 ${session ? 'text-[#84D0D9]' : 'text-slate-500 hover:text-[#3F768C]'}`}>
                      {register.branch_name ?? '—'}
                    </Link>
                  </div>
                </div>
                <div className="flex items-center gap-1 shrink-0">
                  <span className={`px-2.5 py-1 text-xs rounded-full font-semibold ${session ? 'bg-emerald-400/20 text-emerald-200' : inactive ? 'bg-slate-100 text-slate-500' : 'bg-slate-100 text-slate-600'}`}>
                    {session ? 'Abierta' : inactive ? 'Fuera de uso' : 'Cerrada'}
                  </span>
                  {canManage && !session && (
                    <button type="button" title={`Editar ${register.name}`} data-edit-register={register.id} onClick={() => { setEditing(register); setRegisterOpen(true); }} className={iconButton}>
                      <Edit2 size={15} />
                    </button>
                  )}
                </div>
              </div>

              {session ? (
                <>
                  <div>
                    <span className="block text-[11px] font-bold text-[#84D0D9] uppercase tracking-wider">Debe haber en efectivo</span>
                    <span className="block text-3xl font-black tabular-nums" data-expected>{money(session.expected_amount)}</span>
                    <span className="block text-xs text-slate-300 mt-1">Abrió {session.opened_by ?? '—'} · {momentOf(session.opened_at)}</span>
                  </div>

                  <dl className="grid grid-cols-3 gap-2 text-xs">
                    {([['Apertura', session.opening_amount], ['Cobros', session.sales + session.income], ['Salidas', session.expense + session.withdrawal + session.refund]] as [string, number][]).map(([label, value]) => (
                      <div key={label} className="rounded-lg bg-white/10 px-2.5 py-2">
                        <dt className="text-slate-300">{label}</dt>
                        <dd className="font-bold tabular-nums">{money(value)}</dd>
                      </div>
                    ))}
                  </dl>

                  <div className="flex flex-wrap gap-2 mt-auto">
                    {canOperate && (<button type="button" data-action="move" onClick={() => setMoving(register)} className="flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-white/10 hover:bg-white/20 transition-colors">
                      <ArrowLeftRight size={15} /> Movimiento
                    </button>)}
                    <button type="button" data-action="view" onClick={() => setViewing(session.id)} className="flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-white/10 hover:bg-white/20 transition-colors">
                      <Eye size={15} /> Ver turno
                    </button>
                    {canOperate && (<button type="button" data-action="close" onClick={() => setClosing(register)} className="flex items-center gap-1.5 px-3 py-2 text-sm font-bold rounded-lg bg-[#84D0D9] text-[#022340] hover:bg-white transition-colors ml-auto">
                      <Lock size={15} /> Cerrar caja
                    </button>)}
                  </div>
                </>
              ) : (
                <>
                  <p className="text-sm text-slate-500">
                    {inactive ? 'Esta caja está fuera de uso.' : 'Sin turno abierto. Ábrela para cobrar en efectivo en esta sede.'}
                  </p>
                  {!inactive && canOperate && (
                    <button type="button" data-action="open" onClick={() => setOpening(register)} className={`${ghostButton} self-start mt-auto`}>
                      <Unlock size={15} /> Abrir caja
                    </button>
                  )}
                </>
              )}
            </article>
          );
        })}
      </div>

      {data && registers.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Tu empresa aún no tiene una sucursal activa donde abrir una caja.</p>
      )}

      <DataTable<CashSession>
        title="Turnos cerrados"
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
        dateFilters={[{ id: 'closed_at', label: 'Fecha de cierre', value: (s) => s.closed_at }]}
        sortOptions={[
          { id: 'closed_at', label: 'Fecha de cierre', type: 'date', value: (s) => s.closed_at },
          { id: 'register', label: 'Caja', value: (s) => s.register_name },
          { id: 'branch', label: 'Sede', value: (s) => s.branch_name },
          { id: 'counted', label: 'Efectivo contado', type: 'number', value: (s) => s.closing_amount },
          { id: 'difference', label: 'Diferencia', type: 'number', value: (s) => Math.abs(s.difference ?? 0) },
        ]}
        columns={columns}
        data={rows}
        getItemKey={(s) => s.id}
        entityLabel={rows.length === 1 ? 'turno' : 'turnos'}
        metricCards={metricCards}
      />

      <OpenCashPanel register={opening} onClose={() => setOpening(null)} onSaved={load} />
      <CashMovementPanel session={moving?.session ?? null} registerName={moving?.name} onClose={() => setMoving(null)} onSaved={load} />
      <CloseCashPanel session={closing?.session ?? null} registerName={closing?.name} tolerance={data?.tolerance ?? 0} onClose={() => setClosing(null)} onSaved={load} />
      <RegisterPanel isOpen={registerOpen} register={editing} branches={data?.branches ?? []} onClose={() => setRegisterOpen(false)} onSaved={load} />
      <CashSessionPanel sessionId={viewing} onClose={() => setViewing(null)} />
    </div>
  );
}
