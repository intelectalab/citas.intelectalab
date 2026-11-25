'use client';

import { useEffect, useState } from 'react';
import { BarChart3, Check, Copy, ExternalLink, Link2, Loader2, Plus, Share2, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';

interface LinkConfig {
  enabled: boolean;
  title: string | null;
  bio: string | null;
  links: { label: string; url: string }[];
  show_reviews: boolean;
  show_whatsapp: boolean;
  show_location: boolean;
  show_promotions?: boolean;
  show_gift_cards?: boolean;
}

interface Payload {
  visits?: { last_30: number; bookings_30: number; total: number } | null;
  link_page: LinkConfig;
  link_page_url: string;
  channels: { key: string; label: string; url: string }[];
  channels_ready: boolean;
  google: { enabled: boolean; booking_url: string; reserve: { available: boolean; message: string } };
  tracking: { ga4_id: string | null; meta_pixel_id: string | null };
  open: boolean;
  has_whatsapp?: boolean;
}

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

function Toggle({ checked, onChange, title, hint, disabled }: { checked: boolean; onChange: (v: boolean) => void; title: string; hint?: string; disabled?: boolean }) {
  return (
    <div className="flex items-start gap-3">
      <Switch checked={checked} disabled={disabled} onChange={onChange} label={title} />
      <span className="text-sm text-slate-700">{title}{hint && <span className="block text-xs text-slate-500">{hint}</span>}</span>
    </div>
  );
}

function CopyButton({ text, name }: { text: string; name: string }) {
  const [done, setDone] = useState(false);
  const copy = async () => {
    try {
      await navigator.clipboard.writeText(text);
      setDone(true);
      setTimeout(() => setDone(false), 2000);
    } catch {
      setDone(false);
    }
  };
  return (
    <button type="button" onClick={copy} data-copy={name} title={`Copiar enlace de ${name}`} className="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold text-brand-ink border border-slate-200 rounded-lg hover:bg-slate-50 shrink-0 cursor-pointer">
      {done ? <Check size={14} className="text-emerald-600" /> : <Copy size={14} />} {done ? 'Copiado' : 'Copiar'}
    </button>
  );
}

export default function ChannelSettings() {
  const { can } = useAuth();
  const canEdit = can('settings.edit');
  const [data, setData] = useState<Payload | null>(null);
  const [link, setLink] = useState<LinkConfig | null>(null);
  const [channels, setChannels] = useState({ google_enabled: false, ga4_id: '', meta_pixel_id: '' });
  const [saving, setSaving] = useState('');
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const fill = (value: Payload) => {
    setData(value);
    setLink(value.link_page);
    setChannels({ google_enabled: value.google.enabled, ga4_id: value.tracking.ga4_id ?? '', meta_pixel_id: value.tracking.meta_pixel_id ?? '' });
  };

  useEffect(() => {
    api.get<Payload>('/link-page').then((res) => fill(res.data)).catch((err) => setError(apiError(err, 'No se pudieron cargar los canales.')));
  }, []);

  const save = async (kind: 'link' | 'channels') => {
    try {
      setSaving(kind);
      setError('');
      setMessage('');
      const res = kind === 'link'
        ? await api.put<Payload>('/link-page', link)
        : await api.put<Payload>('/link-page/channels', { ...channels, ga4_id: channels.ga4_id.trim() || null, meta_pixel_id: channels.meta_pixel_id.trim() || null });
      fill(res.data);
      setMessage(kind === 'link' ? 'Enlace para redes guardado.' : 'Canales y medición guardados.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setSaving('');
    }
  };

  if (!data || !link) {
    return (
      <section className={cardClass} data-card="channels">
        <SectionTitle><Share2 className="w-4 h-4 text-brand-accent" /> Canales de reserva</SectionTitle>
        {error ? <ErrorAlert message={error} /> : <p className="flex items-center gap-2 text-sm text-slate-500"><Loader2 size={15} className="animate-spin" /> Cargando...</p>}
      </section>
    );
  }

  const setLinkItem = (index: number, key: 'label' | 'url', value: string) => setLink({ ...link, links: link.links.map((item, i) => (i === index ? { ...item, [key]: value } : item)) });

  return (
    <>
      <section className={cardClass} data-card="link-page">
        <SectionTitle><Link2 className="w-4 h-4 text-brand-accent" /> Enlace para redes</SectionTitle>
        <p className="text-sm text-slate-600">Una página corta con tu marca para la biografía de Instagram, TikTok o Facebook: reservar, escribir por WhatsApp, cómo llegar y tus reseñas. Las reservas que llegan desde aquí se marcan como «Enlace para redes» en Reportes.</p>
        <div className="flex flex-wrap gap-2">
          <input readOnly value={data.link_page_url} aria-label="Dirección del enlace para redes" className={`${inputClass} flex-1 min-w-[14rem]`} onFocus={(e) => e.target.select()} />
          <CopyButton text={data.link_page_url} name="enlace para redes" />
          {link.enabled && (
            <a href={data.link_page_url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-50"><ExternalLink size={15} /> Ver</a>
          )}
        </div>
        <Toggle checked={link.enabled} disabled={!canEdit} onChange={(v) => setLink({ ...link, enabled: v })} title="Publicar mi enlace para redes" hint="Mientras esté apagado, la dirección muestra que la página no existe." />
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <label className="block">
            <span className={labelClass}>Título</span>
            <input className={inputClass} maxLength={120} disabled={!canEdit} placeholder="El título de tu página de reservas" value={link.title ?? ''} onChange={(e) => setLink({ ...link, title: e.target.value })} />
          </label>
          <label className="block">
            <span className={labelClass}>Frase corta</span>
            <input className={inputClass} maxLength={300} disabled={!canEdit} placeholder="Ej. Uñas y pestañas en Miraflores" value={link.bio ?? ''} onChange={(e) => setLink({ ...link, bio: e.target.value })} />
          </label>
        </div>
        <div className="space-y-2">
          <span className={labelClass}>Tus enlaces (hasta 6)</span>
          {link.links.map((item, index) => (
            <div key={index} className="flex flex-col sm:flex-row gap-2">
              <input className={inputClass} maxLength={60} disabled={!canEdit} placeholder="Ej. Nuestra carta de servicios" value={item.label} onChange={(e) => setLinkItem(index, 'label', e.target.value)} aria-label="Nombre del enlace" />
              <input className={inputClass} maxLength={500} disabled={!canEdit} placeholder="https://..." value={item.url} onChange={(e) => setLinkItem(index, 'url', e.target.value)} aria-label="Dirección del enlace" />
              {canEdit && (
                <button type="button" title="Quitar enlace" onClick={() => setLink({ ...link, links: link.links.filter((_, i) => i !== index) })} className="p-2 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 self-start cursor-pointer"><Trash2 size={15} /></button>
              )}
            </div>
          ))}
          {canEdit && link.links.length < 6 && (
            <button type="button" data-action="add-link" onClick={() => setLink({ ...link, links: [...link.links, { label: '', url: '' }] })} className="inline-flex items-center gap-1.5 text-sm font-semibold text-brand-accent hover:text-brand-ink cursor-pointer"><Plus size={15} /> Agregar enlace</button>
          )}
        </div>
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <Toggle checked={link.show_whatsapp} disabled={!canEdit} onChange={(v) => setLink({ ...link, show_whatsapp: v })} title="Botón de WhatsApp" hint={data?.has_whatsapp === false ? 'Aún no pusiste tu WhatsApp en la página de reservas (arriba): mientras falte, el botón no aparece.' : 'Usa el WhatsApp de tu página de reservas.'} />
          <Toggle checked={link.show_location} disabled={!canEdit} onChange={(v) => setLink({ ...link, show_location: v })} title="Dirección de las sedes" hint="Con enlace a Google Maps." />
          <Toggle checked={link.show_reviews} disabled={!canEdit} onChange={(v) => setLink({ ...link, show_reviews: v })} title="Reseñas publicadas" hint="Las que apruebas en Encuestas y reseñas." />
          <Toggle checked={link.show_promotions !== false} disabled={!canEdit} onChange={(v) => setLink({ ...link, show_promotions: v })} title="Promociones" hint="Tus paquetes que se venden en línea." />
          <Toggle checked={link.show_gift_cards !== false} disabled={!canEdit} onChange={(v) => setLink({ ...link, show_gift_cards: v })} title="Gift cards" hint="Un aviso para regalar una gift card, si las usas." />
        </div>
        {data.visits && link.enabled && (
          <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200/70 rounded-xl px-4 py-3" data-link-visits>
            En los últimos 30 días tu enlace tuvo <strong>{data.visits.last_30}</strong> {data.visits.last_30 === 1 ? 'visita' : 'visitas'} y <strong>{data.visits.bookings_30}</strong> {data.visits.bookings_30 === 1 ? 'reserva' : 'reservas'}. Desde que lo publicaste: {data.visits.total} {data.visits.total === 1 ? 'visita' : 'visitas'}.
          </p>
        )}
        {canEdit && (
          <div className="flex justify-end">
            <Button variant="primary" type="button" disabled={saving !== ''} onClick={() => save('link')} data-action="save-link-page">{saving === 'link' ? 'Guardando...' : 'Guardar'}</Button>
          </div>
        )}
      </section>

      <section className={cardClass} data-card="channels">
        <SectionTitle><Share2 className="w-4 h-4 text-brand-accent" /> Enlaces por canal, Google y medición</SectionTitle>
        <p className="text-sm text-slate-600">Usa un enlace distinto en cada red. Así cada cita guarda de dónde vino y en Reportes → Citas ves cuántas reservas llegan por Instagram, Google, WhatsApp, etc.</p>
        {!data.channels_ready && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">Falta actualizar la base de datos para guardar el canal de cada cita (php artisan migrate). Mientras tanto las reservas llegan, pero sin su canal.</p>}
        <ul className="divide-y divide-slate-100 border border-slate-200 rounded-xl" data-channel-links>
          {data.channels.map((item) => (
            <li key={item.key} className="flex flex-col sm:flex-row sm:items-center gap-2 px-3 py-2.5">
              <span className="text-sm font-semibold text-slate-700 sm:w-32 shrink-0">{item.label}</span>
              <code className="flex-1 min-w-0 truncate text-xs text-slate-600">{item.url}</code>
              <CopyButton text={item.url} name={item.label} />
            </li>
          ))}
        </ul>

        <div className="border-t border-slate-100 pt-4 space-y-3" data-google>
          <Toggle checked={channels.google_enabled} disabled={!canEdit} onChange={(v) => setChannels({ ...channels, google_enabled: v })} title="Botón «Reservar» en Google" hint={data.google.reserve.message} />
          {channels.google_enabled && (
            <ol className="list-decimal pl-5 text-xs text-slate-600 space-y-1">
              <li>Entra a tu Perfil de Empresa de Google (business.google.com) con la cuenta del negocio.</li>
              <li>Ve a «Editar perfil» → «Reservas» (o «Enlaces de reserva») y pega este enlace: <span className="font-mono break-all">{data.google.booking_url}</span> <CopyButton text={data.google.booking_url} name="Google" /></li>
              <li>Guarda. Google puede tardar unos días en mostrar el botón. Las reservas que lleguen por ahí quedan con el canal «Google».</li>
            </ol>
          )}
          <p className="text-xs text-slate-500">En Instagram: Editar perfil → Enlaces → agrega el enlace de Instagram o el de tu enlace para redes. También puedes poner el botón «Reservar» en Acciones del perfil con el mismo enlace.</p>
        </div>

        <div className="border-t border-slate-100 pt-4 space-y-3">
          <p className="flex items-center gap-2 text-sm font-semibold text-slate-700"><BarChart3 size={15} className="text-brand-accent" /> Medición con tus propias cuentas</p>
          <p className="text-xs text-slate-500">Si usas Google Analytics o el Píxel de Meta, pega aquí tus códigos. Solo se cargan si la persona acepta las cookies, y avisan cuando alguien empieza y termina una reserva (con el precio del servicio).</p>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <label className="block">
              <span className={labelClass}>Google Analytics (GA4)</span>
              <input className={inputClass} maxLength={20} disabled={!canEdit} placeholder="G-AB12CD34EF" value={channels.ga4_id} onChange={(e) => setChannels({ ...channels, ga4_id: e.target.value })} />
            </label>
            <label className="block">
              <span className={labelClass}>Píxel de Meta</span>
              <input className={inputClass} maxLength={20} disabled={!canEdit} placeholder="123456789012345" value={channels.meta_pixel_id} onChange={(e) => setChannels({ ...channels, meta_pixel_id: e.target.value })} />
            </label>
          </div>
        </div>

        {error && <ErrorAlert message={error} />}
        {message && <SuccessAlert message={message} />}

        {canEdit && (
          <div className="flex justify-end">
            <Button variant="primary" type="button" disabled={saving !== ''} onClick={() => save('channels')} data-action="save-channels">{saving === 'channels' ? 'Guardando...' : 'Guardar'}</Button>
          </div>
        )}
      </section>
    </>
  );
}
