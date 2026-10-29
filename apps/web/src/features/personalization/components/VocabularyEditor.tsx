'use client';

import { useCallback, useEffect, useState } from 'react';
import { RotateCcw, Type } from 'lucide-react';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { useAuth } from '@/hooks/useAuth';
import { makeVocabulary, suggestGender, suggestPlural, type Gender, type GenderedKey } from '@/hooks/useVocabulary';

type Words = Record<string, string>;

interface SettingsData {
  vocabulary: { defaults: Words; overrides: Words; current: Words };
  dependents?: { enabled: boolean };
}

const WORDS: { key: GenderedKey; title: string; hint: string }[] = [
  { key: 'client', title: 'Cómo llamas a quien atiendes', hint: 'Por ejemplo: Cliente, Paciente, Alumno, Tutor.' },
  { key: 'pro', title: 'Cómo llamas a quien atiende', hint: 'Por ejemplo: Profesional, Médico, Odontóloga, Estilista.' },
  { key: 'dependent', title: 'Cómo llamas a quien acompaña al cliente', hint: 'Por ejemplo: Mascota, Hijo, Familiar.' },
];

const plural = (key: GenderedKey) => `${key}s`;

export default function VocabularyEditor() {
  const { can, refetchUser } = useAuth();
  const canEdit = can('settings.edit');
  const [data, setData] = useState<SettingsData | null>(null);
  const [draft, setDraft] = useState<Words>({});
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const apply = useCallback((payload: SettingsData) => {
    setData(payload);
    setDraft({ ...payload.vocabulary.current });
    setTouched({});
  }, []);

  useEffect(() => {
    api.get<SettingsData>('/tenant-settings')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar el vocabulario.')));
  }, [apply]);

  if (!data) {
    return (
      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-3">
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando el vocabulario…</p>}
      </section>
    );
  }

  const defaults = data.vocabulary.defaults;
  const current = data.vocabulary.current;
  const shown = WORDS.filter((word) => word.key !== 'dependent' || data.dependents?.enabled);
  const keys = shown.flatMap((word) => [word.key, plural(word.key), `${word.key}_gender`]);
  const dirty = keys.some((key) => (draft[key] ?? '') !== (current[key] ?? ''));
  const isDefault = keys.every((key) => (draft[key] ?? '') === (defaults[key] ?? ''));
  const v = makeVocabulary(draft);

  const setSingular = (key: GenderedKey, value: string) => {
    setDraft((now) => {
      const next = { ...now, [key]: value };
      const before = now[key] ?? '';
      if (!touched[plural(key)] && (!now[plural(key)] || now[plural(key)] === suggestPlural(before) || now[plural(key)] === current[plural(key)])) {
        next[plural(key)] = suggestPlural(value);
      }
      if (!touched[`${key}_gender`]) next[`${key}_gender`] = suggestGender(value);
      return next;
    });
  };

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      const vocabulary: Words = {};
      for (const word of WORDS) {
        for (const key of [word.key, plural(word.key)]) {
          const value = (draft[key] ?? '').trim();
          vocabulary[key] = value && value !== defaults[key] ? value : '';
        }
        const singular = (draft[word.key] ?? '').trim() || defaults[word.key];
        const gender = draft[`${word.key}_gender`];
        vocabulary[`${word.key}_gender`] = gender && gender !== suggestGender(singular) ? gender : '';
      }
      apply((await api.put<SettingsData>('/tenant-settings', { vocabulary })).data);
      await refetchUser();
      setNotice('Listo: el sistema ya usa estas palabras.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el vocabulario.'));
    } finally {
      setBusy(false);
    }
  };

  const phrases = [
    `${v.pick('client', 'Nuevo', 'Nueva')} ${v.client.toLowerCase()}`,
    `${v.pick('client', 'Este', 'Esta')} ${v.client.toLowerCase()} no existe.`,
    `Elige ${v.pick('pro', 'un', 'una')} ${v.pro.toLowerCase()} o uno de los horarios libres.`,
    `${v.pick('client', 'Todos los', 'Todas las')} ${v.clients.toLowerCase()}`,
    `${v.pick('pro', 'Nuevo', 'Nueva')} ${v.pro.toLowerCase()}`,
    ...(data.dependents?.enabled ? [`Aún no tiene ${v.dependents.toLowerCase()}: ${v.pick('dependent', 'agrégalo', 'agrégala')} con +`] : []),
  ];

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="vocabulary">
      <div>
        <SectionTitle><Type className="w-4 h-4 text-brand-accent" /> Vocabulario</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Las palabras con que el sistema nombra a tus clientes y a tu equipo, en el menú, las pantallas, el portal y los documentos. Elige si se dice &quot;el&quot; o &quot;la&quot; para que las frases salgan bien: &quot;Nueva mascota&quot;, &quot;Este paciente&quot;.</p>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(0,20rem)] gap-6 items-start">
        <div className="space-y-4">
          {shown.map((word) => {
            const gender = (draft[`${word.key}_gender`] as Gender) || 'm';
            return (
              <div key={word.key} className="rounded-xl border border-slate-200 p-4 space-y-3" data-vocab-word={word.key}>
                <div>
                  <span className="block text-sm font-bold text-brand-ink">{word.title}</span>
                  <span className="block text-xs text-slate-500">{word.hint}</span>
                </div>
                <div className="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-3 items-end">
                  <div>
                    <label className={labelClass} htmlFor={`vocab-${word.key}`}>Singular</label>
                    <input id={`vocab-${word.key}`} maxLength={40} disabled={!canEdit} className={inputClass} placeholder={defaults[word.key]} value={draft[word.key] ?? ''} onChange={(e) => setSingular(word.key, e.target.value)} />
                  </div>
                  <div>
                    <label className={labelClass} htmlFor={`vocab-${plural(word.key)}`}>Plural</label>
                    <input id={`vocab-${plural(word.key)}`} maxLength={40} disabled={!canEdit} className={inputClass} placeholder={defaults[plural(word.key)]} value={draft[plural(word.key)] ?? ''} onChange={(e) => { setTouched((now) => ({ ...now, [plural(word.key)]: true })); setDraft((now) => ({ ...now, [plural(word.key)]: e.target.value })); }} />
                  </div>
                  <div>
                    <span className={labelClass}>Se dice</span>
                    <div className="inline-flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label={`Se dice el o la ${draft[word.key] || defaults[word.key]}`}>
                      {(['m', 'f'] as Gender[]).map((option) => (
                        <button
                          key={option}
                          type="button"
                          role="radio"
                          aria-checked={gender === option}
                          disabled={!canEdit}
                          data-vocab-gender={`${word.key}-${option}`}
                          onClick={() => { setTouched((now) => ({ ...now, [`${word.key}_gender`]: true })); setDraft((now) => ({ ...now, [`${word.key}_gender`]: option })); }}
                          className={`px-3 py-1.5 rounded-md text-sm font-semibold ${gender === option ? 'bg-brand text-white' : 'text-slate-600 hover:text-brand-ink'}`}
                        >
                          {option === 'm' ? 'el' : 'la'}
                        </button>
                      ))}
                    </div>
                  </div>
                </div>
              </div>
            );
          })}

          {canEdit && (
            <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
              <button type="button" data-vocab-reset disabled={isDefault} onClick={() => { setDraft({ ...defaults }); setTouched({}); }} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-brand-ink disabled:opacity-40">
                <RotateCcw size={13} /> Volver a las palabras de tu rubro
              </button>
              <div className="flex gap-2">
                {dirty && <Button type="button" variant="secondary" onClick={() => apply(data)}>Deshacer cambios</Button>}
                <Button type="button" variant="primary" data-save-vocabulary disabled={busy || !dirty} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
              </div>
            </div>
          )}
        </div>

        <div className="xl:sticky xl:top-4 space-y-2">
          <span className="text-xs font-bold text-slate-600">Así se leerá {dirty ? '(sin guardar)' : ''}</span>
          <ul className="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-2 text-sm text-slate-700" data-vocab-preview>
            {phrases.map((phrase) => <li key={phrase}>{phrase}</li>)}
            <li className="pt-2 border-t border-slate-200 text-xs text-slate-500">En el menú: {v.clients} · {v.pros}</li>
          </ul>
        </div>
      </div>
    </section>
  );
}
