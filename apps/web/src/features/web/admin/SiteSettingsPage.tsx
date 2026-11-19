'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { ExternalLink, Globe, Loader2, Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import RichTextEditor from '@/app/components/common/RichTextEditor';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';

interface Settings {
  url: string | null;
  phone: string | null;
  whatsapp: string | null;
  whatsapp_message: string;
  email: string | null;
  seo_title: string | null;
  seo_description: string | null;
  rubros_title: string;
  rubros_text: string;
  pricing_title: string;
  pricing_text: string;
  cta_title: string;
  cta_text: string;
  contact_form: boolean;
  faq: { q: string; a: string }[];
  ga4_id: string | null;
  meta_pixel_id: string | null;
  cookie_banner: boolean;
  cookie_text: string;
  legal: Record<string, string>;
  legal_updated_at: string | null;
}

const card = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4';

function Field({ id, label, value, onChange, max, placeholder, hint, mono }: { id: string; label: string; value: string | null; onChange: (v: string) => void; max: number; placeholder?: string; hint?: string; mono?: boolean }) {
  const length = (value ?? '').length;
  return (
    <div>
      <label className={labelClass} htmlFor={id}>{label}</label>
      <input id={id} maxLength={max} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={`${inputClass} ${mono ? 'font-mono' : ''}`} placeholder={placeholder} />
      {(hint || max <= 200) && <p className="text-xs text-slate-500 mt-1 flex justify-between gap-3"><span>{hint}</span>{max <= 200 && <span className={length > max * 0.95 ? 'text-amber-700' : ''}>{length}/{max}</span>}</p>}
    </div>
  );
}

function Area({ id, label, value, onChange, max }: { id: string; label: string; value: string | null; onChange: (v: string) => void; max: number }) {
  return (
    <div>
      <label className={labelClass} htmlFor={id}>{label}</label>
      <textarea id={id} rows={2} maxLength={max} value={value ?? ''} onChange={(e) => onChange(e.target.value)} className={inputClass} />
      <p className="text-xs text-slate-500 mt-1 text-right">{(value ?? '').length}/{max}</p>
    </div>
  );
}

export default function SiteSettingsPage() {
  const [values, setValues] = useState<Settings | null>(null);
  const [pages, setPages] = useState<Record<string, string>>({});
  const [legalTab, setLegalTab] = useState('terminos');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [dirty, setDirty] = useState(false);

  useEffect(() => {
    api.get('/admin/web/settings').then((res) => { setValues(res.data.values); setPages(res.data.legal_pages); }).catch((err) => setError(apiError(err, 'No se pudieron cargar los ajustes del sitio.')));
  }, []);

  if (!values) return <div className="space-y-3"><ErrorAlert message={error} />{!error && <Loader2 className="animate-spin text-slate-400" />}</div>;

  const set = <K extends keyof Settings>(key: K, value: Settings[K]) => {
    setValues({ ...values, [key]: value });
    setDirty(true);
    setNotice('');
  };

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const payload = { ...values, url: values.url || null, faq: values.faq.filter((item) => item.q.trim() || item.a.trim()) };
      const res = await api.put('/admin/web/settings', payload);
      setValues(res.data.values);
      setDirty(false);
      setNotice('Listo: el sitio ya muestra los cambios (puede tardar hasta un minuto en verse).');
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar los ajustes.'));
    } finally {
      setBusy(false);
    }
  };

  const faq = values.faq;

  return (
    <div className="space-y-5 pb-24 font-sans" data-site-settings>
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2.5 text-2xl font-black text-brand-ink tracking-tight"><Globe className="w-6 h-6 text-brand-accent" /> Sitio web</h1>
          <p className="text-sm text-slate-500 font-medium">Textos, contacto, buscadores, medición y páginas legales del sitio comercial. Los cambios se ven sin publicar nada.</p>
        </div>
        <a href="/" target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 bg-white"><ExternalLink size={15} /> Ver el sitio</a>
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <section className={card}>
        <SectionTitle>Textos de la portada</SectionTitle>
        <p className="text-xs text-slate-500">El título grande, la descripción, los botones y el logo se cambian en <Link href="/admin/personalization" className="font-semibold text-brand-accent hover:text-brand-ink">Personalización → Portada</Link>. Aquí van los demás bloques del sitio.</p>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Field id="site-rubros-title" label="Título de rubros" value={values.rubros_title} onChange={(v) => set('rubros_title', v)} max={120} />
          <Area id="site-rubros-text" label="Texto de rubros" value={values.rubros_text} onChange={(v) => set('rubros_text', v)} max={300} />
          <Field id="site-pricing-title" label="Título de precios" value={values.pricing_title} onChange={(v) => set('pricing_title', v)} max={120} />
          <Area id="site-pricing-text" label="Texto de precios" value={values.pricing_text} onChange={(v) => set('pricing_text', v)} max={300} />
          <Field id="site-cta-title" label="Título de la invitación a probar" value={values.cta_title} onChange={(v) => set('cta_title', v)} max={120} />
          <Area id="site-cta-text" label="Texto de la invitación a probar" value={values.cta_text} onChange={(v) => set('cta_text', v)} max={300} />
        </div>
        <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
          <span className="text-sm"><span className="block font-semibold text-slate-700">Formulario «¿Prefieres que te llamemos?»</span><span className="block text-xs text-slate-500">Quien lo llena aparece en Embudo e interesados.</span></span>
          <Switch label="Formulario de contacto" checked={values.contact_form} onChange={(v) => set('contact_form', v)} />
        </div>
      </section>

      <section className={card}>
        <SectionTitle>Contacto comercial</SectionTitle>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Field id="site-whatsapp" label="WhatsApp de ventas" value={values.whatsapp} onChange={(v) => set('whatsapp', v)} max={30} placeholder="+51 999 888 777" hint="Sale como botón flotante en todo el sitio." />
          <Field id="site-whatsapp-message" label="Mensaje que llega escrito" value={values.whatsapp_message} onChange={(v) => set('whatsapp_message', v)} max={300} hint="{producto} se cambia por el nombre de la plataforma." />
          <Field id="site-phone" label="Teléfono" value={values.phone} onChange={(v) => set('phone', v)} max={30} placeholder="(01) 555 1234" />
          <Field id="site-email" label="Correo comercial" value={values.email} onChange={(v) => set('email', v)} max={190} placeholder="ventas@tusitio.com" hint="Si lo dejas vacío se usa el correo de soporte." />
        </div>
      </section>

      <section className={card}>
        <SectionTitle>Buscadores</SectionTitle>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Field id="site-url" label="Dirección del sitio" value={values.url} onChange={(v) => set('url', v)} max={190} placeholder="https://www.tusitio.com" hint="Se usa en el mapa del sitio y las direcciones oficiales de cada página." />
          <Field id="site-seo-title" label="Título de la portada en Google" value={values.seo_title} onChange={(v) => set('seo_title', v)} max={70} hint="Entre 30 y 65 caracteres se ve completo." />
        </div>
        <Area id="site-seo-description" label="Descripción de la portada en Google" value={values.seo_description} onChange={(v) => set('seo_description', v)} max={160} />
        <p className="text-xs text-slate-500">El mapa del sitio está en <a href="/sitemap.xml" target="_blank" rel="noopener noreferrer" className="font-semibold text-brand-accent">/sitemap.xml</a> y el blog tiene <a href="/blog/rss.xml" target="_blank" rel="noopener noreferrer" className="font-semibold text-brand-accent">RSS</a>.</p>
      </section>

      <section className={card}>
        <SectionTitle>Medición y cookies</SectionTitle>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <Field id="site-ga4" label="Google Analytics 4" value={values.ga4_id} onChange={(v) => set('ga4_id', v.trim().toUpperCase() || null)} max={30} placeholder="G-XXXXXXXXXX" mono />
          <Field id="site-pixel" label="Píxel de Meta" value={values.meta_pixel_id} onChange={(v) => set('meta_pixel_id', v.replace(/\D/g, '') || null)} max={20} placeholder="Solo números" mono />
        </div>
        <p className="text-xs text-slate-500">Google Analytics y el píxel solo se cargan cuando la persona pulsa «Aceptar» en el aviso de cookies. Las visitas del embudo se cuentan siempre, de forma anónima.</p>
        <div className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 px-4 py-3">
          <span className="text-sm font-semibold text-slate-700">Mostrar el aviso de cookies</span>
          <Switch label="Aviso de cookies" checked={values.cookie_banner} onChange={(v) => set('cookie_banner', v)} />
        </div>
        {!values.cookie_banner && (values.ga4_id || values.meta_pixel_id) && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2">Sin el aviso nadie puede aceptar, así que Google Analytics y el píxel no se cargarán.</p>}
        <Area id="site-cookie-text" label="Texto del aviso" value={values.cookie_text} onChange={(v) => set('cookie_text', v)} max={400} />
      </section>

      <section className={card}>
        <div className="flex items-center justify-between gap-3">
          <SectionTitle>Preguntas frecuentes de la portada</SectionTitle>
          <button type="button" disabled={faq.length >= 12} onClick={() => set('faq', [...faq, { q: '', a: '' }])} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-700 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-50 cursor-pointer"><Plus size={14} /> Agregar</button>
        </div>
        {faq.length === 0 && <p className="text-sm text-slate-500">Sin preguntas: la portada no muestra esta sección.</p>}
        {faq.map((item, index) => (
          <div key={index} className="grid grid-cols-[1fr_auto] gap-2 items-start border-t border-slate-100 pt-3 first:border-0 first:pt-0" data-faq-item={index}>
            <div className="space-y-2 min-w-0">
              <input aria-label={`Pregunta ${index + 1}`} maxLength={200} value={item.q} onChange={(e) => set('faq', faq.map((f, i) => (i === index ? { ...f, q: e.target.value } : f)))} className={inputClass} placeholder="Pregunta" />
              <textarea aria-label={`Respuesta ${index + 1}`} rows={2} maxLength={1000} value={item.a} onChange={(e) => set('faq', faq.map((f, i) => (i === index ? { ...f, a: e.target.value } : f)))} className={inputClass} placeholder="Respuesta" />
            </div>
            <button type="button" aria-label="Quitar pregunta" onClick={() => set('faq', faq.filter((_, i) => i !== index))} className="p-2 rounded-lg text-slate-500 hover:text-rose-600 hover:bg-rose-50 cursor-pointer"><Trash2 size={15} /></button>
          </div>
        ))}
      </section>

      <section className={card}>
        <SectionTitle>Páginas legales</SectionTitle>
        <p className="text-xs text-slate-500">Puedes escribir {'{empresa}'}, {'{ruc}'}, {'{direccion}'}, {'{correo}'} o {'{producto}'}: se cambian por los datos legales de Configuración. El Libro de Reclamaciones ya está enlazado al pie del sitio.</p>
        <div className="flex flex-wrap gap-2">
          {Object.entries(pages).map(([key, label]) => (
            <button key={key} type="button" onClick={() => setLegalTab(key)} className={`px-3 py-1.5 rounded-full border text-sm font-semibold cursor-pointer ${legalTab === key ? 'bg-brand text-white border-transparent' : 'bg-white border-slate-200 text-slate-600 hover:text-brand-ink'}`}>{label}</button>
          ))}
          <a href={`/legal/${legalTab}`} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-semibold text-brand-accent"><ExternalLink size={14} /> Ver</a>
        </div>
        <RichTextEditor key={legalTab} value={values.legal[legalTab] ?? ''} onChange={(html) => { setValues((current) => (current ? { ...current, legal: { ...current.legal, [legalTab]: html } } : current)); setDirty(true); }} minHeight={260} />
      </section>

      <div className="sticky bottom-3 z-10 flex justify-end">
        <button type="button" data-site-save disabled={busy} onClick={save} className={`inline-flex items-center gap-2 px-5 py-2.5 text-sm font-bold rounded-xl shadow-lg cursor-pointer disabled:opacity-50 ${dirty ? 'bg-brand text-white hover:bg-brand-hover' : 'bg-white text-slate-700 border border-slate-200'}`}>
          {busy && <Loader2 size={16} className="animate-spin" />} {dirty ? 'Guardar cambios' : 'Guardado'}
        </button>
      </div>
    </div>
  );
}
