'use client';

import { useEffect, useRef, useState } from 'react';
import QRCode from 'qrcode';
import { Check, Copy, Download, ExternalLink, Globe, ImagePlus, Loader2, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';

type Social = 'instagram' | 'facebook' | 'tiktok' | 'whatsapp';

interface PageData {
  url: string;
  slug: string;
  open: boolean;
  module: boolean;
  enabled: boolean;
  published?: boolean;
  widget?: string;
  button?: string;
  policies?: string[];
  message?: string;
  page: {
    title: string;
    description: string | null;
    cover_url: string | null;
    socials: Record<Social, string | null>;
    show_policies: boolean;
  };
}

const SOCIALS: { key: Social; label: string; placeholder: string }[] = [
  { key: 'instagram', label: 'Instagram', placeholder: '@tuempresa' },
  { key: 'facebook', label: 'Facebook', placeholder: 'tuempresa o enlace' },
  { key: 'tiktok', label: 'TikTok', placeholder: '@tuempresa' },
  { key: 'whatsapp', label: 'WhatsApp', placeholder: '987654321' },
];

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

export default function BookingPageSettings() {
  const { can, user } = useAuth();
  const canEdit = can('settings.edit');
  const [data, setData] = useState<PageData | null>(null);
  const [form, setForm] = useState({ title: '', description: '', show_policies: true, socials: { instagram: '', facebook: '', tiktok: '', whatsapp: '' } as Record<Social, string> });
  const [qr, setQr] = useState('');
  const [copied, setCopied] = useState(false);
  const [copiedCode, setCopiedCode] = useState('');
  const [slug, setSlug] = useState('');
  const [editingSlug, setEditingSlug] = useState(false);
  const [slugError, setSlugError] = useState('');
  const [busy, setBusy] = useState('');
  const [saving, setSaving] = useState(false);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const fill = (value: PageData) => {
    setData(value);
    setSlug(value.slug);
    setForm({
      title: value.page.title === user?.tenant?.name ? '' : value.page.title,
      description: value.page.description ?? '',
      show_policies: value.page.show_policies,
      socials: {
        instagram: value.page.socials.instagram ?? '',
        facebook: value.page.socials.facebook ?? '',
        tiktok: value.page.socials.tiktok ?? '',
        whatsapp: value.page.socials.whatsapp ?? '',
      },
    });
  };

  useEffect(() => {
    api.get<PageData>('/booking-page')
      .then((res) => fill(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la página de reservas.')));
  }, []);

  useEffect(() => {
    if (!data?.url) return;
    const color = getComputedStyle(document.documentElement).getPropertyValue('--brand-solid').trim() || '#022340';
    QRCode.toDataURL(data.url, { width: 1024, margin: 2, color: { dark: color, light: '#FFFFFF' } })
      .then(setQr)
      .catch(() => setQr(''));
  }, [data?.url]);

  const copy = async () => {
    if (!data) return;
    try {
      await navigator.clipboard.writeText(data.url);
      setCopied(true);
      setTimeout(() => setCopied(false), 2000);
    } catch {
      setError('No se pudo copiar. Selecciona el enlace y cópialo a mano.');
    }
  };

  const copyCode = async (key: 'widget' | 'button') => {
    const text = data?.[key];
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      setCopiedCode(key);
      setTimeout(() => setCopiedCode(''), 2000);
    } catch {
      setError('No se pudo copiar. Selecciona el código y cópialo a mano.');
    }
  };

  const togglePublished = async (published: boolean) => {
    try {
      setBusy('publish');
      setError('');
      setMessage('');
      const res = await api.put<PageData>('/booking-page/publish', { published });
      fill(res.data);
      setMessage(res.data.message ?? '');
    } catch (err) {
      setError(apiError(err, 'No se pudo cambiar.'));
    } finally {
      setBusy('');
    }
  };

  const saveSlug = async () => {
    try {
      setBusy('slug');
      setSlugError('');
      setMessage('');
      const res = await api.put<PageData>('/booking-page/slug', { slug });
      fill(res.data);
      setEditingSlug(false);
      setMessage(res.data.message ?? '');
    } catch (err) {
      setSlugError(apiError(err, 'No se pudo guardar la dirección.'));
    } finally {
      setBusy('');
    }
  };

  const downloadQr = () => {
    if (!qr || !data) return;
    const link = document.createElement('a');
    link.href = qr;
    link.download = `qr-reservas-${data.slug}.png`;
    link.click();
  };

  const save = async () => {
    try {
      setSaving(true);
      setError('');
      setMessage('');
      const res = await api.put<PageData>('/booking-page', form);
      fill(res.data);
      setMessage('Página de reservas guardada.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setSaving(false);
    }
  };

  const uploadCover = async (file: File) => {
    try {
      setUploading(true);
      setError('');
      setMessage('');
      const body = new FormData();
      body.append('cover', file);
      const res = await api.post<PageData>('/booking-page/cover', body, { headers: { 'Content-Type': 'multipart/form-data' } });
      fill(res.data);
      setMessage('Portada actualizada.');
    } catch (err) {
      setError(apiError(err, 'No se pudo subir la portada.'));
    } finally {
      setUploading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  };

  const removeCover = async () => {
    try {
      setUploading(true);
      setError('');
      const res = await api.delete<PageData>('/booking-page/cover');
      fill(res.data);
      setMessage('Portada quitada.');
    } catch (err) {
      setError(apiError(err, 'No se pudo quitar la portada.'));
    } finally {
      setUploading(false);
    }
  };

  if (!data) {
    return (
      <section className={cardClass} data-card="booking-page">
        <SectionTitle><Globe className="w-4 h-4 text-brand-accent" /> Página de reservas</SectionTitle>
        {error ? <ErrorAlert message={error} /> : <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando...</p>}
      </section>
    );
  }

  const status = !data.module
    ? 'Tu plan no incluye la reserva en línea: la página muestra que no está disponible.'
    : !data.enabled
      ? 'La reserva en línea está apagada en "Reglas de reserva": la página muestra que no está disponible.'
      : null;

  return (
    <section className={cardClass} data-card="booking-page">
      <SectionTitle><Globe className="w-4 h-4 text-brand-accent" /> Página de reservas</SectionTitle>
      <p className="text-sm text-slate-600">
        Tus clientes reservan aquí sin crear una cuenta. Compártelo en tus redes, en tu web o imprime el código QR. Solo aparecen los servicios y {user?.tenant?.vocabulary?.pros?.toLowerCase() ?? 'profesionales'} marcados para la reserva en línea.
      </p>

      {status && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-booking-status>{status}</p>}

      <div className="flex items-start justify-between gap-3 rounded-2xl border border-slate-200 p-4" data-booking-published>
        <div>
          <p className="text-sm font-bold text-brand-ink">{data.published === false ? 'Página despublicada' : 'Página publicada'}</p>
          <p className="text-xs text-slate-500">{data.published === false ? 'Quien entre al enlace verá que la reserva en línea no está disponible. Tu portal de clientes sigue igual.' : 'Cualquiera con el enlace o el código QR puede reservar.'}</p>
        </div>
        <Switch checked={data.published !== false} disabled={!canEdit || busy !== ''} onChange={togglePublished} label="Página publicada" />
      </div>

      <div className="grid grid-cols-1 md:grid-cols-[1fr_auto] gap-5 items-start">
        <div className="space-y-2 min-w-0">
          <span className={labelClass}>Enlace</span>
          <div className="flex flex-wrap gap-2">
            <input readOnly value={data.url} aria-label="Enlace de la página de reservas" className={`${inputClass} flex-1 min-w-[14rem]`} onFocus={(e) => e.target.select()} />
            <Button variant="secondary" type="button" onClick={copy} data-action="copy-link" className="inline-flex items-center gap-1.5">
              {copied ? <Check size={15} className="text-emerald-600" /> : <Copy size={15} />} {copied ? 'Copiado' : 'Copiar'}
            </Button>
            <a href={data.url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors">
              <ExternalLink size={15} /> Ver mi página
            </a>
          </div>
        </div>
        {qr && (
          <div className="flex md:flex-col items-center gap-3">
            <img src={qr} alt="Código QR de la página de reservas" className="w-28 h-28 rounded-xl border border-slate-200" data-qr />
            <Button variant="secondary" type="button" onClick={downloadQr} data-action="download-qr" className="inline-flex items-center gap-1.5">
              <Download size={15} /> Descargar QR
            </Button>
          </div>
        )}
      </div>

      {canEdit && (
        <div className="space-y-2" data-booking-slug>
          <span className={labelClass}>Dirección de la página</span>
          {editingSlug ? (
            <div className="space-y-2">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-sm text-slate-500 break-all">{data.url.slice(0, data.url.length - data.slug.length)}</span>
                <input aria-label="Dirección de la página" maxLength={60} value={slug} onChange={(e) => setSlug(e.target.value.toLowerCase().replace(/\s+/g, '-'))} className={`${inputClass} flex-1 min-w-[10rem]`} />
              </div>
              <p className="text-xs text-amber-800">Si la cambias, el enlace y el código QR que ya compartiste dejan de funcionar. También cambia tu enlace para redes.</p>
              {slugError && <ErrorAlert message={slugError} />}
              <div className="flex flex-wrap gap-2">
                <Button variant="primary" type="button" disabled={busy !== '' || slug.trim().length < 3 || slug === data.slug} onClick={saveSlug} data-action="save-slug">
                  {busy === 'slug' ? 'Guardando...' : 'Guardar dirección'}
                </Button>
                <Button variant="secondary" type="button" onClick={() => { setEditingSlug(false); setSlug(data.slug); setSlugError(''); }}>Cancelar</Button>
              </div>
            </div>
          ) : (
            <button type="button" onClick={() => setEditingSlug(true)} data-action="edit-slug" className="text-sm font-semibold text-brand-accent hover:text-brand-ink">Cambiar la dirección ({data.slug})</button>
          )}
        </div>
      )}

      {(data.widget || data.button) && (
        <div className="space-y-2" data-booking-widget>
          <span className={labelClass}>Para tu sitio web</span>
          <p className="text-xs text-slate-500">Pega uno de estos códigos en tu web: el primero muestra la reserva dentro de tu página; el segundo, un botón que la abre.</p>
          {([['widget', 'Reserva dentro de tu web'], ['button', 'Botón «Reservar cita»']] as const).map(([key, label]) => data[key] ? (
            <div key={key} className="space-y-1">
              <span className="block text-xs font-semibold text-slate-700">{label}</span>
              <div className="flex flex-col sm:flex-row gap-2">
                <code className="flex-1 min-w-0 text-xs bg-slate-50 border border-slate-200 rounded-xl px-3 py-2 text-slate-700 break-all">{data[key]}</code>
                <Button variant="secondary" type="button" onClick={() => copyCode(key)} data-action={`copy-${key}`} className="inline-flex items-center gap-1.5 shrink-0">
                  {copiedCode === key ? <Check size={15} className="text-emerald-600" /> : <Copy size={15} />} {copiedCode === key ? 'Copiado' : 'Copiar'}
                </Button>
              </div>
            </div>
          ) : null)}
        </div>
      )}

      {(data.policies ?? []).length > 0 && (
        <div className="rounded-2xl bg-slate-50 border border-slate-200 p-4 space-y-2" data-booking-policies>
          <span className="block text-sm font-bold text-brand-ink">Así reservan tus clientes hoy</span>
          <ul className="list-disc pl-5 space-y-0.5 text-xs text-slate-600">
            {(data.policies ?? []).map((line) => <li key={line}>{line}</li>)}
          </ul>
          <a href="/settings#reservas" className="inline-block text-xs font-semibold text-brand-accent hover:text-brand-ink">Cambiar las reglas de reserva</a>
        </div>
      )}

      <div className="border-t border-slate-100 pt-4 space-y-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label className="block">
            <span className={labelClass}>Título</span>
            <input className={inputClass} maxLength={120} disabled={!canEdit} placeholder={user?.tenant?.name ?? 'Nombre de tu empresa'} value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
          </label>
          <div>
            <span className={labelClass}>Portada</span>
            <div className="flex flex-wrap items-center gap-2">
              {data.page.cover_url && (
                <img src={data.page.cover_url} alt="Portada" className="h-11 w-24 object-cover rounded-lg border border-slate-200" />
              )}
              <input ref={fileRef} type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={(e) => e.target.files?.[0] && uploadCover(e.target.files[0])} />
              <Button variant="secondary" type="button" disabled={!canEdit || uploading} onClick={() => fileRef.current?.click()} className="inline-flex items-center gap-1.5">
                {uploading ? <Loader2 size={15} className="animate-spin" /> : <ImagePlus size={15} />} {data.page.cover_url ? 'Cambiar' : 'Subir imagen'}
              </Button>
              {data.page.cover_url && (
                <button type="button" disabled={!canEdit || uploading} onClick={removeCover} title="Quitar portada" className="p-2 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                  <Trash2 size={15} />
                </button>
              )}
            </div>
            <span className="block text-xs text-slate-500 mt-1">PNG, JPG o WEBP de hasta 3 MB. Se ve mejor ancha, por ejemplo 1600 × 500.</span>
          </div>
        </div>

        <label className="block">
          <span className={labelClass}>Descripción</span>
          <textarea rows={3} maxLength={600} className={inputClass} disabled={!canEdit} placeholder="Ej. Reserva tu cita en menos de un minuto. Te esperamos." value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </label>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          {SOCIALS.map((item) => (
            <label key={item.key} className="block">
              <span className={labelClass}>{item.label}</span>
              <input className={inputClass} maxLength={item.key === 'whatsapp' ? 30 : 200} disabled={!canEdit} placeholder={item.placeholder} value={form.socials[item.key]} onChange={(e) => setForm({ ...form, socials: { ...form.socials, [item.key]: e.target.value } })} />
            </label>
          ))}
        </div>

        <div className="flex items-start gap-3">
          <Switch checked={form.show_policies} disabled={!canEdit} onChange={(checked) => setForm({ ...form, show_policies: checked })} label="Mostrar las condiciones de reserva" />
          <span className="text-sm text-slate-700">
            Mostrar las condiciones de reserva
            <span className="block text-xs text-slate-500">Anticipación, hasta cuándo se puede cancelar y qué pasa si no asiste. Salen de tus reglas de reserva.</span>
          </span>
        </div>

        {error && <ErrorAlert message={error} />}
        {message && <SuccessAlert message={message} />}

        {canEdit && (
          <div className="flex justify-end">
            <Button variant="primary" type="button" disabled={saving} onClick={save} data-action="save-booking-page">
              {saving ? 'Guardando...' : 'Guardar'}
            </Button>
          </div>
        )}
      </div>
    </section>
  );
}
