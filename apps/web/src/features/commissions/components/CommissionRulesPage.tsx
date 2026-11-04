'use client';

import { useCallback, useEffect, useState } from 'react';
import { Calculator, Plus } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { ProfessionalLink } from '@/app/components/common/PersonLink';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { api } from '@/lib/axios';
import { formatMoney } from '@/lib/currency';
import { useVocabulary } from '@/hooks/useVocabulary';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount } from '@/features/sales/sales';
import { percentText, type CommissionRule, type RulePreview, type RulesPayload, type RuleType } from '../commissions';
import FormCommissionRule from './FormCommissionRule';

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden';
const th = 'text-left text-[11px] font-bold text-slate-400 uppercase tracking-wider border-y border-slate-100 bg-slate-50';

function SectionHead({ number, title, hint, action }: { number?: number; title: string; hint: string; action?: React.ReactNode }) {
  return (
    <div className="px-6 pt-5 pb-3 flex flex-wrap items-start justify-between gap-3">
      <div className="max-w-3xl">
        <h2 className="text-sm font-extrabold text-brand-ink uppercase tracking-wider flex items-center gap-2">
          {number !== undefined && <span className="w-6 h-6 rounded-full bg-brand text-white text-xs flex items-center justify-center">{number}</span>}
          {title}
        </h2>
        <p className="text-xs text-slate-500 font-medium mt-1.5 leading-relaxed">{hint}</p>
      </div>
      {action}
    </div>
  );
}

export default function CommissionRulesPage() {
  const vocab = useVocabulary();
  const pro = vocab.pro.toLowerCase();
  const [data, setData] = useState<RulesPayload | null>(null);
  const [percents, setPercents] = useState<Record<string, string>>({});
  const [form, setForm] = useState<{ type: RuleType; rule: CommissionRule | null } | null>(null);
  const [deleting, setDeleting] = useState<CommissionRule | null>(null);
  const [check, setCheck] = useState({ professional: '', item: '' });
  const [preview, setPreview] = useState<RulePreview | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  const load = useCallback(() => {
    api.get<RulesPayload>('/commission-rules')
      .then((res) => {
        setData(res.data);
        setPercents(Object.fromEntries(res.data.professionals.map((p) => [p.id, p.commission_percent === null ? '' : String(Number(p.commission_percent))])));
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar las comisiones.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const runPreview = useCallback((professional: string, item: string) => {
    if (!professional || !item) { setPreview(null); return; }
    const [kind, id] = item.split(':');
    api.get<RulePreview>('/commission-rules/preview', { params: { professional_id: professional, kind, reference_id: id } })
      .then((res) => setPreview(res.data))
      .catch((err) => setPreview({ found: false, message: apiError(err, 'No se pudo calcular.') }));
  }, []);

  const changeCheck = (changes: Partial<typeof check>) => {
    const next = { ...check, ...changes };
    setCheck(next);
    runPreview(next.professional, next.item);
  };

  const refresh = (message: string) => {
    setNotice(message);
    load();
    runPreview(check.professional, check.item);
  };

  const rules = data?.rules ?? [];
  const services = rules.filter((r) => r.type === 'service');
  const exceptions = rules.filter((r) => r.type === 'exception');
  const products = rules.filter((r) => r.type === 'product');
  const canEdit = Boolean(data?.can_edit);
  const amount = (r: CommissionRule) => [r.percent > 0 ? percentText(r.percent) : '', r.fixed > 0 ? `${formatMoney(r.fixed)} ${r.type === 'product' ? 'por unidad' : 'por atención'}` : ''].filter(Boolean).join(' + ');

  const open = (type: RuleType, rule: CommissionRule | null = null) => setForm({ type, rule });

  const savePercent = async (id: string) => {
    const professional = data?.professionals.find((p) => p.id === id);
    const value = percents[id] ?? '';
    const before = professional?.commission_percent === null || professional?.commission_percent === undefined ? '' : String(Number(professional.commission_percent));
    if (value === before) return;
    try {
      await api.put(`/professionals/${id}`, { commission_percent: value === '' ? null : Number(value) });
      refresh(`Porcentaje de ${professional?.name} guardado.`);
      setError('');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el porcentaje.'));
    }
  };

  const remove = async () => {
    if (!deleting) return;
    setBusy(true);
    try {
      const res = await api.delete<{ message: string }>(`/commission-rules/${deleting.id}`);
      setDeleting(null);
      setForm(null);
      refresh(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo quitar la comisión.'));
    } finally {
      setBusy(false);
    }
  };

  const state = (r: CommissionRule) => <Badge tone={r.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'} bold nowrap>{r.active ? 'Activa' : 'Apagada'}</Badge>;
  const editCell = (r: CommissionRule) => canEdit ? (
    <button type="button" data-edit-rule={r.id} onClick={() => open(r.type, r)} className="text-sm font-semibold text-brand-accent hover:text-brand-ink">Cambiar</button>
  ) : null;
  const addButton = (type: RuleType, label: string) => canEdit ? (
    <button type="button" data-add={type} onClick={() => open(type)} className="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-brand text-white text-sm font-semibold hover:bg-brand-hover">
      <Plus size={16} /> {label}
    </button>
  ) : null;
  const empty = (text: string, cols: number) => <tr><td colSpan={cols} className="px-6 py-4 text-sm text-slate-400">{text}</td></tr>;

  const itemOptions = [
    ...(data?.services ?? []).map((s) => ({ value: `service:${s.id}`, label: s.name })),
    ...(data?.products ? (data.product_list ?? []).map((p) => ({ value: `product:${p.id}`, label: `Producto: ${p.name}` })) : []),
  ];

  return (
    <div className="space-y-6 mb-8" data-commission-rules>
      <BackLink href="/commissions">Comisiones</BackLink>

      <div>
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">Reglas de comisión</h1>
        <p className="text-sm text-slate-500 font-medium mt-1 max-w-3xl">
          Para cada servicio se mira de abajo hacia arriba: si la persona tiene una <b>excepción</b> (3), se usa esa; si no, la <b>comisión del servicio</b> (2); si no, <b>su porcentaje</b> (1). No puede haber dos comisiones para el mismo caso.
        </p>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <section className={card} data-section="check">
        <SectionHead title="¿Cuánto gana?" hint={`Elige a un ${pro} y un servicio${data?.products ? ' o producto' : ''} para ver qué comisión se le aplica y cuánto gana.`} />
        <div className="px-6 pb-5 space-y-3">
          <div className="grid gap-3 md:grid-cols-2">
            <Select ariaLabel={vocab.pro} placeholder={`Elige al ${pro}`} value={check.professional} onChange={(e) => changeCheck({ professional: String(e.target.value) })} options={(data?.professionals ?? []).map((p) => ({ value: p.id, label: p.name }))} />
            <Select ariaLabel="Servicio o producto" placeholder={data?.products ? 'Elige el servicio o producto' : 'Elige el servicio'} value={check.item} onChange={(e) => changeCheck({ item: String(e.target.value) })} options={itemOptions} />
          </div>
          {preview && (
            <p data-preview={preview.source ?? 'none'} className={`flex items-start gap-2 text-sm rounded-xl px-4 py-3 border ${preview.found ? 'bg-brand-soft/15 border-brand-soft/50 text-brand-ink' : 'bg-slate-50 border-slate-200 text-slate-600'}`}>
              <Calculator size={16} className="mt-0.5 shrink-0" /> {preview.message}
            </p>
          )}
        </div>
      </section>

      {data && (
        <section className={card} data-section="percents">
          <SectionHead number={1} title={`Lo que gana cada ${pro}`} hint="Su porcentaje en todos sus servicios, salvo los que estén en las listas 2 y 3. Vacío, no gana nada por ellos. También se cambia en su ficha." />
          <table className="w-full text-sm">
            <thead><tr className={th}><th className="px-6 py-2.5">{vocab.pro}</th><th className="px-4 py-2.5 w-48">Su porcentaje</th></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {data.professionals.length === 0 && empty(`Aún no hay ${vocab.pros.toLowerCase()}.`, 2)}
              {data.professionals.map((p) => (
                <tr key={p.id} data-percent-row={p.name}>
                  <td className="px-6 py-3 font-semibold text-slate-800"><ProfessionalLink id={p.id}>{p.name}</ProfessionalLink></td>
                  <td className="px-4 py-2">
                    {canEdit ? (
                      <div className="flex items-center gap-2">
                        <input
                          aria-label={`Porcentaje de ${p.name}`}
                          inputMode="decimal"
                          placeholder="Sin porcentaje"
                          className="w-28 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-brand/20"
                          value={percents[p.id] ?? ''}
                          onChange={(e) => { const v = cleanAmount(e.target.value); setPercents((all) => ({ ...all, [p.id]: Number(v) > 100 ? '100' : v })); }}
                          onBlur={() => savePercent(p.id)}
                          onKeyDown={(e) => { if (e.key === 'Enter') (e.target as HTMLInputElement).blur(); }}
                        />
                        <span className="text-slate-500">%</span>
                      </div>
                    ) : <span className="text-slate-700">{Number(p.commission_percent) > 0 ? percentText(Number(p.commission_percent)) : '—'}</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {data && (
        <section className={card} data-section="services">
          <SectionHead number={2} title="Servicios con otra comisión" hint={`Para todos los que atienden ese servicio. Reemplaza el porcentaje de cada ${pro} (lista 1). Puede ser un porcentaje o un monto fijo por atención.`} action={addButton('service', 'Agregar servicio')} />
          <table className="w-full text-sm">
            <thead><tr className={th}><th className="px-6 py-2.5">Servicio</th><th className="px-4 py-2.5">Comisión</th><th className="px-4 py-2.5">Estado</th><th className="px-4 py-2.5 w-24" /></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {services.length === 0 && empty(`Ningún servicio tiene otra comisión: en todos vale el porcentaje de cada ${pro}.`, 4)}
              {services.map((r) => (
                <tr key={r.id} data-rule-row={r.service ?? ''}>
                  <td className="px-6 py-3 font-semibold text-slate-800">{r.service}</td>
                  <td className="px-4 py-3 font-bold text-slate-900 whitespace-nowrap">{amount(r)}</td>
                  <td className="px-4 py-3">{state(r)}</td>
                  <td className="px-4 py-3 text-right">{editCell(r)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {data && (
        <section className={card} data-section="exceptions">
          <SectionHead number={3} title="Excepciones de una persona" hint={`Solo para un ${pro} en un servicio. Reemplaza la comisión del servicio (lista 2) y su porcentaje (lista 1). Sirve, por ejemplo, para quien gana más en un servicio que domina.`} action={addButton('exception', 'Agregar excepción')} />
          <table className="w-full text-sm">
            <thead><tr className={th}><th className="px-6 py-2.5">{vocab.pro}</th><th className="px-4 py-2.5">Servicio</th><th className="px-4 py-2.5">Comisión</th><th className="px-4 py-2.5">Estado</th><th className="px-4 py-2.5 w-24" /></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {exceptions.length === 0 && empty('No hay excepciones.', 5)}
              {exceptions.map((r) => (
                <tr key={r.id} data-rule-row={`${r.professional} en ${r.service}`}>
                  <td className="px-6 py-3 font-semibold text-slate-800"><ProfessionalLink id={r.professional_id}>{r.professional}</ProfessionalLink></td>
                  <td className="px-4 py-3 text-slate-700">{r.service}</td>
                  <td className="px-4 py-3 font-bold text-slate-900 whitespace-nowrap">{amount(r)}</td>
                  <td className="px-4 py-3">{state(r)}</td>
                  <td className="px-4 py-3 text-right">{editCell(r)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      {data?.products && (
        <section className={card} data-section="products">
          <SectionHead
            title="Productos"
            hint={`Lo que gana quien vende un producto (en el punto de venta se elige "¿Quién lo vendió?"). Un producto con su propia comisión usa esa; los demás, la de "Todos los productos". Sin ninguna, los productos no dan comisión.${data.on_products ? '' : ' Ahora mismo está apagado en Configuración → Comisiones.'}`}
            action={addButton('product', 'Agregar')}
          />
          <table className="w-full text-sm">
            <thead><tr className={th}><th className="px-6 py-2.5">Producto</th><th className="px-4 py-2.5">Comisión</th><th className="px-4 py-2.5">Estado</th><th className="px-4 py-2.5 w-24" /></tr></thead>
            <tbody className="divide-y divide-slate-100">
              {products.length === 0 && empty('Los productos no dan comisión.', 4)}
              {[...products].sort((a, b) => (a.product_id ? 1 : 0) - (b.product_id ? 1 : 0)).map((r) => (
                <tr key={r.id} data-rule-row={r.product ?? 'Todos los productos'}>
                  <td className="px-6 py-3 font-semibold text-slate-800">{r.product ?? 'Todos los productos'}</td>
                  <td className="px-4 py-3 font-bold text-slate-900 whitespace-nowrap">{amount(r)}</td>
                  <td className="px-4 py-3">{state(r)}</td>
                  <td className="px-4 py-3 text-right">{editCell(r)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}

      <p className="text-xs text-slate-500 px-1">Los cambios valen para lo que se cobre desde ahora. Para aplicarlos a un mes que ya pasó, usa Recalcular el mes en Comisiones.</p>

      <FormCommissionRule
        isOpen={form !== null}
        type={form?.type ?? 'service'}
        rule={form?.rule ?? null}
        options={data}
        onClose={() => setForm(null)}
        onSaved={refresh}
        onDelete={setDeleting}
        onExisting={(id) => { const found = rules.find((r) => r.id === id); if (found) setForm({ type: found.type, rule: found }); }}
      />

      <AppModal
        isOpen={deleting !== null}
        type="confirm"
        variant="danger"
        title="¿Quitar esta comisión?"
        message="Desde ahora se usará lo que sigue en la lista. Las comisiones ya calculadas no cambian. Si solo quieres dejar de usarla por un tiempo, apágala."
        confirmText="Sí, quitar"
        cancelText="Cancelar"
        isLoading={busy}
        onConfirm={remove}
        onClose={() => setDeleting(null)}
      />
    </div>
  );
}
