'use client';

import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { inputClass } from '@/app/components/ui/forms/styles';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Bot, CalendarCheck, CalendarClock, CheckCircle2, Eye, Hand, HelpCircle, MessagesSquare, RotateCcw, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

type Outcome = 'booked' | 'confirmed' | 'rescheduled' | 'cancelled' | 'faq' | 'handoff' | 'open';

interface BoardRow {
  id: string;
  name: string;
  customer_id: string | null;
  phone: string;
  outcome: Outcome;
  outcome_label: string;
  answers: number;
  last_text: string;
  at: string;
  needs_human: boolean;
}

interface Board {
  days: number;
  enabled: boolean;
  connected: boolean;
  handoff_minutes: number;
  quota: { limit: number | null; used: number; reached: boolean };
  kpis: { key: string; label: string; value: number; hint: string | null }[];
  conversations: BoardRow[];
  errors: number;
  can: { simulate: boolean; settings: boolean; reply: boolean };
}

interface SimTurn { from: 'customer' | 'assistant'; text: string; note?: string }

const TONES: Record<Outcome, string> = {
  booked: 'bg-emerald-50 text-emerald-700',
  confirmed: 'bg-emerald-50 text-emerald-700',
  rescheduled: 'bg-sky-50 text-sky-700',
  cancelled: 'bg-amber-50 text-amber-800',
  faq: 'bg-slate-100 text-slate-600',
  handoff: 'bg-rose-50 text-rose-700',
  open: 'bg-slate-100 text-slate-600',
};

const KPI_ICONS: Record<string, React.ReactNode> = {
  conversations: <MessagesSquare size={18} />,
  booked: <CalendarCheck size={18} />,
  confirmed: <CheckCircle2 size={18} />,
  rescheduled: <CalendarClock size={18} />,
  faq: <HelpCircle size={18} />,
  handoff: <Hand size={18} />,
};

const KPI_FILTER: Record<string, Outcome | null> = { conversations: null, booked: 'booked', confirmed: 'confirmed', rescheduled: 'rescheduled', faq: 'faq', handoff: 'handoff' };

const SUGGESTIONS = ['Hola', '¿Tienen hora para mañana en la tarde?', 'La segunda', 'Confirmo mi cita', 'Quiero reprogramar mi cita', '¿Aceptan Yape?', 'Quiero hablar con una persona'];

export default function AssistantDesk() {
  const [board, setBoard] = useState<Board | null>(null);
  const [days, setDays] = useState(30);
  const [filter, setFilter] = useState<Outcome | null>(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [error, setError] = useState('');
  const [customer, setCustomer] = useState<PickedCustomer | null>(null);
  const [turns, setTurns] = useState<SimTurn[]>([]);
  const [text, setText] = useState('');
  const [busy, setBusy] = useState(false);
  const [simError, setSimError] = useState('');
  const logRef = useRef<HTMLDivElement>(null);

  const load = useCallback(() => {
    api.get('/assistant/board', { params: { days } })
      .then((res) => { setBoard(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el tablero de la recepcionista.')));
  }, [days]);

  useEffect(() => { load(); }, [load]);

  useEffect(() => { logRef.current?.scrollTo({ top: logRef.current.scrollHeight }); }, [turns]);

  const send = async (value: string) => {
    const message = value.trim();
    if (!message || busy) return;
    const history = turns.map(({ from, text: t }) => ({ from, text: t }));
    setTurns([...turns, { from: 'customer', text: message }]);
    setText('');
    setSimError('');
    try {
      setBusy(true);
      const res = await api.post('/assistant/simulate', { text: message, customer_id: customer?.id ?? null, history });
      const done = (res.data.done ?? []).map((d: { label: string }) => d.label).join(' · ');
      const note = [res.data.label, done, res.data.reason].filter(Boolean).join(' — ');
      setTurns((current) => [...current, { from: 'assistant', text: res.data.reply ?? '(No respondió)', note }]);
    } catch (err) {
      setSimError(apiError(err, 'No se pudo probar la recepcionista.'));
      setTurns((current) => current.slice(0, -1));
      setText(message);
    } finally {
      setBusy(false);
    }
  };

  const rows = board?.conversations ?? [];
  const term = searchTerm.trim().toLowerCase();
  const data = rows.filter((r) => (!filter || r.outcome === filter) && (!term || `${r.name} ${r.phone} ${r.last_text}`.toLowerCase().includes(term)));

  const metricCards: MetricCardItem[] = (board?.kpis ?? []).map((k) => ({
    title: k.label,
    value: k.value,
    icon: KPI_ICONS[k.key] ?? <Bot size={18} />,
    iconColorClass: k.key === 'handoff' ? 'text-rose-600' : 'text-brand-accent',
    borderColorClass: KPI_FILTER[k.key] && filter === KPI_FILTER[k.key] ? 'border-brand ring-2 ring-brand/20' : 'hover:border-slate-300',
    onClick: () => setFilter(KPI_FILTER[k.key] && filter !== KPI_FILTER[k.key] ? KPI_FILTER[k.key] : null),
  }));

  const columns: Column<BoardRow>[] = [
    {
      header: 'Cliente',
      cell: (r) => (
        <div key={`who-${r.id}`} className="text-sm">
          <span className="block font-semibold text-slate-700">{r.customer_id ? <CustomerLink id={r.customer_id}>{r.name}</CustomerLink> : <Link href={`/messages/whatsapp/${r.id}`} className={styles.customerName}>{r.name}</Link>}</span>
          <span className="block text-xs text-slate-400">+{r.phone}</span>
        </div>
      ),
    },
    {
      header: 'Lo último que escribió',
      cell: (r) => (
        <div key={`text-${r.id}`} className="min-w-0 max-w-md">
          <Link href={`/messages/whatsapp/${r.id}`} className="block text-sm text-slate-700 truncate hover:text-brand-accent">{r.last_text || '—'}</Link>
          <span className="block text-xs text-slate-400">{momentOf(r.at)} · {r.answers} {r.answers === 1 ? 'respuesta' : 'respuestas'} del asistente</span>
        </div>
      ),
    },
    {
      header: 'Resultado',
      cell: (r) => <Badge key={`out-${r.id}`} tone={TONES[r.outcome]} nowrap data-outcome={r.outcome}>{r.outcome_label}</Badge>,
    },
    {
      header: 'Acciones',
      cell: (r) => (
        <Link key={`act-${r.id}`} href={`/messages/whatsapp/${r.id}`} title="Ver la conversación" className="inline-flex p-1.5 text-slate-400 rounded-lg hover:text-brand-ink hover:bg-slate-100 transition-colors">
          <Eye size={16} />
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8" data-assistant-desk>
      <BackLink href="/messages">Mensajes</BackLink>
      <div>
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Recepcionista con IA</h1>
        <p className="text-sm text-slate-500 mt-1">Lo que respondió por WhatsApp, cómo terminó cada conversación y un simulador para probarla.</p>
      </div>
      <ErrorAlert message={error} />

      {board && (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 ${board.enabled && board.connected ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900'}`} data-assistant-state={board.enabled && board.connected ? 'on' : 'off'}>
          <span className="flex items-center gap-2 text-sm font-semibold">
            <Bot size={16} />
            {!board.connected ? 'Falta la clave de inteligencia artificial: la recepcionista no responde.' : board.enabled ? `Encendida: responde el WhatsApp y pasa a una persona si no resuelve en ${board.handoff_minutes} minutos.` : 'Apagada: no responde los mensajes de WhatsApp. Puedes probarla igual en el simulador.'}
            {board.quota.limit !== null && <span className="font-normal"> · {board.quota.used} de {board.quota.limit} respuestas este mes</span>}
          </span>
          <span className="flex items-center gap-3">
            <span className="w-36"><Select ariaLabel="Periodo del tablero" value={String(days)} options={[{ value: '7', label: 'Últimos 7 días' }, { value: '30', label: 'Últimos 30 días' }, { value: '90', label: 'Últimos 90 días' }]} onChange={(e) => setDays(Number(e.target.value))} /></span>
            {board.can.settings && <Link href="/settings#whatsapp" className="text-xs font-bold underline underline-offset-2">Configurar</Link>}
          </span>
        </div>
      )}

      {board?.can.simulate && (
        <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 sm:p-6 space-y-4" data-simulator>
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 className="text-base font-bold text-brand-ink flex items-center gap-2"><MessagesSquare size={18} className="text-brand-accent" /> Probar la recepcionista</h2>
              <p className="text-xs text-slate-500 mt-1">Escribe como si fueras un cliente. Responde con los datos reales de tu empresa, pero no se guarda nada: no se crean ni cambian citas y no sale ningún WhatsApp.</p>
            </div>
            {turns.length > 0 && (
              <button type="button" data-sim-reset onClick={() => { setTurns([]); setSimError(''); }} className="flex items-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50">
                <RotateCcw size={14} /> Empezar de nuevo
              </button>
            )}
          </div>
          <div className="max-w-md">
            <CustomerPicker value={customer} onChange={(c) => { setCustomer(c); setTurns([]); }} required={false} />
            <p className="text-xs text-slate-400 mt-1">Opcional: elige a alguien para que le responda como a esa persona.</p>
          </div>
          <div ref={logRef} className="rounded-xl bg-slate-50 border border-slate-200 p-3 h-72 overflow-y-auto space-y-2" data-sim-log>
            {turns.length === 0 && <p className="text-xs text-slate-400">Escribe un mensaje para empezar.</p>}
            {turns.map((t, i) => (
              <div key={i} className={`flex ${t.from === 'customer' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm whitespace-pre-line ${t.from === 'customer' ? 'bg-emerald-100 text-emerald-950' : 'bg-white border border-slate-200 text-slate-800'}`} data-sim-turn={t.from}>
                  {t.text}
                  {t.note && <span className="block text-[11px] text-slate-500 mt-1">{t.note}</span>}
                </div>
              </div>
            ))}
            {busy && <p className="text-xs text-slate-400" data-sim-typing>Escribiendo…</p>}
          </div>
          <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); send(text); }}>
            <input aria-label="Mensaje de prueba" maxLength={1000} className={`${inputClass} flex-1 min-w-0`} placeholder="Ej.: ¿tienen hora para manicure mañana a las 4?" value={text} onChange={(e) => setText(e.target.value)} />
            <button type="submit" data-sim-send disabled={busy || !text.trim()} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm disabled:opacity-50">
              <Send size={14} /> <span className="hidden sm:inline">Enviar</span>
            </button>
          </form>
          <div className="flex flex-wrap gap-2">
            {SUGGESTIONS.map((s) => (
              <button key={s} type="button" data-sim-say={s} disabled={busy} onClick={() => send(s)} className="px-3 py-1.5 text-xs font-semibold rounded-full border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-50">{s}</button>
            ))}
          </div>
          <ErrorAlert message={simError} />
        </section>
      )}

      <DataTable<BoardRow>
        title="Conversaciones que atendió"
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        sortOptions={[
          { id: 'at', label: 'Más recientes', type: 'date', value: (r) => r.at },
          { id: 'name', label: 'Cliente', value: (r) => r.name },
        ]}
        columns={columns}
        data={data}
        getItemKey={(r) => r.id}
        entityLabel={data.length === 1 ? 'conversación' : 'conversaciones'}
        metricCards={metricCards}
      />
      {board && rows.length === 0 && <p className="text-sm text-slate-500 px-1">En este periodo la recepcionista aún no atendió conversaciones.</p>}
    </div>
  );
}
