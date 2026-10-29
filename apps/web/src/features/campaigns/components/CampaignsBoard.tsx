'use client';

import Badge from '@/app/components/common/Badge';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { CalendarCheck, CalendarClock, Copy, Eye, Mail, MailCheck, Pencil, XCircle } from 'lucide-react';
import { DataTable, type Column, type ExportColumn, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import styles from '@/app/components/ui/table/DataTable.module.css';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import CampaignForm from './CampaignForm';
import { CAMPAIGN_BADGES, EMAIL_NOTICE, type Campaign, type CampaignOptions, type CampaignStatus } from '../campaigns';

export default function CampaignsBoard() {
  const { can } = useAuth();
  const [campaigns, setCampaigns] = useState<Campaign[]>([]);
  const [email, setEmail] = useState<CampaignOptions['email'] | null>(null);
  const [options, setOptions] = useState<CampaignOptions | null>(null);
  const [status, setStatus] = useState<CampaignStatus | ''>('');
  const [searchTerm, setSearchTerm] = useState('');
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Campaign | null>(null);
  const [duplicating, setDuplicating] = useState<Campaign | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get('/campaigns')
      .then((res) => { setCampaigns(res.data.campaigns); setEmail(res.data.email); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las campañas.')));
  }, []);

  const loadOptions = useCallback(() => {
    api.get('/campaigns/options').then((res) => setOptions(res.data)).catch(() => setOptions(null));
  }, []);

  useEffect(() => { load(); }, [load]);

  const openCampaign = (campaign: Campaign | null, duplicate: Campaign | null = null) => {
    setNotice('');
    setEditing(campaign);
    setDuplicating(duplicate);
    loadOptions();
    setCreating(true);
  };

  const term = searchTerm.trim().toLowerCase();
  const rows = campaigns.filter((c) => (!status || c.status === status || (status === 'sent' && c.status === 'sending')) && (!term || `${c.name} ${c.subject} ${c.audience}`.toLowerCase().includes(term)));

  const card = (title: string, value: number, icon: React.ReactNode, id: CampaignStatus | null, color: string, ring: string): MetricCardItem => ({
    title, value, icon, iconColorClass: color,
    borderColorClass: id && status === id ? ring : 'hover:border-slate-300',
    onClick: id ? () => setStatus(status === id ? '' : id) : undefined,
  });

  const bookedTotal = campaigns.reduce((sum, c) => sum + (c.results?.booked.customers ?? 0), 0);
  const metricCards: MetricCardItem[] = [
    card('Enviadas', campaigns.filter((c) => c.status === 'sent' || c.status === 'sending').length, <MailCheck size={18} />, 'sent', 'text-emerald-600', 'border-emerald-500 ring-2 ring-emerald-500/20'),
    card('Programadas', campaigns.filter((c) => c.status === 'scheduled').length, <CalendarClock size={18} />, 'scheduled', 'text-violet-600', 'border-violet-500 ring-2 ring-violet-500/20'),
    card('Agendaron después', bookedTotal, <CalendarCheck size={18} />, null, 'text-brand-accent', ''),
    card('Canceladas', campaigns.filter((c) => c.status === 'cancelled').length, <XCircle size={18} />, 'cancelled', 'text-slate-500', 'border-slate-400 ring-2 ring-slate-400/20'),
  ];

  const columns: Column<Campaign>[] = [
    {
      header: 'Campaña',
      cell: (c) => (
        <div key={`name-${c.id}`} className="min-w-0 max-w-sm">
          <Link href={`/campaigns/${c.id}`} data-open-campaign={c.name} className={`${styles.customerName} hover:text-brand-accent transition-colors truncate block`}>{c.name}</Link>
          <span className="block text-xs text-slate-400 truncate">{c.audience}</span>
        </div>
      ),
    },
    {
      header: 'Fecha',
      cell: (c) => (
        <div key={`at-${c.id}`} className="text-sm">
          <span className="block text-slate-700">{momentOf(c.sent_at ?? c.scheduled_at ?? c.created_at)}</span>
          <span className="block text-xs text-slate-400">{c.status === 'scheduled' ? 'Sale ese día' : c.by ? `Por ${c.by}` : ''}</span>
        </div>
      ),
    },
    {
      header: 'Enviados',
      cell: (c) => (
        <span key={`sent-${c.id}`} className="text-sm text-slate-700">
          {c.results ? <>{c.results.sent}{c.results.pending ? <span className="text-xs text-sky-600"> · {c.results.pending} por salir</span> : null}{c.results.failed ? <span className="text-xs text-rose-600"> · {c.results.failed} con error</span> : null}</> : '—'}
        </span>
      ),
    },
    {
      header: 'Agendaron después',
      cell: (c) => <span key={`booked-${c.id}`} className="text-sm font-semibold text-brand-ink">{c.results ? c.results.booked.customers : '—'}</span>,
    },
    {
      header: 'Estado',
      cell: (c) => <Badge key={`status-${c.id}`} data-campaign-status={c.status} tone={CAMPAIGN_BADGES[c.status]} nowrap>{c.status_label}</Badge>,
    },
    {
      header: 'Acciones',
      cell: (c) => (
        <div key={`actions-${c.id}`} className="flex items-center gap-1">
          <Link href={`/campaigns/${c.id}`} title="Ver resultados" className="p-1.5 text-slate-400 rounded-lg hover:text-brand-ink hover:bg-slate-100 transition-colors"><Eye size={16} /></Link>
          {c.editable && can('campaigns.edit') && (
            <button type="button" title="Cambiar campaña" onClick={() => openCampaign(c)} className="p-1.5 text-slate-400 rounded-lg hover:text-brand-ink hover:bg-slate-100 transition-colors"><Pencil size={16} /></button>
          )}
          {can('campaigns.create') && (
            <button type="button" title="Duplicar: crear una campaña nueva con lo mismo" data-action="duplicate" data-duplicate={c.name} onClick={() => openCampaign(null, c)} className="p-1.5 text-slate-400 rounded-lg hover:text-brand-ink hover:bg-slate-100 transition-colors"><Copy size={16} /></button>
          )}
        </div>
      ),
    },
  ];

  const exportColumns: ExportColumn<Campaign>[] = [
    { header: 'Campaña', value: (c) => c.name },
    { header: 'A quiénes', value: (c) => c.audience },
    { header: 'Asunto', value: (c) => c.subject },
    { header: 'Cupón', value: (c) => c.coupon?.code },
    { header: 'Estado', value: (c) => c.status_label },
    { header: 'Creada', type: 'datetime', value: (c) => c.created_at },
    { header: 'Programada para', type: 'datetime', value: (c) => c.scheduled_at },
    { header: 'Enviada', type: 'datetime', value: (c) => c.sent_at },
    { header: 'Creada por', value: (c) => c.by },
    { header: 'Enviados', type: 'number', value: (c) => c.results?.sent },
    { header: 'Por salir', type: 'number', value: (c) => c.results?.pending },
    { header: 'Con error', type: 'number', value: (c) => c.results?.failed },
    { header: 'Agendaron después', type: 'number', value: (c) => c.results?.booked.customers },
    { header: 'Citas agendadas', type: 'number', value: (c) => c.results?.booked.appointments },
  ];

  return (
    <div className="space-y-6 mb-8" data-campaigns>
      {email && email.state !== 'ok' && (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 ${EMAIL_NOTICE[email.state]}`} data-email-state={email.state}>
          <span className="flex items-center gap-2 text-sm font-semibold"><Mail size={16} /> {email.label}</span>
          {can('settings.view') && <Link href="/settings#correo" className="text-xs font-bold underline underline-offset-2">Configurar el correo</Link>}
        </div>
      )}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <DataTable<Campaign>
          title="Campañas"
          searchTerm={searchTerm}
          onSearchChange={setSearchTerm}
          primaryActionLabel="Nueva campaña"
          onPrimaryAction={can('campaigns.create') ? () => openCampaign(null) : undefined}
          dateFilters={[{ id: 'created_at', label: 'Fecha', value: (c) => c.sent_at ?? c.scheduled_at ?? c.created_at }]}
          sortOptions={[
            { id: 'created_at', label: 'Más recientes', type: 'date', value: (c) => c.created_at },
            { id: 'booked', label: 'Agendaron después', value: (c) => c.results?.booked.customers ?? 0 },
            { id: 'name', label: 'Nombre', value: (c) => c.name },
          ]}
          columns={columns}
          data={rows}
          exportTitle="Campañas"
          exportScreen="campaigns"
          exportColumns={exportColumns}
          getItemKey={(c) => c.id}
          entityLabel={rows.length === 1 ? 'campaña' : 'campañas'}
          metricCards={metricCards}
        />

      {campaigns.length === 0 && !error && (
        <p className="text-sm text-slate-500 px-1">Aún no hay campañas. Con &quot;Nueva campaña&quot; eliges un grupo de clientes, escribes el correo y lo envías ahora o lo programas. Para repetir una campaña, usa &quot;Duplicar&quot; junto a ella.</p>
      )}

      <CampaignForm
        isOpen={creating}
        onClose={() => { setCreating(false); setEditing(null); setDuplicating(null); }}
        options={options}
        editing={editing}
        duplicateFrom={duplicating}
        onSaved={(_, text) => { setNotice(text); load(); }}
      />
    </div>
  );
}
