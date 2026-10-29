'use client';

import { useCallback, useEffect, useState } from 'react';
import { Hash, Info } from 'lucide-react';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import type { BrandPayload, CodeType } from '../portalLook';

const clean = (value: string) => value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6);

const numberOf = (code: CodeType) => code.next.slice(code.prefix.length + 1);

export default function CodesEditor() {
  const [data, setData] = useState<BrandPayload | null>(null);
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState('');

  const apply = useCallback((payload: BrandPayload) => {
    setData(payload);
    setDraft(Object.fromEntries(payload.codes.map((code) => [code.type, code.prefix])));
  }, []);

  useEffect(() => {
    api.get<BrandPayload>('/branding')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudieron cargar los códigos.')));
  }, [apply]);

  if (!data) {
    return (
      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-3">
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando los códigos…</p>}
      </section>
    );
  }

  const canEdit = data.can_edit;
  const valueOf = (code: CodeType) => draft[code.type] ?? code.prefix;
  const dirty = data.codes.some((code) => (valueOf(code) || code.default) !== code.prefix);
  const repeated = (code: CodeType) => {
    const value = valueOf(code) || code.default;
    return data.codes.find((other) => other.type !== code.type && (valueOf(other) || other.default) === value);
  };
  const blocked = data.codes.some((code) => repeated(code));

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setFieldErrors({});
      setNotice('');
      const prefixes = Object.fromEntries(data.codes.map((code) => [code.type, valueOf(code) || code.default]));
      apply((await api.put<BrandPayload>('/branding/codes', { prefixes })).data);
      setNotice('Listo: los próximos registros ya salen con estos códigos.');
    } catch (err) {
      const errors = (err as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors ?? {};
      setFieldErrors(Object.fromEntries(Object.entries(errors).map(([key, list]) => [key.replace('prefixes.', ''), list[0]])));
      setError(apiError(err, 'No se pudieron guardar los códigos.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="codes">
      <div>
        <SectionTitle><Hash className="w-4 h-4 text-brand-accent" /> Códigos</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">Con qué letras empiezan los códigos que el sistema pone solo. Por ejemplo, si tus clientes son pacientes, puedes usar PAC en vez de CLI.</p>
      </div>

      <p className="flex items-start gap-2 text-xs text-sky-900 bg-sky-50 border border-sky-200 rounded-xl px-4 py-3" data-codes-notice>
        <Info size={15} className="shrink-0 mt-0.5" />
        <span>
          <b>Solo cambia lo nuevo.</b> Lo que ya existe conserva su código, y la numeración sigue: si ibas en CLI-0124 y cambias a PAC, el siguiente será PAC-0125.
          Las series de tus comprobantes (B001, F001) no se cambian aquí, porque son las que registraste ante SUNAT.
        </span>
      </p>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <div className="divide-y divide-slate-100 rounded-xl border border-slate-200" data-codes-list>
        {data.codes.map((code) => {
          const value = valueOf(code);
          const shown = value || code.default;
          const clash = repeated(code);
          const message = fieldErrors[code.type] || (clash ? `"${shown}-" ya se usa en ${clash.label}. Elige otro para que no se confundan.` : '');
          return (
            <div key={code.type} className="grid grid-cols-[minmax(0,1fr)_auto] md:grid-cols-[minmax(0,11rem)_auto_minmax(0,1fr)] items-center gap-x-4 gap-y-1.5 px-4 py-3" data-code-row={code.type}>
              <span className="text-sm font-semibold text-slate-800">{code.label}</span>
              <span className="flex items-center gap-1 w-28">
                <input
                  aria-label={`Letras de ${code.label}`}
                  value={value}
                  maxLength={6}
                  disabled={!canEdit}
                  placeholder={code.default}
                  onChange={(e) => { const next = clean(e.target.value); setDraft((current) => ({ ...current, [code.type]: next })); setFieldErrors((current) => ({ ...current, [code.type]: '' })); }}
                  className={`${inputClass} font-mono uppercase ${message ? 'border-rose-300 focus:border-rose-400' : ''}`}
                />
                <span className="font-mono text-slate-500">-</span>
              </span>
              <span className="col-span-2 md:col-span-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                <span>Próximo: <b className="font-mono text-brand-ink" data-code-next>{shown}-{numberOf(code)}</b></span>
                {canEdit && shown !== code.default && (
                  <button type="button" data-code-default={code.type} onClick={() => setDraft((current) => ({ ...current, [code.type]: code.default }))} className="font-semibold text-slate-500 hover:text-brand-ink">
                    Volver a {code.default}
                  </button>
                )}
                {message && <span className="basis-full text-rose-600" data-code-error={code.type}>{message}</span>}
              </span>
            </div>
          );
        })}
      </div>

      {canEdit && (
        <div className="flex flex-wrap items-center justify-end gap-2 pt-1">
          {dirty && <Button type="button" variant="secondary" onClick={() => { apply(data); setFieldErrors({}); setError(''); }}>Deshacer cambios</Button>}
          <Button type="button" variant="primary" data-save-codes disabled={busy || !dirty || blocked} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
        </div>
      )}
    </section>
  );
}
