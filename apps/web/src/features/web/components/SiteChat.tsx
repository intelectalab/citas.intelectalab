'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Bot, Loader2, MessagesSquare, Send, UserRound, X } from 'lucide-react';
import { api } from '@/lib/axios';
import type { SitePayload, WebBusinessType, WebPlan } from '../web';

type Message = { from: 'bot' | 'me'; text: string; link?: { href: string; label: string } };

const plain = (text: string) => text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

const money = (value: number, currency: string) => `${currency === 'PEN' ? 'S/' : currency} ${value.toLocaleString('es-PE', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`;

export function recommendPlan(plans: WebPlan[], professionals: number): WebPlan | null {
  const sorted = [...plans].filter((p) => p.price_monthly > 0).sort((a, b) => a.price_monthly - b.price_monthly);
  return sorted.find((p) => typeof p.limits?.professionals === 'number' && p.limits.professionals >= professionals) ?? sorted[sorted.length - 1] ?? null;
}

export default function SiteChat({ site, plans, businessTypes }: { site: SitePayload; plans: WebPlan[]; businessTypes: WebBusinessType[] }) {
  const [open, setOpen] = useState(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [text, setText] = useState('');
  const [asking, setAsking] = useState<'' | 'professionals'>('');
  const [leadOpen, setLeadOpen] = useState(false);
  const [person, setPerson] = useState(false);
  const [lead, setLead] = useState({ name: '', phone: '', consent: false, website: '' });
  const [context, setContext] = useState<{ professionals?: number; plan?: string; business_type?: string; message?: string }>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [done, setDone] = useState(false);
  const bottom = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (open && messages.length === 0) setMessages([{ from: 'bot', text: site.chat?.greeting || `Hola, te ayudo con precios, funciones y qué plan de ${site.product_name} te conviene.` }]);
  }, [open, messages.length, site]);

  useEffect(() => { bottom.current?.scrollIntoView({ block: 'end' }); }, [messages, leadOpen, done]);

  const say = (...items: Message[]) => setMessages((current) => [...current, ...items]);

  const prices = () => {
    const list = [...plans].sort((a, b) => a.price_monthly - b.price_monthly)
      .map((p) => `${p.name}: ${p.price_monthly > 0 ? `${money(p.price_monthly, p.currency)} al mes` : 'sin costo'}${typeof p.limits?.professionals === 'number' ? ` (hasta ${p.limits.professionals} ${p.limits.professionals === 1 ? 'profesional' : 'profesionales'})` : ''}`);
    return `${list.join('. ')}. Pagando el año ahorras dos meses.`;
  };

  const recommend = (count: number) => {
    const plan = recommendPlan(plans, count);
    setContext((c) => ({ ...c, professionals: count, plan: plan?.name }));
    setAsking('');
    say({ from: 'bot', text: plan ? `Con ${count} ${count === 1 ? 'profesional' : 'profesionales'} te conviene el plan ${plan.name}: ${money(plan.price_monthly, plan.currency)} al mes, con prueba gratis y sin tarjeta.` : 'Cuéntanos un poco más y te recomendamos un plan.', link: { href: '/precios', label: 'Ver todos los planes' } },
      { from: 'bot', text: '¿Quieres que un asesor te escriba por WhatsApp para ayudarte a empezar?' });
  };

  const answer = (raw: string) => {
    const q = plain(raw);
    const number = q.match(/\d+/);

    if (asking === 'professionals' && number) return recommend(Math.max(1, parseInt(number[0], 10)));
    if (/(persona|asesor|humano|llamar|llamen|hablar con)/.test(q) || /^(si|claro|ok|dale|ya)([ ,!]|$)/.test(q)) return askPerson();
    if (/(plan|conviene|recomienda|cual me)/.test(q) && !/(precio|cuesta|cuanto|costo)/.test(q)) {
      setAsking('professionals');
      return say({ from: 'bot', text: '¿Cuántas personas atienden en tu negocio, contándote a ti?' });
    }
    if (/(precio|cuesta|cuanto|costo|tarifa|pagar)/.test(q)) return say({ from: 'bot', text: prices(), link: { href: '/precios', label: 'Ver precios' } });

    if (/(boleta|factura|sunat|comprobante|electronic)/.test(q)) {
      return say({ from: 'bot', text: 'Sí. Al cobrar eliges boleta o factura y el comprobante electrónico sale en el mismo cobro, listo para imprimir o enviar al cliente. Se conecta con un proveedor autorizado por SUNAT.', link: { href: '/precios', label: 'Ver qué plan lo incluye' } });
    }
    if (/(yape|plin|transferencia|efectivo|tarjeta|medio de pago|metodo de pago|como me pagan|como cobro)/.test(q)) {
      return say({ from: 'bot', text: 'En la caja registras cada cobro con su medio: efectivo, tarjeta, Yape, Plin o transferencia, y al cierre ves cuánto entró por cada uno. Para tu plan puedes pagar con tarjeta, o con transferencia, Yape o Plin si eliges el pago anual.' });
    }
    if (/(migra|excel|importar|pasar mis|traer mis|otro sistema|cuaderno|mis datos)/.test(q)) {
      return say({ from: 'bot', text: 'Puedes traer tus clientes desde un Excel o un CSV: primero ves cómo quedarán y luego confirmas. Los servicios vienen listos con la plantilla de tu rubro y los ajustas a tu gusto. Si quieres, una persona del equipo te ayuda a pasar tus datos.' }, { from: 'bot', text: '¿Quieres que te escribamos por WhatsApp para ayudarte con eso?' });
    }

    const type = businessTypes.find((b) => [b.name, b.short_name, b.slug].some((word) => word && q.includes(plain(word).split(' ')[0])));
    if (type) {
      setContext((c) => ({ ...c, business_type: type.slug }));
      return say({ from: 'bot', text: `Sí, ${site.product_name} viene listo para ${type.short_name || type.name}: servicios, palabras y reglas de tu rubro desde el primer día.`, link: { href: `/rubros/${type.slug}`, label: `Ver ${type.short_name || type.name}` } });
    }

    const words = q.split(/\W+/).filter((w) => w.length > 3);
    const faq = site.faq.map((item) => ({ item, score: words.filter((w) => plain(item.q + ' ' + item.a).includes(w)).length })).sort((a, b) => b.score - a.score)[0];
    if (faq && faq.score > 0) return say({ from: 'bot', text: faq.item.a });

    if (/(funcion|hace|sirve|agenda|cita|recordatorio|whatsapp|caja|boleta|factura)/.test(q)) {
      return say({ from: 'bot', text: 'Agenda en línea, recordatorios por WhatsApp, caja con boleta y factura, paquetes, fichas de tus clientes y reportes. Puedes probarlo todo gratis.', link: { href: '/register', label: 'Probar gratis' } });
    }

    say({ from: 'bot', text: 'No tengo esa respuesta a la mano, pero una persona del equipo sí. Déjame tu nombre y WhatsApp y te escribimos.' });
    setLeadOpen(true);
  };

  const askPerson = () => {
    setPerson(true);
    setLeadOpen(true);
    say({ from: 'bot', text: 'Claro. Déjame tu nombre y tu WhatsApp, y una persona del equipo te escribe.' });
  };

  const send = (value = text) => {
    const clean = value.trim();
    if (!clean) return;
    say({ from: 'me', text: clean });
    setContext((c) => ({ ...c, message: [c.message, clean].filter(Boolean).join(' / ').slice(0, 900) }));
    setText('');
    window.setTimeout(() => answer(clean), 250);
  };

  const submitLead = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setBusy(true);
      setError('');
      let utm: Record<string, string> = {};
      try { utm = JSON.parse(localStorage.getItem('site_utm') ?? '{}') ?? {}; } catch {}
      const transcript = messages.slice(-60).map((m) => ({ from: m.from === 'me' ? 'user' : 'bot', text: m.text.slice(0, 600) }));
      const res = await api.post('/web/chat', { ...lead, ...context, person, utm, transcript });
      setDone(true);
      setLeadOpen(false);
      say({ from: 'bot', text: res.data.message ?? '¡Gracias! Te escribiremos pronto.' });
    } catch (err) {
      const data = (err as { response?: { data?: { message?: string } } }).response?.data;
      setError(data?.message ?? 'No pudimos enviar tus datos. Inténtalo otra vez.');
    } finally {
      setBusy(false);
    }
  };

  const quick = [
    { label: '¿Cuánto cuesta?', text: '¿Cuánto cuesta?' },
    { label: '¿Qué plan me conviene?', text: '¿Qué plan me conviene?' },
    { label: '¿Qué hace el sistema?', text: '¿Qué funciones tiene?' },
    { label: '¿Emite boletas?', text: '¿Emite boletas y facturas?' },
    { label: '¿Acepta Yape?', text: '¿Puedo cobrar con Yape?' },
    { label: 'Pasar mis datos', text: '¿Puedo migrar mis datos?' },
  ];

  const field = 'w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white';

  return (
    <>
      <button
        type="button"
        aria-label={open ? 'Cerrar el chat' : 'Abrir el chat'}
        data-site-chat-open
        onClick={() => setOpen((v) => !v)}
        className="fixed right-4 z-40 w-12 h-12 rounded-full bg-brand hover:bg-brand-hover text-white shadow-lg flex items-center justify-center"
        style={{ bottom: `calc(${site.whatsapp ? '5rem' : '1rem'} + var(--site-bar, 0px))` }}
      >
        {open ? <X size={22} /> : <MessagesSquare size={22} />}
      </button>

      {open && (
        <div role="dialog" aria-label="Chat del sitio" data-site-chat className="fixed z-50 right-4 left-4 sm:left-auto sm:w-96 bg-white border border-slate-200 rounded-2xl shadow-2xl flex flex-col overflow-hidden" style={{ bottom: `calc(${site.whatsapp ? '8.5rem' : '4.5rem'} + var(--site-bar, 0px))`, maxHeight: 'min(70dvh, 560px)' }}>
          <div className="flex items-center justify-between gap-2 px-4 py-3 bg-brand text-white">
            <span className="flex items-center gap-2 text-sm font-bold"><Bot size={18} /> Asistente de {site.product_name}</span>
            <button type="button" aria-label="Cerrar" onClick={() => setOpen(false)} className="p-1 rounded-md hover:bg-white/15"><X size={16} /></button>
          </div>

          <div className="flex-1 overflow-y-auto px-3 py-3 space-y-2 bg-slate-50/60" data-site-chat-messages>
            {messages.map((m, i) => (
              <div key={i} className={`flex ${m.from === 'me' ? 'justify-end' : 'justify-start'}`}>
                <div className={`max-w-[85%] rounded-2xl px-3 py-2 text-sm ${m.from === 'me' ? 'bg-brand text-white rounded-br-sm' : 'bg-white border border-slate-200 text-slate-700 rounded-bl-sm'}`}>
                  {m.text}
                  {m.link && <Link href={m.link.href} className="block mt-1 text-xs font-bold text-brand-accent hover:text-brand-ink">{m.link.label} →</Link>}
                </div>
              </div>
            ))}

            {leadOpen && !done && (
              <form onSubmit={submitLead} className="bg-white border border-slate-200 rounded-xl p-3 space-y-2" data-site-chat-lead>
                <input aria-label="Tu nombre" required minLength={2} maxLength={150} placeholder="Tu nombre" value={lead.name} onChange={(e) => setLead({ ...lead, name: e.target.value })} className={field} />
                <input aria-label="Tu WhatsApp" required minLength={6} maxLength={30} inputMode="tel" placeholder="Tu WhatsApp" value={lead.phone} onChange={(e) => setLead({ ...lead, phone: e.target.value })} className={field} />
                <input tabIndex={-1} autoComplete="off" aria-hidden="true" value={lead.website} onChange={(e) => setLead({ ...lead, website: e.target.value })} className="hidden" name="website" />
                <label className="flex items-start gap-2 text-xs text-slate-600 cursor-pointer">
                  <input type="checkbox" checked={lead.consent} onChange={(e) => setLead({ ...lead, consent: e.target.checked })} className="mt-0.5 accent-[var(--brand-solid)]" required />
                  <span>Acepto que me contacten y la <Link href="/legal/privacidad" className="font-semibold text-brand-accent">política de privacidad</Link>.</span>
                </label>
                {error && <p className="text-xs font-semibold text-rose-600">{error}</p>}
                <button type="submit" disabled={busy} className="w-full inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-bold bg-brand text-white rounded-lg hover:bg-brand-hover disabled:opacity-60">
                  {busy && <Loader2 size={14} className="animate-spin" />} Enviar mis datos
                </button>
              </form>
            )}
            <div ref={bottom} />
          </div>

          <div className="border-t border-slate-100 p-2 space-y-2">
            <div className="flex flex-wrap gap-1.5">
              {quick.map((q) => (
                <button key={q.label} type="button" onClick={() => send(q.text)} className="px-2.5 py-1 text-xs font-semibold rounded-full border border-slate-200 text-slate-600 hover:border-brand-accent hover:text-brand-ink">{q.label}</button>
              ))}
              {!done && (
                <button type="button" data-site-chat-person onClick={askPerson} className="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-bold rounded-full border border-brand-accent text-brand-ink hover:bg-brand-soft/20">
                  <UserRound size={12} /> Hablar con una persona
                </button>
              )}
            </div>
            <form onSubmit={(e) => { e.preventDefault(); send(); }} className="flex items-center gap-2">
              <input aria-label="Escribe tu pregunta" maxLength={300} value={text} onChange={(e) => setText(e.target.value)} placeholder={asking === 'professionals' ? 'Ej. 3' : 'Escribe tu pregunta'} className={field} />
              <button type="submit" aria-label="Enviar" className="p-2 rounded-lg bg-brand text-white hover:bg-brand-hover"><Send size={16} /></button>
            </form>
          </div>
        </div>
      )}
    </>
  );
}
