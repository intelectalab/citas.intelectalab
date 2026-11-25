'use client';

import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useState } from 'react';
import { CheckCircle2, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';
import IntegrationLogList, { type IntegrationLogEntry } from './IntegrationLogList';

export interface AssistantAi {
  ready: boolean;
  own: { provider: string; label: string; model: string | null; key_saved: string | null; saved_at: string | null } | null;
  platform: { label: string } | null;
  providers: { key: string; label: string; default_model: string }[];
  quota: { limit: number | null; used: number; reached: boolean };
  log: IntegrationLogEntry[];
}

export default function AssistantAiSettings({ ai, canSave, onChange }: { ai: AssistantAi; canSave: boolean; onChange: (ai: AssistantAi) => void }) {
  const [provider, setProvider] = useState(ai.own?.provider ?? ai.providers[0]?.key ?? 'anthropic');
  const [apiKey, setApiKey] = useState('');
  const [model, setModel] = useState(ai.own?.model ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const chosen = ai.providers.find((p) => p.key === provider);

  const run = async (action: () => Promise<{ data: { ai: AssistantAi; ok?: boolean; message?: string } }>, done: (res: { ok?: boolean; message?: string }) => void) => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const res = await action();
      onChange(res.data.ai);
      setApiKey('');
      done(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const save = () => run(() => api.put('/messaging/assistant', { provider, api_key: apiKey.trim() || null, model: model.trim() || null }), (res) => setNotice(res.message ?? 'Clave guardada y probada.'));
  const test = () => run(() => api.post('/messaging/assistant/test'), (res) => (res.ok ? setNotice(res.message ?? 'Conexión correcta.') : setError(res.message ?? 'Falló la prueba.')));
  const remove = () => run(() => api.delete('/messaging/assistant'), () => setNotice(ai.platform ? 'Se quitó la clave de tu empresa: el asistente usará la de la plataforma.' : 'Se quitó la clave de tu empresa.'));

  return (
    <div className="space-y-4 pt-4 border-t border-slate-100" data-assistant-ai>
      <div>
        <span className={labelClass}>Inteligencia artificial del asistente</span>
        <p className="text-xs text-slate-500 mt-1">
          El asistente entiende lo que escribe el cliente, le dice precios y horarios, busca horas libres reales y reserva, cambia, confirma o cancela citas con las mismas reglas de la reserva en línea. Nunca recibe datos de las fichas de atención.
          {ai.own ? ` Usa la clave de tu empresa (${ai.own.label}).` : ai.platform ? ` Hoy usa la clave de la plataforma (${ai.platform.label}); si prefieres pagar tu propio consumo, pega aquí tu clave.` : ' Todavía no hay una clave: mientras tanto solo aplica el paso a una persona en temas de salud.'}
        </p>
      </div>

      {!ai.ready && <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">Falta actualizar la base de datos para guardar la clave del asistente: ejecuta php artisan migrate.</p>}

      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Proveedor de inteligencia artificial">
        {ai.providers.map((p) => (
          <OptionCard key={p.key} type="button" role="radio" aria-checked={provider === p.key} data-ai-provider={p.key} disabled={!canSave || !ai.ready} selected={provider === p.key}
            onClick={() => { setProvider(p.key); setNotice(''); setError(''); }} className="text-left rounded-xl border px-3.5 py-3 transition-colors">
            <span className="block text-sm font-bold text-brand-ink">{p.label}</span>
            <span className="block text-xs text-slate-500 mt-0.5">{p.key === 'anthropic' ? 'Recomendado.' : 'Alternativa.'} Modelo por defecto: {p.default_model}</span>
          </OptionCard>
        ))}
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        <label className="space-y-1">
          <span className={labelClass}>Clave (API key)</span>
          <input aria-label="Clave del proveedor de inteligencia artificial" type="password" autoComplete="off" maxLength={400} disabled={!canSave || !ai.ready}
            placeholder={ai.own?.provider === provider ? ai.own.key_saved ?? '' : provider === 'anthropic' ? 'sk-ant-...' : 'sk-...'} className={`${inputClass} font-mono`} value={apiKey} onChange={(e) => setApiKey(e.target.value.trim())} />
        </label>
        <label className="space-y-1">
          <span className={labelClass}>Modelo (opcional)</span>
          <input aria-label="Modelo" maxLength={80} disabled={!canSave || !ai.ready} placeholder={chosen?.default_model ?? ''} className={`${inputClass} font-mono`} value={model} onChange={(e) => setModel(e.target.value.trim())} />
        </label>
      </div>
      {ai.own?.saved_at && <p className="text-xs text-slate-500">Guardada el {momentOf(ai.own.saved_at)}. Deja la clave vacía para no cambiarla.</p>}

      <p className="text-xs text-slate-600" data-ai-quota>
        Respuestas del asistente este mes: <span className="font-semibold tabular-nums">{ai.quota.used}</span>{ai.quota.limit !== null ? ` de ${ai.quota.limit} que incluye tu plan` : ' (tu plan no tiene tope)'}.
        {ai.quota.reached && ' Llegaste al tope: los mensajes nuevos pasan a una persona.'}
      </p>

      <div className="flex flex-wrap items-center gap-2">
        {canSave && (
          <button type="button" data-save-ai disabled={busy || !ai.ready || (!apiKey && ai.own?.provider !== provider)} onClick={save} className="px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
            {busy ? 'Probando...' : 'Probar y guardar'}
          </button>
        )}
        <button type="button" data-test-ai disabled={busy || (!ai.own && !ai.platform)} onClick={test} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
          <CheckCircle2 size={14} /> Probar conexión
        </button>
        {canSave && ai.own && (
          <button type="button" data-remove-ai disabled={busy} onClick={remove} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50 rounded-xl transition-colors">
            <Trash2 size={14} /> Quitar la clave
          </button>
        )}
      </div>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      <IntegrationLogList entries={ai.log} />
    </div>
  );
}
