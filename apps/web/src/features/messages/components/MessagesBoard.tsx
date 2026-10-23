'use client';

import RichTextView from '@/app/components/common/RichTextView';
import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { AlertTriangle, CalendarClock, Eye, Mail, MailCheck, RefreshCw, Send } from 'lucide-react';
import { DataTable, type Column, type FilterConfig, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import ComposePanel from './ComposePanel';
import { EMAIL_BADGES, MESSAGE_BADGES, type Message, type MessagesBoard as Board } from '../messages';

type View = 'all' | 'sent' | 'scheduled' | 'pending' | 'problems';

const PROBLEMS = ['failed', 'not_sent'];

export function MessageDetail({ message, can, onClose, onChanged, onEdit }: {
  message: Message | null;
  can: Board['can'] | null;
  onClose: () => void;
  onChanged: (message: Message, notice: string) => void;
  onEdit: (message: Message) => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => { setError(''); }, [message?.id]);

  const act = async (path: string, done: (m: Message) => string) => {
    if (!message) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.post(`/messages/${message.id}/${path}`);
      onChanged(res.data, done(res.data));
    } catch (err) {
      setError(apiError(err, 'No se pudo hacer el cambio.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={message !== null} onClose={onClose} title={message?.subject ?? 'Mensaje'} subtitle={message ? `${message.kind_label} · ${message.to_name ?? message.to ?? 'Sin destinatario'}` : undefined} icon={<Mail size={20} />}>
      {message && (
        <div className="space-y-5 pb-4" data-message-detail={message.id}>
          <div className="flex flex-wrap items-center gap-2">
            <Badge tone={MESSAGE_BADGES[message.status]} bold>{message.status_label}</Badge>
            <span className="text-xs text-slate-500">
              {message.status === 'scheduled' && message.scheduled_at ? `Sale ${momentOf(message.scheduled_at)}` : message.sent_at ? `${message.status === 'simulated' ? 'Simulado' : 'Enviado'} ${momentOf(message.sent_at)}` : `Creado ${momentOf(message.created_at)}`}
            </span>
          </div>

          {message.error && <p className={`text-sm rounded-lg px-3 py-2 border ${message.status === 'cancelled' ? 'text-slate-600 bg-slate-50 border-slate-200' : 'text-amber-800 bg-amber-50 border-amber-200'}`}>{message.error}</p>}

          <dl className="grid grid-cols-1 gap-2 text-sm">
            <div><dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Para</dt><dd className="font-semibold text-slate-800">{message.customer ? <CustomerLink id={message.customer.id}>{message.customer.name}</CustomerLink> : message.to_name ?? '—'} {message.to && <span className="font-normal text-slate-500">· {message.to}</span>}</dd></div>
            <div><dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Por qué</dt><dd className="font-semibold text-slate-800">{message.kind_label}{message.by ? ` · lo escribió ${message.by}` : ''}</dd></div>
            {message.attempts > 1 && <div><dt className="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Intentos</dt><dd className="font-semibold text-slate-800">{message.attempts}</dd></div>}
          </dl>

          <div className="rounded-2xl border border-slate-200 overflow-hidden">
            <div className="px-5 py-3 bg-slate-50/80 border-b border-slate-100 text-sm font-bold text-[#022340]">{message.subject}</div>
            <RichTextView body={message.body} className="px-5 py-4 text-sm text-slate-700" data-message-body />
          </div>

          <ErrorAlert message={error} />

          {can?.edit && (message.editable || message.retryable || message.status === 'queued') && (
            <div className="flex flex-wrap gap-2 pt-2 border-t border-slate-100">
              {message.editable && (
                <button type="button" data-action="edit-message" onClick={() => onEdit(message)} className="px-4 py-2 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50">Cambiar texto o fecha</button>
              )}
              {(message.editable || message.status === 'queued') && (
                <button type="button" data-action="cancel-message" disabled={busy} onClick={() => act('cancel', () => 'El mensaje se canceló: ya no saldrá.')} className="px-4 py-2 text-sm font-semibold border border-rose-200 rounded-lg text-rose-700 hover:bg-rose-50 disabled:opacity-50">Cancelar envío</button>
              )}
              {message.retryable && (
                <button type="button" data-action="retry-message" disabled={busy} onClick={() => act('retry', (m) => (m.status === 'failed' || m.status === 'not_sent' ? m.error ?? 'Volvió a fallar.' : `Reintento: ${m.status_label.toLowerCase()}.`))} className="flex items-center gap-1.5 px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-lg hover:bg-[#03315a] disabled:opacity-50">
                  <RefreshCw size={14} /> Reintentar
                </button>
              )}
            </div>
          )}
        </div>
      )}
    </FormDrawer>
  );
}

export default function MessagesBoard() {
  const [data, setData] = useState<Board | null>(null);
  const [view, setView] = useState<View>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ kind: '', status: '' });
  const [detail, setDetail] = useState<Message | null>(null);
  const [composing, setComposing] = useState(false);
  const [editing, setEditing] = useState<Message | null>(null);
  const [checking, setChecking] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/messages')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los mensajes.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const all = data?.messages ?? [];
  const term = searchTerm.trim().toLowerCase();
  const inView = (m: Message) => view === 'all'
    || (view === 'sent' && (m.status === 'sent' || m.status === 'simulated'))
    || (view === 'scheduled' && m.status === 'scheduled')
    || (view === 'pending' && m.status === 'queued')
    || (view === 'problems' && PROBLEMS.includes(m.status));

  const rows = all.filter((m) => inView(m)
    && (!filters.kind || m.kind === filters.kind)
    && (!filters.status || m.status === filters.status)
    && (!term || `${m.subject ?? ''} ${m.to ?? ''} ${m.to_name ?? ''} ${m.kind_label}`.toLowerCase().includes(term)));

  const checkNow = async () => {
    try {
      setChecking(true);
      setError('');
      const res = await api.post('/messages/process');
      const { sent, failed, not_sent: notSent } = res.data;
      setNotice(sent + failed + notSent === 0 ? 'Revisado: no había nada por enviar en este momento.' : `Revisado: ${sent} ${data?.email.state === 'simulated' ? 'simulados (no salieron)' : 'enviados'}${failed ? `, ${failed} con error` : ''}${notSent ? `, ${notSent} no enviados` : ''}.`);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo revisar.'));
    } finally {
      setChecking(false);
    }
  };

  const changed = (message: Message, text: string) => {
    setNotice(text);
    setDetail(message);
    load();
  };

  const card = (title: string, value: number, icon: React.ReactNode, id: View, color: string, ring: string): MetricCardItem => ({
    title, value, icon, iconColorClass: color,
    borderColorClass: view === id ? ring : 'hover:border-slate-300',
    onClick: () => setView(view === id ? 'all' : id),
  });

  const metricCards: MetricCardItem[] = [
    card('Enviados', all.filter((m) => m.status === 'sent' || m.status === 'simulated').length, <MailCheck size={18} />, 'sent', 'text-emerald-600', 'border-emerald-500 ring-2 ring-emerald-500/20'),
    card('Programados', all.filter((m) => m.status === 'scheduled').length, <CalendarClock size={18} />, 'scheduled', 'text-violet-600', 'border-violet-500 ring-2 ring-violet-500/20'),
    card('Por enviar', all.filter((m) => m.status === 'queued').length, <Send size={18} />, 'pending', 'text-sky-600', 'border-sky-500 ring-2 ring-sky-500/20'),
    card('Con problemas', all.filter((m) => PROBLEMS.includes(m.status)).length, <AlertTriangle size={18} />, 'problems', 'text-amber-600', 'border-amber-500 ring-2 ring-amber-500/20'),
  ];

  const columns: Column<Message>[] = [
    {
      header: 'Fecha',
      cell: (m) => (
        <div key={`at-${m.id}`} className="text-sm">
          <span className="block text-slate-700">{momentOf(m.status === 'scheduled' && m.scheduled_at ? m.scheduled_at : m.sent_at ?? m.created_at)}</span>
          {m.status === 'scheduled' && <span className="block text-xs text-violet-600 font-medium">Programado</span>}
        </div>
      ),
    },
    {
      header: 'Cliente',
      cell: (m) => (
        <div key={`to-${m.id}`} className="text-sm">
          <span className="block font-semibold text-slate-700">{m.customer ? <CustomerLink id={m.customer.id}>{m.customer.name}</CustomerLink> : m.to_name ?? '—'}</span>
          <span className="block text-xs text-slate-400">{m.to ?? 'Sin correo'}</span>
        </div>
      ),
    },
    {
      header: 'Mensaje',
      cell: (m) => (
        <div key={`subject-${m.id}`} className="min-w-0 max-w-md">
          <button type="button" data-open-message={m.subject ?? ''} onClick={() => setDetail(m)} className={`${styles.customerName} hover:text-[#3F768C] transition-colors text-left truncate block max-w-full`}>{m.subject ?? '(sin asunto)'}</button>
          <span className="block text-xs text-slate-400">{m.kind_label}</span>
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (m) => (
        <div key={`status-${m.id}`}>
          <Badge data-message-status={m.status} tone={MESSAGE_BADGES[m.status]} nowrap>{m.status_label}</Badge>
          {PROBLEMS.includes(m.status) && m.error && <span className="block mt-1 text-[11px] text-amber-700 max-w-[14rem] line-clamp-2">{m.error.replace(/^No enviado: /, '')}</span>}
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (m) => (
        <button key={`actions-${m.id}`} type="button" title="Ver el mensaje" onClick={() => setDetail(m)} className="p-1.5 text-slate-400 rounded-lg hover:text-[#022340] hover:bg-slate-100 transition-colors">
          <Eye size={16} />
        </button>
      ),
    },
  ];

  const filterConfigs: FilterConfig[] = [
    { id: 'kind', label: 'Tipo', type: 'select', options: (data?.kinds ?? []).map((k) => ({ value: k.value, label: k.label })) },
    { id: 'status', label: 'Estado', type: 'select', options: (data?.statuses ?? []).map((s) => ({ value: s.value, label: s.label })) },
  ];

  const email = data?.email;

  return (
    <div className="space-y-6 mb-8" data-messages>
      {email && (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 ${EMAIL_BADGES[email.state]}`} data-email-state={email.state}>
          <span className="flex items-center gap-2 text-sm font-semibold"><Mail size={16} /> Correo de tu empresa: {email.label}</span>
          <div className="flex items-center gap-3">
            {data?.can.settings && <Link href="/settings#correo" className="text-xs font-bold underline underline-offset-2">Configurar el correo</Link>}
            <button type="button" data-check-now disabled={checking} onClick={checkNow} className="flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold bg-white border border-slate-200 text-slate-700 rounded-lg hover:bg-slate-50 disabled:opacity-50">
              <RefreshCw size={13} className={checking ? 'animate-spin' : ''} /> Revisar ahora{data?.due ? ` (${data.due} por salir)` : ''}
            </button>
          </div>
        </div>
      )}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <DataTable<Message>
        title="Mensajes"
        primaryActionLabel="Nuevo mensaje"
        onPrimaryAction={data?.can.create ? () => { setEditing(null); setComposing(true); setNotice(''); } : undefined}
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        filterConfigs={filterConfigs}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((current) => ({ ...current, [id]: value }))}
        dateFilters={[{ id: 'created_at', label: 'Fecha', value: (m) => m.created_at }]}
        sortOptions={[
          { id: 'created_at', label: 'Más recientes', type: 'date', value: (m) => m.created_at },
          { id: 'scheduled_at', label: 'Fecha programada', type: 'date', value: (m) => m.scheduled_at },
          { id: 'customer', label: 'Cliente', value: (m) => m.customer?.name ?? m.to_name ?? '' },
        ]}
        columns={columns}
        data={rows}
        getItemKey={(m) => m.id}
        entityLabel={rows.length === 1 ? 'mensaje' : 'mensajes'}
        metricCards={metricCards}
      />

      {data && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay mensajes. Aparecen aquí cuando alguien agenda una cita, se cobra una venta o escribes uno con &quot;Nuevo mensaje&quot;.</p>
      )}

      <MessageDetail message={detail} can={data?.can ?? null} onClose={() => setDetail(null)} onChanged={changed} onEdit={(m) => { setDetail(null); setEditing(m); setComposing(true); }} />

      <ComposePanel
        isOpen={composing}
        onClose={() => { setComposing(false); setEditing(null); }}
        editing={editing}
        emailState={email?.state}
        onSaved={(m, text) => { setNotice(text); load(); }}
      />
    </div>
  );
}
