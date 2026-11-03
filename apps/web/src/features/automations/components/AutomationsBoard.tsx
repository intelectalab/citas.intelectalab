'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { CalendarCheck, Mail, MailCheck, Pencil, Power } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type ExportColumn, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { EMAIL_NOTICE } from '@/features/campaigns/campaigns';
import AutomationForm from './AutomationForm';
import { repeatLabel, timingLabel, type Automation, type AutomationsPayload } from '../automations';

const iconButton = 'p-1.5 text-slate-400 rounded-lg transition-colors disabled:opacity-50';

export default function AutomationsBoard() {
  const { can } = useAuth();
  const [data, setData] = useState<AutomationsPayload | null>(null);
  const [editing, setEditing] = useState<Automation | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);
  const [onlyActive, setOnlyActive] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    api.get<AutomationsPayload>('/automations')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las automatizaciones.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const toggle = async (automation: Automation, active: boolean) => {
    try {
      setBusyId(automation.id);
      setError('');
      setNotice('');
      const res = await api.put<AutomationsPayload>(`/automations/${automation.id}`, { active });
      setData(res.data);
      setNotice(active ? `"${automation.label}" quedó encendida.` : `"${automation.label}" quedó apagada.`);
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar la automatización.'));
    } finally {
      setBusyId(null);
    }
  };

  const automations = data?.automations ?? [];
  const rows = onlyActive ? automations.filter((a) => a.active) : automations;
  const canEdit = can('automations.edit');
  const sentMonth = automations.reduce((sum, a) => sum + a.results.sent_month, 0);
  const bookedMonth = automations.reduce((sum, a) => sum + a.results.booked_month, 0);

  const metricCards: MetricCardItem[] = [
    {
      title: 'Encendidas',
      value: automations.filter((a) => a.active).length,
      icon: <Power size={18} />,
      iconColorClass: 'text-emerald-600',
      borderColorClass: onlyActive ? 'border-emerald-500 ring-2 ring-emerald-500/20' : 'hover:border-emerald-300',
      onClick: () => setOnlyActive((current) => !current),
    },
    { title: 'Correos este mes', value: sentMonth, icon: <MailCheck size={18} />, iconColorClass: 'text-sky-600', borderColorClass: '' },
    { title: 'Agendaron después', value: bookedMonth, icon: <CalendarCheck size={18} />, iconColorClass: 'text-brand-accent', borderColorClass: '' },
  ];

  const columns: Column<Automation>[] = [
    {
      header: 'Automatización',
      cell: (a) => (
        <div key={`name-${a.id}`} className="max-w-sm" data-automation={a.key}>
          <span className="block text-sm font-semibold text-slate-800">{a.label}</span>
          <span className="block text-xs text-slate-500">{a.when}</span>
        </div>
      ),
    },
    {
      header: 'Cuándo sale',
      cell: (a) => (
        <div key={`when-${a.id}`} className="text-sm">
          <span className="block text-slate-700">{timingLabel(a)}</span>
          <span className="block text-xs text-slate-500">{repeatLabel(a.repeat_days)}</span>
        </div>
      ),
    },
    {
      header: 'Asunto',
      cell: (a) => (
        <div key={`subject-${a.id}`} className="max-w-xs">
          <span className="block text-sm text-slate-700 truncate" title={a.subject}>{a.subject}</span>
          {a.coupon_code && <span className="block text-xs text-slate-500">Con el cupón {a.coupon_code}</span>}
        </div>
      ),
    },
    {
      header: 'Este mes',
      cell: (a) => (
        <div key={`results-${a.id}`} className="text-sm whitespace-nowrap">
          <span className="block text-slate-700">{a.results.sent_month} {a.results.sent_month === 1 ? 'correo' : 'correos'}</span>
          <span className="block text-xs text-slate-500">{a.results.booked_month} agendaron después</span>
          {a.results.last_sent_at && <span className="block text-xs text-slate-400">Último: {momentOf(a.results.last_sent_at)}</span>}
        </div>
      ),
    },
    {
      header: 'Estado',
      cell: (a) => (
        <div key={`state-${a.id}`} className="flex items-center gap-2">
          {canEdit ? (
            <Switch checked={a.active} disabled={busyId === a.id} onChange={(next) => toggle(a, next)} label={`${a.active ? 'Apagar' : 'Encender'} ${a.label}`} />
          ) : null}
          <Badge tone={a.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'} nowrap data-state={a.active ? 'on' : 'off'}>
            {a.active ? 'Encendida' : 'Apagada'}
          </Badge>
        </div>
      ),
    },
    {
      header: 'Acciones',
      cell: (a) => canEdit ? (
        <button key={`actions-${a.id}`} type="button" title="Editar" data-edit-automation={a.key} onClick={() => { setNotice(''); setEditing(a); }} className={`${iconButton} hover:text-brand-ink hover:bg-slate-100`}>
          <Pencil size={16} />
        </button>
      ) : <span key={`actions-${a.id}`} className="text-sm text-slate-300">—</span>,
    },
  ];

  const exportColumns: ExportColumn<Automation>[] = [
    { header: 'Automatización', value: (a) => a.label },
    { header: 'Cuándo', value: (a) => a.when },
    { header: 'Envío', value: (a) => timingLabel(a) },
    { header: 'Repetición', value: (a) => repeatLabel(a.repeat_days) },
    { header: 'Estado', value: (a) => (a.active ? 'Encendida' : 'Apagada') },
    { header: 'Asunto', value: (a) => a.subject },
    { header: 'Cupón', value: (a) => a.coupon_code ?? '' },
    { header: 'Correos este mes', type: 'number', value: (a) => a.results.sent_month },
    { header: 'Agendaron después', type: 'number', value: (a) => a.results.booked_month },
  ];

  return (
    <div className="space-y-6 mb-8" data-automations>
      {data && data.email.state !== 'ok' && (
        <div className={`flex flex-wrap items-center justify-between gap-3 rounded-2xl border px-4 py-3 ${EMAIL_NOTICE[data.email.state]}`} data-email-state={data.email.state}>
          <span className="flex items-center gap-2 text-sm font-semibold"><Mail size={16} /> {data.email.label}</span>
          {can('settings.view') && <Link href="/settings#correo" className="text-xs font-bold underline underline-offset-2">Configurar el correo</Link>}
        </div>
      )}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <DataTable<Automation>
        title="Automatizaciones"
        columns={columns}
        data={rows}
        getItemKey={(a) => a.id}
        entityLabel="automatizaciones"
        metricCards={metricCards}
        exportTitle="Automatizaciones"
        exportScreen="automations"
        exportColumns={exportColumns}
      />

      <p className="text-sm text-slate-500 px-1">
        Son correos que salen solos. Vienen apagadas con un texto de ejemplo: revísalo con el lápiz y enciéndelas. Les llegan solo a quienes aceptaron recibir promociones, y cada cliente recibe como máximo una por día. &quot;Agendaron después&quot; cuenta a quienes reservaron una cita en los {data?.booked_days ?? 7} días siguientes al correo.
      </p>

      <AutomationForm
        automation={editing}
        options={data}
        onClose={() => setEditing(null)}
        onSaved={(payload) => { setData(payload); setNotice(payload.message ?? 'Automatización guardada.'); }}
      />
    </div>
  );
}
