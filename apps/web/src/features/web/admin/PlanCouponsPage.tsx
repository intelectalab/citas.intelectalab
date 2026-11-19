'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Loader2, Pencil, Tag, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import OptionCard from '@/app/components/common/OptionCard';
import Switch from '@/app/components/common/Switch';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError, formatDate } from '@/features/appointments/appointments';

interface Option {
  value: string;
  label: string;
}

interface Coupon {
  id: string;
  code: string;
  description: string | null;
  kind: 'percent' | 'amount';
  value: number;
  months: number;
  max_uses: number | null;
  used: number;
  valid_until: string | null;
  active: boolean;
  plan_ids: string[];
  plan_names: string[];
  state: 'active' | 'inactive' | 'expired' | 'exhausted';
  text: string;
  tenants: { id: string; name: string; at: string; cycles_left: number }[];
}

const STATE: Record<Coupon['state'], { label: string; tone: string }> = {
  active: { label: 'Vigente', tone: 'bg-emerald-50 text-emerald-700' },
  inactive: { label: 'Desactivado', tone: 'bg-slate-100 text-slate-600' },
  expired: { label: 'Vencido', tone: 'bg-rose-50 text-rose-700' },
  exhausted: { label: 'Agotado', tone: 'bg-amber-50 text-amber-800' },
};

const TABS = [
  { id: 'active', label: 'Vigentes' },
  { id: 'off', label: 'Vencidos, agotados o desactivados' },
];

const EMPTY = { code: '', description: '', kind: 'percent' as 'percent' | 'amount', value: '20', months: '3', max_uses: '', valid_until: '', active: true, plan_ids: [] as string[] };

export default function PlanCouponsPage() {
  const [items, setItems] = useState<Coupon[]>([]);
  const [plans, setPlans] = useState<Option[]>([]);
  const [ready, setReady] = useState(true);
  const [message, setMessage] = useState('');
  const [tab, setTab] = useState('active');
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<Coupon | null>(null);
  const [open, setOpen] = useState(false);
  const [usesOf, setUsesOf] = useState<Coupon | null>(null);
  const [form, setForm] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const [removing, setRemoving] = useState<Coupon | null>(null);

  const load = useCallback(() => {
    api.get('/admin/web/coupons').then((res) => {
      setItems(res.data.items);
      setPlans(res.data.plans);
      setReady(res.data.ready);
      setMessage(res.data.message ?? '');
    }).catch((err) => setError(apiError(err, 'No se pudieron cargar los cupones.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const startNew = () => {
    setEditing(null);
    setForm(EMPTY);
    setFormError('');
    setOpen(true);
  };

  const startEdit = (c: Coupon) => {
    setEditing(c);
    setForm({ code: c.code, description: c.description ?? '', kind: c.kind, value: String(c.value), months: String(c.months), max_uses: c.max_uses ? String(c.max_uses) : '', valid_until: c.valid_until ?? '', active: c.active, plan_ids: c.plan_ids });
    setFormError('');
    setOpen(true);
  };

  const save = async () => {
    try {
      setBusy(true);
      setFormError('');
      const payload = { ...form, value: Number(form.value), months: Number(form.months), max_uses: form.max_uses ? Number(form.max_uses) : null, valid_until: form.valid_until || null };
      if (editing) await api.put(`/admin/web/coupons/${editing.id}`, payload);
      else await api.post('/admin/web/coupons', payload);
      setNotice(editing ? 'Cupón guardado.' : 'Cupón creado. Ya se puede usar al registrarse y al pagar el plan.');
      setOpen(false);
      load();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar el cupón.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!removing) return;
    try {
      setBusy(true);
      await api.delete(`/admin/web/coupons/${removing.id}`);
      setNotice('Cupón eliminado.');
      setRemoving(null);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el cupón.'));
      setRemoving(null);
    } finally {
      setBusy(false);
    }
  };

  const term = search.trim().toLowerCase();
  const inTab = (c: Coupon, id: string) => (id === 'active' ? c.state === 'active' : c.state !== 'active');
  const shown = items.filter((c) => inTab(c, tab)).filter((c) => !term || c.code.toLowerCase().includes(term) || (c.description ?? '').toLowerCase().includes(term));
  const togglePlan = (id: string) => setForm((f) => ({ ...f, plan_ids: f.plan_ids.includes(id) ? f.plan_ids.filter((x) => x !== id) : [...f.plan_ids, id] }));

  const columns: Column<Coupon>[] = [
    { header: 'Cupón', cell: (c) => <span className="block"><span className="block font-mono font-bold text-brand-ink">{c.code}</span>{c.description && <span className="block text-xs text-slate-500">{c.description}</span>}</span> },
    { header: 'Descuento', cell: (c) => <span className="text-sm">{c.text}</span> },
    { header: 'Planes', cell: (c) => <span className="text-sm">{c.plan_names.length ? c.plan_names.join(', ') : 'Todos'}</span> },
    {
      header: 'Usos',
      cell: (c) => (
        <button type="button" data-coupon-uses={c.code} disabled={!c.tenants.length} onClick={() => setUsesOf(c)} className="text-sm font-semibold text-brand-accent hover:text-brand-ink disabled:text-slate-500 disabled:cursor-default cursor-pointer">
          {c.used}{c.max_uses ? ` de ${c.max_uses}` : ''}
        </button>
      ),
    },
    { header: 'Vence', cell: (c) => (c.valid_until ? formatDate(c.valid_until, { day: 'numeric', month: 'short', year: 'numeric' }) : 'Sin vencimiento') },
    { header: 'Estado', cell: (c) => <Badge tone={STATE[c.state].tone} bold>{STATE[c.state].label}</Badge> },
    {
      header: 'Acciones',
      cell: (c) => (
        <span className="flex items-center gap-1">
          <button type="button" aria-label="Editar" onClick={() => startEdit(c)} className="p-2 rounded-lg text-slate-500 hover:text-brand-ink hover:bg-slate-100 cursor-pointer"><Pencil size={15} /></button>
          {c.used === 0 && <button type="button" aria-label="Eliminar" onClick={() => setRemoving(c)} className="p-2 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer"><Trash2 size={15} /></button>}
        </span>
      ),
    },
  ];

  const value = Number(form.value);
  const valid = form.code.trim().length >= 4 && value > 0 && (form.kind === 'amount' || value <= 100) && Number(form.months) >= 1;

  return (
    <div className="space-y-5 pb-10 font-sans" data-plan-coupons>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Tag className="w-6 h-6 text-brand-accent" /> Cupones de planes</h1>
        <p className="text-sm text-slate-500 font-medium">Descuentos para campañas, ferias y aliados. La empresa los escribe al crear su cuenta o en Tu plan, y se descuentan en sus primeros cobros.</p>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error || (!ready ? message : '')} />

      <DataTable<Coupon>
        title="Cupones"
        primaryActionLabel={ready ? 'Nuevo cupón' : undefined}
        onPrimaryAction={ready ? startNew : undefined}
        tabs={TABS.map((t) => ({ ...t, count: items.filter((c) => inTab(c, t.id)).length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(c) => c.id}
        entityLabel={shown.length === 1 ? 'cupón' : 'cupones'}
        sortOptions={[{ id: 'used', label: 'Usos', type: 'number', value: (c) => c.used }, { id: 'code', label: 'Código', value: (c) => c.code }]}
      />

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title={editing ? `Editar ${editing.code}` : 'Nuevo cupón'} subtitle="Para registrarse o pagar el plan" icon={<Tag size={18} />}>
        <div className="space-y-4" data-coupon-form>
          <div>
            <label className={labelClass} htmlFor="coupon-code">Código</label>
            <input id="coupon-code" maxLength={30} disabled={Boolean(editing && editing.used > 0)} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase().replace(/\s/g, '') })} className={`${inputClass} font-mono uppercase`} placeholder="Ej. FERIA20" />
            {editing && editing.used > 0 && <p className="text-xs text-slate-500 mt-1">Ya se usó: el código no se puede cambiar.</p>}
          </div>
          <div>
            <label className={labelClass} htmlFor="coupon-description">Para qué es (opcional)</label>
            <input id="coupon-description" maxLength={200} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={inputClass} placeholder="Ej. Feria de belleza de noviembre" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Tipo de descuento</label>
              <Select ariaLabel="Tipo de descuento" clearable={false} value={form.kind} onChange={(e) => setForm({ ...form, kind: String(e.target.value) as 'percent' | 'amount' })} options={[{ value: 'percent', label: 'Porcentaje' }, { value: 'amount', label: 'Monto fijo por mes' }]} />
            </div>
            <div>
              <label className={labelClass} htmlFor="coupon-value">{form.kind === 'percent' ? 'Porcentaje (%)' : 'Monto (S/)'}</label>
              <input id="coupon-value" inputMode="decimal" value={form.value} onChange={(e) => setForm({ ...form, value: e.target.value.replace(/[^0-9.]/g, '') })} className={inputClass} />
            </div>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="coupon-months">Meses que aplica</label>
              <input id="coupon-months" inputMode="numeric" value={form.months} onChange={(e) => setForm({ ...form, months: e.target.value.replace(/\D/g, '') })} className={inputClass} />
            </div>
            <div>
              <label className={labelClass} htmlFor="coupon-max">Usos máximos (vacío = sin límite)</label>
              <input id="coupon-max" inputMode="numeric" value={form.max_uses} onChange={(e) => setForm({ ...form, max_uses: e.target.value.replace(/\D/g, '') })} className={inputClass} placeholder="Sin límite" />
            </div>
          </div>
          <div>
            <label className={labelClass}>Vence (opcional)</label>
            <DateField ariaLabel="Vence" value={form.valid_until} clearable onChange={(v) => setForm({ ...form, valid_until: v })} />
          </div>
          <div>
            <label className={labelClass}>Planes</label>
            <p className="text-xs text-slate-500 mb-2">Si no eliges ninguno, vale para todos.</p>
            <div className="flex flex-wrap gap-2">
              {plans.map((p) => (
                <OptionCard key={p.value} type="button" selected={form.plan_ids.includes(p.value)} onClick={() => togglePlan(p.value)} className="px-3 py-2 rounded-xl border text-sm font-semibold cursor-pointer">{p.label}</OptionCard>
              ))}
            </div>
          </div>
          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span className="text-sm font-semibold text-slate-700">Se puede usar</span>
            <Switch label="Se puede usar" checked={form.active} onChange={(v) => setForm({ ...form, active: v })} />
          </div>
          <p className="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-xl px-3 py-2">
            En el pago mensual descuenta cada mes hasta completar los meses del cupón. En el pago anual se descuenta una sola vez, en proporción a esos meses (por ejemplo, 20 % por 3 meses = 5 % del año).
          </p>
          <ErrorAlert message={formError} />
          <button type="button" data-coupon-save disabled={busy || !valid} onClick={save} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={16} className="animate-spin" />} {editing ? 'Guardar cambios' : 'Crear cupón'}
          </button>
        </div>
      </FormDrawer>

      <FormDrawer isOpen={Boolean(usesOf)} onClose={() => setUsesOf(null)} title={`Quién usó ${usesOf?.code ?? ''}`} subtitle={usesOf?.text} icon={<Tag size={18} />}>
        <ul className="divide-y divide-slate-100" data-coupon-tenants>
          {(usesOf?.tenants ?? []).map((t) => (
            <li key={t.id} className="py-3 flex items-center justify-between gap-3 text-sm">
              <Link href={`/admin/tenants/${t.id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{t.name}</Link>
              <span className="text-xs text-slate-500 text-right">{formatDate(t.at.slice(0, 10), { day: 'numeric', month: 'short', year: 'numeric' })}<br />{t.cycles_left > 0 ? `Le quedan ${t.cycles_left} ${t.cycles_left === 1 ? 'cobro' : 'cobros'}` : 'Ya lo usó todo'}</span>
            </li>
          ))}
        </ul>
      </FormDrawer>

      <AppModal
        isOpen={Boolean(removing)}
        type="confirm"
        variant="danger"
        title="¿Eliminar el cupón?"
        message={`El cupón ${removing?.code ?? ''} deja de existir. Como nadie lo usó, no afecta a ninguna empresa.`}
        confirmText="Eliminar"
        cancelText="Cancelar"
        onConfirm={remove}
        onClose={() => setRemoving(null)}
        isLoading={busy}
      />
    </div>
  );
}
