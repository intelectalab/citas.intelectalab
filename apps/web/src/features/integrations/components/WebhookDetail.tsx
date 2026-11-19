'use client';

import { useRouter } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { RefreshCw, Send, Webhook as WebhookIcon } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import BackLink from '@/app/components/common/BackLink';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { SecretBox, WebhookDrawer } from './ApiSettings';
import { DELIVERY_TONES, type Delivery, type Webhook } from '../integrations';

function DeliveryRow({ delivery, canEdit, onResend }: { delivery: Delivery; canEdit: boolean; onResend: (d: Delivery) => Promise<void> }) {
  const [open, setOpen] = useState(false);
  const [busy, setBusy] = useState(false);

  return (
    <li className="px-5 py-3 space-y-2" data-delivery={delivery.id}>
      <div className="flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4">
        <div className="flex-1 min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm font-bold text-brand-ink">{delivery.event_label}</span>
            <Badge tone={DELIVERY_TONES[delivery.status]} data-delivery-status={delivery.status}>{delivery.status_label}</Badge>
            {delivery.response_code !== null && <span className="text-xs text-slate-500">Respuesta {delivery.response_code}</span>}
          </div>
          <p className="text-xs text-slate-500">
            {momentOf(delivery.created_at)} · {delivery.attempts} {delivery.attempts === 1 ? 'intento' : 'intentos'}
            {delivery.next_attempt_at ? ` · Próximo intento: ${momentOf(delivery.next_attempt_at)}` : ''}
          </p>
          {delivery.error && <p className="text-xs text-rose-700">{delivery.error}</p>}
        </div>
        <div className="flex gap-1">
          <button type="button" onClick={() => setOpen((v) => !v)} className="px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 rounded-lg">{open ? 'Ocultar datos' : 'Ver datos'}</button>
          {canEdit && delivery.status !== 'pending' && (
            <button type="button" data-resend disabled={busy} onClick={async () => { setBusy(true); await onResend(delivery); setBusy(false); }} className="flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-brand-accent hover:bg-slate-50 rounded-lg">
              <RefreshCw size={12} /> {busy ? 'Reenviando...' : 'Reenviar'}
            </button>
          )}
        </div>
      </div>
      {open && (
        <pre className="max-h-72 overflow-auto rounded-lg bg-slate-50 p-3 text-xs text-slate-700 whitespace-pre-wrap break-all">{JSON.stringify(delivery.payload, null, 2)}{delivery.response_body ? `\n\nRespuesta:\n${delivery.response_body}` : ''}</pre>
      )}
    </li>
  );
}

export default function WebhookDetail({ id }: { id: string }) {
  const router = useRouter();
  const { can } = useAuth();
  const canEdit = can('integrations.edit');
  const [webhook, setWebhook] = useState<Webhook | null>(null);
  const [deliveries, setDeliveries] = useState<Delivery[]>([]);
  const [events, setEvents] = useState<{ key: string; label: string }[]>([]);
  const [status, setStatus] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [secret, setSecret] = useState('');
  const [editing, setEditing] = useState(false);
  const [asking, setAsking] = useState<'' | 'secret' | 'delete'>('');

  const load = useCallback(() => {
    api.get(`/integrations/api/webhooks/${id}/deliveries`, { params: status ? { status } : {} })
      .then((res) => { setWebhook(res.data.webhook); setDeliveries(res.data.deliveries); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el webhook.')));
  }, [id, status]);

  useEffect(() => { load(); }, [load]);
  useEffect(() => { api.get('/integrations/api').then((res) => setEvents(res.data.events ?? [])).catch(() => setEvents([])); }, []);

  const act = async (fn: () => Promise<void>) => {
    try {
      setError('');
      setNotice('');
      await fn();
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setAsking('');
    }
  };

  const resend = (d: Delivery) => act(async () => { const res = await api.post(`/integrations/api/webhooks/${id}/deliveries/${d.id}/resend`); setNotice(res.data.message); load(); });
  const test = () => act(async () => { const res = await api.post(`/integrations/api/webhooks/${id}/test`); setNotice(res.data.message); load(); });
  const rotate = () => act(async () => { const res = await api.post(`/integrations/api/webhooks/${id}/secret`); setSecret(res.data.secret); setNotice(res.data.message); });
  const remove = () => act(async () => { await api.delete(`/integrations/api/webhooks/${id}`); router.push('/settings#integraciones'); });

  return (
    <div className="max-w-6xl mx-auto space-y-5 font-sans" data-webhook-detail={id}>
      <BackLink href="/settings#integraciones">Integraciones</BackLink>
      {webhook && (
        <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4">
          <div className="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
            <div className="flex items-start gap-3 min-w-0">
              <span className="p-2.5 bg-brand-soft/20 text-brand-ink rounded-xl shrink-0"><WebhookIcon size={20} /></span>
              <div className="min-w-0">
                <h1 className="text-lg font-bold text-brand-ink break-all">{webhook.description || 'Webhook'}</h1>
                <p className="text-sm text-slate-600 break-all">{webhook.url}</p>
                <div className="flex flex-wrap gap-1.5 mt-2">
                  {!webhook.active && <Badge tone="bg-slate-100 text-slate-500">Pausado</Badge>}
                  {webhook.events.map((e) => <Badge key={e} tone="bg-slate-100 text-slate-600">{events.find((x) => x.key === e)?.label ?? e}</Badge>)}
                </div>
              </div>
            </div>
            {canEdit && (
              <div className="flex flex-wrap gap-2">
                <Button variant="secondary" type="button" onClick={test} className="flex items-center gap-1.5"><Send size={14} /> Probar</Button>
                <Button variant="secondary" type="button" onClick={() => setEditing(true)}>Editar</Button>
                <Button variant="secondary" type="button" onClick={() => setAsking('secret')}>Cambiar clave secreta</Button>
                <Button variant="secondary" type="button" onClick={() => setAsking('delete')} className="text-rose-700">Eliminar</Button>
              </div>
            )}
          </div>
          {asking && (
            <div className="flex flex-wrap items-center gap-2 rounded-lg bg-rose-50 px-3 py-2">
              <span className="text-sm text-rose-800">{asking === 'secret' ? 'La clave actual dejará de servir: tendrás que poner la nueva en el sistema que recibe los avisos.' : 'Se borrará el webhook y su historial de envíos.'}</span>
              <button type="button" data-confirm onClick={asking === 'secret' ? rotate : remove} className="px-3 py-1.5 text-xs font-bold bg-rose-600 text-white rounded-lg">Sí, continuar</button>
              <button type="button" onClick={() => setAsking('')} className="px-3 py-1.5 text-xs font-semibold text-slate-600">No</button>
            </div>
          )}
          {secret && <SecretBox value={secret} note="Nueva clave secreta. Guárdala ahora: no se volverá a mostrar." />}
          <SuccessAlert message={notice} />
          <ErrorAlert message={error} />
        </section>
      )}
      {!webhook && <ErrorAlert message={error} />}

      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 py-4 border-b border-slate-100">
          <h2 className="text-base font-bold text-brand-ink">Envíos</h2>
          <div className="sm:w-56">
            <Select ariaLabel="Filtrar envíos" clearable placeholder="Todos" value={status} onChange={(e) => setStatus(String(e.target.value ?? ''))}
              options={[{ value: 'sent', label: 'Entregados' }, { value: 'retrying', label: 'Reintentando' }, { value: 'failed', label: 'Fallidos' }, { value: 'pending', label: 'Por enviar' }]} />
          </div>
        </div>
        <PagedList<Delivery> items={deliveries} empty="Todavía no hay envíos." renderRow={(d) => <DeliveryRow key={d.id} delivery={d} canEdit={canEdit} onResend={resend} />} />
      </section>

      {webhook && <WebhookDrawer data={{ events }} webhook={webhook} open={editing} onClose={() => setEditing(false)} onSaved={load} />}
    </div>
  );
}
