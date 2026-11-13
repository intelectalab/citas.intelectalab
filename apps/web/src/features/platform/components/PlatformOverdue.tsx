'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { PagedList } from '@/app/components/common/Pager';
import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { BellRing, CalendarPlus, CreditCard, Loader2, RotateCw, TriangleAlert } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money, momentOf } from '@/features/sales/sales';

interface Attempt {
  kind: 'retry' | 'remind' | 'readonly' | 'cancel' | 'extend';
  result: string;
  day: number;
  message: string | null;
  manual: boolean;
  at: string;
}

interface OverdueRow {
  subscription_id: string;
  tenant: { id: string; name: string };
  plan: string | null;
  amount: number;
  currency: string;
  state: 'grace' | 'locked';
  ended_at: string;
  day: number;
  readonly_on: string | null;
  cancel_on: string | null;
  extra_days: number;
  saved_card: boolean;
  card: string | null;
  last_failure: string | null;
  retries: number;
  reminders: number;
  attempts: Attempt[];
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs';
const longDay = (date: string | null) => (date ? formatDate(date, { day: 'numeric', month: 'long' }) : '—');

const ATTEMPT_LABEL: Record<Attempt['kind'], string> = {
  retry: 'Cobro a la tarjeta',
  remind: 'Recordatorio',
  readonly: 'Pasó a solo lectura',
  cancel: 'Plan cancelado',
  extend: 'Prórroga',
};

const resultTone = (attempt: Attempt) => (attempt.result === 'failed' ? 'bg-rose-50 text-rose-700' : attempt.kind === 'retry' && attempt.result === 'ok' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600');
const resultText = (attempt: Attempt) => (attempt.kind === 'retry' ? (attempt.result === 'ok' ? 'Pagado' : 'No pasó') : attempt.kind === 'remind' ? 'Enviado' : 'Hecho');

export default function PlatformOverdue({ onChanged }: { onChanged?: () => void }) {
  const [rows, setRows] = useState<OverdueRow[] | null>(null);
  const [ready, setReady] = useState(true);
  const [open, setOpen] = useState<OverdueRow | null>(null);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/admin/billing/overdue')
      .then((res) => { setRows(res.data.overdue); setReady(res.data.ready); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los pagos vencidos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const act = async (row: OverdueRow, action: 'retry' | 'remind' | 'extend') => {
    try {
      setBusy(action);
      setError('');
      setNotice('');
      const res = await api.post(`/admin/billing/overdue/${row.subscription_id}/${action}`);
      setNotice(`${row.tenant.name}: ${res.data.message}`);
      const list: OverdueRow[] = res.data.overdue ?? [];
      setRows(list);
      setOpen(list.find((item) => item.subscription_id === row.subscription_id) ?? null);
      onChanged?.();
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="space-y-4" data-overdue>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {!ready && <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">Falta actualizar la base de datos para ver los pagos vencidos: ejecuta php artisan migrate.</p>}

      <section className={`${card} overflow-hidden flex flex-col`}>
        {rows === null ? (
          <p className="p-6 text-sm text-slate-500">Cargando...</p>
        ) : (
          <PagedList
            items={rows}
            empty="Ninguna empresa tiene el plan vencido."
            renderRow={(row) => (
              <li key={row.subscription_id}>
                <button type="button" data-overdue-row={row.tenant.id} onClick={() => { setOpen(row); setNotice(''); setError(''); }} className="w-full text-left flex flex-wrap sm:flex-nowrap items-center gap-3 px-5 py-3.5 hover:bg-slate-50">
                  <span className={`p-2 rounded-xl shrink-0 ${row.state === 'locked' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800'}`}><TriangleAlert size={16} /></span>
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-bold text-slate-800 truncate">{row.tenant.name}</span>
                    <span className="block text-xs text-slate-500 truncate">
                      Plan {row.plan} · venció hace {row.day} {row.day === 1 ? 'día' : 'días'} · {row.state === 'locked' ? `se cancela el ${longDay(row.cancel_on)}` : `solo lectura el ${longDay(row.readonly_on)}`}
                      {row.last_failure ? ` · ${row.last_failure}` : ''}
                    </span>
                  </span>
                  <span className="text-sm font-black text-slate-800 tabular-nums">{money(row.amount, row.currency)}</span>
                  <Badge tone={row.state === 'locked' ? 'bg-rose-50 text-rose-700' : 'bg-amber-50 text-amber-800'} bold className="shrink-0">{row.state === 'locked' ? 'Solo lectura' : 'Pago vencido'}</Badge>
                </button>
              </li>
            )}
          />
        )}
      </section>

      {open && (
        <FormDrawer isOpen onClose={() => setOpen(null)} title={open.tenant.name} subtitle={`Plan ${open.plan} · ${money(open.amount, open.currency)} · venció el ${longDay(open.ended_at)}`} icon={<TriangleAlert size={18} />}>
          <div className="space-y-4" data-overdue-detail>
            <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
              {([
                ['Días de atraso', String(open.day)],
                ['Tarjeta', open.card ? `${open.card}${open.saved_card ? '' : ' (la cobra Mercado Pago)'}` : 'Sin tarjeta guardada'],
                ['Queda en solo lectura', longDay(open.readonly_on)],
                ['Se cancela', longDay(open.cancel_on)],
                ['Prórroga dada', open.extra_days ? `${open.extra_days} días` : null],
                ['Último rechazo', open.last_failure],
              ] as [string, string | null][]).filter(([, value]) => value).map(([label, value]) => (
                <div key={label} className="flex items-center justify-between gap-3 px-4 py-2.5">
                  <dt className="text-xs text-slate-500">{label}</dt>
                  <dd className="font-semibold text-slate-800 text-right">{value}</dd>
                </div>
              ))}
            </dl>

            <div className="flex flex-wrap gap-2">
              <button type="button" data-overdue-action="retry" disabled={busy !== '' || !open.saved_card} onClick={() => act(open, 'retry')} title={open.saved_card ? undefined : 'No tiene una tarjeta guardada en Culqi'} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm disabled:opacity-50">
                {busy === 'retry' ? <Loader2 size={15} className="animate-spin" /> : <RotateCw size={15} />} Reintentar cobro
              </button>
              <button type="button" data-overdue-action="remind" disabled={busy !== ''} onClick={() => act(open, 'remind')} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 disabled:opacity-50">
                {busy === 'remind' ? <Loader2 size={15} className="animate-spin" /> : <BellRing size={15} />} Recordar
              </button>
              <button type="button" data-overdue-action="extend" disabled={busy !== ''} onClick={() => act(open, 'extend')} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 disabled:opacity-50">
                {busy === 'extend' ? <Loader2 size={15} className="animate-spin" /> : <CalendarPlus size={15} />} Dar 7 días más
              </button>
            </div>
            {!open.saved_card && <p className="text-xs text-slate-500 flex items-center gap-1.5"><CreditCard size={13} /> Sin tarjeta en Culqi no se puede reintentar desde aquí: la empresa paga con &quot;Pagar ahora&quot;.</p>}
            <ErrorAlert message={error} />

            <div className="space-y-2">
              <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Lo que se hizo</span>
              <div className="rounded-xl border border-slate-200 overflow-hidden flex flex-col">
                <PagedList
                  items={open.attempts}
                  empty="Todavía no hay intentos ni avisos."
                  renderRow={(attempt) => (
                    <li key={`${attempt.kind}-${attempt.at}`} className="flex items-center gap-3 px-4 py-3">
                      <Badge tone={resultTone(attempt)} bold className="shrink-0">{resultText(attempt)}</Badge>
                      <span className="flex-1 min-w-0">
                        <span className="block text-sm font-semibold text-slate-800 truncate">{ATTEMPT_LABEL[attempt.kind]} · día {attempt.day}{attempt.manual ? ' · desde la consola' : ''}</span>
                        <span className="block text-xs text-slate-500 truncate">{attempt.message ? `${attempt.message} · ` : ''}{momentOf(attempt.at)}</span>
                      </span>
                    </li>
                  )}
                />
              </div>
            </div>
            <Link href={`/admin/tenants/${open.tenant.id}`} className="block text-xs font-semibold text-brand-accent hover:underline">Ver la empresa</Link>
          </div>
        </FormDrawer>
      )}
    </div>
  );
}
