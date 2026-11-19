'use client';

import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { CheckCircle2, FileText, ShieldCheck } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

type SeriesKey = 'boleta' | 'factura' | 'nota_boleta' | 'nota_factura';
type Series = Record<SeriesKey, string>;

interface InvoicingPayload {
  module_on: boolean;
  provider: 'simulated' | 'nubefact';
  active: string;
  mode: 'sandbox' | 'production' | null;
  saved: { route: string | null; token: string | null; saved_at: string } | null;
  test: { ok: boolean; message: string | null; at: string } | null;
  series: Series;
  defaults: Series;
  branch_series_ready: boolean;
  electronic_ready: boolean;
  branches: { id: string; name: string; series: Partial<Series> }[];
  pending: number;
  void_days: number;
  can_edit_all_branches: boolean;
}

const SERIES_FIELDS: { key: SeriesKey; label: string }[] = [
  { key: 'boleta', label: 'Boleta' },
  { key: 'factura', label: 'Factura' },
  { key: 'nota_boleta', label: 'Nota de crédito de boleta' },
  { key: 'nota_factura', label: 'Nota de crédito de factura' },
];

const seriesInput = `${inputClass} font-mono uppercase`;

export default function InvoicingSettings({ canSave }: { canSave: boolean }) {
  const [data, setData] = useState<InvoicingPayload | null>(null);
  const [chosen, setChosen] = useState<'simulated' | 'nubefact'>('simulated');
  const [mode, setMode] = useState<'sandbox' | 'production'>('sandbox');
  const [route, setRoute] = useState('');
  const [token, setToken] = useState('');
  const [company, setCompany] = useState<Series>({ boleta: '', factura: '', nota_boleta: '', nota_factura: '' });
  const [branches, setBranches] = useState<Record<string, Partial<Series>>>({});
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = (payload: InvoicingPayload) => {
    setData(payload);
    setChosen(payload.provider);
    setMode(payload.mode === 'production' ? 'production' : 'sandbox');
    setRoute('');
    setToken('');
    setCompany(payload.series);
    setBranches(Object.fromEntries(payload.branches.map((b) => [b.id, { ...b.series }])));
  };

  useEffect(() => {
    api.get('/integrations/invoicing')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la facturación electrónica.')));
  }, []);

  if (!data) {
    return error ? <p className="text-sm text-slate-600">{error}</p> : null;
  }

  const providerDirty = chosen !== data.provider || Boolean(route || token) || (chosen === 'nubefact' && data.provider === 'nubefact' && mode !== (data.mode ?? 'sandbox'));
  const seriesDirty = SERIES_FIELDS.some(({ key }) => company[key] !== data.series[key])
    || data.branches.some((b) => SERIES_FIELDS.some(({ key }) => (branches[b.id]?.[key] ?? '') !== (b.series[key] ?? '')));

  const run = async (kind: string, call: () => Promise<{ data: InvoicingPayload & { ok?: boolean; message?: string } }>, done: (payload: InvoicingPayload & { ok?: boolean; message?: string }) => string | null) => {
    try {
      setBusy(kind);
      setError('');
      setNotice('');
      const res = await call();
      apply(res.data);
      const message = done(res.data);
      if (res.data.ok === false) setError(res.data.message ?? 'No se pudo.');
      else if (message) setNotice(message);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy('');
    }
  };

  const save = () => run('save', () => api.put('/integrations/invoicing', { provider: chosen, ...(chosen === 'nubefact' ? { route, token, mode } : {}) }), () => chosen === 'nubefact'
    ? 'Nubefact quedó guardado. Usa «Probar conexión» para confirmar la ruta y el token. Desde ahora, las boletas, facturas y notas de crédito se envían a SUNAT por Nubefact.'
    : 'Listo: los comprobantes vuelven a la simulación y no se envían a SUNAT.');

  const test = () => run('test', () => api.post('/integrations/invoicing/test'), (payload) => payload.message ?? null);

  const saveSeries = () => run('series', () => api.put('/integrations/invoicing/series', {
    ...(data.can_edit_all_branches ? { series: company } : {}),
    ...(data.branch_series_ready ? { branches: data.branches.map((b) => ({ id: b.id, series: branches[b.id] ?? {} })) } : {}),
  }), () => 'Series guardadas. Los próximos comprobantes salen con estas series.');

  return (
    <div className="space-y-4 pt-4 border-t border-slate-100" data-invoicing>
      <div>
        <span className="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 uppercase tracking-wider"><FileText size={13} /> Facturación electrónica</span>
        <p className="text-xs text-slate-500 mt-1">
          Con qué proveedor se envían a SUNAT tus boletas, facturas y notas de crédito. Si el proveedor no responde, el comprobante queda «Pendiente de envío» y el sistema lo vuelve a intentar solo.
        </p>
      </div>

      {!data.module_on && (
        <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">Tu plan emite solo comprobantes internos. Las boletas y facturas se activan con el módulo de facturación electrónica.</p>
      )}
      {!data.electronic_ready && (
        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos (php artisan migrate) para guardar los enlaces del PDF, reintentar solo y anular con baja o nota de crédito.</p>
      )}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Proveedor de facturación">
        {([
          ['simulated', 'Simulación', 'Los comprobantes se numeran e imprimen, pero no van a SUNAT.'],
          ['nubefact', 'Nubefact', 'Envía tus comprobantes a SUNAT con tu cuenta de Nubefact.'],
        ] as const).map(([key, label, hint]) => (
          <OptionCard key={key} type="button" role="radio" aria-checked={chosen === key} data-invoicing-provider={key} disabled={!canSave} selected={chosen === key} onClick={() => { setChosen(key); setNotice(''); setError(''); }} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
            <span className="block text-sm font-bold text-brand-ink">{label}</span>
            <span className={`block text-xs mt-0.5 ${data.active === key ? 'text-emerald-700 font-semibold' : 'text-slate-500'}`}>{data.active === key ? 'En uso' : hint}</span>
          </OptionCard>
        ))}
      </div>

      {chosen === 'nubefact' ? (
        <div className="rounded-xl border border-slate-200 p-4 space-y-4" data-invoicing-keys>
          <p className="text-sm text-slate-600">
            En tu cuenta de Nubefact entra a <span className="font-semibold">API (Integración)</span> y copia la <span className="font-semibold">ruta</span> y el <span className="font-semibold">token</span>. Las series que uses aquí deben estar creadas también en Nubefact.
          </p>
          <div className="space-y-1.5">
            <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Modo</span>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Modo de facturación">
              {([
                ['sandbox', 'Pruebas', 'Con la cuenta de pruebas (demo) de Nubefact: no tiene valor ante SUNAT.'],
                ['production', 'Producción', 'Con tu cuenta real: los comprobantes llegan a SUNAT.'],
              ] as const).map(([key, label, hint]) => (
                <OptionCard key={key} type="button" role="radio" aria-checked={mode === key} data-invoicing-mode={key} disabled={!canSave} selected={mode === key} onClick={() => setMode(key)} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
                  <span className="block text-sm font-bold text-brand-ink">{label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
                </OptionCard>
              ))}
            </div>
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            <label className="space-y-1">
              <span className="block text-xs font-semibold text-slate-600">Ruta</span>
              <input aria-label="Ruta de Nubefact" maxLength={300} placeholder={data.saved?.route ?? 'https://api.nubefact.com/api/v1/...'} disabled={!canSave} className={`${inputClass} font-mono`} value={route} onChange={(e) => setRoute(e.target.value)} />
            </label>
            <label className="space-y-1">
              <span className="block text-xs font-semibold text-slate-600">Token</span>
              <input aria-label="Token de Nubefact" type="password" autoComplete="off" maxLength={300} placeholder={data.saved?.token ?? 'Token'} disabled={!canSave} className={`${inputClass} font-mono`} value={token} onChange={(e) => setToken(e.target.value)} />
            </label>
          </div>
          {data.saved && <p className="text-xs text-slate-500">Guardados el {momentOf(data.saved.saved_at)}. Deja los campos vacíos para no cambiarlos.</p>}
          {data.test && (
            <p className={`text-xs ${data.test.ok ? 'text-emerald-700' : 'text-rose-700'}`} data-invoicing-test>Última prueba ({momentOf(data.test.at)}): {data.test.message}</p>
          )}
          <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
            Al anular un comprobante, dentro de los {data.void_days} días siguientes a su emisión se comunica la baja a SUNAT; después, se emite una nota de crédito por el total. Las devoluciones de una venta con comprobante emiten su nota de crédito.
          </p>
        </div>
      ) : (
        <p className="flex items-start gap-2 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
          <ShieldCheck size={15} className="shrink-0 mt-0.5" />
          Con la simulación, las boletas y facturas se numeran e imprimen, pero no se envían a SUNAT ni tienen validez tributaria.
        </p>
      )}

      {data.pending > 0 && (
        <p className="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-invoicing-pending>
          {data.pending === 1 ? 'Hay 1 comprobante pendiente de envío.' : `Hay ${data.pending} comprobantes pendientes de envío.`} Se ven en Ventas → Boletas y facturas.
        </p>
      )}

      <div className="flex flex-wrap gap-2">
        {canSave && (
          <button type="button" data-invoicing-save disabled={busy !== '' || !providerDirty} onClick={save} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
            {busy === 'save' ? 'Guardando...' : 'Guardar facturación'}
          </button>
        )}
        {canSave && (
          <button type="button" data-invoicing-test-button disabled={busy !== '' || providerDirty} onClick={test} title={providerDirty ? 'Guarda primero los cambios' : undefined} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
            <CheckCircle2 size={15} /> {busy === 'test' ? 'Probando...' : 'Probar conexión'}
          </button>
        )}
      </div>

      {data.module_on && (
        <div className="rounded-xl border border-slate-200 p-4 space-y-4" data-invoicing-series>
          <div>
            <span className="block text-sm font-bold text-brand-ink">Series</span>
            <p className="text-xs text-slate-500 mt-0.5">Cuatro letras o números: las de boleta empiezan con B y las de factura con F. Cada serie lleva su propia numeración. Si una sede no tiene serie propia, usa la de la empresa.</p>
          </div>
          {data.can_edit_all_branches && (
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
              {SERIES_FIELDS.map(({ key, label }) => (
                <label key={key} className="space-y-1">
                  <span className="block text-xs font-semibold text-slate-600">{label}</span>
                  <input aria-label={`Serie de ${label.toLowerCase()} de la empresa`} data-series-company={key} maxLength={4} disabled={!canSave} className={seriesInput} value={company[key]} onChange={(e) => setCompany({ ...company, [key]: e.target.value.toUpperCase() })} />
                </label>
              ))}
            </div>
          )}
          {data.branch_series_ready ? data.branches.length > 0 && (
            <div className="space-y-2">
              <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">Por sede</span>
              <ul className="divide-y divide-slate-100 rounded-lg border border-slate-200">
                {data.branches.map((branch) => (
                  <li key={branch.id} className="p-3 space-y-2" data-series-branch={branch.id}>
                    <span className="block text-sm font-semibold text-slate-800">{branch.name}</span>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-2">
                      {SERIES_FIELDS.map(({ key, label }) => (
                        <input key={key} aria-label={`Serie de ${label.toLowerCase()} de ${branch.name}`} data-series-branch-field={key} maxLength={4} disabled={!canSave} placeholder={`${company[key] || data.defaults[key]} (empresa)`} className={seriesInput} value={branches[branch.id]?.[key] ?? ''} onChange={(e) => setBranches({ ...branches, [branch.id]: { ...branches[branch.id], [key]: e.target.value.toUpperCase() } })} />
                      ))}
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          ) : (
            <p className="text-xs text-amber-800">Falta actualizar la base de datos (php artisan migrate) para dar una serie propia a cada sede.</p>
          )}
          {canSave && (
            <button type="button" data-series-save disabled={busy !== '' || !seriesDirty} onClick={saveSeries} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
              {busy === 'series' ? 'Guardando...' : 'Guardar series'}
            </button>
          )}
        </div>
      )}

      {notice && <p data-invoicing-notice className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-lg px-3 py-2">{notice}</p>}
      <ErrorAlert message={error} />
    </div>
  );
}
