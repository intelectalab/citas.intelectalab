'use client';

import { useCallback, useEffect, useState } from 'react';
import { Loader2, PackagePlus, Pencil, Trash2 } from 'lucide-react';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';

interface Addon {
  id: string;
  code: string;
  name: string;
  description: string | null;
  monthly_price: number;
  currency: string;
  module_key: string | null;
  limit_key: string | null;
  limit_amount: number;
  max_quantity: number;
  status: 'active' | 'inactive';
  presentation_order: number;
  tenants: number;
}

const EMPTY = { name: '', description: '', monthly_price: '', limit_key: '', limit_amount: '1', module_key: '', max_quantity: '1', status: 'active' as 'active' | 'inactive', presentation_order: '0' };

export default function SaaSAddonsTable() {
  const [rows, setRows] = useState<Addon[]>([]);
  const [limits, setLimits] = useState<{ key: string; label: string }[]>([]);
  const [modules, setModules] = useState<{ key: string; name: string }[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [editing, setEditing] = useState<Addon | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [formError, setFormError] = useState('');
  const [busy, setBusy] = useState(false);
  const [deleting, setDeleting] = useState('');

  const load = useCallback(() => {
    api.get('/admin/addons')
      .then((res) => { setRows(res.data.addons); setLimits(res.data.limits); setModules(res.data.modules); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los complementos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const limitLabel = (key: string | null) => limits.find((item) => item.key === key)?.label ?? key ?? '';
  const moduleLabel = (key: string | null) => modules.find((item) => item.key === key)?.name ?? key ?? '';

  const start = (addon: Addon | null) => {
    setEditing(addon);
    setFormError('');
    setForm(addon ? {
      name: addon.name, description: addon.description ?? '', monthly_price: String(addon.monthly_price), limit_key: addon.limit_key ?? '', limit_amount: String(addon.limit_amount || 1),
      module_key: addon.module_key ?? '', max_quantity: String(addon.max_quantity), status: addon.status, presentation_order: String(addon.presentation_order),
    } : EMPTY);
    setOpen(true);
  };

  const save = async () => {
    try {
      setBusy(true);
      setFormError('');
      const body = {
        name: form.name, description: form.description || null, monthly_price: Number(form.monthly_price || 0), limit_key: form.limit_key || null,
        limit_amount: form.limit_key ? Number(form.limit_amount || 0) : 0, module_key: form.module_key || null, max_quantity: Number(form.max_quantity || 1),
        status: form.status, presentation_order: Number(form.presentation_order || 0),
      };
      if (editing) await api.put(`/admin/addons/${editing.id}`, body);
      else await api.post('/admin/addons', body);
      setOpen(false);
      setNotice(editing ? 'Listo: el complemento quedó cambiado. Las empresas que ya lo tienen siguen pagando el precio con el que lo contrataron.' : 'Listo: el complemento quedó creado.');
      load();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async (addon: Addon) => {
    try {
      setDeleting(addon.id);
      await api.delete(`/admin/addons/${addon.id}`);
      setNotice('Complemento eliminado.');
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar.'));
    } finally {
      setDeleting('');
    }
  };

  const columns: Column<Addon>[] = [
    {
      header: 'Complemento',
      cell: (row) => (
        <div key={`n-${row.id}`} className="max-w-[20rem]">
          <button type="button" onClick={() => start(row)} className="block text-sm font-semibold text-slate-800 hover:text-brand-accent text-left">{row.name}</button>
          <span className="block text-xs text-slate-500 truncate">{row.description ?? '—'}</span>
        </div>
      ),
    },
    { header: 'Precio', cell: (row) => <span key={`p-${row.id}`} className="text-sm font-bold text-slate-800 whitespace-nowrap">{money(row.monthly_price, row.currency)} al mes</span> },
    {
      header: 'Qué suma',
      cell: (row) => (
        <span key={`w-${row.id}`} className="text-sm text-slate-700">
          {[row.limit_key ? `${limitLabel(row.limit_key)}: +${row.limit_amount}` : null, row.module_key ? `Enciende ${moduleLabel(row.module_key)}` : null].filter(Boolean).join(' · ')}
        </span>
      ),
    },
    { header: 'Empresas', cell: (row) => <span key={`t-${row.id}`} className="text-sm text-slate-700">{row.tenants}</span> },
    { header: 'Estado', cell: (row) => <Badge key={`s-${row.id}`} tone={row.status === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'} nowrap>{row.status === 'active' ? 'Se ofrece' : 'Apagado'}</Badge> },
    {
      header: 'Acciones',
      cell: (row) => (
        <div key={`a-${row.id}`} className="flex items-center gap-1">
          <button type="button" data-addon-edit={row.id} onClick={() => start(row)} title="Editar" aria-label="Editar" className="p-1.5 text-slate-400 hover:text-brand-accent hover:bg-brand-soft/20 rounded-lg"><Pencil size={16} /></button>
          {row.tenants === 0 && (
            <button type="button" data-addon-delete={row.id} disabled={deleting !== ''} onClick={() => remove(row)} title="Eliminar" aria-label="Eliminar" className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg">
              {deleting === row.id ? <Loader2 size={16} className="animate-spin" /> : <Trash2 size={16} />}
            </button>
          )}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-3" data-saas-addons>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      <DataTable<Addon>
        title="Complementos"
        primaryActionLabel="Nuevo complemento"
        onPrimaryAction={() => start(null)}
        columns={columns}
        data={rows}
        getItemKey={(row) => row.id}
        entityLabel={rows.length === 1 ? 'complemento' : 'complementos'}
      />
      <p className="text-xs text-slate-500 px-1">Las empresas los suman desde Mi empresa → Plan. Se cobran junto con el plan (al año, 10 meses) y amplían lo que dice «Qué suma». Al contratar uno a mitad de periodo se cobran solo los días que faltan; al quitarlo, sigue hasta el fin del periodo.</p>

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title={editing ? `Editar ${editing.name}` : 'Nuevo complemento'} subtitle="Un extra que las empresas suman a su plan" icon={<PackagePlus size={18} />}>
        <div className="space-y-4" data-addon-form>
          <div><label className={labelClass} htmlFor="ad-name">Nombre</label><input id="ad-name" maxLength={120} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className={inputClass} placeholder="Ej. Sede adicional" /></div>
          <div><label className={labelClass} htmlFor="ad-desc">Descripción para la empresa</label><input id="ad-desc" maxLength={300} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className={inputClass} placeholder="Ej. Una sede más con su propia agenda y caja" /></div>
          <div className="grid grid-cols-2 gap-3">
            <div><label className={labelClass} htmlFor="ad-price">Precio al mes</label><input id="ad-price" inputMode="decimal" value={form.monthly_price} onChange={(e) => setForm({ ...form, monthly_price: e.target.value.replace(/[^\d.]/g, '') })} className={inputClass} placeholder="39" /></div>
            <div><label className={labelClass} htmlFor="ad-max">Máximo por empresa</label><input id="ad-max" inputMode="numeric" value={form.max_quantity} onChange={(e) => setForm({ ...form, max_quantity: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Select label="Qué amplía" options={limits.map((item) => ({ value: item.key, label: item.label }))} value={form.limit_key} onChange={(e) => setForm({ ...form, limit_key: String(e.target.value) })} clearable placeholder="Nada" />
            {form.limit_key && <div><label className={labelClass} htmlFor="ad-amount">Cuánto suma cada uno</label><input id="ad-amount" inputMode="numeric" value={form.limit_amount} onChange={(e) => setForm({ ...form, limit_amount: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>}
          </div>
          <Select label="Módulo que enciende (opcional)" options={modules.map((item) => ({ value: item.key, label: item.name }))} value={form.module_key} onChange={(e) => setForm({ ...form, module_key: String(e.target.value) })} clearable placeholder="Ninguno" />
          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span className="text-sm font-semibold text-slate-700">Se ofrece a las empresas</span>
            <Switch checked={form.status === 'active'} onChange={(on) => setForm({ ...form, status: on ? 'active' : 'inactive' })} label="Se ofrece a las empresas" />
          </div>
          <ErrorAlert message={formError} />
          <button type="button" data-addon-save disabled={busy || !form.name || form.monthly_price === ''} onClick={save} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy && <Loader2 size={15} className="animate-spin" />} Guardar
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
