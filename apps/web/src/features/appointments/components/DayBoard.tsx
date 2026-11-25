'use client';

import { ErrorAlert } from '@/app/components/common/Alert';
import DateField from '@/app/components/common/DateField';
import Select from '@/app/components/common/Select';
import { useCallback, useEffect, useState } from 'react';
import { ArrowRight, ChevronLeft, ChevronRight, Eye, Wallet } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { suggestPlural, useVocabulary } from '@/hooks/useVocabulary';
import ChargePanel from '@/features/sales/components/ChargePanel';
import { actionLabel, addDays, apiError, formatDate, statusStyle, timeOf, todayISO, type Appointment } from '../appointments';
import { useAutoRefresh } from './useAutoRefresh';

interface DayBoardProps {
  onOpen: (id: string) => void;
  paused: boolean;
  version: number;
}

const nextFor = (appointment: Appointment, withArrival: boolean): string | null => {
  const order: Record<string, string> = withArrival
    ? { pending: 'confirmed', confirmed: 'checked_in', checked_in: 'in_progress', in_progress: 'completed' }
    : { pending: 'confirmed', confirmed: 'completed', checked_in: 'completed', in_progress: 'completed' };
  const next = order[appointment.status];
  return next && appointment.next_statuses.includes(next) ? next : null;
};

export default function DayBoard({ onOpen, paused, version }: DayBoardProps) {
  const { user, can, hasModule } = useAuth();
  const vocab = useVocabulary();
  const [date, setDate] = useState(todayISO());
  const [rows, setRows] = useState<Appointment[]>([]);
  const [professionals, setProfessionals] = useState<{ id: string; display_name: string }[]>([]);
  const [professionalId, setProfessionalId] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [charging, setCharging] = useState<Appointment | null>(null);
  const [phoneColumn, setPhoneColumn] = useState('');

  const withArrival = user?.tenant?.rules?.['agenda.checkin_step'] !== false;
  const columns = withArrival ? ['pending', 'confirmed', 'checked_in', 'in_progress', 'completed'] : ['pending', 'confirmed', 'completed'];
  const statusNames = Object.fromEntries((user?.tenant?.appointment_statuses ?? []).map((s) => [s.key, s.name]));
  const canCharge = hasModule('pos') && can('sales.create');

  const load = useCallback(() => {
    api.get('/appointments', { params: { date, with_paid: 1, ...(professionalId ? { professional_id: professionalId } : {}) } })
      .then((res) => { setRows(Array.isArray(res.data) ? res.data : []); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el tablero.')))
      .finally(() => setLoading(false));
  }, [date, professionalId]);

  useEffect(() => { setLoading(true); load(); }, [load, version]);
  useAutoRefresh(load, paused || busy !== '' || charging !== null, 30000);

  useEffect(() => {
    api.get('/appointments/context')
      .then((res) => setProfessionals(res.data.professionals ?? []))
      .catch(() => setProfessionals([]));
  }, []);

  const advance = async (appointment: Appointment, to: string) => {
    try {
      setBusy(appointment.id);
      setError('');
      await api.post(`/appointments/${appointment.id}/status`, { status: to });
      load();
    } catch (err) {
      const code = (err as { response?: { data?: { code?: string } } })?.response?.data?.code;
      if (code === 'RECORD_REQUIRED') {
        setError(`${appointment.code}: falta firmar la ficha de atención. Ábrela con «Ver».`);
      } else {
        setError(`${appointment.code}: ${apiError(err, 'No se pudo cambiar la fase.')}`);
      }
    } finally {
      setBusy('');
    }
  };

  const noShows = rows.filter((a) => a.status === 'no_show').length;
  const cancelled = rows.filter((a) => a.status === 'cancelled').length;
  const shownColumn = phoneColumn || columns[0];

  const card = (appointment: Appointment) => {
    const next = nextFor(appointment, withArrival);
    const style = statusStyle(appointment.status);
    return (
      <div key={appointment.id} data-board-card={appointment.id} className="bg-white border border-slate-200 rounded-xl p-2.5 shadow-xs" style={{ borderLeft: `4px solid ${appointment.professional?.color_code ?? '#cbd5e1'}` }}>
        <div className="flex items-start justify-between gap-2">
          <span className="min-w-0">
            <span className="block text-sm font-bold text-slate-800 truncate">{timeOf(appointment.starts_at)} · {appointment.dependent?.name ?? appointment.customer?.name ?? '—'}</span>
            <span className="block text-xs text-slate-500 truncate">{appointment.service?.name}{appointment.professional ? ` · ${appointment.professional.display_name}` : ''}</span>
            {appointment.source_label && appointment.source !== 'staff' && <span className="block text-[11px] text-slate-400 truncate">{appointment.source_label}</span>}
          </span>
          <button type="button" data-board-open={appointment.id} onClick={() => onOpen(appointment.id)} title="Ver la cita" className="shrink-0 flex items-center gap-1 px-2 py-1 text-xs font-semibold text-slate-500 hover:text-brand-ink hover:bg-slate-50 rounded-lg">
            <Eye size={13} /> Ver
          </button>
        </div>
        {next && (
          <button
            type="button"
            data-board-advance={appointment.id}
            data-to={next}
            disabled={busy !== ''}
            onClick={() => advance(appointment, next)}
            className="mt-2 w-full flex items-center justify-center gap-1.5 px-2.5 py-1.5 text-xs font-bold rounded-lg border border-slate-200 text-slate-700 hover:border-brand-accent hover:text-brand-ink disabled:opacity-50"
          >
            {busy === appointment.id ? 'Guardando...' : actionLabel(next, appointment.status)} <ArrowRight size={13} />
          </button>
        )}
        {appointment.status === 'completed' && canCharge && appointment.paid === false && (
          <button
            type="button"
            data-board-charge={appointment.id}
            onClick={() => setCharging(appointment)}
            className="mt-2 w-full flex items-center justify-center gap-1.5 px-2.5 py-1.5 text-xs font-bold rounded-lg bg-brand text-white hover:bg-brand-hover"
          >
            <Wallet size={13} /> Cobrar
          </button>
        )}
        {appointment.status === 'completed' && appointment.paid === true && (
          <span className={`mt-2 inline-block px-2 py-0.5 rounded-full text-[11px] font-semibold ${style.badge}`}>Cobrada</span>
        )}
      </div>
    );
  };

  return (
    <div className="space-y-4" data-day-board>
      <div className="flex flex-wrap items-center gap-2">
        <button type="button" title="Día anterior" onClick={() => setDate(addDays(date, -1))} className="p-2 border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50"><ChevronLeft size={16} /></button>
        <button type="button" onClick={() => setDate(todayISO())} className="px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50">Hoy</button>
        <button type="button" title="Día siguiente" onClick={() => setDate(addDays(date, 1))} className="p-2 border border-slate-200 bg-white rounded-lg text-slate-600 hover:bg-slate-50"><ChevronRight size={16} /></button>
        <DateField ariaLabel="Día del tablero" size="compact" clearable={false} className="w-40" value={date} onChange={(value) => value && setDate(value)} />
        {professionals.length > 1 && (
          <div className="w-52">
            <Select ariaLabel={vocab.pro} placeholder={`${vocab.pick('pro', 'Todos los', 'Todas las')} ${suggestPlural(vocab.pro).toLowerCase()}`} value={professionalId} onChange={(e) => setProfessionalId(String(e.target.value))} options={professionals.map((p) => ({ value: p.id, label: p.display_name }))} />
          </div>
        )}
        <span className="text-sm font-semibold text-slate-700 first-letter:uppercase">{formatDate(date, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
        <span className="sm:ml-auto text-xs text-slate-500" data-board-counts>
          {noShows} {noShows === 1 ? 'no asistió' : 'no asistieron'} · {cancelled} {cancelled === 1 ? 'cancelada' : 'canceladas'}
        </span>
      </div>

      <ErrorAlert message={error} />

      <div className="sm:hidden flex gap-1.5 overflow-x-auto pb-0.5" role="radiogroup" aria-label="Fase">
        {columns.map((status) => (
          <button
            key={status}
            type="button"
            role="radio"
            aria-checked={shownColumn === status}
            onClick={() => setPhoneColumn(status)}
            className={`shrink-0 px-3 py-1.5 rounded-full border text-xs font-semibold ${shownColumn === status ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 bg-white text-slate-600'}`}
          >
            {statusNames[status] ?? status} · {rows.filter((a) => a.status === status).length}
          </button>
        ))}
      </div>

      {loading && rows.length === 0 ? (
        <p className="text-sm text-slate-500">Cargando tablero...</p>
      ) : (
        <div className="grid gap-3 sm:grid-flow-col sm:auto-cols-[minmax(210px,1fr)] sm:overflow-x-auto pb-2">
          {columns.map((status) => {
            const list = rows.filter((a) => a.status === status).sort((a, b) => a.starts_at.localeCompare(b.starts_at));
            return (
              <section key={status} data-board-column={status} className={`rounded-2xl bg-slate-50 border border-slate-200/80 p-2.5 space-y-2 ${shownColumn === status ? '' : 'hidden sm:block'}`}>
                <header className="flex items-center justify-between px-1">
                  <span className="flex items-center gap-1.5 text-sm font-bold text-slate-700">
                    <span className={`w-2 h-2 rounded-full ${statusStyle(status).dot}`} />{statusNames[status] ?? status}
                  </span>
                  <span className="px-2 py-0.5 rounded-full bg-white border border-slate-200 text-[11px] font-bold text-slate-500">{list.length}</span>
                </header>
                {list.length === 0 ? <p className="px-1 text-xs text-slate-400">Ninguna.</p> : list.map(card)}
              </section>
            );
          })}
        </div>
      )}

      <ChargePanel
        appointmentId={charging?.id ?? null}
        appointmentCode={charging?.code}
        onClose={() => { setCharging(null); load(); }}
        onChanged={() => undefined}
      />
    </div>
  );
}
