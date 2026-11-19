'use client';

import { useCallback, useEffect, useState } from 'react';
import { Loader2, Megaphone, Pencil, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import DateField from '@/app/components/common/DateField';
import OptionCard from '@/app/components/common/OptionCard';
import RichTextEditor from '@/app/components/common/RichTextEditor';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError, formatDate, todayISO } from '@/features/appointments/appointments';
import { KIND_TONE, type Announcement } from '../announcements';

interface Option {
  value: string;
  label: string;
}

interface Payload {
  ready: boolean;
  message?: string;
  items: Announcement[];
  kinds: Option[];
  audiences: Option[];
  plans: Option[];
  business_types: Option[];
}

const STATE: Record<Announcement['state'], { label: string; tone: string }> = {
  scheduled: { label: 'Programado', tone: 'bg-sky-50 text-sky-700' },
  live: { label: 'Visible', tone: 'bg-emerald-50 text-emerald-700' },
  ended: { label: 'Terminado', tone: 'bg-slate-100 text-slate-600' },
};

const TABS = [
  { id: 'live', label: 'Visibles' },
  { id: 'scheduled', label: 'Programados' },
  { id: 'ended', label: 'Terminados' },
];

const toDay = (iso: string | null) => {
  if (!iso) return '';
  const date = new Date(iso);
  return date.toLocaleDateString('en-CA', { timeZone: 'America/Lima' });
};

const lastDay = (iso: string | null) => {
  if (!iso) return '';
  const date = new Date(new Date(iso).getTime() - 60000);
  return date.toLocaleDateString('en-CA', { timeZone: 'America/Lima' });
};

const EMPTY = { title: '', body: '', kind: 'news', audience: 'all', plan_ids: [] as string[], business_type_ids: [] as string[], starts_on: '', ends_on: '' };

export default function PlatformAnnouncements() {
  const [data, setData] = useState<Payload | null>(null);
  const [tab, setTab] = useState('live');
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<Announcement | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const [removing, setRemoving] = useState<Announcement | null>(null);

  const load = useCallback(() => {
    api.get<Payload>('/admin/announcements').then((res) => { setData(res.data); setError(''); }).catch((err) => setError(apiError(err, 'No se pudieron cargar los anuncios.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const startNew = () => {
    setEditing(null);
    setForm({ ...EMPTY, starts_on: todayISO() });
    setFormError('');
    setOpen(true);
  };

  const startEdit = (a: Announcement) => {
    setEditing(a);
    setForm({ title: a.title, body: a.body, kind: a.kind, audience: a.audience, plan_ids: a.plan_ids, business_type_ids: a.business_type_ids, starts_on: toDay(a.starts_at), ends_on: lastDay(a.ends_at) });
    setFormError('');
    setOpen(true);
  };

  const toggle = (key: 'plan_ids' | 'business_type_ids', id: string) =>
    setForm((f) => ({ ...f, [key]: f[key].includes(id) ? f[key].filter((x) => x !== id) : [...f[key], id] }));

  const save = async () => {
    try {
      setBusy(true);
      setFormError('');
      const payload = { ...form, ends_on: form.ends_on || null };
      if (editing) await api.put(`/admin/announcements/${editing.id}`, payload);
      else await api.post('/admin/announcements', payload);
      setNotice(editing ? 'Anuncio guardado.' : 'Anuncio publicado.');
      setOpen(false);
      load();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar el anuncio.'));
    } finally {
      setBusy(false);
    }
  };

  const remove = async () => {
    if (!removing) return;
    try {
      setBusy(true);
      await api.delete(`/admin/announcements/${removing.id}`);
      setNotice('Anuncio eliminado.');
      setRemoving(null);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el anuncio.'));
    } finally {
      setBusy(false);
    }
  };

  const items = data?.items ?? [];
  const term = search.trim().toLowerCase();
  const shown = items.filter((a) => a.state === tab).filter((a) => !term || a.title.toLowerCase().includes(term));
  const audienceText = (a: Announcement) => {
    if (a.audience === 'plans') return (data?.plans ?? []).filter((p) => a.plan_ids.includes(p.value)).map((p) => p.label).join(', ') || a.audience_label;
    if (a.audience === 'business_types') return (data?.business_types ?? []).filter((b) => a.business_type_ids.includes(b.value)).map((b) => b.label).join(', ') || a.audience_label;
    return a.audience_label;
  };

  const columns: Column<Announcement>[] = [
    { header: 'Anuncio', cell: (a) => <span className="block"><span className="block font-semibold text-slate-800">{a.title}</span><Badge tone={KIND_TONE[a.kind]} bold className="inline-block mt-1">{a.kind_label}</Badge></span> },
    { header: 'Para', cell: (a) => <span className="text-sm">{audienceText(a)} <span className="block text-xs text-slate-500">{a.reach} {a.reach === 1 ? 'empresa' : 'empresas'}</span></span> },
    { header: 'Se ve', cell: (a) => `Desde ${formatDate(toDay(a.starts_at), { day: 'numeric', month: 'short' })}${a.ends_at ? ` hasta ${formatDate(lastDay(a.ends_at), { day: 'numeric', month: 'short' })}` : ', sin fecha de fin'}` },
    { header: 'Lo cerraron', cell: (a) => `${a.dismissals} ${a.dismissals === 1 ? 'persona' : 'personas'}` },
    { header: 'Estado', cell: (a) => <Badge tone={STATE[a.state].tone} bold>{STATE[a.state].label}</Badge> },
    {
      header: 'Acciones',
      cell: (a) => (
        <span className="flex items-center gap-1">
          <button type="button" aria-label="Editar" onClick={() => startEdit(a)} className="p-2 rounded-lg text-slate-500 hover:text-brand-ink hover:bg-slate-100 cursor-pointer"><Pencil size={15} /></button>
          <button type="button" aria-label="Eliminar" onClick={() => setRemoving(a)} className="p-2 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer"><Trash2 size={15} /></button>
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-platform-announcements>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Megaphone className="w-6 h-6 text-brand-accent" /> Anuncios</h1>
        <p className="text-sm text-slate-500 font-medium">Novedades y avisos para las empresas. Salen en su Inicio y en la campana mientras estén en sus fechas; cada persona puede cerrarlos y no le vuelven a salir.</p>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error || (data && !data.ready ? data.message ?? '' : '')} />

      <DataTable<Announcement>
        title="Anuncios"
        primaryActionLabel={data?.ready ? 'Nuevo anuncio' : undefined}
        onPrimaryAction={data?.ready ? startNew : undefined}
        tabs={TABS.map((t) => ({ ...t, count: items.filter((a) => a.state === t.id).length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(a) => a.id}
        entityLabel={shown.length === 1 ? 'anuncio' : 'anuncios'}
        sortOptions={[{ id: 'start', label: 'Fecha de inicio', type: 'date', value: (a) => a.starts_at }]}
      />

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title={editing ? `Editar «${editing.title}»` : 'Nuevo anuncio'} subtitle="Lo verán las empresas en su Inicio" icon={<Megaphone size={18} />} maxWidth="max-w-2xl">
        <div className="space-y-4" data-announcement-form>
          <div>
            <label className={labelClass} htmlFor="announcement-title">Título</label>
            <input id="announcement-title" maxLength={150} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className={inputClass} placeholder="Ej. Nuevo reporte de ventas por sede" />
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Tipo</label>
              <Select ariaLabel="Tipo" clearable={false} value={form.kind} onChange={(e) => setForm({ ...form, kind: String(e.target.value) })} options={data?.kinds ?? []} />
            </div>
            <div>
              <label className={labelClass}>Para quién</label>
              <Select ariaLabel="Para quién" clearable={false} value={form.audience} onChange={(e) => setForm({ ...form, audience: String(e.target.value) })} options={data?.audiences ?? []} />
            </div>
          </div>
          {form.audience === 'plans' && (
            <div className="flex flex-wrap gap-2" data-announcement-plans>
              {(data?.plans ?? []).map((p) => (
                <OptionCard key={p.value} type="button" selected={form.plan_ids.includes(p.value)} onClick={() => toggle('plan_ids', p.value)} className="px-3 py-2 rounded-xl border text-sm font-semibold cursor-pointer">{p.label}</OptionCard>
              ))}
            </div>
          )}
          {form.audience === 'business_types' && (
            <div className="flex flex-wrap gap-2" data-announcement-types>
              {(data?.business_types ?? []).map((b) => (
                <OptionCard key={b.value} type="button" selected={form.business_type_ids.includes(b.value)} onClick={() => toggle('business_type_ids', b.value)} className="px-3 py-2 rounded-xl border text-sm font-semibold cursor-pointer">{b.label}</OptionCard>
              ))}
            </div>
          )}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={labelClass}>Desde</label>
              <DateField ariaLabel="Desde" value={form.starts_on} onChange={(value) => setForm({ ...form, starts_on: value })} required />
            </div>
            <div>
              <label className={labelClass}>Hasta (opcional)</label>
              <DateField ariaLabel="Hasta" value={form.ends_on} min={form.starts_on || undefined} clearable onChange={(value) => setForm({ ...form, ends_on: value })} />
            </div>
          </div>
          <div>
            <label className={labelClass}>Texto</label>
            <RichTextEditor value={form.body} onChange={(html) => setForm((f) => ({ ...f, body: html }))} minHeight={160} />
          </div>
          <ErrorAlert message={formError} />
          <button type="button" data-announcement-save disabled={busy || form.title.trim().length < 3 || !form.starts_on} onClick={save} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={16} className="animate-spin" />} {editing ? 'Guardar cambios' : 'Publicar anuncio'}
          </button>
        </div>
      </FormDrawer>

      <AppModal
        isOpen={Boolean(removing)}
        type="confirm"
        variant="danger"
        title="¿Eliminar el anuncio?"
        message={`«${removing?.title ?? ''}» deja de verse en todas las empresas.`}
        confirmText="Eliminar"
        cancelText="Cancelar"
        onConfirm={remove}
        onClose={() => setRemoving(null)}
        isLoading={busy}
      />
    </div>
  );
}
