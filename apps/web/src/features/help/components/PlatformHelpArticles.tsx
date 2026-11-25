'use client';

import { useCallback, useEffect, useState } from 'react';
import { LibraryBig, Loader2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import RichTextEditor from '@/app/components/common/RichTextEditor';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

interface Article {
  id: string;
  slug: string;
  title: string;
  category: string;
  summary: string | null;
  body: string;
  permission: string | null;
  link: string | null;
  replaces_topic: string | null;
  is_active: boolean;
  sort_order: number;
  updated_at: string;
}

const empty = { title: '', category: '', summary: '', body: '', permission: '', link: '', replaces_topic: '', is_active: true, sort_order: 0 };

export default function PlatformHelpArticles() {
  const [rows, setRows] = useState<Article[]>([]);
  const [permissions, setPermissions] = useState<{ value: string; label: string }[]>([]);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [tab, setTab] = useState('active');
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<Article | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(empty);
  const [busy, setBusy] = useState(false);
  const [formError, setFormError] = useState('');

  const load = useCallback(() => {
    api.get('/admin/help-articles')
      .then((res) => { setRows(res.data.items); setPermissions(res.data.permissions); setError(''); })
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los artículos.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const edit = (article: Article | null) => {
    setEditing(article);
    setForm(article ? { title: article.title, category: article.category, summary: article.summary ?? '', body: article.body, permission: article.permission ?? '', link: article.link ?? '', replaces_topic: article.replaces_topic ?? '', is_active: article.is_active, sort_order: article.sort_order } : empty);
    setFormError('');
    setOpen(true);
  };

  const save = async () => {
    try {
      setBusy(true);
      setFormError('');
      const body = { ...form, summary: form.summary || null, permission: form.permission || null, link: form.link || null, replaces_topic: form.replaces_topic || null };
      if (editing) await api.put(`/admin/help-articles/${editing.id}`, body);
      else await api.post('/admin/help-articles', body);
      setOpen(false);
      setNotice(editing ? 'Artículo guardado.' : 'Artículo creado. Ya lo ve el equipo de cada empresa que tiene el permiso.');
      load();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar el artículo.'));
    } finally {
      setBusy(false);
    }
  };

  const toggle = async (article: Article) => {
    try {
      await api.put(`/admin/help-articles/${article.id}`, { is_active: !article.is_active });
      setNotice(article.is_active ? 'Artículo desactivado: ya no se ve en la autoayuda.' : 'Artículo activado.');
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar el artículo.'));
    }
  };

  const tabs = [
    { id: 'active', label: 'Activos', test: (a: Article) => a.is_active },
    { id: 'inactive', label: 'Desactivados', test: (a: Article) => !a.is_active },
    { id: 'all', label: 'Todos', test: () => true },
  ];
  const term = search.trim().toLowerCase();
  const shown = rows.filter(tabs.find((t) => t.id === tab)?.test ?? (() => true)).filter((a) => !term || [a.title, a.category, a.summary].some((v) => (v ?? '').toLowerCase().includes(term)));
  const permissionLabel = (key: string | null) => (key ? permissions.find((p) => p.value === key)?.label ?? key : 'Todo el equipo');

  const columns: Column<Article>[] = [
    { header: 'Artículo', cell: (a) => <button type="button" data-article-edit={a.slug} onClick={() => edit(a)} className="block text-left"><span className="block font-bold text-brand-accent hover:text-brand-ink">{a.title}</span><span className="block text-xs text-slate-500">{a.category}{a.link ? ` · abre ${a.link}` : ''}</span></button> },
    { header: 'Quién lo ve', cell: (a) => <span className="text-sm">{permissionLabel(a.permission)}</span> },
    { header: 'Reemplaza', cell: (a) => a.replaces_topic ?? '—' },
    { header: 'Estado', cell: (a) => <span className="flex items-center gap-2"><Switch checked={a.is_active} onChange={() => toggle(a)} label={a.is_active ? 'Desactivar' : 'Activar'} /><Badge tone={a.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}>{a.is_active ? 'Activo' : 'Desactivado'}</Badge></span> },
    { header: 'Cambio', cell: (a) => <span className="text-sm">{momentOf(a.updated_at)}</span> },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-help-articles-page>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><LibraryBig className="w-6 h-6 text-brand-accent" /> Artículos de ayuda</h1>
        <p className="text-sm text-slate-500 font-medium">Artículos que se suman a la autoayuda de todas las empresas. Puedes limitar quién los ve con un permiso y reemplazar o apagar la guía de una pantalla escribiendo su dirección (por ejemplo /agenda).</p>
      </div>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <DataTable<Article>
        title="Artículos"
        primaryActionLabel="Nuevo artículo"
        onPrimaryAction={() => edit(null)}
        tabs={tabs.map((t) => ({ id: t.id, label: t.label, count: rows.filter(t.test).length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(a) => a.id}
        entityLabel={shown.length === 1 ? 'artículo' : 'artículos'}
      />

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title={editing ? 'Editar artículo' : 'Nuevo artículo'} subtitle="Se ve en la autoayuda de cada empresa" icon={<LibraryBig size={18} />}>
        <div className="space-y-4" data-article-form>
          <div><label className={labelClass} htmlFor="art-title">Título</label><input id="art-title" maxLength={150} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} className={inputClass} placeholder="Ej. Cómo reprogramar una cita" /></div>
          <div><label className={labelClass} htmlFor="art-category">Categoría</label><input id="art-category" maxLength={80} value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })} className={inputClass} placeholder="Ej. Agenda" /></div>
          <div><label className={labelClass} htmlFor="art-summary">Resumen (opcional)</label><input id="art-summary" maxLength={255} value={form.summary} onChange={(e) => setForm({ ...form, summary: e.target.value })} className={inputClass} /></div>
          <div><label className={labelClass}>Contenido</label><RichTextEditor value={form.body} onChange={(html) => setForm((f) => ({ ...f, body: html }))} minHeight={200} sitePaths /></div>
          <div><label className={labelClass}>Quién lo ve</label><Select ariaLabel="Quién lo ve" value={form.permission} onChange={(e) => setForm({ ...form, permission: String(e.target.value ?? '') })} options={permissions} placeholder="Todo el equipo" /></div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label className={labelClass} htmlFor="art-link">Pantalla que abre</label><input id="art-link" maxLength={255} value={form.link} onChange={(e) => setForm({ ...form, link: e.target.value })} className={inputClass} placeholder="/agenda" /></div>
            <div><label className={labelClass} htmlFor="art-replaces">Reemplaza la guía de</label><input id="art-replaces" maxLength={120} value={form.replaces_topic} onChange={(e) => setForm({ ...form, replaces_topic: e.target.value })} className={inputClass} placeholder="/agenda (opcional)" /></div>
          </div>
          <div className="flex items-center justify-between gap-3">
            <span className="text-sm font-semibold text-slate-800">Activo</span>
            <Switch checked={form.is_active} onChange={(v) => setForm({ ...form, is_active: v })} label="Activo" />
          </div>
          <ErrorAlert message={formError} />
          <button type="button" data-article-save disabled={busy || !form.title.trim() || !form.category.trim()} onClick={save} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy && <Loader2 size={16} className="animate-spin" />} Guardar artículo
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
