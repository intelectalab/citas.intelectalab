'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Filter, Hourglass, Loader2, MessageCircle, Phone, TicketPercent, UserPlus } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import SectionTitle from '@/app/components/common/SectionTitle';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column, type ExportColumn, type MetricCardItem } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { STAGE_TONE, when, type Lead } from './leads';

interface Option {
  value: string;
  label: string;
}

interface Group {
  key: string;
  label: string;
  leads: number;
  trials: number;
  paid: number;
  trial_rate: number | null;
  paid_rate: number | null;
}

interface Funnel {
  ready: boolean;
  message?: string;
  from: string;
  to: string;
  steps: { key: string; label: string; value: number; rate: number | null }[];
  lost: number;
  by_source: Group[];
  by_business_type: Group[];
  visits_by_source: { source: string; visits: number }[];
  visits_by_page: { page: string; visits: number }[];
}

interface Extras {
  days: number;
  expiring: { tenant_id: string; name: string; plan: string; ends_on: string; days_left: number; owner: string | null; email: string | null; phone: string | null }[];
  chatbot: number;
  coupon: number;
}

const waLink = (phone: string) => {
  const digits = phone.replace(/\D/g, '');
  return `https://wa.me/${digits.length === 9 ? `51${digits}` : digits}`;
};

const PAGE_LABELS: Record<string, string> = { home: 'Portada', pricing: 'Precios', business: 'Rubros', blog: 'Blog', post: 'Artículos', legal: 'Legales', signup: 'Registro' };
const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs';
const pct = (value: number | null) => (value === null ? '—' : `${value.toLocaleString('es-PE')} %`);

const isoDay = (date: Date) => date.toLocaleDateString('en-CA', { timeZone: 'America/Lima' });

function GroupTable({ title, rows }: { title: string; rows: Group[] }) {
  return (
    <div className={`${card} p-5 space-y-3 min-w-0`}>
      <SectionTitle>{title}</SectionTitle>
      {rows.length === 0 ? <p className="text-sm text-slate-500">Aún no hay interesados en estas fechas.</p> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm min-w-[420px]">
            <thead>
              <tr className="text-left text-xs text-slate-500">
                <th className="py-2 font-semibold">De dónde</th>
                <th className="py-2 font-semibold text-right">Interesados</th>
                <th className="py-2 font-semibold text-right">Pruebas</th>
                <th className="py-2 font-semibold text-right">Pagaron</th>
                <th className="py-2 font-semibold text-right">Conversión</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.key || 'none'} className="border-t border-slate-100">
                  <td className="py-2 font-semibold text-slate-700">{row.label}</td>
                  <td className="py-2 text-right">{row.leads}</td>
                  <td className="py-2 text-right">{row.trials}</td>
                  <td className="py-2 text-right">{row.paid}</td>
                  <td className="py-2 text-right font-bold text-brand-ink">{pct(row.paid_rate)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

export default function FunnelPage() {
  const today = new Date();
  const [range, setRange] = useState({ from: isoDay(new Date(today.getTime() - 29 * 86400000)), to: isoDay(today) });
  const [funnel, setFunnel] = useState<Funnel | null>(null);
  const [extras, setExtras] = useState<Extras | null>(null);
  const [only, setOnly] = useState<'' | 'chatbot' | 'coupon'>('');
  const [changing, setChanging] = useState('');
  const [leads, setLeads] = useState<Lead[]>([]);
  const [options, setOptions] = useState<{ stages: Option[]; sources: Option[]; business_types: Option[] }>({ stages: [], sources: [], business_types: [] });
  const [tab, setTab] = useState('open');
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<Record<string, string>>({ source: '', business_type: '' });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');
  const [form, setForm] = useState({ name: '', phone: '', email: '', business: '', business_type: '', note: '' });

  const loadFunnel = useCallback(() => {
    api.get('/admin/web/funnel', { params: range }).then((res) => setFunnel(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar el embudo.')));
    api.get<Extras>('/admin/funnel/extras', { params: range }).then((res) => setExtras(res.data)).catch(() => setExtras(null));
  }, [range]);

  const loadLeads = useCallback(() => {
    api.get('/admin/web/leads').then((res) => {
      setLeads(res.data.items);
      setOptions({ stages: res.data.stages, sources: res.data.sources, business_types: res.data.business_types });
    }).catch((err) => setError(apiError(err, 'No se pudieron cargar los interesados.')));
  }, []);

  useEffect(() => { loadFunnel(); }, [loadFunnel]);
  useEffect(() => { loadLeads(); }, [loadLeads]);

  const changeStage = async (lead: Lead, stage: string) => {
    if (!stage || stage === lead.stage) return;
    try {
      setChanging(lead.id);
      setError('');
      await api.put(`/admin/web/leads/${lead.id}/stage`, { stage });
      setNotice(`${lead.name}: etapa cambiada.`);
      loadLeads();
      loadFunnel();
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar la etapa.'));
    } finally {
      setChanging('');
    }
  };

  const create = async () => {
    try {
      setBusy(true);
      setFormError('');
      await api.post('/admin/web/leads', { ...form, business_type: form.business_type || null });
      setOpen(false);
      setNotice('Interesado agregado.');
      setForm({ name: '', phone: '', email: '', business: '', business_type: '', note: '' });
      loadLeads();
      loadFunnel();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo agregar al interesado.'));
    } finally {
      setBusy(false);
    }
  };

  const tabs = [
    { id: 'open', label: 'Por atender', test: (l: Lead) => l.stage === 'new' || l.stage === 'contacted' },
    { id: 'trial', label: 'En prueba', test: (l: Lead) => l.stage === 'trial' },
    { id: 'paid', label: 'Pagaron', test: (l: Lead) => l.stage === 'paid' },
    { id: 'lost', label: 'Perdidos', test: (l: Lead) => l.stage === 'lost' },
    { id: 'all', label: 'Todos', test: () => true },
  ];
  const term = search.trim().toLowerCase();
  const shown = leads
    .filter(tabs.find((t) => t.id === tab)?.test ?? (() => true))
    .filter((l) => !filters.source || l.source === filters.source)
    .filter((l) => !filters.business_type || l.business_type === filters.business_type)
    .filter((l) => only !== 'chatbot' || l.source === 'chatbot')
    .filter((l) => only !== 'coupon' || Boolean(l.coupon))
    .filter((l) => !term || [l.name, l.business, l.email, l.phone].some((v) => (v ?? '').toLowerCase().includes(term)));

  const columns: Column<Lead>[] = [
    { header: 'Interesado', cell: (l) => <Link href={`/admin/funnel/${l.id}`} data-lead-link={l.name} className="block"><span className="block font-bold text-brand-accent hover:text-brand-ink">{l.name}</span><span className="block text-xs text-slate-500">{[l.business, l.phone, l.email].filter(Boolean).join(' · ')}</span></Link> },
    { header: 'Rubro', cell: (l) => l.business_type_name ?? '—' },
    { header: 'Fuente', cell: (l) => <span className="text-sm">{l.source_label}{l.utm.source && <span className="block text-xs text-slate-500">{l.utm.source}{l.utm.campaign ? ` · ${l.utm.campaign}` : ''}</span>}</span> },
    {
      header: 'Etapa',
      cell: (l) => (l.stage === 'new' || l.stage === 'contacted') ? (
        <div className="min-w-[9rem]" data-lead-stage={l.id}>
          <Select ariaLabel={`Etapa de ${l.name}`} clearable={false} disabled={changing === l.id} value={l.stage} onChange={(e) => changeStage(l, String(e.target.value))} options={options.stages.filter((s) => s.value === 'new' || s.value === 'contacted')} />
        </div>
      ) : <Badge tone={STAGE_TONE[l.stage]} bold>{l.stage_label}</Badge>,
    },
    { header: 'Empresa', cell: (l) => (l.tenant_id ? <Link href={`/admin/tenants/${l.tenant_id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{l.tenant_name}</Link> : '—') },
    { header: 'Llegó', cell: (l) => <span className="text-sm">{when(l.created_at)}{l.notes ? <span className="block text-xs text-slate-500">{l.notes} {l.notes === 1 ? 'nota' : 'notas'}</span> : null}</span> },
  ];

  const top = funnel?.steps[0]?.value || 0;

  const exportColumns: ExportColumn<Lead>[] = [
    { header: 'Nombre', value: (l) => l.name },
    { header: 'Negocio', value: (l) => l.business },
    { header: 'Celular', value: (l) => l.phone },
    { header: 'Correo', value: (l) => l.email },
    { header: 'Rubro', value: (l) => l.business_type_name },
    { header: 'Fuente', value: (l) => l.source_label },
    { header: 'Cupón', value: (l) => l.coupon },
    { header: 'Etapa', value: (l) => l.stage_label },
    { header: 'Empresa', value: (l) => l.tenant_name },
    { header: 'Llegó', type: 'date', value: (l) => l.created_at },
  ];

  const metricCards: MetricCardItem[] = extras ? [
    { title: `Pruebas que vencen en ${extras.days} días`, value: extras.expiring.length, icon: <Hourglass size={18} />, iconColorClass: 'text-amber-600', onClick: () => document.getElementById('funnel-call-today')?.scrollIntoView({ behavior: 'smooth', block: 'start' }) },
    { title: 'Llegaron por el chat', value: extras.chatbot, icon: <MessageCircle size={18} />, iconColorClass: 'text-sky-600', hint: 'En las fechas elegidas', onClick: () => { setOnly(only === 'chatbot' ? '' : 'chatbot'); setTab('all'); }, borderColorClass: only === 'chatbot' ? 'border-sky-500 ring-2 ring-sky-100 shadow-sm' : 'hover:border-sky-300' },
    { title: 'Llegaron con cupón', value: extras.coupon, icon: <TicketPercent size={18} />, iconColorClass: 'text-emerald-600', hint: 'En las fechas elegidas', onClick: () => { setOnly(only === 'coupon' ? '' : 'coupon'); setTab('all'); }, borderColorClass: only === 'coupon' ? 'border-emerald-500 ring-2 ring-emerald-100 shadow-sm' : 'hover:border-emerald-300' },
  ] : [];

  return (
    <div className="space-y-5 pb-10 font-sans" data-funnel-page>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Filter className="w-6 h-6 text-brand-accent" /> Embudo e interesados</h1>
          <p className="text-sm text-slate-500 font-medium">Del primer contacto al pago: visitas del sitio, interesados, pruebas gratis y empresas que pagaron.</p>
        </div>
        <div className="flex items-end gap-2">
          <div className="w-40"><label className={labelClass}>Desde</label><DateField ariaLabel="Desde" size="compact" value={range.from} max={range.to} onChange={(v) => v && setRange({ ...range, from: v })} /></div>
          <div className="w-40"><label className={labelClass}>Hasta</label><DateField ariaLabel="Hasta" size="compact" value={range.to} min={range.from} onChange={(v) => v && setRange({ ...range, to: v })} /></div>
        </div>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error || (funnel && !funnel.ready ? funnel.message ?? '' : '')} />

      {funnel?.ready && (
        <>
          <div className={`${card} p-5 space-y-3`} data-funnel-steps>
            {funnel.steps.map((step, index) => (
              <div key={step.key} className="grid grid-cols-[6.5rem_1fr] sm:grid-cols-[8rem_1fr_9rem] items-center gap-3" data-funnel-step={step.key}>
                <span className="text-sm font-semibold text-slate-600">{step.label}</span>
                <div className="h-9 rounded-xl bg-slate-100 overflow-hidden">
                  <div className="h-full rounded-xl bg-brand flex items-center px-3 text-white text-sm font-black" style={{ width: `${Math.max(8, top ? (step.value / top) * 100 : 0)}%` }}>{step.value.toLocaleString('es-PE')}</div>
                </div>
                <span className="col-start-2 sm:col-start-auto text-xs text-slate-500">{index === 0 ? 'Conteo anónimo, sin datos personales' : `${pct(step.rate)} del paso anterior`}</span>
              </div>
            ))}
            <p className="text-xs text-slate-500">Se cuentan los interesados que llegaron en estas fechas y, de ellos, cuántos empezaron la prueba y cuántos pagaron. {funnel.lost > 0 && `${funnel.lost} se marcaron como perdidos.`}</p>
          </div>

          <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
            <GroupTable title="Por fuente" rows={funnel.by_source} />
            <GroupTable title="Por rubro" rows={funnel.by_business_type} />
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div className={`${card} p-5 space-y-2`}>
              <SectionTitle>Visitas por origen</SectionTitle>
              {funnel.visits_by_source.length === 0 ? <p className="text-sm text-slate-500">Aún no hay visitas en estas fechas.</p> : funnel.visits_by_source.map((row) => (
                <div key={row.source} className="flex justify-between text-sm border-t border-slate-100 pt-2 first:border-0 first:pt-0"><span className="text-slate-600">{row.source === 'directo' ? 'Directo o sin origen' : row.source}</span><span className="font-bold text-brand-ink">{row.visits}</span></div>
              ))}
            </div>
            <div className={`${card} p-5 space-y-2`}>
              <SectionTitle>Visitas por página</SectionTitle>
              {funnel.visits_by_page.length === 0 ? <p className="text-sm text-slate-500">Aún no hay visitas en estas fechas.</p> : funnel.visits_by_page.map((row) => (
                <div key={row.page} className="flex justify-between text-sm border-t border-slate-100 pt-2 first:border-0 first:pt-0"><span className="text-slate-600">{PAGE_LABELS[row.page] ?? row.page}</span><span className="font-bold text-brand-ink">{row.visits}</span></div>
              ))}
            </div>
          </div>
        </>
      )}

      {extras && (
        <div id="funnel-call-today" className={`${card} p-5 space-y-3`} data-funnel-expiring>
          <SectionTitle><Phone size={16} className="text-brand-accent" /> Llamar hoy: pruebas por vencer</SectionTitle>
          <p className="text-xs text-slate-500">Empresas en prueba gratis que terminan en los próximos {extras.days} días y aún no guardaron tarjeta. Una llamada a tiempo ayuda a que se queden.</p>
          {extras.expiring.length === 0 ? <p className="text-sm text-slate-500">Ninguna prueba vence en estos días.</p> : (
            <ul className="divide-y divide-slate-100">
              {extras.expiring.map((row) => (
                <li key={row.tenant_id} className="flex flex-wrap items-center gap-3 py-2.5" data-expiring={row.tenant_id}>
                  <span className="flex-1 min-w-[12rem]">
                    <Link href={`/admin/tenants/${row.tenant_id}`} className="block font-bold text-brand-ink hover:text-brand-accent">{row.name}</Link>
                    <span className="block text-xs text-slate-500">{[row.owner, row.plan, row.days_left <= 0 ? 'vence hoy' : row.days_left === 1 ? 'vence mañana' : `vence en ${row.days_left} días`].filter(Boolean).join(' · ')}</span>
                  </span>
                  {row.phone ? (
                    <span className="flex gap-2">
                      <a href={`tel:${row.phone}`} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-white bg-brand rounded-lg hover:bg-brand-hover"><Phone size={13} /> Llamar</a>
                      <a href={waLink(row.phone)} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-emerald-700 border border-emerald-200 rounded-lg hover:bg-emerald-50"><MessageCircle size={13} /> WhatsApp</a>
                    </span>
                  ) : row.email ? <a href={`mailto:${row.email}`} className="text-xs font-semibold text-brand-accent hover:text-brand-ink">{row.email}</a> : <span className="text-xs text-slate-400">Sin celular</span>}
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      <DataTable<Lead>
        title="Interesados"
        metricCards={metricCards}
        exportColumns={exportColumns}
        primaryActionLabel="Nuevo interesado"
        onPrimaryAction={() => { setFormError(''); setOpen(true); }}
        tabs={tabs.map((t) => ({ id: t.id, label: t.label, count: leads.filter(t.test).length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={[
          { id: 'source', label: 'Fuente', type: 'select', options: options.sources },
          { id: 'business_type', label: 'Rubro', type: 'select', options: options.business_types },
        ]}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters({ ...filters, [id]: value ?? '' })}
        onClearFilters={() => { setFilters({ source: '', business_type: '' }); setOnly(''); }}
        sortOptions={[{ id: 'created', label: 'Fecha de llegada', type: 'date', value: (l) => l.created_at }, { id: 'name', label: 'Nombre', value: (l) => l.name }]}
        columns={columns}
        data={shown}
        getItemKey={(l) => l.id}
        entityLabel={shown.length === 1 ? 'interesado' : 'interesados'}
      />

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title="Nuevo interesado" subtitle="Alguien que te escribió o llamó" icon={<UserPlus size={18} />}>
        <div className="space-y-4" data-lead-form>
          <div><label className={labelClass} htmlFor="lead-name">Nombre</label><input id="lead-name" maxLength={150} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} /></div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label className={labelClass} htmlFor="lead-phone">Celular</label><input id="lead-phone" maxLength={30} inputMode="tel" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className={inputClass} /></div>
            <div><label className={labelClass} htmlFor="lead-email">Correo</label><input id="lead-email" maxLength={190} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className={inputClass} /></div>
          </div>
          <div><label className={labelClass} htmlFor="lead-business">Negocio</label><input id="lead-business" maxLength={200} value={form.business} onChange={(e) => setForm({ ...form, business: e.target.value })} className={inputClass} /></div>
          <div><label className={labelClass}>Rubro</label><Select ariaLabel="Rubro" value={form.business_type} onChange={(e) => setForm({ ...form, business_type: String(e.target.value) })} options={options.business_types} placeholder="Elige el rubro" /></div>
          <div><label className={labelClass} htmlFor="lead-note">Nota (opcional)</label><textarea id="lead-note" rows={3} maxLength={2000} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} className={inputClass} /></div>
          <ErrorAlert message={formError} />
          <button type="button" data-lead-save disabled={busy || form.name.trim().length < 2 || (!form.phone.trim() && !form.email.trim())} onClick={create} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={16} className="animate-spin" />} Agregar interesado
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
