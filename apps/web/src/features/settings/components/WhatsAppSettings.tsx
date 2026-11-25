'use client';

import OptionCard from '@/app/components/common/OptionCard';
import SectionTitle from '@/app/components/common/SectionTitle';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useEffect, useState } from 'react';
import { Bot, CheckCircle2, Copy, MessageCircle, Route, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import { WHATSAPP_BADGES, type WhatsAppStatus } from '@/features/messages/messages';
import Select from '@/app/components/common/Select';
import AssistantAiSettings, { type AssistantAi } from './AssistantAiSettings';
import IntegrationLogList, { type IntegrationLogEntry } from './IntegrationLogList';
import WhatsAppTemplatesCatalog, { TEMPLATE_TONES, type CatalogTemplate, type MetaTemplatesInfo } from './WhatsAppTemplatesCatalog';
import Badge from '@/app/components/common/Badge';
import Link from 'next/link';

const cardClass = 'bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4';

type Provider = 'none' | 'simulated' | 'cloud' | '360dialog';
type MetaTemplate = { name: string; language: string; params: number; body: string };
type Template = { name: string; language: string };

interface Rules {
  channel: 'email' | 'whatsapp' | 'both';
  consent: 'required' | 'unless_revoked';
  assistant_enabled: boolean;
  blocked_words: string;
  assistant_can_book: boolean;
  assistant_handoff_minutes: number;
}

interface Payload {
  status: WhatsAppStatus;
  simulate_forced: boolean;
  webhook_url: string;
  config: {
    provider: 'simulated' | 'cloud' | '360dialog';
    phone_number_id: string;
    business_account_id: string;
    display_phone: string;
    token_saved: string | null;
    app_secret_saved: string | null;
    verify_token: string | null;
    templates: Record<string, Template>;
    tested_at: string | null;
    test_message: string | null;
    saved_at: string | null;
  } | null;
  notices: { kind: string; label: string; vars: { position: number; token: string; label: string }[] }[];
  rules: Rules;
  assistant: { provider: string; connected: boolean; stats: { action: string; label: string; total: number }[]; ai: AssistantAi };
  log: IntegrationLogEntry[];
  waiting: number;
  meta_templates?: MetaTemplatesInfo;
}

const EMPTY = { phone_number_id: '', business_account_id: '', display_phone: '', token: '', app_secret: '', verify_token: '' };

const CHANNELS: [Rules['channel'], string, string][] = [
  ['email', 'Solo correo', 'Como hasta ahora: los avisos salen por correo.'],
  ['whatsapp', 'WhatsApp', 'Por WhatsApp; si no se puede (sin celular, sin permiso o falla), sale por correo.'],
  ['both', 'WhatsApp y correo', 'Cada aviso sale por los dos.'],
];

const CONSENTS: [Rules['consent'], string, string][] = [
  ['required', 'Solo a quien aceptó', 'Se escribe por WhatsApp solo a los clientes que marcaron que aceptan WhatsApp en su ficha.'],
  ['unless_revoked', 'A todos, salvo quien lo rechazó', 'Se escribe a todos los que tienen celular, menos a quien pidió no recibir WhatsApp.'],
];

export default function WhatsAppSettings({ canSave, onDirtyChange }: { canSave: boolean; onDirtyChange?: (dirty: boolean) => void }) {
  const [data, setData] = useState<Payload | null>(null);
  const [provider, setProvider] = useState<Provider>('none');
  const [form, setForm] = useState(EMPTY);
  const [templates, setTemplates] = useState<Record<string, Template>>({});
  const [rules, setRules] = useState<Rules | null>(null);
  const [testTo, setTestTo] = useState('');
  const [copied, setCopied] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [rulesNotice, setRulesNotice] = useState('');
  const [metaTemplates, setMetaTemplates] = useState<MetaTemplate[] | null>(null);

  const apply = (payload: Payload) => {
    setData(payload);
    setProvider(payload.config?.provider ?? 'none');
    setForm({
      phone_number_id: payload.config?.phone_number_id ?? '',
      business_account_id: payload.config?.business_account_id ?? '',
      display_phone: payload.config?.display_phone ?? '',
      token: '',
      app_secret: '',
      verify_token: '',
    });
    setTemplates({ ...(payload.config?.templates ?? {}) });
    setRules(payload.rules);
  };

  useEffect(() => {
    api.get('/messaging/whatsapp')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar WhatsApp.')));
  }, []);

  const savedProvider: Provider = data?.config?.provider ?? 'none';
  const configDirty = Boolean(data) && (provider !== savedProvider
    || Boolean(form.token || form.app_secret || form.verify_token)
    || form.phone_number_id !== (data?.config?.phone_number_id ?? '')
    || form.business_account_id !== (data?.config?.business_account_id ?? '')
    || form.display_phone !== (data?.config?.display_phone ?? '')
    || JSON.stringify(Object.fromEntries(Object.entries(templates).filter(([, t]) => t.name.trim()))) !== JSON.stringify(data?.config?.templates ?? {}));
  const rulesDirty = Boolean(data && rules) && JSON.stringify(rules) !== JSON.stringify(data?.rules);

  useEffect(() => { onDirtyChange?.(configDirty || rulesDirty); }, [configDirty, rulesDirty, onDirtyChange]);

  useEffect(() => () => onDirtyChange?.(false), [onDirtyChange]);

  if (!data || !rules) {
    return error ? <section className={cardClass}><p className="text-sm text-slate-600">{error}</p></section> : null;
  }

  const run = async (action: () => Promise<{ data: Payload & { ok?: boolean; message?: string } }>, done: (res: Payload & { ok?: boolean; message?: string }) => void) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await action();
      apply(res.data);
      done(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const save = () => {
    if (provider === 'none') {
      run(() => api.delete('/messaging/whatsapp'), () => setNotice('Listo: WhatsApp quedó sin conectar. Los avisos salen solo por correo.'));
      return;
    }
    const cleanTemplates = Object.fromEntries(Object.entries(templates).filter(([, t]) => t.name.trim()).map(([k, t]) => [k, { name: t.name.trim(), language: t.language.trim() || 'es' }]));
    const body = provider === 'cloud' ? { provider, ...form, templates: cleanTemplates }
      : provider === '360dialog' ? { provider, token: form.token, verify_token: form.verify_token, display_phone: form.display_phone, templates: cleanTemplates }
        : { provider, templates: cleanTemplates };
    run(() => api.put('/messaging/whatsapp', body),
      () => setNotice(provider === 'cloud' ? 'Guardado. Ahora pulsa "Probar conexión" para comprobar que Meta reconoce tus claves.' : provider === '360dialog' ? 'Guardado. Pulsa "Probar conexión": comprueba la clave y deja registrada en 360dialog la dirección para recibir los mensajes.' : 'Listo: WhatsApp quedó en "Probar sin enviar". Los mensajes se arman y se guardan, pero no salen.'));
  };

  const test = () => run(() => api.post('/messaging/whatsapp/test', testTo ? { to: testTo } : {}), (res) => {
    if (res.ok) setNotice(res.message ?? 'Conexión correcta.');
    else setError(res.message ?? 'Falló la prueba.');
  });

  const loadTemplates = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.get('/messaging/whatsapp/templates');
      if (res.data.ok) {
        setMetaTemplates(res.data.templates);
        if (data && data.meta_templates && res.data.catalog) setData({ ...data, meta_templates: { ...data.meta_templates, catalog: res.data.catalog as CatalogTemplate[], synced_at: new Date().toISOString() } });
        setNotice(res.data.templates.length ? `Se leyeron ${res.data.templates.length} plantillas aprobadas: elige la de cada aviso.` : 'Tu cuenta todavía no tiene plantillas aprobadas.');
      } else {
        setError(res.data.message ?? 'No se pudieron leer las plantillas.');
      }
    } catch (err) {
      setError(apiError(err, 'No se pudieron leer las plantillas.'));
    } finally {
      setBusy(false);
    }
  };

  const saveRules = async () => {
    try {
      setBusy(true);
      setError('');
      setRulesNotice('');
      const res = await api.put('/messaging/whatsapp/rules', rules);
      apply(res.data);
      setRulesNotice('Reglas guardadas.');
    } catch (err) {
      setError(apiError(err, 'No se pudieron guardar las reglas.'));
    } finally {
      setBusy(false);
    }
  };

  const copy = async (what: string, value: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(what);
      setTimeout(() => setCopied(''), 2000);
    } catch {
      setCopied('');
    }
  };

  const status = data.status;
  const connected = status.state !== 'none' && status.state !== 'not_ready';

  return (
    <>
      <section className={cardClass} data-card="whatsapp">
        <div>
          <SectionTitle><MessageCircle className="w-4 h-4 text-brand-accent" /> WhatsApp</SectionTitle>
          <p className="text-xs text-slate-500 mt-1">Conecta el número de WhatsApp de tu empresa con la API oficial de Meta. Necesitas una cuenta de WhatsApp Business en Meta; cada conversación que inicia tu empresa tiene un costo que cobra Meta. Las claves se guardan cifradas y nunca se muestran completas.</p>
        </div>

        <div className={`rounded-xl border px-4 py-3 text-sm font-semibold ${WHATSAPP_BADGES[status.state]}`} data-whatsapp-state={status.state}>{status.label}</div>

        {status.state !== 'not_ready' && (
          <>
            <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2" role="radiogroup" aria-label="Conexión de WhatsApp">
              {([
                ['none', 'Sin conectar', 'Los avisos salen solo por correo.'],
                ['simulated', 'Probar sin enviar', 'Los mensajes se arman y se guardan, pero no salen.'],
                ['cloud', 'WhatsApp de Meta', 'Con las claves de tu cuenta de WhatsApp Business.'],
                ['360dialog', '360dialog', 'Si tu número lo administra 360dialog: solo pegas su clave.'],
              ] as [Provider, string, string][]).map(([key, label, hint]) => (
                <OptionCard key={key} type="button" role="radio" aria-checked={provider === key} data-wa-provider={key} disabled={!canSave} selected={provider === key}
                  onClick={() => { setProvider(key); setNotice(''); setError(''); }} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
                  <span className="block text-sm font-bold text-brand-ink">{label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
                </OptionCard>
              ))}
            </div>

            {provider === '360dialog' && (
              <div className="rounded-xl border border-slate-200 p-4 space-y-4" data-wa-keys="360dialog">
                <p className="text-sm text-slate-600">En tu panel de 360dialog entra a tu número y genera la clave de la API (API key). Con ella se envían los mensajes; al pulsar &quot;Probar conexión&quot; el sistema registra solo en 360dialog la dirección para recibir lo que te escriben, con una clave que comprueba que los avisos vienen de ahí.</p>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label className="space-y-1">
                    <span className={labelClass}>Clave de 360dialog (API key)</span>
                    <input aria-label="Clave de 360dialog" type="password" autoComplete="off" maxLength={1000} disabled={!canSave} placeholder={data.config?.provider === '360dialog' ? data.config.token_saved ?? '' : ''} className={`${inputClass} font-mono`} value={form.token} onChange={(e) => setForm({ ...form, token: e.target.value.trim() })} />
                  </label>
                  <label className="space-y-1">
                    <span className={labelClass}>Número que ven tus clientes (opcional)</span>
                    <input aria-label="Número de WhatsApp en 360dialog" maxLength={30} disabled={!canSave} placeholder="+51 999 888 777" className={inputClass} value={form.display_phone} onChange={(e) => setForm({ ...form, display_phone: e.target.value })} />
                  </label>
                </div>
                {data.config?.saved_at && data.config.provider === '360dialog' && <p className="text-xs text-slate-500">Guardado el {momentOf(data.config.saved_at)}. Deja la clave vacía para no cambiarla.</p>}
              </div>
            )}

            {provider !== 'none' && provider !== 'simulated' && data.simulate_forced && (
              <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">En este equipo de pruebas WhatsApp siempre se simula: aunque guardes claves reales, no sale ningún mensaje.</p>
            )}

            {provider === 'cloud' && (
              <div className="rounded-xl border border-slate-200 p-4 space-y-4" data-wa-keys>
                <p className="text-sm text-slate-600">En Meta entra a tu aplicación → WhatsApp → Configuración de la API. Ahí están el identificador del número y el de la cuenta. Para no tener que renovarlo, usa un token de un usuario del sistema (Configuración del negocio → Usuarios del sistema). La clave secreta de la aplicación está en Configuración de la app → Básica.</p>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                  <label className="space-y-1">
                    <span className={labelClass}>Identificador del número (Phone number ID)</span>
                    <input aria-label="Identificador del número" inputMode="numeric" maxLength={60} disabled={!canSave} className={`${inputClass} font-mono`} value={form.phone_number_id} onChange={(e) => setForm({ ...form, phone_number_id: e.target.value.trim() })} />
                  </label>
                  <label className="space-y-1">
                    <span className={labelClass}>Identificador de la cuenta (opcional)</span>
                    <input aria-label="Identificador de la cuenta" inputMode="numeric" maxLength={60} disabled={!canSave} className={`${inputClass} font-mono`} value={form.business_account_id} onChange={(e) => setForm({ ...form, business_account_id: e.target.value.trim() })} />
                  </label>
                  <label className="space-y-1">
                    <span className={labelClass}>Token de acceso</span>
                    <input aria-label="Token de acceso" type="password" autoComplete="off" maxLength={1000} disabled={!canSave} placeholder={data.config?.token_saved ?? 'EAA...'} className={`${inputClass} font-mono`} value={form.token} onChange={(e) => setForm({ ...form, token: e.target.value.trim() })} />
                  </label>
                  <label className="space-y-1">
                    <span className={labelClass}>Clave secreta de la aplicación (App Secret)</span>
                    <input aria-label="Clave secreta de la aplicación" type="password" autoComplete="off" maxLength={255} disabled={!canSave} placeholder={data.config?.app_secret_saved ?? ''} className={`${inputClass} font-mono`} value={form.app_secret} onChange={(e) => setForm({ ...form, app_secret: e.target.value.trim() })} />
                  </label>
                  <label className="space-y-1">
                    <span className={labelClass}>Número que ven tus clientes (opcional)</span>
                    <input aria-label="Número de WhatsApp" maxLength={30} disabled={!canSave} placeholder="+51 999 888 777" className={inputClass} value={form.display_phone} onChange={(e) => setForm({ ...form, display_phone: e.target.value })} />
                  </label>
                </div>
                {data.config?.saved_at && <p className="text-xs text-slate-500">Guardado el {momentOf(data.config.saved_at)}. Deja el token y la clave vacíos para no cambiarlos.</p>}
              </div>
            )}

            {provider !== 'none' && (
              <div className="rounded-lg bg-slate-50 border border-slate-200 p-3 space-y-2" data-wa-webhook>
                <span className="block text-xs font-bold text-slate-700">Mensajes que te escriben los clientes</span>
                <p className="text-xs text-slate-600">{provider === '360dialog' ? 'Con 360dialog esta dirección se registra sola al probar la conexión. Así ves aquí lo que te escriben y si cada mensaje llegó y se leyó.' : <>En Meta → WhatsApp → Configuración → Webhook, pega esta dirección y la clave de verificación, y suscríbete a &quot;messages&quot;. Así ves aquí lo que te escriben y si cada mensaje llegó y se leyó.</>}</p>
                <div className="flex flex-col sm:flex-row gap-2">
                  <input readOnly aria-label="Dirección del webhook de WhatsApp" value={data.webhook_url} onFocus={(e) => e.target.select()} className={`${inputClass} font-mono text-xs flex-1`} />
                  <button type="button" data-copy-webhook onClick={() => copy('url', data.webhook_url)} className="flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50">
                    <Copy size={14} /> {copied === 'url' ? '¡Copiada!' : 'Copiar'}
                  </button>
                </div>
                {data.config?.verify_token ? (
                  <div className="flex flex-col sm:flex-row gap-2">
                    <input readOnly aria-label="Clave de verificación" value={data.config.verify_token} onFocus={(e) => e.target.select()} className={`${inputClass} font-mono text-xs flex-1`} />
                    {canSave && (
                      <button type="button" data-copy-verify onClick={() => copy('verify', data.config?.verify_token ?? '')} className="flex items-center justify-center gap-1.5 px-3 py-2 text-sm font-semibold border border-slate-200 bg-white rounded-lg text-slate-700 hover:bg-slate-50">
                        <Copy size={14} /> {copied === 'verify' ? '¡Copiada!' : 'Copiar'}
                      </button>
                    )}
                  </div>
                ) : <p className="text-xs text-slate-500">La clave de verificación se crea sola al guardar.</p>}
              </div>
            )}

            {provider !== 'none' && (
              <div className="space-y-2" data-wa-templates>
                <span className={labelClass}>Plantillas aprobadas por Meta</span>
                <p className="text-xs text-slate-500">WhatsApp solo deja escribir libremente dentro de las 24 horas después del último mensaje del cliente. Fuera de ese plazo, cada aviso necesita una plantilla aprobada en Meta. Escribe su nombre tal como está en Meta, o tráelas con el botón; sus datos van en el orden que se indica. Un aviso sin plantilla sale por correo cuando pasan las 24 horas.</p>
                {provider !== 'simulated' && canSave && data.config && data.config.provider === provider && (
                  <button type="button" data-load-templates disabled={busy} onClick={loadTemplates} className="px-3 py-2 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
                    Traer las plantillas aprobadas
                  </button>
                )}
                {data.meta_templates && (
                  <WhatsAppTemplatesCatalog info={data.meta_templates} canSave={canSave && Boolean(data.config) && data.config?.provider === provider} simulated={provider === 'simulated'}
                    onChange={(catalog, message) => { setData({ ...data, meta_templates: { ...data.meta_templates!, catalog } }); setNotice(message); }} />
                )}
                <div className="rounded-xl border border-slate-200 overflow-hidden">
                  <PagedList
                    items={data.notices}
                    empty="Sin avisos."
                    renderRow={(n) => (
                      <li key={n.kind} className="px-4 py-3 space-y-2" data-wa-template={n.kind}>
                        <span className="flex flex-wrap items-center gap-2 text-sm font-bold text-slate-800">{n.label}
                          {(() => { const chosen = templates[n.kind]; const found = chosen?.name ? data.meta_templates?.catalog.find((t) => t.name === chosen.name && t.language === (chosen.language || 'es')) : null; return found ? <Badge tone={TEMPLATE_TONES[found.status]} nowrap data-notice-template-status={found.status}>{found.status_label}</Badge> : null; })()}
                        </span>
                        <span className="block text-[11px] text-slate-500">Datos: {n.vars.map((v) => `{{${v.position}}} ${v.label.toLowerCase()}`).join(' · ')}</span>
                        {metaTemplates && metaTemplates.length > 0 && canSave && (
                          <Select ariaLabel={`Elegir plantilla para ${n.label}`} value={templates[n.kind]?.name ? `${templates[n.kind].name}|${templates[n.kind].language}` : ''} placeholder="Elegir una plantilla aprobada" clearable
                            options={metaTemplates.map((t) => ({ value: `${t.name}|${t.language}`, label: `${t.name} (${t.language})${t.params !== n.vars.length ? ` · usa ${t.params} datos` : ''}` }))}
                            onChange={(e) => { const [name, language] = String(e.target.value || '|').split('|'); setTemplates({ ...templates, [n.kind]: { name: name ?? '', language: language || 'es' } }); }} />
                        )}
                        <div className="grid grid-cols-1 sm:grid-cols-[1fr_7rem] gap-2">
                          <input aria-label={`Plantilla de ${n.label}`} maxLength={120} disabled={!canSave} placeholder="nombre_de_la_plantilla" className={`${inputClass} font-mono text-xs`}
                            value={templates[n.kind]?.name ?? ''} onChange={(e) => setTemplates({ ...templates, [n.kind]: { name: e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''), language: templates[n.kind]?.language ?? 'es' } })} />
                          <input aria-label={`Idioma de ${n.label}`} maxLength={10} disabled={!canSave} placeholder="es" className={`${inputClass} font-mono text-xs`}
                            value={templates[n.kind]?.language ?? ''} onChange={(e) => setTemplates({ ...templates, [n.kind]: { name: templates[n.kind]?.name ?? '', language: e.target.value.replace(/[^a-zA-Z_]/g, '') } })} />
                        </div>
                      </li>
                    )}
                  />
                </div>
              </div>
            )}

            <div className="flex flex-wrap items-center gap-2">
              {canSave && (
                <button type="button" data-save-whatsapp disabled={busy || !configDirty} onClick={save} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
                  {busy ? 'Guardando...' : 'Guardar'}
                </button>
              )}
              {canSave && data.config && provider !== 'none' && (
                <button type="button" data-remove-whatsapp disabled={busy} onClick={() => { setProvider('none'); run(() => api.delete('/messaging/whatsapp'), () => setNotice('Listo: WhatsApp quedó sin conectar.')); }} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
                  <Trash2 size={14} /> Desconectar
                </button>
              )}
            </div>

            {canSave && connected && !configDirty && (
              <div className="space-y-2 pt-4 border-t border-slate-100" data-test-whatsapp>
                <span className={labelClass}>Probar la conexión</span>
                <div className="flex flex-wrap items-center gap-2">
                  <input aria-label="Celular para la prueba" inputMode="tel" maxLength={30} className={`${inputClass} max-w-xs`} placeholder="Celular (opcional)" value={testTo} onChange={(e) => setTestTo(e.target.value)} />
                  <button type="button" data-test-whatsapp-button disabled={busy} onClick={test} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold border border-brand text-brand-ink rounded-xl hover:bg-brand-soft/15 disabled:opacity-50">
                    <CheckCircle2 size={14} /> Probar conexión
                  </button>
                </div>
                <p className="text-xs text-slate-500">Comprueba que {provider === '360dialog' ? '360dialog' : 'Meta'} reconoce tus claves. Si escribes un celular, le llega la plantilla de prueba de Meta. {data.config?.tested_at ? `Última prueba: ${momentOf(data.config.tested_at)}.` : ''}</p>
              </div>
            )}

            <SuccessAlert message={notice} />
            {provider !== 'none' && <IntegrationLogList entries={data.log} />}
          </>
        )}
        <ErrorAlert message={error} />
      </section>

      {status.state !== 'not_ready' && (
        <section className={cardClass} data-card="whatsapp-rules">
          <div>
            <SectionTitle><Route className="w-4 h-4 text-brand-accent" /> Por dónde salen los avisos</SectionTitle>
            <p className="text-xs text-slate-500 mt-1">Vale para los avisos automáticos: confirmación, recordatorios, cambios de la cita con su enlace para confirmar o cancelar, lista de espera, controles, paquetes, membresías y comprobantes. El link de pago sigue saliendo por correo.</p>
          </div>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-2" role="radiogroup" aria-label="Canal de los avisos">
            {CHANNELS.map(([key, label, hint]) => (
              <OptionCard key={key} type="button" role="radio" aria-checked={rules.channel === key} data-wa-channel={key} disabled={!canSave} selected={rules.channel === key}
                onClick={() => { setRules({ ...rules, channel: key }); setRulesNotice(''); }} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
                <span className="block text-sm font-bold text-brand-ink">{label}</span>
                <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
              </OptionCard>
            ))}
          </div>
          {rules.channel !== 'email' && !connected && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Mientras WhatsApp no esté conectado, los avisos siguen saliendo por correo.</p>}
          <div className="space-y-1.5">
            <span className={labelClass}>A quién se le escribe por WhatsApp</span>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Permiso del cliente">
              {CONSENTS.map(([key, label, hint]) => (
                <OptionCard key={key} type="button" role="radio" aria-checked={rules.consent === key} data-wa-consent={key} disabled={!canSave} selected={rules.consent === key}
                  onClick={() => { setRules({ ...rules, consent: key }); setRulesNotice(''); }} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
                  <span className="block text-sm font-bold text-brand-ink">{label}</span>
                  <span className="block text-xs text-slate-500 mt-0.5">{hint}</span>
                </OptionCard>
              ))}
            </div>
          </div>
        </section>
      )}

      {status.state !== 'not_ready' && (
        <section className={cardClass} data-card="assistant">
          <div className="flex items-start justify-between gap-4">
            <div>
              <SectionTitle><Bot className="w-4 h-4 text-brand-accent" /> Asistente de WhatsApp</SectionTitle>
              <p className="text-xs text-slate-500 mt-1">Responde solo los mensajes que te escriben por WhatsApp. Nunca responde temas de salud: esos pasan a una persona del equipo, que recibe un aviso.</p>
            </div>
            <Switch checked={rules.assistant_enabled} disabled={!canSave} label="Encender el asistente" onChange={(on) => { setRules({ ...rules, assistant_enabled: on }); setRulesNotice(''); }} />
          </div>
          <div className="flex items-start justify-between gap-4 rounded-xl border border-slate-200 px-4 py-3">
            <span>
              <span className="block text-sm font-bold text-brand-ink">Puede reservar y cambiar citas</span>
              <span className="block text-xs text-slate-500 mt-0.5">Apagado, el asistente solo informa precios, horarios y horas libres, y para reservar pasa con una persona.</span>
            </span>
            <Switch checked={rules.assistant_can_book} disabled={!canSave} label="El asistente puede reservar" onChange={(on) => { setRules({ ...rules, assistant_can_book: on }); setRulesNotice(''); }} />
          </div>
          <label className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 rounded-xl border border-slate-200 px-4 py-3">
            <span>
              <span className="block text-sm font-bold text-brand-ink">Pasar a una persona si no se resuelve en</span>
              <span className="block text-xs text-slate-500 mt-0.5">Si la conversación sigue sin resolverse pasados estos minutos, o nadie le respondió al cliente, pasa a la bandeja con un resumen de lo hablado.</span>
            </span>
            <span className="flex items-center gap-2 shrink-0">
              <input aria-label="Minutos para pasar a una persona" type="number" inputMode="numeric" min={1} max={120} disabled={!canSave} className={`${inputClass} w-24`}
                value={rules.assistant_handoff_minutes ?? 10} onChange={(e) => { setRules({ ...rules, assistant_handoff_minutes: Math.max(1, Math.min(120, Number(e.target.value) || 1)) }); setRulesNotice(''); }} />
              <span className="text-sm text-slate-600">minutos</span>
            </span>
          </label>
          <label className="block space-y-1">
            <span className={labelClass}>Palabras de temas de salud (separadas por comas)</span>
            <textarea aria-label="Palabras de temas de salud" rows={3} maxLength={3000} disabled={!canSave} className={inputClass} value={rules.blocked_words} onChange={(e) => { setRules({ ...rules, blocked_words: e.target.value }); setRulesNotice(''); }} />
            <span className="block text-xs text-slate-500">Si el mensaje del cliente tiene alguna, el asistente no responde y la conversación queda como &quot;Necesita una persona&quot;.</span>
          </label>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-2" data-assistant-stats>
            {data.assistant.stats.filter((s) => s.action !== 'none' || s.total > 0).map((s) => (
              <div key={s.action} className="rounded-xl border border-slate-200 px-3 py-2">
                <span className="block text-lg font-bold text-brand-ink tabular-nums">{s.total}</span>
                <span className="block text-xs text-slate-500">{s.label}</span>
              </div>
            ))}
          </div>
          <p className="text-xs text-slate-400">Últimos 30 días. <Link href="/messages/assistant" className="font-semibold text-brand-ink underline underline-offset-2" data-assistant-board-link>Ver el tablero de la recepcionista y probarla</Link></p>
          {data.assistant.ai && <AssistantAiSettings ai={data.assistant.ai} canSave={canSave} onChange={(ai) => setData({ ...data, assistant: { ...data.assistant, ai, connected: Boolean(ai.own || ai.platform) } })} />}
          <div className="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-slate-100" data-save-bar="whatsapp-rules">
            {rulesNotice && <span className="text-sm font-medium text-emerald-600">{rulesNotice}</span>}
            {rulesDirty && !rulesNotice && <span className="text-xs text-amber-700" data-unsaved>Tienes cambios sin guardar.</span>}
            {canSave && (
              <button type="button" data-save-wa-rules disabled={busy || !rulesDirty} onClick={saveRules} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
                Guardar reglas
              </button>
            )}
          </div>
        </section>
      )}
    </>
  );
}
