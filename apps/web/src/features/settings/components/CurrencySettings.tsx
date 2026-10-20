'use client';

import OptionCard from '@/app/components/common/OptionCard';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useCallback, useEffect, useState } from 'react';
import { ArrowRight, Coins, History, Loader2, RefreshCw } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import { PagedList } from '@/app/components/common/Pager';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import type { CurrencyInfo } from '@/lib/currency';

interface CurrencyRecords extends CurrencyInfo {
  sales: number;
  appointments: number;
}

interface CurrencyChange {
  id: string;
  kind: 'setting' | 'relabel' | 'convert';
  from: CurrencyInfo;
  to: CurrencyInfo;
  exchange_rate: number | null;
  starts_on: string | null;
  ends_on: string | null;
  counts: { sales?: number; appointments?: number; sessions?: number };
  excluded: number;
  by: string | null;
  created_at: string;
}

interface CurrencyData {
  current: CurrencyInfo;
  currencies: CurrencyInfo[];
  blockers: string[];
  records: CurrencyRecords[];
  history: CurrencyChange[];
  can_edit: boolean;
}

interface Preview {
  from: CurrencyInfo;
  to: CurrencyInfo;
  counts: { sales: number; payments: number; appointments: number; sessions: number; excluded: number };
  sample: { before: string; after: string } | null;
}

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';
const chip = (on: boolean) => `px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${on ? 'border-[#022340] bg-[#84D0D9]/15 text-[#022340]' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`;

const label = (c: CurrencyInfo) => `${c.name} (${c.symbol})`;
const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;
const cleanRate = (text: string) => {
  const [whole, ...rest] = text.replace(',', '.').replace(/[^\d.]/g, '').split('.');
  return rest.length ? `${whole}.${rest.join('').slice(0, 6)}` : whole;
};
const shortRate = (value: number) => Number(value.toFixed(value >= 1 ? 4 : 6)).toString();

const describe = (change: CurrencyChange): string => {
  if (change.kind === 'setting') return `Cambió la moneda de ${change.from.symbol} a ${change.to.symbol}.`;
  const parts = [
    change.counts.sales ? plural(change.counts.sales, 'venta', 'ventas') : '',
    change.counts.appointments ? plural(change.counts.appointments, 'cita', 'citas') : '',
    change.counts.sessions ? plural(change.counts.sessions, 'turno de caja', 'turnos de caja') : '',
  ].filter(Boolean).join(', ');
  const period = change.starts_on || change.ends_on ? ` (del ${change.starts_on ?? 'inicio'} al ${change.ends_on ?? 'hoy'})` : '';
  if (change.kind === 'relabel') return `Corrigió la moneda de ${parts || 'ningún registro'}${period}: de ${change.from.symbol} a ${change.to.symbol}, con los mismos montos.`;
  const rate = change.exchange_rate ?? 1;
  const human = rate < 1 ? `1 ${change.to.symbol} = ${shortRate(1 / rate)} ${change.from.symbol}` : `1 ${change.from.symbol} = ${shortRate(rate)} ${change.to.symbol}`;
  return `Pasó ${parts || 'ningún registro'}${period} de ${change.from.symbol} a ${change.to.symbol} con ${human}.`;
};

export default function CurrencySettings() {
  const { refetchUser } = useAuth();
  const [data, setData] = useState<CurrencyData | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const [changing, setChanging] = useState(false);
  const [code, setCode] = useState('');
  const [saving, setSaving] = useState(false);
  const [changeError, setChangeError] = useState('');
  const [justChanged, setJustChanged] = useState<CurrencyInfo | null>(null);

  const [records, setRecords] = useState(false);
  const [kind, setKind] = useState<'relabel' | 'convert'>('relabel');
  const [from, setFrom] = useState('');
  const [period, setPeriod] = useState<'all' | 'range'>('all');
  const [startsOn, setStartsOn] = useState('');
  const [endsOn, setEndsOn] = useState('');
  const [rate, setRate] = useState('');
  const [direction, setDirection] = useState<'to_from' | 'from_to'>('to_from');
  const [preview, setPreview] = useState<Preview | null>(null);
  const [previewError, setPreviewError] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [applying, setApplying] = useState(false);

  const load = useCallback(() => {
    api.get('/currency')
      .then((res) => { setData(res.data); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la moneda de la empresa.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const current = data?.current;
  const others = (data?.records ?? []).filter((r) => r.code !== current?.code && (r.sales > 0 || r.appointments > 0));
  const fromCurrency = others.find((r) => r.code === from);

  const params = useCallback(() => ({
    kind,
    from,
    starts_on: period === 'range' ? startsOn || null : null,
    ends_on: period === 'range' ? endsOn || null : null,
    rate: kind === 'convert' && rate ? Number(rate) : null,
    direction,
  }), [kind, from, period, startsOn, endsOn, rate, direction]);

  useEffect(() => {
    if (!records || !from) return;
    const timer = setTimeout(() => {
      api.post('/currency/records/preview', params())
        .then((res) => { setPreview(res.data); setPreviewError(''); })
        .catch((err) => { setPreview(null); setPreviewError(apiError(err, 'No se pudo revisar qué registros cambiarían.')); });
    }, 300);
    return () => clearTimeout(timer);
  }, [records, from, params]);

  const openChange = () => {
    setCode(current?.code ?? '');
    setChangeError('');
    setJustChanged(null);
    setChanging(true);
  };

  const saveCurrency = async () => {
    if (!data || !code || code === data.current.code) return;
    try {
      setSaving(true);
      setChangeError('');
      const previous = data.current;
      const res = await api.put('/currency', { code });
      setData(res.data);
      await refetchUser();
      setJustChanged(previous);
    } catch (err) {
      setChangeError(apiError(err, 'No se pudo cambiar la moneda.'));
    } finally {
      setSaving(false);
    }
  };

  const openRecords = (fromCode?: string) => {
    setChanging(false);
    setKind('relabel');
    setFrom(fromCode && fromCode !== current?.code ? fromCode : others[0]?.code ?? '');
    setPeriod('all');
    setStartsOn('');
    setEndsOn('');
    setRate('');
    setDirection('to_from');
    setPreview(null);
    setPreviewError('');
    setRecords(true);
  };

  const apply = async () => {
    try {
      setApplying(true);
      const res = await api.post('/currency/records', params());
      setData(res.data);
      const counts = res.data.applied;
      setNotice(`Listo: se cambiaron ${[plural(counts.sales, 'venta', 'ventas'), plural(counts.appointments, 'cita', 'citas'), plural(counts.sessions, 'turno de caja', 'turnos de caja')].join(', ')}.`);
      setRecords(false);
    } catch (err) {
      setPreviewError(apiError(err, 'No se pudieron cambiar los registros.'));
    } finally {
      setApplying(false);
      setConfirming(false);
    }
  };

  if (error) {
    return (
      <section className={cardClass} data-card="currency">
        <SectionTitle><Coins className="w-4 h-4 text-[#3F768C]" /> Moneda</SectionTitle>
        <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{error}</p>
      </section>
    );
  }

  if (!data || !current) {
    return (
      <section className={cardClass} data-card="currency">
        <Loader2 className="w-5 h-5 text-[#3F768C] animate-spin" />
      </section>
    );
  }

  const total = preview ? preview.counts.sales + preview.counts.appointments + preview.counts.sessions : 0;
  const fromSymbol = fromCurrency?.symbol ?? '';
  const rateValid = kind === 'relabel' || Number(rate) > 0;
  const rangeValid = period === 'all' || Boolean(startsOn || endsOn);

  return (
    <section className={cardClass} data-card="currency">
      <div>
        <SectionTitle>
          <Coins className="w-4 h-4 text-[#3F768C]" /> Moneda
        </SectionTitle>
        <p className="text-xs text-slate-500 font-medium mt-1">
          La moneda en que tu empresa cobra. Cada venta, pago, caja y cita guarda la moneda en que se hizo: cambiarla aquí no altera lo ya registrado.
        </p>
      </div>

      <SuccessAlert message={notice} />

      <div className="flex flex-wrap items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
        <div>
          <span className="block text-xs text-slate-500">Tu empresa cobra en</span>
          <span className="block text-lg font-black text-[#022340]" data-current-currency={current.code}>{label(current)}</span>
        </div>
        {data.can_edit && (
          <div className="flex flex-wrap gap-2">
            <button type="button" data-action="change-currency" onClick={openChange} className="px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] transition-colors">
              Cambiar moneda
            </button>
            {others.length > 0 && (
              <button type="button" data-action="change-records" onClick={() => openRecords()} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-[#3F768C] border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
                <RefreshCw size={14} /> Cambiar registros anteriores
              </button>
            )}
          </div>
        )}
      </div>

      {others.length > 0 && (
        <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2.5" data-other-currencies>
          También tienes registros en otra moneda: {others.map((r) => `${[r.sales ? plural(r.sales, 'venta', 'ventas') : '', r.appointments ? plural(r.appointments, 'cita', 'citas') : ''].filter(Boolean).join(' y ')} en ${r.symbol}`).join('; ')}.
          Se muestran con su moneda y no se suman a los totales en {current.symbol}.
        </p>
      )}

      {data.history.length > 0 && (
        <div className="border border-slate-100 rounded-xl overflow-hidden flex flex-col" data-card="currency-history">
          <span className="flex items-center gap-1.5 px-4 pt-3 text-[11px] font-bold text-slate-400"><History size={12} /> Cambios de moneda</span>
          <PagedList
            items={data.history}
            empty="Sin cambios."
            renderRow={(change) => (
              <li key={change.id} className="px-4 py-2.5 text-sm">
                <span className="block text-slate-700">{describe(change)}</span>
                <span className="block text-xs text-slate-400">{momentOf(change.created_at)}{change.by ? ` · ${change.by}` : ''}{change.excluded ? ` · ${plural(change.excluded, 'venta con comprobante quedó', 'ventas con comprobante quedaron')} fuera` : ''}</span>
              </li>
            )}
          />
        </div>
      )}

      <FormDrawer isOpen={changing} onClose={() => setChanging(false)} title="Cambiar la moneda" subtitle={`Hoy cobras en ${label(current)}`} icon={<Coins size={20} />}>
        <div className="flex flex-col h-full justify-between">
          {justChanged ? (
            <div className="space-y-4" data-currency-changed>
              <p className="text-sm text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-3">
                Listo: desde ahora tu empresa cobra en {label(current)}. Lo que registraste en {justChanged.symbol} se queda como está.
              </p>
              {others.some((r) => r.code === justChanged.code) ? (
                <>
                  <p className="text-sm text-slate-700">¿Hay registros anteriores en {justChanged.symbol} que también quieras cambiar? Por ejemplo, citas ya agendadas que se cobrarán en {current.symbol}.</p>
                  <div className="flex flex-wrap gap-2">
                    <button type="button" data-action="open-records" onClick={() => openRecords(justChanged.code)} className="px-4 py-2 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] transition-colors">
                      Sí, cambiar registros anteriores
                    </button>
                    <button type="button" onClick={() => setChanging(false)} className="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
                      No, dejarlos así
                    </button>
                  </div>
                </>
              ) : (
                <button type="button" onClick={() => setChanging(false)} className="px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">Cerrar</button>
              )}
            </div>
          ) : (
            <>
              <div className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
                <div>
                  <span className={labelClass}>Nueva moneda</span>
                  <Select ariaLabel="Nueva moneda" clearable={false} value={code} onChange={(e) => setCode(String(e.target.value))} options={data.currencies.map((c) => ({ label: label(c), value: c.code }))} />
                </div>

                {code && code !== current.code && (
                  <div className="space-y-3 text-sm text-slate-700" data-change-summary>
                    <p className="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                      Desde ahora las ventas, los pagos, las cajas y las citas nuevas se registran en {data.currencies.find((c) => c.code === code)?.symbol}. Lo que ya registraste se queda en {current.symbol}, con sus mismos montos.
                    </p>
                    <p className="text-xs text-slate-500">
                      Tus servicios y tus cupones de monto fijo siguen con los mismos números: un servicio de 150 pasa a costar {data.currencies.find((c) => c.code === code)?.symbol} 150. Revisa sus precios en Servicios y Cupones.
                    </p>
                  </div>
                )}

                {data.blockers.length > 0 && (
                  <ul className="space-y-1.5 text-sm text-amber-800 bg-amber-50 border border-amber-100 rounded-xl px-4 py-3" data-blockers>
                    {data.blockers.map((b) => <li key={b}>{b}</li>)}
                  </ul>
                )}

                <ErrorAlert message={changeError} />
              </div>

              <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
                <Button type="button" onClick={() => setChanging(false)} variant="secondary">Cancelar</Button>
                <Button
                  type="button"
                  data-submit="currency"
                  disabled={saving || !code || code === current.code || data.blockers.length > 0}
                  onClick={saveCurrency} variant="primary"
                >
                  {saving ? 'Guardando...' : 'Cambiar la moneda'}
                </Button>
              </div>
            </>
          )}
        </div>
      </FormDrawer>

      <FormDrawer isOpen={records} onClose={() => setRecords(false)} title="Cambiar registros anteriores" subtitle={`Pasarlos a ${label(current)}`} icon={<RefreshCw size={20} />}>
        <div className="flex flex-col h-full justify-between">
          <div className="space-y-5 overflow-y-auto flex-1 pr-1 pb-4">
            <div>
              <span className={labelClass}>¿Qué pasó?</span>
              <div className="grid gap-2" role="radiogroup" aria-label="Qué pasó">
                <OptionCard type="button" role="radio" aria-checked={kind === 'relabel'} data-kind="relabel" onClick={() => setKind('relabel')} selected={kind === 'relabel'} className="text-left rounded-xl border px-4 py-3 transition-colors">
                  <span className="flex items-center gap-2 text-sm font-bold text-[#022340]">Me equivoqué de moneda <span className="px-1.5 py-0.5 text-[10px] font-bold rounded-md bg-emerald-50 text-emerald-700">Recomendado</span></span>
                  <span className="block text-xs text-slate-600 mt-1">
                    Registraste en {fromSymbol || 'otra moneda'} cobros y precios que en realidad eran en {current.symbol}. Los montos se quedan igual; solo cambia la moneda.
                  </span>
                  <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-700 mt-1.5">Ejemplo: {fromSymbol || '—'} 100.00 <ArrowRight size={12} /> {current.symbol} 100.00</span>
                </OptionCard>
                <OptionCard type="button" role="radio" aria-checked={kind === 'convert'} data-kind="convert" onClick={() => setKind('convert')} selected={kind === 'convert'} className="text-left rounded-xl border px-4 py-3 transition-colors">
                  <span className="block text-sm font-bold text-[#022340]">Quiero pasar los montos a la otra moneda</span>
                  <span className="block text-xs text-slate-600 mt-1">
                    Los montos se recalculan con un tipo de cambio, como si siempre hubieran sido en {current.symbol}. Ojo: dejarán de decir lo que el cliente pagó en su momento.
                  </span>
                  <span className="flex items-center gap-1.5 text-xs font-semibold text-slate-700 mt-1.5">Ejemplo: {fromSymbol || '—'} 370.00 <ArrowRight size={12} /> {current.symbol} 100.00, con 1 {current.symbol} = 3.70 {fromSymbol || '—'}</span>
                </OptionCard>
              </div>
            </div>

            <div>
              <span className={labelClass}>Registros en</span>
              <Select ariaLabel="Registros en" clearable={false} placeholder="Elige la moneda" value={from} onChange={(e) => setFrom(String(e.target.value))} options={others.map((r) => ({ label: label(r), value: r.code }))} />
              <p className="text-xs text-slate-500 mt-1.5">Pasan a {label(current)}, la moneda de tu empresa.</p>
            </div>

            <div>
              <span className={labelClass}>¿Cuáles?</span>
              <div className="flex gap-2" role="radiogroup" aria-label="Periodo">
                <button type="button" role="radio" aria-checked={period === 'all'} data-period="all" onClick={() => setPeriod('all')} className={chip(period === 'all')}>Todos</button>
                <button type="button" role="radio" aria-checked={period === 'range'} data-period="range" onClick={() => setPeriod('range')} className={chip(period === 'range')}>De una fecha a otra</button>
              </div>
              {period === 'range' && (
                <div className="grid grid-cols-2 gap-3 mt-3">
                  <DateField ariaLabel="Desde" placeholder="Desde" value={startsOn} onChange={(value) => { setStartsOn(value); if (endsOn && value && endsOn < value) setEndsOn(value); }} />
                  <DateField ariaLabel="Hasta" placeholder="Hasta" align="right" min={startsOn || undefined} value={endsOn} onChange={setEndsOn} />
                </div>
              )}
              {period === 'range' && <p className="text-xs text-slate-500 mt-1.5">Las ventas cuentan por el día en que se hicieron; las citas, por el día en que son.</p>}
            </div>

            {kind === 'convert' && (
              <div>
                <span className={labelClass}>Tipo de cambio</span>
                <div className="flex gap-2 mb-2" role="radiogroup" aria-label="Cómo escribir el tipo de cambio">
                  <button type="button" role="radio" aria-checked={direction === 'to_from'} data-direction="to_from" onClick={() => setDirection('to_from')} className={chip(direction === 'to_from')}>1 {current.symbol} = … {fromSymbol}</button>
                  <button type="button" role="radio" aria-checked={direction === 'from_to'} data-direction="from_to" onClick={() => setDirection('from_to')} className={chip(direction === 'from_to')}>1 {fromSymbol} = … {current.symbol}</button>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-sm font-semibold text-slate-700 shrink-0">1 {direction === 'to_from' ? current.symbol : fromSymbol} =</span>
                  <input aria-label="Tipo de cambio" inputMode="decimal" placeholder="3.70" className={inputClass} value={rate} onChange={(e) => setRate(cleanRate(e.target.value))} />
                  <span className="text-sm font-semibold text-slate-700 shrink-0">{direction === 'to_from' ? fromSymbol : current.symbol}</span>
                </div>
              </div>
            )}

            {from && rangeValid && (
              <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 space-y-2 text-sm" data-preview>
                {preview ? (
                  <>
                    <p className="font-semibold text-slate-800">
                      {total === 0 ? 'No hay registros que cambiar con lo que elegiste.' : `Se cambiarán ${[plural(preview.counts.sales, 'venta', 'ventas') + (preview.counts.payments ? ` (con sus ${plural(preview.counts.payments, 'pago', 'pagos')})` : ''), plural(preview.counts.appointments, 'cita', 'citas'), plural(preview.counts.sessions, 'turno de caja', 'turnos de caja')].join(', ')}.`}
                    </p>
                    {preview.sample && rateValid && (
                      <p className="flex items-center gap-1.5 text-slate-600">Por ejemplo: {preview.sample.before} <ArrowRight size={13} /> <span className="font-semibold text-slate-800">{preview.sample.after}</span></p>
                    )}
                    {preview.counts.excluded > 0 && (
                      <p className="text-xs text-amber-800" data-excluded>
                        Quedan fuera {plural(preview.counts.excluded, 'venta con comprobante emitido', 'ventas con comprobante emitido')}, y sus citas y turnos de caja: una boleta o factura no cambia de moneda; se corrige con una nota de crédito.
                      </p>
                    )}
                  </>
                ) : (
                  <p className="text-slate-500">{previewError || 'Revisando qué registros cambiarían…'}</p>
                )}
              </div>
            )}

            {preview && previewError && <p role="alert" className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{previewError}</p>}
          </div>

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
            <Button type="button" onClick={() => setRecords(false)} variant="secondary">Cancelar</Button>
            <Button
              type="button"
              data-submit="records"
              disabled={!preview || total === 0 || !rateValid || !rangeValid || applying}
              onClick={() => setConfirming(true)} variant="primary"
            >
              {preview && total > 0 ? `Cambiar ${plural(total, 'registro', 'registros')}` : 'Cambiar registros'}
            </Button>
          </div>
        </div>
      </FormDrawer>

      <AppModal
        isOpen={confirming}
        type="confirm"
        variant={kind === 'convert' ? 'danger' : 'default'}
        title={kind === 'convert' ? '¿Pasar los montos con el tipo de cambio?' : `¿Corregir la moneda a ${current.symbol}?`}
        message={kind === 'convert'
          ? `Los montos de estos registros se recalculan y dejarán de decir lo que se cobró en ${fromSymbol}. El cambio queda anotado en el historial, pero no se deshace con un clic.`
          : `Estos registros pasan de ${fromSymbol} a ${current.symbol} con los mismos montos. El cambio queda anotado en el historial.`}
        confirmText="Sí, cambiar"
        cancelText="Cancelar"
        isLoading={applying}
        onConfirm={apply}
        onClose={() => setConfirming(false)}
      />
    </section>
  );
}
