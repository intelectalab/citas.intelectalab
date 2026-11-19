'use client';

import { useCallback, useEffect, useState } from 'react';
import { Copy } from 'lucide-react';
import { api } from '@/lib/axios';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { copyText, type CalendarOwner, type CalendarPayload } from '../integrations';

type Kind = 'professional' | 'branch';

function FeedRow({ owner, kind, details, canEdit, onChanged, onMessage }: { owner: CalendarOwner; kind: Kind; details: CalendarPayload['details']; canEdit: boolean; onChanged: () => void; onMessage: (ok: boolean, text: string) => void }) {
  const [busy, setBusy] = useState(false);
  const [asking, setAsking] = useState<'' | 'regenerate' | 'remove'>('');
  const [copied, setCopied] = useState(false);
  const feed = owner.feed;

  const run = async (action: () => Promise<{ data: { message: string } }>) => {
    try {
      setBusy(true);
      const res = await action();
      onMessage(true, res.data.message);
      onChanged();
    } catch (err) {
      onMessage(false, apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
      setAsking('');
    }
  };

  const createCall = () => api.post<{ message: string }>('/integrations/api/calendars', kind === 'professional' ? { professional_id: owner.id } : { branch_id: owner.id });
  const create = () => run(createCall);

  return (
    <li className="px-5 py-3 space-y-2" data-feed-owner={owner.id}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="text-sm font-bold text-brand-ink">{owner.name}</span>
        {!feed && canEdit && <button type="button" data-create-feed disabled={busy} onClick={create} className="px-3 py-1.5 text-xs font-semibold text-brand-accent hover:bg-slate-50 rounded-lg">Crear enlace</button>}
        {!feed && !canEdit && <span className="text-xs text-slate-400">Sin enlace</span>}
      </div>
      {feed && (
        <>
          <div className="flex flex-col sm:flex-row gap-2">
            <code className="flex-1 min-w-0 break-all rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-700" data-feed-url>{feed.url}</code>
            <button type="button" onClick={async () => { setCopied(await copyText(feed.url)); setTimeout(() => setCopied(false), 2000); }} className="flex items-center justify-center gap-1 px-3 py-2 text-xs font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50">
              <Copy size={12} /> {copied ? 'Copiado' : 'Copiar'}
            </button>
          </div>
          <div className="flex flex-col sm:flex-row sm:items-center gap-2">
            <div className="sm:w-80">
              <Select ariaLabel="Qué muestra cada cita" disabled={!canEdit || busy} value={feed.detail}
                options={Object.entries(details).map(([value, label]) => ({ value, label }))}
                onChange={(e) => run(() => api.put(`/integrations/api/calendars/${feed.id}`, { detail: e.target.value }))} />
            </div>
            <span className="text-xs text-slate-400 flex-1">{feed.last_accessed_at ? `Consultado por última vez: ${momentOf(feed.last_accessed_at)}` : 'Todavía nadie se suscribió'}</span>
            {canEdit && (asking ? (
              <div className="flex items-center gap-2">
                <span className="text-xs text-slate-600">{asking === 'regenerate' ? 'El enlace actual dejará de funcionar.' : 'El calendario suscrito dejará de recibir citas.'}</span>
                <button type="button" data-confirm-feed disabled={busy} onClick={() => run(asking === 'regenerate' ? createCall : () => api.delete(`/integrations/api/calendars/${feed.id}`))} className="px-3 py-1.5 text-xs font-bold bg-rose-600 text-white rounded-lg">Sí</button>
                <button type="button" onClick={() => setAsking('')} className="px-3 py-1.5 text-xs font-semibold text-slate-600">No</button>
              </div>
            ) : (
              <div className="flex gap-1">
                <button type="button" data-regenerate-feed onClick={() => setAsking('regenerate')} className="px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 rounded-lg">Enlace nuevo</button>
                <button type="button" data-remove-feed onClick={() => setAsking('remove')} className="px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 rounded-lg">Quitar</button>
              </div>
            ))}
          </div>
        </>
      )}
    </li>
  );
}

export default function CalendarFeedsCard({ canEdit }: { canEdit: boolean }) {
  const [data, setData] = useState<CalendarPayload | null>(null);
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null);

  const load = useCallback(() => {
    api.get('/integrations/api/calendars').then((res) => setData(res.data)).catch((err) => setMessage({ ok: false, text: apiError(err, 'No se pudieron cargar los calendarios.') }));
  }, []);

  useEffect(() => { load(); }, [load]);

  const onMessage = (ok: boolean, text: string) => setMessage({ ok, text });

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-600">Cada profesional o sede puede ver sus citas en Google Calendar u Outlook. Copia su enlace privado y, en Google Calendar, ve a Otros calendarios → + → Desde URL; en Outlook, Agregar calendario → Suscribirse desde la web. Se actualiza solo cada cierto tiempo. Por defecto no muestra datos del cliente. Quien tenga el enlace ve esas citas: si se comparte por error, crea un enlace nuevo.</p>
      {message && (message.ok ? <SuccessAlert message={message.text} /> : <ErrorAlert message={message.text} />)}
      {data && (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {([['professional', 'Profesionales', data.professionals], ['branch', 'Sedes', data.branches]] as const).map(([kind, title, owners]) => (
            <div key={kind} className="rounded-xl border border-slate-200 flex flex-col">
              <p className="px-5 py-2.5 border-b border-slate-100 text-sm font-semibold text-slate-700">{title}</p>
              <PagedList<CalendarOwner> items={[...owners]} empty="No hay a quién mostrar." renderRow={(owner) => (
                <FeedRow key={owner.id} owner={owner} kind={kind} details={data.details} canEdit={canEdit} onChanged={load} onMessage={onMessage} />
              )} />
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
