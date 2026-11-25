'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import { AlertTriangle, CheckCircle2, ExternalLink, ImagePlus, Loader2, Newspaper, Pencil, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import RichTextEditor from '@/app/components/common/RichTextEditor';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { DataTable, type Column } from '@/app/components/ui/table/DataTable';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import type { WebPost } from '@/features/web/web';

type AdminPost = WebPost & { seo_warnings: string[]; leads?: number; signups?: number };

interface Option {
  value: string;
  label: string;
}

const TABS = [
  { id: 'published', label: 'Publicados' },
  { id: 'draft', label: 'Borradores' },
];

const slugify = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);

const EMPTY = { title: '', slug: '', excerpt: '', body: '', business_type: '', seo_title: '', seo_description: '', author: '', keyword: '', status: 'draft' as 'draft' | 'published' };

const plainText = (html: string) => html.replace(/<[^>]+>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ').trim();
const fold = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

function liveWarnings(form: typeof EMPTY, hasCover: boolean): string[] {
  const out: string[] = [];
  if (!form.seo_title.trim()) out.push('Falta el título para buscadores.');
  else if (form.seo_title.length > 65) out.push(`El título para buscadores pasa de 65 caracteres (${form.seo_title.length}): Google lo cortará.`);
  if (!form.seo_description.trim()) out.push('Falta la descripción para buscadores.');
  else if (form.seo_description.length > 160) out.push(`La descripción para buscadores pasa de 160 caracteres (${form.seo_description.length}).`);
  if (!hasCover) out.push('No tiene portada: al compartirlo saldrá sin imagen.');
  const text = plainText(form.body);
  const words = text ? text.split(' ').length : 0;
  if (!form.excerpt.trim()) out.push('Falta el resumen: es lo que se ve en la lista del blog.');
  if (words < 300) out.push(`El artículo es corto (${words} palabras): a Google le van mejor los de 300 palabras o más.`);
  if (!/<h[23][\s>]/i.test(form.body)) out.push('No tiene subtítulos: divide el texto con al menos un subtítulo.');
  if (/<img\b(?![^>]*\balt="[^"]+")[^>]*>/i.test(form.body)) out.push('Hay imágenes sin texto alternativo: descríbelas para buscadores y lectores de pantalla.');
  if (!/href="\/(register|precios|rubros|blog)/i.test(form.body)) out.push('No enlaza a otras páginas del sitio (por ejemplo a Precios o a la prueba gratis).');
  const keyword = fold(form.keyword.trim());
  if (!keyword) {
    out.push('Falta la palabra clave: la frase que la gente escribiría en Google para encontrarlo.');
  } else {
    if (!fold(form.title).includes(keyword) && !fold(form.seo_title).includes(keyword)) out.push('La palabra clave no está en el título.');
    if (form.seo_description.trim() && !fold(form.seo_description).includes(keyword)) out.push('La palabra clave no está en la descripción para buscadores.');
    if (!fold(text.slice(0, 600)).includes(keyword)) out.push('La palabra clave no aparece al comienzo del texto.');
    if (!form.slug.includes(slugify(keyword))) out.push('La dirección del artículo no lleva la palabra clave.');
  }
  return out;
}

export default function BlogAdminPage() {
  const [items, setItems] = useState<AdminPost[]>([]);
  const [types, setTypes] = useState<Option[]>([]);
  const [ready, setReady] = useState(true);
  const [message, setMessage] = useState('');
  const [tab, setTab] = useState('published');
  const [search, setSearch] = useState('');
  const [editing, setEditing] = useState<AdminPost | null>(null);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState(EMPTY);
  const [slugTouched, setSlugTouched] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [formError, setFormError] = useState('');
  const [notice, setNotice] = useState('');
  const [removing, setRemoving] = useState<AdminPost | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(() => {
    api.get('/admin/web/posts').then((res) => {
      setItems(res.data.items);
      setTypes(res.data.business_types);
      setReady(res.data.ready);
      setMessage(res.data.message ?? '');
    }).catch((err) => setError(apiError(err, 'No se pudo cargar el blog.')));
  }, []);

  useEffect(() => { load(); }, [load]);

  const startNew = () => {
    setEditing(null);
    setForm(EMPTY);
    setSlugTouched(false);
    setFormError('');
    setOpen(true);
  };

  const startEdit = (post: AdminPost) => {
    setEditing(post);
    setForm({ title: post.title, slug: post.slug, excerpt: post.excerpt ?? '', body: post.body ?? '', business_type: post.business_type ?? '', seo_title: post.seo_title ?? '', seo_description: post.seo_description ?? '', author: post.author ?? '', keyword: post.keyword ?? '', status: post.status });
    setSlugTouched(true);
    setFormError('');
    setOpen(true);
  };

  const save = async () => {
    try {
      setBusy(true);
      setFormError('');
      const payload = { ...form, business_type: form.business_type || null };
      const res = editing ? await api.put(`/admin/web/posts/${editing.id}`, payload) : await api.post('/admin/web/posts', payload);
      setEditing(res.data.item);
      setNotice(form.status === 'published' ? 'Artículo publicado: ya se ve en el blog y en el mapa del sitio.' : 'Borrador guardado: no se ve en el sitio hasta que lo publiques.');
      load();
      if (editing) setOpen(false);
    } catch (err) {
      setFormError(apiError(err, 'No se pudo guardar el artículo.'));
    } finally {
      setBusy(false);
    }
  };

  const upload = async (file: File | undefined) => {
    if (!file || !editing) return;
    try {
      setBusy(true);
      setFormError('');
      const data = new FormData();
      data.append('file', file);
      const res = await api.post(`/admin/web/posts/${editing.id}/cover`, data, { headers: { 'Content-Type': 'multipart/form-data' } });
      setEditing(res.data.item);
      load();
    } catch (err) {
      setFormError(apiError(err, 'No se pudo subir la portada.'));
    } finally {
      setBusy(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const removeCover = async () => {
    if (!editing) return;
    const res = await api.delete(`/admin/web/posts/${editing.id}/cover`);
    setEditing(res.data.item);
    load();
  };

  const remove = async () => {
    if (!removing) return;
    try {
      setBusy(true);
      await api.delete(`/admin/web/posts/${removing.id}`);
      setNotice('Artículo eliminado.');
      setRemoving(null);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo eliminar el artículo.'));
    } finally {
      setBusy(false);
    }
  };

  const term = search.trim().toLowerCase();
  const shown = items.filter((p) => p.status === tab).filter((p) => !term || p.title.toLowerCase().includes(term));
  const warnings = liveWarnings(form, Boolean(editing?.cover_url));

  const columns: Column<AdminPost>[] = [
    { header: 'Artículo', cell: (p) => <button type="button" onClick={() => startEdit(p)} className="block text-left cursor-pointer"><span className="block font-bold text-brand-accent hover:text-brand-ink">{p.title}</span><span className="block text-xs text-slate-500 font-mono break-all">/blog/{p.slug}</span></button> },
    { header: 'Rubro', cell: (p) => p.business_type_name ?? 'Todos' },
    {
      header: 'Buscadores',
      cell: (p) => (p.seo_warnings.length
        ? <span className="inline-flex items-start gap-1.5 text-xs text-amber-800" title={p.seo_warnings.join('\n')} data-seo-warnings={p.seo_warnings.length}><AlertTriangle size={14} className="shrink-0" /> {p.seo_warnings.length === 1 ? p.seo_warnings[0] : `${p.seo_warnings.length} cosas por revisar`}</span>
        : <span className="inline-flex items-center gap-1.5 text-xs text-emerald-700"><CheckCircle2 size={14} /> Listo</span>),
    },
    { header: 'Visitas', cell: (p) => <span className="text-xs text-slate-600 tabular-nums" data-post-stats><strong className="text-sm text-brand-ink">{p.views ?? 0}</strong> visitas<span className="block">{p.leads ?? 0} contactos · {p.signups ?? 0} registros</span></span> },
    { header: 'Estado', cell: (p) => <Badge tone={p.status === 'published' ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'} bold>{p.status === 'published' ? 'Publicado' : 'Borrador'}</Badge> },
    {
      header: 'Acciones',
      cell: (p) => (
        <span className="flex items-center gap-1">
          {p.status === 'published' && <a href={`/blog/${p.slug}`} target="_blank" rel="noopener noreferrer" aria-label="Ver en el sitio" className="p-2 rounded-lg text-slate-500 hover:text-brand-ink hover:bg-slate-100"><ExternalLink size={15} /></a>}
          <button type="button" aria-label="Editar" onClick={() => startEdit(p)} className="p-2 rounded-lg text-slate-500 hover:text-brand-ink hover:bg-slate-100 cursor-pointer"><Pencil size={15} /></button>
          <button type="button" aria-label="Eliminar" onClick={() => setRemoving(p)} className="p-2 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer"><Trash2 size={15} /></button>
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-5 pb-10 font-sans" data-blog-admin>
      <div>
        <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Newspaper className="w-6 h-6 text-brand-accent" /> Blog</h1>
        <p className="text-sm text-slate-500 font-medium">Artículos del sitio para atraer empresas desde Google. Un borrador no se ve en el sitio ni en el mapa del sitio; cada artículo invita a crear la cuenta.</p>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error || (!ready ? message : '')} />

      <DataTable<AdminPost>
        title="Artículos"
        primaryActionLabel={ready ? 'Nuevo artículo' : undefined}
        onPrimaryAction={ready ? startNew : undefined}
        tabs={TABS.map((t) => ({ ...t, count: items.filter((p) => p.status === t.id).length }))}
        activeTab={tab}
        onTabChange={setTab}
        searchTerm={search}
        onSearchChange={setSearch}
        columns={columns}
        data={shown}
        getItemKey={(p) => p.id}
        entityLabel={shown.length === 1 ? 'artículo' : 'artículos'}
        sortOptions={[{ id: 'updated', label: 'Última edición', type: 'date', value: (p) => p.updated_at }, { id: 'published', label: 'Publicación', type: 'date', value: (p) => p.published_at }]}
      />

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title={editing ? `Editar «${editing.title}»` : 'Nuevo artículo'} subtitle="Blog del sitio" icon={<Newspaper size={18} />} maxWidth="max-w-3xl">
        <div className="space-y-4" data-post-form>
          <div>
            <label className={labelClass} htmlFor="post-title">Título</label>
            <input id="post-title" maxLength={160} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value, slug: slugTouched ? form.slug : slugify(e.target.value) })} className={inputClass} placeholder="Ej. 5 formas de reducir las inasistencias" />
          </div>
          <div>
            <label className={labelClass} htmlFor="post-slug">Dirección</label>
            <div className="flex items-center rounded-xl border border-slate-200 bg-white overflow-hidden">
              <span className="pl-3 text-sm text-slate-400 shrink-0">/blog/</span>
              <input id="post-slug" maxLength={80} value={form.slug} onChange={(e) => { setSlugTouched(true); setForm({ ...form, slug: slugify(e.target.value) }); }} className="flex-1 min-w-0 px-1 py-2.5 text-sm font-mono outline-none bg-transparent" />
            </div>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div><label className={labelClass}>Rubro</label><Select ariaLabel="Rubro" value={form.business_type} onChange={(e) => setForm({ ...form, business_type: String(e.target.value ?? '') })} options={types} placeholder="Para todos los rubros" /></div>
            <div><label className={labelClass} htmlFor="post-author">Autor (opcional)</label><input id="post-author" maxLength={150} value={form.author} onChange={(e) => setForm({ ...form, author: e.target.value })} className={inputClass} /></div>
            <div className="sm:col-span-2"><label className={labelClass} htmlFor="post-keyword">Palabra clave</label><input id="post-keyword" maxLength={80} value={form.keyword} onChange={(e) => setForm({ ...form, keyword: e.target.value })} className={inputClass} placeholder="Ej. reducir inasistencias" /><p className="text-xs text-slate-500 mt-1">La frase que alguien escribiría en Google para encontrar este artículo. Úsala en el título, al comienzo del texto y en la descripción.</p></div>
            {editing && <p className="sm:col-span-2 text-xs text-slate-600 bg-slate-50 border border-slate-200/70 rounded-xl px-3 py-2" data-post-stats-detail>Este artículo tuvo <strong>{editing.views ?? 0}</strong> visitas. Llegaron desde él {editing.leads ?? 0} contactos y {editing.signups ?? 0} registros.</p>}
          </div>
          <div>
            <label className={labelClass} htmlFor="post-excerpt">Resumen</label>
            <textarea id="post-excerpt" rows={2} maxLength={300} value={form.excerpt} onChange={(e) => setForm({ ...form, excerpt: e.target.value })} className={inputClass} placeholder="Una o dos frases que invitan a leer" />
          </div>
          <div>
            <label className={labelClass}>Contenido</label>
            <RichTextEditor value={form.body} onChange={(html) => setForm((f) => ({ ...f, body: html }))} minHeight={240} sitePaths linkVariables={[{ token: '/register', label: 'Prueba gratis' }, { token: '/precios', label: 'Precios' }, { token: '/rubros', label: 'Rubros' }, { token: '/blog', label: 'Blog' }]} />
            <p className="text-xs text-slate-500 mt-1.5">Para enlazar a otra página de este sitio escribe su dirección empezando con /, por ejemplo /precios o /rubros/barberia.</p>
          </div>

          <div className="rounded-2xl border border-slate-200 p-4 space-y-3" data-seo-box>
            <span className="block text-sm font-bold text-brand-ink">Cómo se verá en Google</span>
            <div>
              <label className={labelClass} htmlFor="post-seo-title">Título para buscadores</label>
              <input id="post-seo-title" maxLength={120} value={form.seo_title} onChange={(e) => setForm({ ...form, seo_title: e.target.value })} className={inputClass} />
              <p className={`text-xs mt-1 text-right ${form.seo_title.length > 65 ? 'text-amber-700 font-semibold' : 'text-slate-500'}`}>{form.seo_title.length}/65</p>
            </div>
            <div>
              <label className={labelClass} htmlFor="post-seo-description">Descripción para buscadores</label>
              <textarea id="post-seo-description" rows={2} maxLength={300} value={form.seo_description} onChange={(e) => setForm({ ...form, seo_description: e.target.value })} className={inputClass} />
              <p className={`text-xs mt-1 text-right ${form.seo_description.length > 160 ? 'text-amber-700 font-semibold' : 'text-slate-500'}`}>{form.seo_description.length}/160</p>
            </div>
            <div className="rounded-xl bg-slate-50 border border-slate-200 p-3 min-w-0">
              <span className="block text-sm text-brand-accent font-semibold truncate">{form.seo_title || form.title || 'Título del artículo'}</span>
              <span className="block text-xs text-emerald-700 truncate">/blog/{form.slug || 'direccion'}</span>
              <span className="block text-xs text-slate-600 line-clamp-2">{form.seo_description || form.excerpt || 'Descripción del artículo.'}</span>
            </div>
            {warnings.length > 0 && (
              <ul className="space-y-1" data-seo-live>
                {warnings.map((w) => <li key={w} className="flex items-start gap-1.5 text-xs text-amber-800"><AlertTriangle size={13} className="shrink-0 mt-0.5" /> {w}</li>)}
              </ul>
            )}
          </div>

          <div className="rounded-2xl border border-slate-200 p-4 space-y-3">
            <span className="block text-sm font-bold text-brand-ink">Portada</span>
            {editing ? (
              <>
                {editing.cover_url && <img src={editing.cover_url} alt="" className="w-full max-h-48 object-cover rounded-xl border border-slate-200" />}
                <div className="flex flex-wrap gap-2">
                  <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => upload(e.target.files?.[0])} />
                  <button type="button" disabled={busy} onClick={() => fileRef.current?.click()} className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 border border-slate-200 rounded-lg hover:bg-slate-50 cursor-pointer"><ImagePlus size={14} /> {editing.cover_url ? 'Cambiar portada' : 'Subir portada'}</button>
                  {editing.cover_url && <button type="button" onClick={removeCover} className="px-3 py-2 text-xs font-semibold text-rose-700 hover:bg-rose-50 rounded-lg cursor-pointer">Quitar</button>}
                </div>
                <p className="text-xs text-slate-500">PNG, JPG o WEBP de hasta 3 MB; ideal 1200×630 px.</p>
              </>
            ) : <p className="text-xs text-slate-500">Guarda el artículo primero y luego sube su portada.</p>}
          </div>

          <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
            <span className="text-sm"><span className="block font-semibold text-slate-700">Publicado</span><span className="block text-xs text-slate-500">Apagado queda como borrador y no se ve en el sitio.</span></span>
            <Switch label="Publicado" checked={form.status === 'published'} onChange={(v) => setForm({ ...form, status: v ? 'published' : 'draft' })} />
          </div>

          <ErrorAlert message={formError} />
          <button type="button" data-post-save disabled={busy || form.title.trim().length < 5 || !form.body.replace(/<[^>]+>/g, '').trim()} onClick={save} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
            {busy && <Loader2 size={16} className="animate-spin" />} {form.status === 'published' ? 'Guardar y publicar' : 'Guardar borrador'}
          </button>
        </div>
      </FormDrawer>

      <AppModal
        isOpen={Boolean(removing)}
        type="confirm"
        variant="danger"
        title="¿Eliminar el artículo?"
        message={`«${removing?.title ?? ''}» deja de verse en el sitio y su dirección dará «no encontrado».`}
        confirmText="Eliminar"
        cancelText="Cancelar"
        onConfirm={remove}
        onClose={() => setRemoving(null)}
        isLoading={busy}
      />
    </div>
  );
}
