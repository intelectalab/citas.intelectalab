'use client';

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { Check, Copy, Gift, LifeBuoy, Loader2, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
import { AttachmentPicker } from './TicketDetail';
import { STATUS_TONE, dateTime, type Option, type Ticket } from '../support';

interface Payload {
  ready: boolean;
  message?: string;
  tickets: Ticket[];
  topics: Option[];
  hours?: number;
  urgent_hours?: number;
  access_hours?: number;
}

interface Referral {
  ready: boolean;
  enabled: boolean;
  code?: string | null;
  reward?: string;
  rows?: { id: string; name: string; status: string; status_label: string; note: string | null; created_at: string }[];
}

const TABS = [
  { id: 'open', label: 'En curso' },
  { id: 'resolved', label: 'Resueltos' },
  { id: 'all', label: 'Todos' },
];

const EMPTY = { subject: '', topic: 'other', priority: 'normal', body: '', allow_access: false };

function ReferralCard() {
  const [data, setData] = useState<Referral | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    api.get<Referral>('/support/referral').then((res) => setData(res.data)).catch(() => setData(null));
  }, []);

  if (!data?.enabled || !data.code) return null;

  const link = typeof window === 'undefined' ? '' : `${window.location.origin}/register?ref=${data.code}`;
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(link);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setCopied(false);
    }
  };

  return (
    <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 space-y-3" data-referral-card>
      <h2 className="flex items-center gap-2 text-sm font-black text-brand-ink"><Gift size={16} className="text-brand-accent" /> Recomienda y gana</h2>
      <p className="text-sm text-slate-600">Comparte tu enlace con otro negocio. Cuando se registre con él y pague su primer periodo, recibes {data.reward}. El premio se da una sola vez por cada empresa que recomiendes.</p>
      <div className="flex flex-col sm:flex-row gap-2">
        <code className="flex-1 min-w-0 truncate text-sm bg-slate-50 border border-slate-200 rounded-xl px-3.5 py-2.5 text-slate-700" data-referral-link>{link}</code>
        <button type="button" onClick={copy} className="inline-flex items-center justify-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-brand-ink border border-slate-200 rounded-xl hover:bg-slate-50 cursor-pointer">
          {copied ? <Check size={15} /> : <Copy size={15} />} {copied ? 'Copiado' : 'Copiar enlace'}
        </button>
      </div>
      {(data.rows ?? []).length > 0 && (
        <ul className="divide-y divide-slate-100 text-sm">
          {(data.rows ?? []).map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
              <span className="font-semibold text-slate-800">{r.name}</span>
              <span className="text-xs text-slate-500">{r.note ?? r.status_label}</span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export default function SupportPage() {
  const { user } = useAuth();
  const [data, setData] = useState<Payload | null>(null);
  const [tab, setTab] = useState('open');
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get<Payload>('/support/tickets').then((res) => { setData(res.data); setError(''); }).catch((err) => setError(apiError(err, 'No se pudieron cargar tus pedidos de ayuda.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const tickets = useMemo(() => data?.tickets ?? [], [data]);
  const counts = { open: tickets.filter((t) => t.status !== 'resolved').length, resolved: tickets.filter((t) => t.status === 'resolved').length, all: tickets.length };
  const term = search.trim().toLowerCase();
  const shown = tickets
    .filter((t) => (tab === 'all' ? true : tab === 'resolved' ? t.status === 'resolved' : t.status !== 'resolved'))
    .filter((t) => !term || `${t.code} ${t.subject} ${t.topic_label}`.toLowerCase().includes(term));

  const submit = async () => {
    try {
      setBusy(true);
      setFormError('');
      const body = new FormData();
      body.append('subject', form.subject);
      body.append('topic', form.topic);
      body.append('priority', form.priority);
      body.append('body', form.body);
      body.append('allow_access', form.allow_access ? '1' : '0');
      if (file) body.append('attachment', file);
      const res = await api.post('/support/tickets', body);
      setNotice(res.data.message);
      setOpen(false);
      setForm(EMPTY);
      setFile(null);
      load();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo enviar el pedido.'));
    } finally {
      setBusy(false);
    }
  };

  const columns: Column<Ticket>[] = [
    { header: 'Pedido', cell: (t) => <Link href={`/support/${t.id}`} data-ticket-link={t.code} className="block"><span className="block font-bold text-brand-accent hover:text-brand-ink">{t.code}</span><span className="block text-sm text-slate-700">{t.subject}</span></Link> },
    { header: 'Tema', cell: (t) => t.topic_label },
    { header: 'Abierto por', cell: (t) => t.created_by ?? '—' },
    { header: 'Última novedad', cell: (t) => dateTime(t.last_message_at) },
    { header: 'Estado', cell: (t) => <Badge tone={STATUS_TONE[t.status]} bold>{t.status_label}</Badge> },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-support-page>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><LifeBuoy className="w-6 h-6 text-brand-accent" /> Soporte</h1>
        <p className="text-sm text-slate-500 font-medium">
          Escríbenos si algo no funciona o tienes una duda. {data?.hours ? `Respondemos en ${data.hours} horas o menos (si es urgente, en ${data.urgent_hours}).` : ''} Te avisamos en la campana y por correo.
        </p>
      </div>

      {user?.is_tenant_admin && <ReferralCard />}

      <SuccessAlert message={notice} />
      <ErrorAlert message={error || (data && !data.ready ? data.message ?? '' : '')} />

      <DataTable<Ticket>
        title="Mis pedidos de ayuda"
        primaryActionLabel={data?.ready ? 'Nuevo pedido' : undefined}
        onPrimaryAction={data?.ready ? () => { setOpen(true); setFormError(''); } : undefined}
        tabs={TABS.map((t) => ({ ...t, count: counts[t.id as keyof typeof counts] }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(t) => t.id}
        entityLabel={shown.length === 1 ? 'pedido' : 'pedidos'}
        sortOptions={[
          { id: 'last', label: 'Última novedad', type: 'date', value: (t) => t.last_message_at },
          { id: 'created', label: 'Fecha de apertura', type: 'date', value: (t) => t.created_at },
        ]}
      />

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title="Nuevo pedido de ayuda" subtitle="Cuéntanos qué pasa" icon={<LifeBuoy size={18} />}>
        <div className="space-y-4" data-support-form>
          <div>
            <label className={labelClass} htmlFor="support-subject">Asunto</label>
            <input id="support-subject" maxLength={150} value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} className={inputClass} placeholder="Ej. No puedo cobrar con Yape" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Tema</label>
              <Select ariaLabel="Tema" clearable={false} value={form.topic} onChange={(e) => setForm({ ...form, topic: String(e.target.value) })} options={data?.topics ?? []} />
            </div>
            <div>
              <label className={labelClass}>Prioridad</label>
              <Select ariaLabel="Prioridad" clearable={false} value={form.priority} onChange={(e) => setForm({ ...form, priority: String(e.target.value) })} options={[{ value: 'normal', label: 'Normal' }, { value: 'urgent', label: 'Urgente: no puedo trabajar' }]} />
            </div>
          </div>
          <div>
            <label className={labelClass} htmlFor="support-body">Detalle</label>
            <textarea id="support-body" rows={6} maxLength={5000} value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} className={inputClass} placeholder="Qué intentabas hacer, qué pasó y desde cuándo." />
          </div>
          <div>
            <p className={labelClass}>Captura (opcional)</p>
            <AttachmentPicker file={file} onChange={setFile} />
          </div>
          <div className="flex items-start justify-between gap-3 rounded-2xl border border-slate-200 p-4">
            <div>
              <p className="text-sm font-bold text-brand-ink">Permitir que soporte entre a mi cuenta</p>
              <p className="text-xs text-slate-500">Para revisar el problema desde tu panel durante {data?.access_hours ?? 72} horas. Lo que haga queda en tu Auditoría y verás un aviso mientras esté dentro.</p>
            </div>
            <Switch label="Permitir que soporte entre a mi cuenta" checked={form.allow_access} onChange={(value) => setForm({ ...form, allow_access: value })} />
          </div>
          <ErrorAlert message={formError} />
          <button type="button" data-support-send disabled={busy || form.subject.trim().length < 4 || form.body.trim().length < 10} onClick={submit} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy ? <Loader2 size={16} className="animate-spin" /> : <Send size={16} />} Enviar pedido
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
