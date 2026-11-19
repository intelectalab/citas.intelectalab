'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Gift, Loader2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';

interface Config {
  enabled: boolean;
  kind: 'days' | 'discount';
  days: number;
  percent: number;
  referred_percent: number;
  referred_months: number;
}

interface Row {
  id: string;
  referrer: { id: string; name: string };
  referred: { id: string; name: string };
  status: 'pending' | 'rewarded' | 'void';
  status_label: string;
  note: string | null;
  created_at: string;
  rewarded_at: string | null;
}

interface Payload {
  ready: boolean;
  config: Config;
  kinds?: { value: string; label: string }[];
  rows: Row[];
}

const TONE = { pending: 'bg-sky-50 text-sky-700', rewarded: 'bg-emerald-50 text-emerald-700', void: 'bg-slate-100 text-slate-600' };

export default function PlatformReferrals() {
  const [data, setData] = useState<Payload | null>(null);
  const [form, setForm] = useState<Config | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [search, setSearch] = useState('');

  const apply = (payload: Payload) => {
    setData(payload);
    setForm(payload.config);
  };

  useEffect(() => {
    api.get<Payload>('/admin/referrals').then((res) => apply(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar Recomienda y gana.')));
  }, []);

  const save = async () => {
    if (!form) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.put<Payload>('/admin/referrals', form);
      apply(res.data);
      setNotice('Guardado.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const rows = data?.rows ?? [];
  const term = search.trim().toLowerCase();
  const shown = rows.filter((r) => !term || `${r.referrer.name} ${r.referred.name}`.toLowerCase().includes(term));
  const columns: Column<Row>[] = [
    { header: 'Recomendó', cell: (r) => <Link href={`/admin/tenants/${r.referrer.id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{r.referrer.name}</Link> },
    { header: 'Empresa nueva', cell: (r) => <Link href={`/admin/tenants/${r.referred.id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{r.referred.name}</Link> },
    { header: 'Se registró', cell: (r) => new Date(r.created_at).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }) },
    { header: 'Premio', cell: (r) => <span className="text-sm">{r.note ?? '—'}</span> },
    { header: 'Estado', cell: (r) => <Badge tone={TONE[r.status]} bold nowrap>{r.status_label}</Badge> },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-platform-referrals>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Gift className="w-6 h-6 text-brand-accent" /> Recomienda y gana</h1>
        <p className="text-sm text-slate-500 font-medium">Cada empresa tiene su enlace para recomendar la plataforma (lo ve quien la administra en Soporte). Cuando una empresa nueva se registra con ese enlace y paga su primer periodo, la que recomendó recibe el premio, una sola vez por empresa nueva.</p>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {data && !data.ready && <ErrorAlert message="Falta actualizar la base de datos para usar Recomienda y gana: ejecuta php artisan migrate." />}

      {form && (
        <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4" data-referral-settings>
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="text-sm font-bold text-brand-ink">Programa encendido</p>
              <p className="text-xs text-slate-500">Apagado, nadie ve su enlace ni se registran recomendaciones nuevas.</p>
            </div>
            <Switch label="Programa encendido" checked={form.enabled} onChange={(value) => setForm({ ...form, enabled: value })} />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label className={labelClass}>Premio</label>
              <Select ariaLabel="Premio" clearable={false} value={form.kind} onChange={(e) => setForm({ ...form, kind: String(e.target.value) as Config['kind'] })} options={data?.kinds ?? [{ value: 'days', label: 'Días gratis' }, { value: 'discount', label: 'Descuento en el siguiente cobro' }]} />
            </div>
            <div>
              <label className={labelClass} htmlFor="referral-days">Días gratis</label>
              <input id="referral-days" type="number" min={1} max={365} value={form.days} onChange={(e) => setForm({ ...form, days: Number(e.target.value) })} className={inputClass} />
            </div>
            <div>
              <label className={labelClass} htmlFor="referral-percent">Descuento (%)</label>
              <input id="referral-percent" type="number" min={5} max={100} disabled={form.kind !== 'discount'} value={form.percent} onChange={(e) => setForm({ ...form, percent: Number(e.target.value) })} className={inputClass} />
            </div>
          </div>
          <p className="text-xs text-slate-500">
            {form.kind === 'discount'
              ? `Se descuenta el ${form.percent} % del siguiente cobro mensual. Si la empresa paga por año, está en prueba o ya tiene otro descuento, recibe ${form.days} días gratis en su lugar.`
              : `Se suman ${form.days} días a la fecha en que vence su plan (o su prueba).`}
          </p>
          <p className="text-sm font-bold text-brand-ink pt-2">Para la empresa recomendada</p>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label className={labelClass} htmlFor="referral-referred-percent">Descuento (%)</label>
              <input id="referral-referred-percent" type="number" min={0} max={100} value={form.referred_percent ?? 20} onChange={(e) => setForm({ ...form, referred_percent: Number(e.target.value) })} className={inputClass} />
            </div>
            <div>
              <label className={labelClass} htmlFor="referral-referred-months">Meses con descuento</label>
              <input id="referral-referred-months" type="number" min={0} max={24} value={form.referred_months ?? 3} onChange={(e) => setForm({ ...form, referred_months: Number(e.target.value) })} className={inputClass} />
            </div>
          </div>
          <p className="text-xs text-slate-500">
            {(form.referred_percent ?? 20) > 0 && (form.referred_months ?? 3) > 0
              ? `Al registrarse con el enlace, sus primeros ${form.referred_months ?? 3} cobros mensuales tienen ${form.referred_percent ?? 20} % de descuento. Si paga por año, no se aplica. Pon 0 para no darle descuento.`
              : 'La empresa recomendada no recibe descuento.'}
          </p>
          <button type="button" data-referral-save disabled={busy} onClick={save} className="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={15} className="animate-spin" />} Guardar
          </button>
        </section>
      )}

      <DataTable<Row>
        title="Recomendaciones"
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(r) => r.id}
        entityLabel={shown.length === 1 ? 'recomendación' : 'recomendaciones'}
        sortOptions={[{ id: 'created', label: 'Fecha de registro', type: 'date', value: (r) => r.created_at }]}
      />
    </div>
  );
}
