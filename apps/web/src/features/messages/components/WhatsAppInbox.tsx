'use client';

import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert } from '@/app/components/common/Alert';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Bot, Eye, Hand, MessageCircle, MessagesSquare } from 'lucide-react';
import { DataTable, type Column, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { WHATSAPP_BADGES, type WhatsAppConversation, type WhatsAppStatus } from '../messages';

type View = 'all' | 'needs_human' | 'unread' | 'open';

export const nameOf = (c: WhatsAppConversation) => c.customer?.name ?? c.contact_name ?? `+${c.phone}`;

export default function WhatsAppInbox() {
  const [rows, setRows] = useState<WhatsAppConversation[] | null>(null);
  const [status, setStatus] = useState<WhatsAppStatus | null>(null);
  const [canSettings, setCanSettings] = useState(false);
  const [view, setView] = useState<View>('all');
  const [searchTerm, setSearchTerm] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get('/whatsapp/conversations')
      .then((res) => { setRows(res.data.conversations); setStatus(res.data.status); setCanSettings(Boolean(res.data.can?.settings)); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las conversaciones.')));
  }, []);

  useEffect(() => {
    load();
    const timer = setInterval(load, 30000);
    return () => clearInterval(timer);
  }, [load]);

  const all = rows ?? [];
  const term = searchTerm.trim().toLowerCase();
  const data = all.filter((c) => (view === 'all'
    || (view === 'needs_human' && c.status === 'needs_human')
    || (view === 'unread' && c.unread > 0)
    || (view === 'open' && c.window_open))
    && (!term || `${nameOf(c)} ${c.phone} ${c.last?.body ?? ''}`.toLowerCase().includes(term)));

  const card = (title: string, value: number, icon: React.ReactNode, id: View, color: string, ring: string): MetricCardItem => ({
    title, value, icon, iconColorClass: color,
    borderColorClass: view === id ? ring : 'hover:border-slate-300',
    onClick: () => setView(view === id ? 'all' : id),
  });

  const metricCards: MetricCardItem[] = [
    card('Necesitan una persona', all.filter((c) => c.status === 'needs_human').length, <Hand size={18} />, 'needs_human', 'text-amber-600', 'border-amber-500 ring-2 ring-amber-500/20'),
    card('Sin leer', all.filter((c) => c.unread > 0).length, <MessageCircle size={18} />, 'unread', 'text-sky-600', 'border-sky-500 ring-2 ring-sky-500/20'),
    card('Se puede responder', all.filter((c) => c.window_open).length, <MessagesSquare size={18} />, 'open', 'text-emerald-600', 'border-emerald-500 ring-2 ring-emerald-500/20'),
  ];

  const columns: Column<WhatsAppConversation>[] = [
    {
      header: 'Cliente',
      cell: (c) => (
        <div key={`who-${c.id}`} className="text-sm">
          <span className="block font-semibold text-slate-700">{c.customer ? <CustomerLink id={c.customer.id}>{c.customer.name}</CustomerLink> : <Link href={`/messages/whatsapp/${c.id}`} className={styles.customerName}>{nameOf(c)}</Link>}</span>
          <span className="block text-xs text-slate-400">+{c.phone}{!c.customer ? ' · sin cliente unido' : ''}</span>
        </div>
      ),
    },
    {
      header: 'Último mensaje',
      cell: (c) => (
        <div key={`last-${c.id}`} className="min-w-0 max-w-md">
          <Link href={`/messages/whatsapp/${c.id}`} data-open-conversation={c.id} className="block text-sm text-slate-700 truncate hover:text-brand-accent">{c.last ? `${c.last.direction === 'out' ? 'Tú: ' : ''}${c.last.body}` : '—'}</Link>
          <span className="block text-xs text-slate-400">{c.last_message_at ? momentOf(c.last_message_at) : ''}</span>
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (c) => (
        <div key={`status-${c.id}`} className="flex flex-wrap items-center gap-1.5">
          <Badge data-conversation-status={c.status} tone={c.status === 'needs_human' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'} nowrap>{c.status_label}</Badge>
          {c.unread > 0 && <Badge tone="bg-sky-50 text-sky-700" bold nowrap>{c.unread} sin leer</Badge>}
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (c) => (
        <Link key={`actions-${c.id}`} href={`/messages/whatsapp/${c.id}`} title="Ver la conversación" className="inline-flex p-1.5 text-slate-400 rounded-lg hover:text-brand-ink hover:bg-slate-100 transition-colors">
          <Eye size={16} />
        </Link>
      ),
    },
  ];

  return (
    <div className="space-y-6 mb-8" data-whatsapp-inbox>
      <BackLink href="/messages">Mensajes</BackLink>
      {status && (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 ${WHATSAPP_BADGES[status.state]}`} data-whatsapp-state={status.state}>
          <span className="flex items-center gap-2 text-sm font-semibold"><MessageCircle size={16} /> WhatsApp de tu empresa: {status.label}</span>
          <span className="flex flex-wrap items-center gap-3">
            <Link href="/messages/assistant" data-open-assistant className="flex items-center gap-1 text-xs font-bold underline underline-offset-2"><Bot size={13} /> Recepcionista con IA</Link>
            {canSettings && <Link href="/settings#whatsapp" className="text-xs font-bold underline underline-offset-2">Configurar WhatsApp</Link>}
          </span>
        </div>
      )}
      <ErrorAlert message={error} />
      <DataTable<WhatsAppConversation>
        title="Conversaciones de WhatsApp"
        searchTerm={searchTerm}
        onSearchChange={setSearchTerm}
        sortOptions={[
          { id: 'last', label: 'Más recientes', type: 'date', value: (c) => c.last_message_at },
          { id: 'name', label: 'Cliente', value: (c) => nameOf(c) },
        ]}
        columns={columns}
        data={data}
        getItemKey={(c) => c.id}
        entityLabel={data.length === 1 ? 'conversación' : 'conversaciones'}
        metricCards={metricCards}
      />
      {rows && all.length === 0 && (
        <p className="text-sm text-slate-500 px-1">Aún no hay conversaciones. Aparecen aquí cuando un cliente le escribe al WhatsApp de tu empresa.</p>
      )}
    </div>
  );
}
