'use client';

import { useEffect, useState } from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import Select from '@/app/components/common/Select';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';

export interface ContentValues {
  promo_enabled?: boolean;
  promo_text?: string | null;
  promo_coupon?: string | null;
  hero_video_url?: string | null;
  video_url?: string | null;
  video_title?: string | null;
  video_chapters?: { time: string; label: string }[];
  trust_badges?: { label: string; detail: string | null }[];
  results?: { value: string; label: string }[];
  testimonials?: { text: string; name: string; business: string | null; rubro: string | null; result: string | null }[];
  partners_enabled?: boolean;
  partners_title?: string | null;
  partners_text?: string | null;
  partners_conditions?: string | null;
  popup_enabled?: boolean;
  popup_title?: string | null;
  popup_text?: string | null;
  popup_delay?: number;
  demo_enabled?: boolean;
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4';
const addClass = 'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 cursor-pointer';
const removeClass = 'p-2 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer shrink-0';
const hidden = 'Mientras esté vacío, el sitio no muestra esta sección.';

function Toggle({ title, hint, checked, onChange }: { title: string; hint: string; checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
      <span className="text-sm"><span className="block font-semibold text-slate-700">{title}</span><span className="block text-xs text-slate-500">{hint}</span></span>
      <Switch label={title} checked={checked} onChange={onChange} />
    </div>
  );
}

function Text({ id, label, value, onChange, max, placeholder, area }: { id: string; label: string; value: string | null | undefined; onChange: (v: string) => void; max: number; placeholder?: string; area?: boolean }) {
  return (
    <div>
      <label className={labelClass} htmlFor={id}>{label}</label>
      {area
        ? <textarea id={id} rows={3} maxLength={max} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={inputClass} placeholder={placeholder} />
        : <input id={id} maxLength={max} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={inputClass} placeholder={placeholder} />}
    </div>
  );
}

export default function SiteContentEditor({ values, set }: { values: ContentValues; set: <K extends keyof ContentValues>(key: K, value: ContentValues[K]) => void }) {
  const [rubros, setRubros] = useState<{ value: string; label: string }[]>([]);

  useEffect(() => {
    api.get('/web/site').then((res) => setRubros((res.data.business_types ?? []).map((b: { slug: string; name: string }) => ({ value: b.slug, label: b.name })))).catch(() => setRubros([]));
  }, []);

  const chapters = values.video_chapters ?? [];
  const badges = values.trust_badges ?? [];
  const results = values.results ?? [];
  const testimonials = values.testimonials ?? [];

  return (
    <>
      <section className={card} data-site-content="promo">
        <SectionTitle>Barra de promoción</SectionTitle>
        <Toggle title="Mostrar la barra arriba del sitio" hint="Una franja delgada con tu promoción y, si quieres, un cupón que se copia con un toque." checked={Boolean(values.promo_enabled)} onChange={(v) => set('promo_enabled', v)} />
        <div className="grid grid-cols-1 md:grid-cols-[1fr_14rem] gap-4">
          <Text id="site-promo-text" label="Texto de la promoción" value={values.promo_text} onChange={(v) => set('promo_text', v)} max={160} placeholder="Ej. 20 % de descuento los 3 primeros meses" />
          <Text id="site-promo-coupon" label="Cupón (opcional)" value={values.promo_coupon} onChange={(v) => set('promo_coupon', v.toUpperCase().replace(/[^A-Z0-9_-]/g, ''))} max={30} placeholder="Ej. FERIA20" />
        </div>
        <p className="text-xs text-slate-500">El cupón debe existir en Cupones de planes para que se pueda usar al registrarse.</p>
      </section>

      <section className={card} data-site-content="video">
        <SectionTitle>Videos</SectionTitle>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Text id="site-hero-video" label="Video corto del inicio (botón «Ver video»)" value={values.hero_video_url} onChange={(v) => set('hero_video_url', v)} max={300} placeholder="https://www.youtube.com/watch?v=..." />
          <Text id="site-video" label="Video con capítulos" value={values.video_url} onChange={(v) => set('video_url', v)} max={300} placeholder="https://www.youtube.com/watch?v=..." />
          <Text id="site-video-title" label="Título del video con capítulos" value={values.video_title} onChange={(v) => set('video_title', v)} max={120} />
        </div>
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-3">
            <span className={labelClass}>Capítulos</span>
            <button type="button" className={addClass} disabled={chapters.length >= 12} onClick={() => set('video_chapters', [...chapters, { time: '', label: '' }])}><Plus size={14} /> Agregar</button>
          </div>
          {chapters.map((item, index) => (
            <div key={index} className="flex gap-2 items-start">
              <input aria-label={`Minuto del capítulo ${index + 1}`} maxLength={5} value={item.time} onChange={(e) => set('video_chapters', chapters.map((c, i) => (i === index ? { ...c, time: e.target.value } : c)))} className={`${inputClass} w-24 font-mono`} placeholder="0:00" />
              <input aria-label={`Nombre del capítulo ${index + 1}`} maxLength={80} value={item.label} onChange={(e) => set('video_chapters', chapters.map((c, i) => (i === index ? { ...c, label: e.target.value } : c)))} className={inputClass} placeholder="Ej. Cómo se reserva" />
              <button type="button" aria-label="Quitar capítulo" className={removeClass} onClick={() => set('video_chapters', chapters.filter((_, i) => i !== index))}><Trash2 size={15} /></button>
            </div>
          ))}
        </div>
        <p className="text-xs text-slate-500">Sirven enlaces de YouTube o Vimeo. {hidden}</p>
      </section>

      <section className={card} data-site-content="badges">
        <div className="flex items-center justify-between gap-3">
          <SectionTitle>Sellos de confianza</SectionTitle>
          <button type="button" className={addClass} disabled={badges.length >= 8} onClick={() => set('trust_badges', [...badges, { label: '', detail: null }])}><Plus size={14} /> Agregar</button>
        </div>
        <p className="text-xs text-slate-500">Solo los que puedas mostrar de verdad (por ejemplo, un proveedor de facturación autorizado o un certificado). Se ven debajo del inicio y al pie del sitio. {hidden}</p>
        {badges.map((item, index) => (
          <div key={index} className="flex flex-col sm:flex-row gap-2 items-start">
            <input aria-label={`Sello ${index + 1}`} maxLength={60} value={item.label} onChange={(e) => set('trust_badges', badges.map((b, i) => (i === index ? { ...b, label: e.target.value } : b)))} className={inputClass} placeholder="Nombre del sello" />
            <input aria-label={`Detalle del sello ${index + 1}`} maxLength={120} value={item.detail ?? ''} onChange={(e) => set('trust_badges', badges.map((b, i) => (i === index ? { ...b, detail: e.target.value || null } : b)))} className={inputClass} placeholder="Detalle (opcional)" />
            <button type="button" aria-label="Quitar sello" className={removeClass} onClick={() => set('trust_badges', badges.filter((_, i) => i !== index))}><Trash2 size={15} /></button>
          </div>
        ))}
      </section>

      <section className={card} data-site-content="results">
        <div className="flex items-center justify-between gap-3">
          <SectionTitle>Franja de resultados</SectionTitle>
          <button type="button" className={addClass} disabled={results.length >= 6} onClick={() => set('results', [...results, { value: '', label: '' }])}><Plus size={14} /> Agregar</button>
        </div>
        <p className="text-xs text-slate-500">Cifras reales y comprobables de tus clientes. {hidden}</p>
        {results.map((item, index) => (
          <div key={index} className="flex gap-2 items-start">
            <input aria-label={`Cifra ${index + 1}`} maxLength={20} value={item.value} onChange={(e) => set('results', results.map((r, i) => (i === index ? { ...r, value: e.target.value } : r)))} className={`${inputClass} w-32`} placeholder="Ej. 30 %" />
            <input aria-label={`Qué significa la cifra ${index + 1}`} maxLength={100} value={item.label} onChange={(e) => set('results', results.map((r, i) => (i === index ? { ...r, label: e.target.value } : r)))} className={inputClass} placeholder="Ej. menos inasistencias" />
            <button type="button" aria-label="Quitar resultado" className={removeClass} onClick={() => set('results', results.filter((_, i) => i !== index))}><Trash2 size={15} /></button>
          </div>
        ))}
      </section>

      <section className={card} data-site-content="testimonials">
        <div className="flex items-center justify-between gap-3">
          <SectionTitle>Testimonios</SectionTitle>
          <button type="button" className={addClass} disabled={testimonials.length >= 12} onClick={() => set('testimonials', [...testimonials, { text: '', name: '', business: null, rubro: null, result: null }])}><Plus size={14} /> Agregar</button>
        </div>
        <p className="text-xs text-slate-500">Solo testimonios reales, con permiso de quien los dice. Si eliges un rubro, también salen en la página de ese rubro. {hidden}</p>
        {testimonials.map((item, index) => {
          const change = (patch: Partial<typeof item>) => set('testimonials', testimonials.map((t, i) => (i === index ? { ...t, ...patch } : t)));
          return (
            <div key={index} className="grid grid-cols-[1fr_auto] gap-2 items-start border-t border-slate-100 pt-3 first:border-0 first:pt-0" data-testimonial-item={index}>
              <div className="space-y-2 min-w-0">
                <textarea aria-label={`Testimonio ${index + 1}`} rows={2} maxLength={400} value={item.text} onChange={(e) => change({ text: e.target.value })} className={inputClass} placeholder="Lo que dijo" />
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                  <input aria-label="Nombre" maxLength={80} value={item.name} onChange={(e) => change({ name: e.target.value })} className={inputClass} placeholder="Nombre" />
                  <input aria-label="Negocio" maxLength={120} value={item.business ?? ''} onChange={(e) => change({ business: e.target.value || null })} className={inputClass} placeholder="Negocio y distrito (opcional)" />
                  <Select ariaLabel="Rubro del testimonio" placeholder="Sin rubro" value={item.rubro ?? ''} onChange={(e) => change({ rubro: String(e.target.value) || null })} options={rubros} />
                  <input aria-label="Resultado" maxLength={80} value={item.result ?? ''} onChange={(e) => change({ result: e.target.value || null })} className={inputClass} placeholder="Resultado (opcional)" />
                </div>
              </div>
              <button type="button" aria-label="Quitar testimonio" className={removeClass} onClick={() => set('testimonials', testimonials.filter((_, i) => i !== index))}><Trash2 size={15} /></button>
            </div>
          );
        })}
      </section>

      <section className={card} data-site-content="partners">
        <SectionTitle>Programa de partners</SectionTitle>
        <Toggle title="Mostrar el programa de partners" hint="Una sección en el inicio para quienes quieran recomendar o revender el sistema. Las condiciones las pones tú." checked={Boolean(values.partners_enabled)} onChange={(v) => set('partners_enabled', v)} />
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Text id="site-partners-title" label="Título" value={values.partners_title} onChange={(v) => set('partners_title', v)} max={120} placeholder="Ej. Gana recomendando" />
          <Text id="site-partners-text" label="Texto" value={values.partners_text} onChange={(v) => set('partners_text', v)} max={400} area />
        </div>
        <Text id="site-partners-conditions" label="Condiciones" value={values.partners_conditions} onChange={(v) => set('partners_conditions', v)} max={2000} area />
      </section>

      <section className={card} data-site-content="popup">
        <SectionTitle>Ventana para captar contactos</SectionTitle>
        <Toggle title="Mostrar la ventana" hint="Aparece una sola vez por visitante, después de unos segundos, y pide nombre y celular. Necesita el formulario de contacto encendido." checked={Boolean(values.popup_enabled)} onChange={(v) => set('popup_enabled', v)} />
        <div className="grid grid-cols-1 md:grid-cols-[1fr_1fr_10rem] gap-4">
          <Text id="site-popup-title" label="Título" value={values.popup_title} onChange={(v) => set('popup_title', v)} max={100} placeholder="Ej. ¿Te ayudamos a empezar?" />
          <Text id="site-popup-text" label="Texto" value={values.popup_text} onChange={(v) => set('popup_text', v)} max={300} />
          <div>
            <label className={labelClass} htmlFor="site-popup-delay">Aparece a los (segundos)</label>
            <input id="site-popup-delay" type="number" min={5} max={120} value={values.popup_delay ?? 20} onChange={(e) => set('popup_delay', Math.max(5, Math.min(120, Number(e.target.value) || 20)))} className={inputClass} />
          </div>
        </div>
        <Toggle title="Botón «Agendar demo» en el menú" hint="Lleva al formulario de contacto del inicio." checked={values.demo_enabled !== false} onChange={(v) => set('demo_enabled', v)} />
      </section>
    </>
  );
}
