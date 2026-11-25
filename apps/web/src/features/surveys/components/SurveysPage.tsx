'use client';

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { MessageSquareHeart, Settings2, Star } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { apiError } from '@/features/appointments/appointments';
import SurveySettingsDrawer from './SurveySettingsDrawer';
import { FOLLOW_TONE, npsText, scoreTone, shortDate, type Breakdown, type Survey, type SurveyConfig, type SurveySummary } from '../surveys';

interface Payload {
  config: SurveyConfig;
  can_edit: boolean;
  summary: SurveySummary;
  by_professional: Breakdown[];
  by_service: Breakdown[];
  by_branch: Breakdown[];
  surveys: Survey[];
  periods: { value: string; label: string }[];
  options: { branches: { id: string; name: string }[]; professionals: { id: string; name: string }[]; services: { id: string; name: string }[] };
  branch_locked: boolean;
}

const TABS = [
  { id: 'answered', label: 'Respuestas' },
  { id: 'recover', label: 'Por recuperar' },
  { id: 'published', label: 'Reseñas publicadas' },
  { id: 'all', label: 'Todas las enviadas' },
];

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4';

function Kpi({ title, value, hint, alert = false }: { title: string; value: string; hint: string; alert?: boolean }) {
  return (
    <div className={`${card} ${alert ? 'border-rose-200 bg-rose-50/40' : ''}`}>
      <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</p>
      <p className="text-2xl font-black text-brand-ink mt-1">{value}</p>
      <p className="text-xs text-slate-500">{hint}</p>
    </div>
  );
}

function BreakdownCard({ title, rows }: { title: string; rows: Breakdown[] }) {
  return (
    <section className={card} data-survey-breakdown={title}>
      <h2 className="text-sm font-black text-brand-ink mb-3">{title}</h2>
      {rows.length === 0 ? (
        <p className="text-sm text-slate-500">Aún no hay respuestas.</p>
      ) : (
        <ul className="divide-y divide-slate-100 text-sm">
          {rows.map((row) => (
            <li key={row.id ?? row.name} className="flex items-center justify-between gap-3 py-2">
              <span className="min-w-0 truncate text-slate-700">{row.name} <span className="text-xs text-slate-400">({row.answered})</span></span>
              <span className="shrink-0 text-right">
                <span className="font-bold text-brand-ink">NPS {npsText(row.nps)}</span>
                <span className="block text-xs text-slate-500">promedio {row.average ?? '—'}</span>
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export default function SurveysPage() {
  const { can } = useAuth();
  const vocab = useVocabulary();
  const [data, setData] = useState<Payload | null>(null);
  const [period, setPeriod] = useState('90');
  const [filters, setFilters] = useState<Record<string, string>>({});
  const [tab, setTab] = useState('answered');
  const [search, setSearch] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [settingsOpen, setSettingsOpen] = useState(false);

  const load = useCallback(() => {
    api.get<Payload>('/surveys', { params: { period, branch_id: filters.branch || undefined, professional_id: filters.professional || undefined, service_id: filters.service || undefined } })
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las encuestas.')));
  }, [period, filters]);

  useEffect(() => { load(); }, [load]);

  const rows = useMemo(() => {
    if (!data) return [];
    const term = search.trim().toLowerCase();
    return data.surveys
      .filter((s) => (tab === 'answered' ? s.status === 'answered' : tab === 'recover' ? s.follow_up !== null : tab === 'published' ? s.published : true))
      .filter((s) => !term || `${s.customer.name} ${s.comment ?? ''} ${s.professional?.name ?? ''} ${s.service?.name ?? ''}`.toLowerCase().includes(term));
  }, [data, tab, search]);

  const columns: Column<Survey>[] = [
    { header: 'Nota', cell: (s) => <Link href={`/surveys/${s.id}`} data-survey-link={s.id}><Badge tone={scoreTone(s.score)} bold nowrap>{s.score ?? s.status_label}</Badge></Link> },
    { header: vocab.client, cell: (s) => <CustomerLink id={s.customer.id} className="font-semibold text-slate-800">{s.customer.name}</CustomerLink> },
    { header: 'Atención', cell: (s) => <span className="block text-sm"><span className="block text-slate-700">{s.service?.name ?? '—'}</span><span className="block text-xs text-slate-500">con <ProfessionalLink id={s.professional?.id}>{s.professional?.name ?? '—'}</ProfessionalLink></span></span> },
    { header: 'Comentario', cell: (s) => <Link href={`/surveys/${s.id}`} className="block max-w-xs truncate text-sm text-slate-600 hover:text-brand-accent">{s.comment ? `«${s.comment}»` : s.skip_reason ?? '—'}</Link> },
    { header: 'Fecha', cell: (s) => shortDate(s.answered_at ?? s.sent_at ?? s.send_at) },
    { header: 'Seguimiento', cell: (s) => (s.follow_up ? <Badge tone={FOLLOW_TONE[s.follow_up]} bold nowrap>{s.follow_up_label}</Badge> : s.published ? <Badge tone="bg-emerald-50 text-emerald-700" nowrap>Publicada</Badge> : <span className="text-xs text-slate-400">—</span>) },
  ];

  if (!data) {
    return <div className="space-y-4"><ErrorAlert message={error} />{!error && <p className="text-sm text-slate-500">Cargando...</p>}</div>;
  }

  const s = data.summary;

  return (
    <div className="space-y-5 pb-10 font-sans" data-surveys>
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><MessageSquareHeart className="w-6 h-6 text-brand-accent" /> Encuestas y reseñas</h1>
          <p className="text-sm text-slate-500 font-medium">Después de cada atención el {vocab.client.toLowerCase()} califica del 0 al 10. Las notas altas reciben la invitación a reseñar en Google y las bajas te avisan para recuperarlo.</p>
        </div>
        <div className="flex flex-wrap gap-2 items-center">
          <div className="w-48"><Select ariaLabel="Periodo" value={period} clearable={false} options={data.periods.filter((p) => p.value !== 'custom')} onChange={(e) => setPeriod(String(e.target.value))} /></div>
          {can('surveys.edit') && (
            <Button variant="secondary" type="button" onClick={() => setSettingsOpen(true)} data-action="survey-settings" className="inline-flex items-center gap-1.5"><Settings2 size={15} /> Cómo se envían</Button>
          )}
        </div>
      </div>

      {!data.config.enabled && (
        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-surveys-off>
          Las encuestas están apagadas: no se envía ninguna. {data.can_edit ? 'Enciéndelas en «Cómo se envían».' : 'Pídele al administrador que las encienda.'}
        </p>
      )}
      {data.config.enabled && !data.config.google_url && (
        <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">Aún no pusiste el enlace de reseñas de Google: a las notas altas no se les invita a reseñar.</p>
      )}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-2 lg:grid-cols-6 gap-3" data-survey-kpis>
        <Kpi title="NPS" value={npsText(s.nps)} hint="promotores menos detractores" alert={s.nps !== null && s.nps < 0} />
        <Kpi title="Nota promedio" value={s.average === null ? '—' : String(s.average)} hint={`${s.answered} respuestas`} />
        <Kpi title="Respondieron" value={s.response_rate === null ? '—' : `${s.response_rate} %`} hint={`${s.delivered} enviadas`} />
        <Kpi title="Por recuperar" value={String(s.pending)} hint="notas de 0 a 6 sin contactar" alert={s.pending > 0} />
        <Kpi title="Invitados a Google" value={String(s.google_invited)} hint={`${s.google_clicked} abrieron la reseña`} />
        <Kpi title="Reseñas publicadas" value={String(s.published)} hint="en tu página de reservas" />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <BreakdownCard title={`Por ${vocab.pro.toLowerCase()}`} rows={data.by_professional} />
        <BreakdownCard title="Por servicio" rows={data.by_service} />
        <BreakdownCard title="Por sede" rows={data.by_branch} />
      </div>

      <DataTable<Survey>
        title="Encuestas"
        tabs={TABS.map((t) => ({ ...t, count: t.id === 'recover' ? s.pending : t.id === 'published' ? s.published : t.id === 'answered' ? s.answered : data.surveys.length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        filterConfigs={[
          ...(data.branch_locked ? [] : [{ id: 'branch', label: 'Sede', type: 'select' as const, options: data.options.branches.map((b) => ({ label: b.name, value: b.id })) }]),
          { id: 'professional', label: vocab.pro, type: 'select' as const, options: data.options.professionals.map((p) => ({ label: p.name, value: p.id })) },
          { id: 'service', label: 'Servicio', type: 'select' as const, options: data.options.services.map((p) => ({ label: p.name, value: p.id })) },
        ]}
        filterValues={filters}
        onFilterChange={(id, value) => setFilters((f) => ({ ...f, [id]: value ? String(value) : '' }))}
        onClearFilters={() => setFilters({})}
        columns={columns}
        data={rows}
        getItemKey={(r) => r.id}
        entityLabel={rows.length === 1 ? 'encuesta' : 'encuestas'}
      />

      <p className="flex items-center gap-1.5 text-xs text-slate-500"><Star size={13} className="text-brand-accent" /> Del 9 al 10 son promotores, 7 y 8 pasivos, y del 0 al 6 detractores. El NPS va de −100 a +100.</p>

      {data.can_edit && (
        <SurveySettingsDrawer
          isOpen={settingsOpen}
          config={data.config}
          onClose={() => setSettingsOpen(false)}
          onSaved={(config) => { setData({ ...data, config }); setSettingsOpen(false); setNotice('Listo, guardamos cómo se envían las encuestas.'); }}
        />
      )}
    </div>
  );
}
