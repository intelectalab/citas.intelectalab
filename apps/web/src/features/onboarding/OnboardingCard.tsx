'use client';

import { useCallback, useEffect, useState } from 'react';
import Link from 'next/link';
import { Check, Database, Rocket, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';

interface OnboardingStep {
  key: string;
  title: string;
  hint: string;
  url: string;
  done: boolean;
}

interface SampleStatus {
  ready: boolean;
  loaded: boolean;
  customers: number;
  professionals: number;
  appointments: number;
}

interface OnboardingData {
  visible: boolean;
  can_hide: boolean;
  steps: OnboardingStep[];
  done: number;
  total: number;
  next: string | null;
  sample_data: SampleStatus | null;
}

const message = (err: unknown, fallback: string) =>
  (err as { response?: { data?: { message?: string } } })?.response?.data?.message ?? fallback;

export default function OnboardingCard() {
  const { user } = useAuth();
  const vocab = useVocabulary();
  const clients = vocab.clients.toLowerCase();
  const [data, setData] = useState<OnboardingData | null>(null);
  const [busy, setBusy] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState('');

  const load = useCallback(() => {
    api.get('/tenant/onboarding').then((res) => setData(res.data)).catch(() => setData(null));
  }, []);

  useEffect(() => {
    if (user?.tenant && user.is_tenant_admin) load();
  }, [user?.tenant, user?.is_tenant_admin, load]);

  if (!data) return null;

  const sample = data.sample_data;
  const showSample = Boolean(sample?.ready && (sample.loaded || data.visible));

  if (!data.visible && !showSample) return null;

  const run = async (action: 'hide' | 'sample' | 'remove') => {
    setBusy(action);
    setError('');
    setNote('');
    try {
      if (action === 'hide') {
        await api.post('/tenant/onboarding/hide');
      } else if (action === 'sample') {
        const res = await api.post('/tenant/sample-data');
        setNote(`Listo: cargamos ${res.data.customers} ${clients}, ${res.data.professionals} personas del equipo y ${res.data.appointments} citas de ejemplo para que pruebes. Puedes borrarlos cuando quieras.`);
      } else {
        const res = await api.delete('/tenant/sample-data');
        setNote(res.data.message);
      }
      load();
    } catch (err) {
      setError(message(err, 'No se pudo completar. Inténtalo de nuevo.'));
    } finally {
      setBusy('');
    }
  };

  const percent = data.total ? Math.round((data.done / data.total) * 100) : 0;

  return (
    <section data-onboarding className="bg-white border border-slate-200 rounded-2xl shadow-2xs overflow-hidden">
      {data.visible && (
        <>
          <div className="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div className="flex items-start gap-3 min-w-0">
              <div className="p-2 rounded-xl bg-slate-50 border border-slate-100 text-brand-accent shrink-0"><Rocket size={18} /></div>
              <div className="min-w-0">
                <h2 className="text-base font-bold text-brand-ink">Primeros pasos</h2>
                <p className="text-xs text-slate-500">
                  {data.done} de {data.total} listos. Cada paso se marca solo cuando lo haces.
                </p>
              </div>
            </div>
            <div className="flex items-center gap-3">
              <div className="w-40 h-2 rounded-full bg-slate-100 overflow-hidden" role="progressbar" aria-valuemin={0} aria-valuemax={data.total} aria-valuenow={data.done} aria-label="Avance de los primeros pasos">
                <div className="h-full bg-brand rounded-full" style={{ width: `${percent}%` }} />
              </div>
              {data.can_hide && (
                <button type="button" data-action="hide-onboarding" disabled={busy !== ''} onClick={() => run('hide')} className="text-xs font-semibold text-slate-500 hover:text-slate-800 disabled:opacity-50">
                  Ocultar
                </button>
              )}
            </div>
          </div>

          <ol className="divide-y divide-slate-100">
            {data.steps.map((step) => {
              const isNext = step.key === data.next;
              return (
                <li key={step.key} data-step={step.key} data-done={step.done ? '1' : '0'} className="px-5 py-3 flex items-center gap-3">
                  <span className={`w-6 h-6 rounded-full flex items-center justify-center shrink-0 border ${step.done ? 'bg-emerald-500 border-emerald-500 text-white' : isNext ? 'border-brand text-brand-ink' : 'border-slate-300 text-slate-300'}`}>
                    {step.done ? <Check size={14} className="stroke-[3]" /> : null}
                  </span>
                  <div className="min-w-0 flex-1">
                    <span className={`block text-sm font-semibold ${step.done ? 'text-slate-400 line-through' : 'text-slate-800'}`}>{step.title}</span>
                    {!step.done && <span className="block text-xs text-slate-500">{step.hint}</span>}
                  </div>
                  {!step.done && (
                    <Link
                      href={step.url}
                      className={`shrink-0 px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors ${isNext ? 'bg-brand text-white border-brand hover:bg-brand-hover' : 'bg-white text-slate-700 border-slate-200 hover:bg-slate-50'}`}
                    >
                      {isNext ? 'Configurar' : 'Ir'}
                    </Link>
                  )}
                </li>
              );
            })}
          </ol>
        </>
      )}

      {showSample && sample && (
        <div className={`px-5 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-slate-50 ${data.visible ? 'border-t border-slate-100' : ''}`} data-sample-data>
          <div className="flex items-start gap-3 min-w-0">
            <Database size={18} className="text-slate-400 mt-0.5 shrink-0" />
            <p className="text-xs text-slate-600">
              {sample.loaded
                ? `Tienes datos de ejemplo: ${sample.customers} ${clients}, ${sample.professionals} del equipo y ${sample.appointments} citas. Bórralos antes de empezar a trabajar de verdad.`
                : `¿Quieres ver cómo se ve todo con movimiento? Carga ${clients}, equipo y citas de ejemplo. Se borran con un clic y no tocan tus datos.`}
            </p>
          </div>
          {sample.loaded ? (
            <button type="button" data-action="remove-sample" disabled={busy !== ''} onClick={() => run('remove')} className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-rose-200 text-rose-700 bg-white hover:bg-rose-50 disabled:opacity-50">
              <Trash2 size={14} /> {busy === 'remove' ? 'Borrando...' : 'Borrar los datos de ejemplo'}
            </button>
          ) : (
            <button type="button" data-action="load-sample" disabled={busy !== ''} onClick={() => run('sample')} className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 text-slate-700 bg-white hover:bg-slate-100 disabled:opacity-50">
              <Database size={14} /> {busy === 'sample' ? 'Cargando...' : 'Cargar datos de ejemplo'}
            </button>
          )}
        </div>
      )}

      {(note || error) && (
        <p className={`px-5 py-3 text-xs border-t ${error ? 'text-rose-700 bg-rose-50 border-rose-100' : 'text-emerald-700 bg-emerald-50 border-emerald-100'}`}>{error || note}</p>
      )}
    </section>
  );
}
