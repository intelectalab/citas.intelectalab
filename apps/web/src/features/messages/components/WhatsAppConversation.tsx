'use client';

import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { CustomerLink } from '@/app/components/common/PersonLink';
import { inputClass } from '@/app/components/ui/forms/styles';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useParams } from 'next/navigation';
import { Bot, CheckCheck, Clock, Hand, Paperclip, Send } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { WA_MESSAGE_BADGES, type WhatsAppThread } from '../messages';
import { nameOf } from './WhatsAppInbox';

const CONSENT_TEXT: Record<WhatsAppThread['consent'], string | null> = {
  ok: null,
  missing: 'Este cliente no ha aceptado recibir avisos automáticos por WhatsApp. Puedes responderle mientras siga abierta la conversación.',
  revoked: 'Este cliente pidió no recibir mensajes por WhatsApp.',
  unknown: 'Este número no está unido a ningún cliente: se une solo cuando el celular coincide con el de un cliente.',
};

function MediaButton({ id, image, name }: { id: string; image: boolean; name: string | null }) {
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  const open = async () => {
    try {
      setBusy(true);
      setFailed(false);
      const res = await api.get(`/whatsapp/messages/${id}/media`, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data as Blob);
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch {
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="block mt-1">
      <button type="button" data-wa-media={id} disabled={busy} onClick={open} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-accent hover:underline disabled:opacity-50">
        <Paperclip size={12} /> {busy ? 'Abriendo...' : image ? 'Ver la foto' : `Abrir ${name ?? 'el archivo'}`}
      </button>
      {failed && <span className="block text-[11px] text-rose-700">Este archivo ya no está disponible.</span>}
    </span>
  );
}

export default function WhatsAppConversation() {
  const { id } = useParams<{ id: string }>();
  const [thread, setThread] = useState<WhatsAppThread | null>(null);
  const [body, setBody] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const fileRef = useRef<HTMLInputElement>(null);

  const load = useCallback(() => {
    api.get(`/whatsapp/conversations/${id}`)
      .then((res) => setThread(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la conversación.')));
  }, [id]);

  useEffect(() => {
    load();
    const timer = setInterval(load, 20000);
    return () => clearInterval(timer);
  }, [load]);

  const send = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await api.post(`/whatsapp/conversations/${id}/reply`, { body });
      setThread(res.data);
      setBody('');
    } catch (err) {
      const data = (err as { response?: { data?: WhatsAppThread } }).response?.data;
      if (data?.messages) setThread(data);
      setError(apiError(err, 'No se pudo enviar.'));
    } finally {
      setBusy(false);
    }
  };

  const sendFile = async (file: File | undefined) => {
    if (!file) return;
    if (!['image/jpeg', 'image/png', 'application/pdf'].includes(file.type)) { setError('Por WhatsApp se pueden mandar fotos (JPG o PNG) y documentos PDF.'); return; }
    if (file.size > 5 * 1024 * 1024) { setError('El archivo pesa demasiado: hasta 5 MB.'); return; }
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const form = new FormData();
      form.append('file', file);
      if (body.trim()) form.append('caption', body.trim());
      const res = await api.post(`/whatsapp/conversations/${id}/file`, form);
      setThread(res.data);
      setBody('');
      setNotice('Archivo enviado.');
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar el archivo.'));
      load();
    } finally {
      setBusy(false);
    }
  };

  const resolve = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post(`/whatsapp/conversations/${id}/resolve`);
      setThread(res.data);
      setNotice('Listo: la conversación quedó atendida. El asistente vuelve a responder si está encendido.');
    } catch (err) {
      setError(apiError(err, 'No se pudo marcar como atendida.'));
    } finally {
      setBusy(false);
    }
  };

  if (!thread) {
    return (
      <div className="space-y-4">
        <BackLink href="/messages/whatsapp">Conversaciones de WhatsApp</BackLink>
        <ErrorAlert message={error} />
      </div>
    );
  }

  const c = thread.conversation;
  const consentText = CONSENT_TEXT[thread.consent];

  return (
    <div className="space-y-5 mb-8" data-whatsapp-conversation={c.id}>
      <BackLink href="/messages/whatsapp">Conversaciones de WhatsApp</BackLink>

      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-5 flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h1 className="text-lg font-bold text-brand-ink">{c.customer ? <CustomerLink id={c.customer.id}>{c.customer.name}</CustomerLink> : nameOf(c)}</h1>
          <p className="text-sm text-slate-500">+{c.phone}{c.contact_name && c.customer ? ` · en WhatsApp: ${c.contact_name}` : ''}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={c.status === 'needs_human' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'} bold nowrap data-conversation-status={c.status}>{c.status_label}</Badge>
          <Badge tone={c.window_open ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'} nowrap>
            {c.window_open && c.window_ends_at ? `Puedes responder hasta ${momentOf(c.window_ends_at)}` : 'Fuera de las 24 horas'}
          </Badge>
        </div>
      </div>

      {c.status === 'needs_human' && (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3" data-handoff>
          <span className="min-w-0 space-y-1">
            <span className="flex items-center gap-2 text-sm text-amber-900"><Hand size={16} className="shrink-0" /> {c.handoff_reason ?? 'Esta conversación necesita una persona.'}</span>
            {c.handoff_summary && <span className="block text-xs text-amber-800" data-handoff-summary>Resumen: {c.handoff_summary}</span>}
          </span>
          {thread.can.reply && (
            <button type="button" data-resolve disabled={busy} onClick={resolve} className="px-3 py-1.5 text-xs font-bold bg-white border border-amber-300 text-amber-900 rounded-lg hover:bg-amber-100 disabled:opacity-50">Marcar como atendida</button>
          )}
        </div>
      )}

      {consentText && <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">{consentText}</p>}

      <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 sm:p-5 space-y-3" data-thread>
        {thread.messages.length === 0 && <p className="text-sm text-slate-500">Sin mensajes.</p>}
        {thread.messages.map((m) => (
          <div key={m.id} className={`flex ${m.direction === 'out' ? 'justify-end' : 'justify-start'}`} data-wa-message={m.direction}>
            <div className={`max-w-[85%] sm:max-w-[70%] rounded-2xl px-4 py-2.5 ${m.direction === 'out' ? 'bg-brand-soft/25 text-slate-800 rounded-br-sm' : 'bg-slate-100 text-slate-800 rounded-bl-sm'}`}>
              {m.template && <span className="block text-[11px] font-semibold text-slate-500 mb-0.5">Plantilla: {m.template}</span>}
              <p className="text-sm whitespace-pre-wrap break-words">{m.body}</p>
              {m.media && <MediaButton id={m.id} image={m.media.image} name={m.media.name} />}
              <div className="flex flex-wrap items-center justify-end gap-1.5 mt-1 text-[11px] text-slate-500">
                {m.by_assistant && <span className="flex items-center gap-1"><Bot size={11} /> Asistente</span>}
                {!m.by_assistant && m.by && <span>{m.by}</span>}
                <span>{m.at ? momentOf(m.at) : ''}</span>
                {m.direction === 'out' && <Badge tone={WA_MESSAGE_BADGES[m.status]} nowrap data-wa-status={m.status}>{m.status === 'read' ? <span className="inline-flex items-center gap-0.5"><CheckCheck size={11} /> {m.status_label}</span> : m.status_label}</Badge>}
              </div>
              {m.error && <p className="mt-1 text-[11px] text-rose-700">{m.error}</p>}
            </div>
          </div>
        ))}
      </div>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      {thread.can.reply && (
        <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 space-y-2" data-reply>
          {c.window_open && thread.consent !== 'revoked' ? (
            <>
              <textarea aria-label="Respuesta" rows={3} maxLength={4000} className={inputClass} placeholder="Escribe tu respuesta" value={body} onChange={(e) => setBody(e.target.value)} />
              <div className="flex flex-wrap justify-end gap-2">
                <input ref={fileRef} type="file" accept="image/jpeg,image/png,application/pdf" className="hidden" data-wa-file onChange={(e) => { sendFile(e.target.files?.[0]); e.target.value = ''; }} />
                <button type="button" data-send-file disabled={busy} onClick={() => fileRef.current?.click()} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 disabled:opacity-50">
                  <Paperclip size={14} /> Foto o PDF
                </button>
                <button type="button" data-send-reply disabled={busy || !body.trim()} onClick={send} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
                  <Send size={14} /> {busy ? 'Enviando...' : 'Enviar'}
                </button>
              </div>
            </>
          ) : (
            <p className="flex items-start gap-2 text-sm text-slate-600"><Clock size={15} className="shrink-0 mt-0.5" /> {thread.consent === 'revoked' ? 'No se le puede escribir: pidió no recibir mensajes por WhatsApp.' : 'Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp solo deja escribirle con una plantilla aprobada; los avisos automáticos salen con la suya.'}</p>
          )}
        </div>
      )}

      {thread.assistant.length > 0 && (
        <div className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-4 space-y-2" data-assistant-log>
          <span className="flex items-center gap-2 text-sm font-bold text-brand-ink"><Bot size={15} /> Lo que hizo el asistente</span>
          <ul className="space-y-1">
            {thread.assistant.slice(0, 6).map((l) => (
              <li key={l.id} className="text-xs text-slate-600">{momentOf(l.at)} · <span className="font-semibold">{l.label}</span>{l.reason ? ` · ${l.reason}` : ''}</li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
