'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { Loader2, LogIn, Paperclip, Send, ShieldCheck, X } from 'lucide-react';
import { api } from '@/lib/axios';
import Badge from '@/app/components/common/Badge';
import BackLink from '@/app/components/common/BackLink';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import ImpersonateDrawer from './ImpersonateDrawer';
import { ATTACHMENT_ACCEPT, PRIORITY_TONE, STATUS_TONE, dateTime, type Option, type Ticket, type TicketMessage } from '../support';

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6';

interface Payload {
  ticket: Ticket;
  messages: TicketMessage[];
  access_hours?: number;
  statuses?: Option[];
  priorities?: Option[];
  agents?: Option[];
  settings?: { consent_required: boolean };
}

export function AttachmentPicker({ file, onChange }: { file: File | null; onChange: (file: File | null) => void }) {
  const input = useRef<HTMLInputElement>(null);
  return (
    <div className="flex flex-wrap items-center gap-2" data-attachment>
      <input ref={input} type="file" accept={ATTACHMENT_ACCEPT} className="hidden" onChange={(e) => { onChange(e.target.files?.[0] ?? null); e.target.value = ''; }} />
      <button type="button" onClick={() => input.current?.click()} className="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-brand-ink border border-slate-200 rounded-xl hover:bg-slate-50 cursor-pointer">
        <Paperclip size={14} /> {file ? 'Cambiar captura' : 'Adjuntar captura'}
      </button>
      {file && (
        <span className="inline-flex items-center gap-1 text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 max-w-full">
          <span className="truncate max-w-[220px]">{file.name}</span>
          <button type="button" aria-label="Quitar la captura" onClick={() => onChange(null)} className="text-slate-400 hover:text-rose-600 cursor-pointer"><X size={13} /></button>
        </span>
      )}
    </div>
  );
}

function AttachmentLink({ attachment }: { attachment: NonNullable<TicketMessage['attachment']> }) {
  const [error, setError] = useState('');
  const open = async () => {
    try {
      const res = await api.get<Blob>(attachment.url, { responseType: 'blob' });
      const url = URL.createObjectURL(res.data);
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch {
      setError('No se pudo abrir la captura.');
    }
  };
  return (
    <span className="block mt-2">
      <button type="button" onClick={open} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-accent hover:text-brand-ink cursor-pointer" data-attachment-open>
        <Paperclip size={13} /> {attachment.name}
      </button>
      {error && <span className="block text-xs text-rose-700">{error}</span>}
    </span>
  );
}

export default function TicketDetail({ id, platform = false }: { id: string; platform?: boolean }) {
  const path = platform ? `/admin/support/${id}` : `/support/tickets/${id}`;
  const back = platform ? '/admin/support' : '/support';
  const [data, setData] = useState<Payload | null>(null);
  const [body, setBody] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [nextStatus, setNextStatus] = useState('waiting');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);
  const [entering, setEntering] = useState(false);

  const load = useCallback(() => {
    api.get<Payload>(path).then((res) => { setData(res.data); setError(''); }).catch((err) => setError(apiError(err, 'No encontramos ese pedido de ayuda.')));
  }, [path]);

  useEffect(() => { load(); }, [load]);

  const send = async () => {
    try {
      setBusy(true);
      setError('');
      const form = new FormData();
      form.append('body', body);
      if (platform) form.append('status', nextStatus);
      if (file) form.append('attachment', file);
      const res = await api.post<Payload>(`${path}/messages`, form);
      setData(res.data);
      setBody('');
      setFile(null);
      setNotice(platform ? 'Respuesta enviada. La empresa recibe un aviso y un correo.' : 'Mensaje enviado a soporte.');
    } catch (err) {
      setError(apiError(err, 'No se pudo enviar el mensaje.'));
    } finally {
      setBusy(false);
    }
  };

  const change = async (values: Record<string, unknown>, ok: string) => {
    try {
      setBusy(true);
      setError('');
      const res = await api.put<Payload>(path, values);
      setData(res.data);
      setNotice(ok);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el cambio.'));
    } finally {
      setBusy(false);
    }
  };

  if (!data) {
    return (
      <div className="space-y-4">
        <BackLink href={back}>Soporte</BackLink>
        {error ? <ErrorAlert message={error} /> : <Loader2 className="w-6 h-6 text-brand-accent animate-spin" />}
      </div>
    );
  }

  const t = data.ticket;

  return (
    <div className="space-y-5 pb-10 font-sans" data-ticket-detail={t.code}>
      <BackLink href={back}>Soporte</BackLink>
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="text-2xl font-black text-brand-ink tracking-tight">{t.code} · {t.subject}</h1>
        <Badge tone={STATUS_TONE[t.status]} bold data-ticket-status={t.status}>{t.status_label}</Badge>
        {t.priority !== 'normal' && <Badge tone={PRIORITY_TONE[t.priority]} bold>{t.priority_label}</Badge>}
        {platform && t.overdue && <Badge tone="bg-rose-600 text-white" bold>Vencido</Badge>}
      </div>
      <p className="text-sm text-slate-500 font-medium">
        {platform && t.tenant ? <><Link href={`/admin/tenants/${t.tenant.id}`} className="font-semibold text-brand-ink hover:text-brand-accent hover:underline">{t.tenant.name}</Link> · </> : null}
        {t.topic_label} · Abierto el {dateTime(t.created_at)}{t.created_by ? ` por ${t.created_by}` : ''}
        {platform && t.due_at && ['open', 'in_progress'].includes(t.status) ? ` · Responder hasta el ${dateTime(t.due_at)}` : ''}
      </p>

      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-5">
        <section className={`${card} xl:col-span-2 space-y-4`} data-ticket-messages>
          <h2 className="text-sm font-black text-brand-ink">Conversación</h2>
          <ol className="space-y-3">
            {data.messages.map((m) => {
              const mine = platform ? m.side === 'platform' : m.side === 'tenant';
              return (
                <li key={m.id} className={`flex ${mine ? 'justify-end' : 'justify-start'}`} data-message-side={m.side}>
                  <div className={`max-w-[90%] sm:max-w-[80%] rounded-2xl px-4 py-3 border ${mine ? 'bg-brand-soft/15 border-brand-soft/40' : 'bg-slate-50 border-slate-200'}`}>
                    <p className="text-xs font-semibold text-slate-500 mb-1">{m.author} · {dateTime(m.created_at)}</p>
                    <p className="text-sm text-slate-800 whitespace-pre-line break-words">{m.body}</p>
                    {m.attachment && <AttachmentLink attachment={m.attachment} />}
                  </div>
                </li>
              );
            })}
          </ol>

          <div className="space-y-3 border-t border-slate-100 pt-4" data-ticket-reply>
            <textarea aria-label="Mensaje" rows={4} maxLength={5000} value={body} onChange={(e) => setBody(e.target.value)} className={inputClass} placeholder={platform ? 'Escribe la respuesta para la empresa.' : 'Escribe tu mensaje para soporte.'} />
            <div className="flex flex-wrap items-center justify-between gap-3">
              <AttachmentPicker file={file} onChange={setFile} />
              <div className="flex flex-wrap items-center gap-2">
                {platform && (
                  <div className="w-60">
                    <Select ariaLabel="Después de responder" clearable={false} value={nextStatus} onChange={(e) => setNextStatus(String(e.target.value))} options={(data.statuses ?? []).filter((s) => s.value !== 'open')} />
                  </div>
                )}
                <button type="button" data-ticket-send disabled={busy || body.trim().length < 2} onClick={send} className="inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50 cursor-pointer">
                  {busy ? <Loader2 size={15} className="animate-spin" /> : <Send size={15} />} Enviar
                </button>
              </div>
            </div>
            {!platform && t.status === 'resolved' && <p className="text-xs text-slate-500">Si escribes de nuevo, el pedido se vuelve a abrir.</p>}
          </div>
        </section>

        <aside className={`${card} space-y-4 h-fit`}>
          {platform ? (
            <>
              <div>
                <label className={labelClass}>Estado</label>
                <Select ariaLabel="Estado" clearable={false} value={t.status} disabled={busy} onChange={(e) => change({ status: String(e.target.value) }, 'Estado cambiado.')} options={data.statuses ?? []} />
              </div>
              <div>
                <label className={labelClass}>Prioridad</label>
                <Select ariaLabel="Prioridad" clearable={false} value={t.priority} disabled={busy} onChange={(e) => change({ priority: String(e.target.value) }, 'Prioridad cambiada.')} options={data.priorities ?? []} />
              </div>
              <div>
                <label className={labelClass}>Asignado a</label>
                <Select ariaLabel="Asignado a" placeholder="Sin asignar" value={t.assigned_to ?? ''} disabled={busy} onChange={(e) => change({ assigned_to: e.target.value ? String(e.target.value) : null }, 'Asignación guardada.')} options={data.agents ?? []} />
              </div>
              <div className="rounded-2xl border border-slate-200 p-4 space-y-2" data-ticket-access>
                <p className="flex items-center gap-1.5 text-sm font-bold text-brand-ink"><ShieldCheck size={15} /> Entrar a su cuenta</p>
                <p className="text-xs text-slate-500">
                  {t.access_until ? `La empresa autorizó que soporte entre hasta el ${dateTime(t.access_until)}.` : data.settings?.consent_required ? 'La empresa no autorizó el acceso: pídeselo en la conversación.' : 'La empresa no marcó la autorización. Lo que hagas queda en su auditoría y ella ve un aviso.'}
                </p>
                {t.tenant && (
                  <button type="button" onClick={() => setEntering(true)} disabled={Boolean(data.settings?.consent_required && !t.access_until)} className="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-bold bg-amber-400 text-brand-ink rounded-xl hover:bg-amber-300 disabled:opacity-50 cursor-pointer">
                    <LogIn size={14} /> Entrar como la empresa
                  </button>
                )}
              </div>
            </>
          ) : (
            <>
              <div className="flex items-center justify-between gap-3" data-ticket-allow>
                <div>
                  <p className="text-sm font-bold text-brand-ink">Permitir que soporte entre a mi cuenta</p>
                  <p className="text-xs text-slate-500">{t.access_until ? `Autorizado hasta el ${dateTime(t.access_until)}.` : `Dura ${data.access_hours ?? 72} horas. Lo que haga soporte queda en tu Auditoría.`}</p>
                </div>
                <Switch label="Permitir que soporte entre a mi cuenta" checked={Boolean(t.access_until)} disabled={busy || t.status === 'resolved'} onChange={(value) => change({ allow_access: value }, value ? 'Soporte podrá entrar a tu cuenta.' : 'Quitaste el permiso a soporte.')} />
              </div>
              {t.status !== 'resolved' ? (
                <button type="button" data-ticket-resolve disabled={busy} onClick={() => change({ resolved: true }, 'Marcaste el pedido como resuelto.')} className="w-full px-4 py-2.5 text-sm font-semibold text-brand-ink border border-slate-200 rounded-xl hover:bg-slate-50 cursor-pointer">
                  Ya está resuelto
                </button>
              ) : (
                <p className="text-xs text-slate-500">Este pedido está resuelto.</p>
              )}
            </>
          )}
        </aside>
      </div>

      {platform && t.tenant && (
        <ImpersonateDrawer isOpen={entering} onClose={() => setEntering(false)} tenant={t.tenant} ticket={{ id: t.id, code: t.code, subject: t.subject }} />
      )}
    </div>
  );
}
