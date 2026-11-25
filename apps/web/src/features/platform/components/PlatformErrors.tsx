'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Bug, Loader2, Settings2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Switch from '@/app/components/common/Switch';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

export interface ErrorRow {
  id: string;
  source: 'server' | 'web';
  kind: string | null;
  message: string;
  file: string | null;
  line: number | null;
  url: string | null;
  occurrences: number;
  first_seen_at: string;
  last_seen_at: string;
  resolved_at: string | null;
  tenant: string | null;
}

interface ErrorSettings {
  notify: boolean;
  notify_every_minutes: number;
  sentry_enabled: boolean;
  has_sentry_dsn: boolean;
}

export const SOURCE_LABELS: Record<ErrorRow['source'], string> = { server: 'Servidor', web: 'Web' };

export default function PlatformErrors() {
  const [rows, setRows] = useState<ErrorRow[]>([]);
  const [tab, setTab] = useState('open');
  const [search, setSearch] = useState('');
  const [filters, setFilters] = useState<{ source: string }>({ source: '' });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [loading, setLoading] = useState(true);
  const [settings, setSettings] = useState<ErrorSettings | null>(null);
  const [panel, setPanel] = useState(false);
  const [form, setForm] = useState({ notify: true, notify_every_minutes: 60, sentry_enabled: false, sentry_dsn: '' });
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');

  const load = useCallback(() => {
    setLoading(true);
    api.get('/admin/errors', { params: { state: 'all' } })
      .then((res) => { setRows(res.data.items); setSettings(res.data.settings); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los errores.')))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => { load(); }, [load]);

  const openSettings = () => {
    if (settings) setForm({ notify: settings.notify, notify_every_minutes: settings.notify_every_minutes, sentry_enabled: settings.sentry_enabled, sentry_dsn: '' });
    setFormError('');
    setPanel(true);
  };

  const save = async () => {
    try {
      setBusy(true);
      setFormError('');
      const res = await api.put('/admin/errors-settings', { ...form, sentry_dsn: form.sentry_dsn.trim() || null });
      setSettings(res.data);
      setPanel(false);
      setNotice('Listo: guardamos cómo te avisamos de los errores.');
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const tabs = [
    { id: 'open', label: 'Por revisar', test: (r: ErrorRow) => !r.resolved_at },
    { id: 'resolved', label: 'Resueltos', test: (r: ErrorRow) => Boolean(r.resolved_at) },
    { id: 'all', label: 'Todos', test: () => true },
  ];
  const term = search.trim().toLowerCase();
  const shown = rows
    .filter(tabs.find((t) => t.id === tab)?.test ?? (() => true))
    .filter((r) => !filters.source || r.source === filters.source)
    .filter((r) => !term || [r.message, r.url, r.file, r.tenant].some((v) => (v ?? '').toLowerCase().includes(term)));

  const columns: Column<ErrorRow>[] = [
    { header: 'Error', cell: (r) => <Link href={`/admin/errors/${r.id}`} data-error-link className="block min-w-0"><span className="block font-bold text-brand-accent hover:text-brand-ink line-clamp-2">{r.message}</span><span className="block text-xs text-slate-500 truncate">{r.url ?? r.file ?? ''}</span></Link> },
    { header: 'Dónde', cell: (r) => <Badge tone={r.source === 'web' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-700'} bold>{SOURCE_LABELS[r.source]}</Badge> },
    { header: 'Veces', cell: (r) => <span className="font-bold tabular-nums">{r.occurrences.toLocaleString('es-PE')}</span> },
    { header: 'Empresa', cell: (r) => r.tenant ?? '—' },
    { header: 'Última vez', cell: (r) => <span className="text-sm">{momentOf(r.last_seen_at)}{r.resolved_at && <span className="block text-xs text-emerald-700">Resuelto</span>}</span> },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-errors-page>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Bug className="w-6 h-6 text-brand-accent" /> Errores del sistema</h1>
          <p className="text-sm text-slate-500 font-medium">Las fallas del servidor y de la web, juntas cuando son la misma. Si una vuelve a pasar después de marcarla resuelta, regresa a «Por revisar».</p>
        </div>
        <button type="button" data-error-settings onClick={openSettings} className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 bg-white">
          <Settings2 size={15} /> Avisos y Sentry
        </button>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {settings && (
        <p className="text-xs text-slate-500">
          {settings.notify ? `Te avisamos por correo cuando aparece un error (como máximo cada ${settings.notify_every_minutes} minutos por error).` : 'Los avisos por correo están apagados.'}{' '}
          {settings.sentry_enabled ? 'También se envían a Sentry.' : 'No se envían a Sentry.'}
        </p>
      )}

      {loading && rows.length === 0 ? <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando…</p> : (
        <DataTable<ErrorRow>
          title="Errores"
          tabs={tabs.map((t) => ({ id: t.id, label: t.label, count: rows.filter(t.test).length }))}
          activeTab={tab}
          onTabChange={setTab}
          searchTerm={search}
          onSearchChange={setSearch}
          filterConfigs={[{ id: 'source', label: 'Dónde', type: 'select', options: [{ value: 'server', label: 'Servidor' }, { value: 'web', label: 'Web' }] }]}
          filterValues={filters}
          onFilterChange={(id, value) => setFilters({ ...filters, [id]: value ?? '' })}
          onClearFilters={() => setFilters({ source: '' })}
          sortOptions={[{ id: 'last', label: 'Última vez', type: 'date', value: (r) => r.last_seen_at }, { id: 'count', label: 'Veces', type: 'number', value: (r) => r.occurrences }]}
          columns={columns}
          data={shown}
          getItemKey={(r) => r.id}
          entityLabel={shown.length === 1 ? 'error' : 'errores'}
        />
      )}

      <FormDrawer isOpen={panel} onClose={() => setPanel(false)} title="Avisos y Sentry" subtitle="Cómo te enteras de los errores" icon={<Settings2 size={18} />}>
        <div className="space-y-5" data-error-settings-panel>
          <div className="flex items-start justify-between gap-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Avisarme por correo</span>
              <span className="block text-xs text-slate-500">Llega a los administradores de la plataforma, con el correo de la plataforma.</span>
            </div>
            <Switch checked={form.notify} onChange={(v) => setForm({ ...form, notify: v })} label="Avisarme por correo" />
          </div>
          <div>
            <label className={labelClass} htmlFor="err-every">No repetir el aviso del mismo error antes de (minutos)</label>
            <input id="err-every" type="number" min={5} max={1440} value={form.notify_every_minutes} onChange={(e) => setForm({ ...form, notify_every_minutes: Number(e.target.value) })} className={inputClass} />
          </div>
          <div className="pt-4 border-t border-slate-100 space-y-3">
            <div className="flex items-start justify-between gap-3">
              <div>
                <span className="block text-sm font-semibold text-slate-800">Enviar también a Sentry</span>
                <span className="block text-xs text-slate-500">Solo si tienes una cuenta de Sentry. Los errores se siguen guardando aquí igual.</span>
              </div>
              <Switch checked={form.sentry_enabled} onChange={(v) => setForm({ ...form, sentry_enabled: v })} label="Enviar también a Sentry" />
            </div>
            <div>
              <label className={labelClass} htmlFor="err-dsn">DSN de Sentry</label>
              <input id="err-dsn" value={form.sentry_dsn} onChange={(e) => setForm({ ...form, sentry_dsn: e.target.value })} className={inputClass} placeholder={settings?.has_sentry_dsn ? 'Ya guardado: déjalo vacío para no cambiarlo' : 'https://clave@o123.ingest.sentry.io/456'} />
            </div>
          </div>
          <ErrorAlert message={formError} />
          <button type="button" data-error-settings-save disabled={busy} onClick={save} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy && <Loader2 size={16} className="animate-spin" />} Guardar
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
