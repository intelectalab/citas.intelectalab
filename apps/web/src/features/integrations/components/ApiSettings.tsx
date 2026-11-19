'use client';

import Link from 'next/link';
import { useCallback, useEffect, useState } from 'react';
import { CalendarDays, Copy, KeyRound, Plus, Send, Webhook as WebhookIcon } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import Badge from '@/app/components/common/Badge';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import CalendarFeedsCard from './CalendarFeedsCard';
import { copyText, DELIVERY_TONES, type ApiKey, type ApiOverview, type Level, type Webhook } from '../integrations';

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

export function SecretBox({ value, note }: { value: string; note: string }) {
  const [copied, setCopied] = useState(false);

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 space-y-2" data-secret-box>
      <p className="text-sm font-semibold text-amber-900">{note}</p>
      <div className="flex flex-col sm:flex-row gap-2">
        <code className="flex-1 min-w-0 break-all rounded-lg bg-white border border-amber-200 px-3 py-2 text-xs text-slate-800" data-secret-value>{value}</code>
        <button type="button" onClick={async () => setCopied(await copyText(value))} className="flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold border border-amber-300 rounded-lg text-amber-900 hover:bg-amber-100">
          <Copy size={14} /> {copied ? 'Copiado' : 'Copiar'}
        </button>
      </div>
    </div>
  );
}

function KeyDrawer({ data, open, onClose, onCreated }: { data: ApiOverview; open: boolean; onClose: () => void; onCreated: () => void }) {
  const [name, setName] = useState('');
  const [scopes, setScopes] = useState<Record<string, Level>>({});
  const [plain, setPlain] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (open) { setName(''); setScopes({}); setPlain(''); setError(''); }
  }, [open]);

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/integrations/api/keys', { name, scopes });
      setPlain(res.data.plain);
      onCreated();
    } catch (err) {
      setError(apiError(err, 'No se pudo crear la clave.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={open} onClose={onClose} title="Nueva clave de API" subtitle="Para que otro sistema trabaje con tu agenda" icon={<KeyRound size={20} />}>
      {plain ? (
        <div className="space-y-4">
          <SecretBox value={plain} note="Copia la clave ahora y guárdala en un lugar seguro: por seguridad no se volverá a mostrar." />
          <p className="text-sm text-slate-600">El otro sistema debe enviarla en cada solicitud, en el encabezado <code className="text-xs">Authorization: Bearer</code> seguido de la clave.</p>
          <div className="flex justify-end"><Button variant="primary" type="button" onClick={onClose}>Listo</Button></div>
        </div>
      ) : (
        <div className="space-y-5">
          <div>
            <label className={labelClass} htmlFor="api-key-name">Nombre</label>
            <input id="api-key-name" maxLength={120} className={inputClass} placeholder="Por ejemplo: Página web" value={name} onChange={(e) => setName(e.target.value)} />
          </div>
          <div className="space-y-3">
            <span className={labelClass}>Qué puede hacer</span>
            {data.resources.map((resource) => {
              const current = scopes[resource.key] ?? 'none';
              const options: Level[] = ['none', ...resource.levels];
              return (
                <div key={resource.key} className="rounded-xl border border-slate-200 p-3 space-y-2" data-scope={resource.key}>
                  <div>
                    <p className="text-sm font-semibold text-brand-ink">{resource.label}</p>
                    <p className="text-xs text-slate-500">{resource.hint}</p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {options.map((level) => (
                      <button key={level} type="button" data-level={level} onClick={() => setScopes((s) => ({ ...s, [resource.key]: level }))}
                        className={`px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${current === level ? 'bg-brand text-white border-brand' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                        {data.levels[level]}
                      </button>
                    ))}
                  </div>
                </div>
              );
            })}
          </div>
          {error && <ErrorAlert message={error} />}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" type="button" onClick={onClose}>Cancelar</Button>
            <Button variant="primary" type="button" data-save-key disabled={busy || !name.trim()} onClick={save}>{busy ? 'Creando...' : 'Crear clave'}</Button>
          </div>
        </div>
      )}
    </FormDrawer>
  );
}

export function WebhookDrawer({ data, webhook, open, onClose, onSaved }: { data: Pick<ApiOverview, 'events'>; webhook: Webhook | null; open: boolean; onClose: () => void; onSaved: () => void }) {
  const [url, setUrl] = useState('');
  const [description, setDescription] = useState('');
  const [events, setEvents] = useState<string[]>([]);
  const [active, setActive] = useState(true);
  const [secret, setSecret] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setUrl(webhook?.url ?? '');
    setDescription(webhook?.description ?? '');
    setEvents(webhook?.events ?? []);
    setActive(webhook?.active ?? true);
    setSecret('');
    setError('');
  }, [open, webhook]);

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const payload = { url, description: description || null, events, active };
      if (webhook) {
        await api.put(`/integrations/api/webhooks/${webhook.id}`, payload);
        onSaved();
        onClose();
      } else {
        const res = await api.post('/integrations/api/webhooks', payload);
        setSecret(res.data.secret);
        onSaved();
      }
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el webhook.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <FormDrawer isOpen={open} onClose={onClose} title={webhook ? `Editar webhook` : 'Nuevo webhook'} subtitle={webhook ? webhook.url : 'Avisar a otro sistema cuando pasa algo'} icon={<WebhookIcon size={20} />}>
      {secret ? (
        <div className="space-y-4">
          <SecretBox value={secret} note="Esta es la clave secreta del webhook. Guárdala ahora: no se volverá a mostrar." />
          <p className="text-sm text-slate-600">Cada aviso llega firmado en el encabezado <code className="text-xs">X-Webhook-Signature</code> (t=hora, v1=firma). El sistema que recibe calcula HMAC-SHA256 de «hora.contenido» con esta clave y la compara con v1 para saber que el aviso es tuyo.</p>
          <div className="flex justify-end"><Button variant="primary" type="button" onClick={onClose}>Listo</Button></div>
        </div>
      ) : (
        <div className="space-y-5">
          <div>
            <label className={labelClass} htmlFor="webhook-url">Dirección que recibe los avisos</label>
            <input id="webhook-url" maxLength={500} className={inputClass} placeholder="https://tusistema.com/avisos" value={url} onChange={(e) => setUrl(e.target.value)} />
          </div>
          <div>
            <label className={labelClass} htmlFor="webhook-description">Para qué es (opcional)</label>
            <input id="webhook-description" maxLength={150} className={inputClass} placeholder="Por ejemplo: Contabilidad" value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>
          <div className="space-y-2">
            <span className={labelClass}>Avisar cuando</span>
            {data.events.map((event) => (
              <div key={event.key} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 px-3 py-2" data-event={event.key}>
                <span className="text-sm text-slate-700">{event.label}</span>
                <Switch label={event.label} checked={events.includes(event.key)} onChange={(on) => setEvents((list) => on ? [...list, event.key] : list.filter((e) => e !== event.key))} />
              </div>
            ))}
          </div>
          <div className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2">
            <span className="text-sm font-semibold text-slate-700">Enviar avisos</span>
            <Switch label="Enviar avisos" checked={active} onChange={setActive} />
          </div>
          {error && <ErrorAlert message={error} />}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" type="button" onClick={onClose}>Cancelar</Button>
            <Button variant="primary" type="button" data-save-webhook disabled={busy || !url.trim() || events.length === 0} onClick={save}>{busy ? 'Guardando...' : 'Guardar'}</Button>
          </div>
        </div>
      )}
    </FormDrawer>
  );
}

function KeyRow({ apiKey, data, canEdit, onRevoked }: { apiKey: ApiKey; data: ApiOverview; canEdit: boolean; onRevoked: () => void }) {
  const [asking, setAsking] = useState(false);
  const [busy, setBusy] = useState(false);
  const scopes = Object.entries(apiKey.scopes).map(([key, level]) => `${data.resources.find((r) => r.key === key)?.label ?? key}: ${data.levels[level].toLowerCase()}`);

  const revoke = async () => {
    try {
      setBusy(true);
      await api.post(`/integrations/api/keys/${apiKey.id}/revoke`);
      onRevoked();
    } finally {
      setBusy(false);
      setAsking(false);
    }
  };

  return (
    <li className="px-5 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4" data-api-key={apiKey.id}>
      <div className="flex-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm font-bold text-brand-ink">{apiKey.name}</span>
          <code className="text-xs text-slate-500">{apiKey.prefix}…</code>
          {apiKey.revoked_at ? <Badge tone="bg-slate-100 text-slate-500">Revocada</Badge> : <Badge tone="bg-emerald-50 text-emerald-700">Activa</Badge>}
        </div>
        <p className="text-xs text-slate-500">{scopes.join(' · ')}</p>
        <p className="text-xs text-slate-400">
          {apiKey.revoked_at ? `Revocada el ${momentOf(apiKey.revoked_at)}${apiKey.revoked_by ? ` por ${apiKey.revoked_by}` : ''}` : apiKey.last_used_at ? `Último uso: ${momentOf(apiKey.last_used_at)}` : 'Todavía no se usa'}
          {apiKey.created_by ? ` · Creada por ${apiKey.created_by}` : ''}
        </p>
      </div>
      {canEdit && !apiKey.revoked_at && (asking ? (
        <div className="flex items-center gap-2">
          <span className="text-xs text-slate-600">Dejará de funcionar al instante.</span>
          <button type="button" data-confirm-revoke disabled={busy} onClick={revoke} className="px-3 py-1.5 text-xs font-bold bg-rose-600 text-white rounded-lg">Revocar</button>
          <button type="button" onClick={() => setAsking(false)} className="px-3 py-1.5 text-xs font-semibold text-slate-600">No</button>
        </div>
      ) : (
        <button type="button" data-revoke onClick={() => setAsking(true)} className="self-start sm:self-auto px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-50 rounded-lg">Revocar</button>
      ))}
    </li>
  );
}

export default function ApiSettings() {
  const { can, hasModule } = useAuth();
  const canView = can('integrations.view');
  const canEdit = can('integrations.edit');
  const enabled = hasModule('api');
  const [data, setData] = useState<ApiOverview | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [keyOpen, setKeyOpen] = useState(false);
  const [hookOpen, setHookOpen] = useState(false);
  const [editing, setEditing] = useState<Webhook | null>(null);
  const [copied, setCopied] = useState(false);

  const load = useCallback(() => {
    api.get('/integrations/api').then((res) => setData(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar la API y los webhooks.')));
  }, []);

  useEffect(() => { if (canView && enabled) load(); }, [canView, enabled, load]);

  if (!canView) return null;

  if (!enabled) {
    return (
      <section className={cardClass} data-card="api-locked">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <SectionTitle><KeyRound className="w-4 h-4 text-brand-accent" /> API y webhooks</SectionTitle>
          <Badge tone="bg-slate-100 text-slate-600">Requiere módulo API y webhooks</Badge>
        </div>
        <p className="text-sm text-slate-600">Conecta tu página web, tu contabilidad u otro sistema con tu agenda: claves de API, avisos automáticos (webhooks) y tu agenda en Google Calendar u Outlook. Tu plan todavía no incluye este módulo.</p>
      </section>
    );
  }

  if (!data) return error ? <section className={cardClass}><ErrorAlert message={error} /></section> : null;

  if (!data.ready) return <section className={cardClass}><p className="text-sm text-slate-600">{data.message}</p></section>;

  const test = async (hook: Webhook) => {
    try {
      const res = await api.post(`/integrations/api/webhooks/${hook.id}/test`);
      setNotice(res.data.message);
      load();
    } catch (err) {
      setError(apiError(err, 'No se pudo probar el webhook.'));
    }
  };

  return (
    <>
      <section className={cardClass} data-card="api-keys">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <SectionTitle><KeyRound className="w-4 h-4 text-brand-accent" /> Claves de API</SectionTitle>
          {canEdit && <Button variant="classic" type="button" data-new-key onClick={() => setKeyOpen(true)} className="flex items-center gap-1.5"><Plus size={14} /> Nueva clave</Button>}
        </div>
        <p className="text-sm text-slate-600">Con una clave, otro sistema puede ver tus servicios y horarios libres, agendar y cancelar citas, y registrar clientes, siempre con las mismas reglas de tu agenda. Cada clave hace solo lo que le permitas y admite hasta {data.per_minute} solicitudes por minuto.</p>
        <div className="flex flex-col sm:flex-row sm:items-center gap-2 rounded-lg bg-slate-50 px-3 py-2">
          <span className="text-xs font-semibold text-slate-500">Dirección de la API</span>
          <code className="flex-1 min-w-0 break-all text-xs text-slate-700">{data.base_url}</code>
          <button type="button" onClick={async () => { setCopied(await copyText(data.base_url)); setTimeout(() => setCopied(false), 2000); }} className="flex items-center gap-1 text-xs font-semibold text-brand-accent"><Copy size={12} /> {copied ? 'Copiada' : 'Copiar'}</button>
        </div>
        <div className="rounded-xl border border-slate-200">
          <PagedList<ApiKey> items={data.keys} empty="Todavía no hay claves." renderRow={(key) => <KeyRow key={key.id} apiKey={key} data={data} canEdit={canEdit} onRevoked={load} />} />
        </div>
      </section>

      <section className={cardClass} data-card="webhooks">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <SectionTitle><WebhookIcon className="w-4 h-4 text-brand-accent" /> Webhooks salientes</SectionTitle>
          {canEdit && <Button variant="classic" type="button" data-new-webhook onClick={() => { setEditing(null); setHookOpen(true); }} className="flex items-center gap-1.5"><Plus size={14} /> Nuevo webhook</Button>}
        </div>
        <p className="text-sm text-slate-600">Avisa al instante a otro sistema cuando pasa algo, por ejemplo una cita nueva o una venta pagada. Cada aviso va firmado; si el otro sistema no responde, se reintenta solo hasta {data.max_attempts} veces, cada vez con más espera.</p>
        {notice && <SuccessAlert message={notice} />}
        {error && <ErrorAlert message={error} />}
        <div className="rounded-xl border border-slate-200">
          <PagedList<Webhook> items={data.webhooks} empty="Todavía no hay webhooks." renderRow={(hook) => (
            <li key={hook.id} className="px-5 py-3 flex flex-col sm:flex-row sm:items-center gap-2 sm:gap-4" data-webhook={hook.id}>
              <div className="flex-1 min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <Link href={`/settings/webhooks/${hook.id}`} className="text-sm font-bold text-brand-ink hover:text-brand-accent break-all">{hook.description || hook.url}</Link>
                  {!hook.active && <Badge tone="bg-slate-100 text-slate-500">Pausado</Badge>}
                  {hook.last && <Badge tone={DELIVERY_TONES[hook.last.status]}>Último: {hook.last.status_label}</Badge>}
                </div>
                {hook.description && <p className="text-xs text-slate-500 break-all">{hook.url}</p>}
                <p className="text-xs text-slate-400">{hook.events.map((e) => data.events.find((x) => x.key === e)?.label ?? e).join(' · ')}</p>
              </div>
              <div className="flex flex-wrap gap-1">
                <Link href={`/settings/webhooks/${hook.id}`} className="px-3 py-1.5 text-xs font-semibold text-brand-accent hover:bg-slate-50 rounded-lg">Ver envíos</Link>
                {canEdit && (
                  <>
                    <button type="button" data-test-webhook onClick={() => test(hook)} className="flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 rounded-lg"><Send size={12} /> Probar</button>
                    <button type="button" onClick={() => { setEditing(hook); setHookOpen(true); }} className="px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 rounded-lg">Editar</button>
                  </>
                )}
              </div>
            </li>
          )} />
        </div>
      </section>

      <section className={cardClass} data-card="calendars">
        <SectionTitle><CalendarDays className="w-4 h-4 text-brand-accent" /> Calendario externo</SectionTitle>
        <CalendarFeedsCard canEdit={canEdit} />
      </section>

      <KeyDrawer data={data} open={keyOpen} onClose={() => setKeyOpen(false)} onCreated={load} />
      <WebhookDrawer data={data} webhook={editing} open={hookOpen} onClose={() => setHookOpen(false)} onSaved={load} />
    </>
  );
}
