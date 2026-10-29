'use client';

import { useCallback, useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { RotateCcw, ShieldCheck } from 'lucide-react';
import Button from '@/app/components/common/Button';
import SectionTitle from '@/app/components/common/SectionTitle';
import RichTextEditor, { type RichTextHandle } from '@/app/components/common/RichTextEditor';
import RichTextView from '@/app/components/common/RichTextView';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { fillTokens, type BrandPayload } from '../portalLook';

const canonical = (html: string) => {
  const box = document.createElement('div');
  box.innerHTML = html;
  return box.innerHTML.replace(/<p><\/p>/g, '').trim();
};

const same = (a: string, b: string) => canonical(a) === canonical(b);

const dateOf = (iso: string) => new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });

export default function PrivacyEditor() {
  const [data, setData] = useState<BrandPayload | null>(null);
  const [body, setBody] = useState('');
  const [email, setEmail] = useState('');
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const editor = useRef<RichTextHandle>(null);

  const apply = useCallback((payload: BrandPayload) => {
    setData(payload);
    setBody(payload.portal.privacy.body);
    setEmail(payload.portal.privacy.email);
    setVersion((v) => v + 1);
  }, []);

  useEffect(() => {
    api.get<BrandPayload>('/branding')
      .then((res) => apply(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar la política de privacidad.')));
  }, [apply]);

  if (!data) {
    return (
      <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-3">
        <ErrorAlert message={error} />
        {!error && <p className="text-sm text-slate-500">Cargando la política de privacidad…</p>}
      </section>
    );
  }

  const privacy = data.portal.privacy;
  const canEdit = data.can_edit;
  const dirty = !same(body, privacy.body) || email.trim() !== privacy.email;
  const isTemplate = same(body, privacy.template);
  const values: Record<string, string> = { ...privacy.company, 'Correo de privacidad': email.trim() };
  const used = privacy.tokens.filter((t) => body.includes(t.token));
  const missing = used.filter((t) => !values[t.token.slice(1, -1)]?.trim());

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      setNotice('');
      apply((await api.put<BrandPayload>('/branding/privacy', { body: isTemplate ? null : body, email: email.trim() || null })).data);
      setNotice('Listo: tus clientes ya ven esta política en su portal y al crear su cuenta.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la política.'));
    } finally {
      setBusy(false);
    }
  };

  const restore = (text: string) => {
    setBody(text);
    setVersion((v) => v + 1);
  };

  return (
    <section className="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-5" data-card="privacy">
      <div>
        <SectionTitle><ShieldCheck className="w-4 h-4 text-brand-accent" /> Política de privacidad</SectionTitle>
        <p className="text-xs text-slate-500 mt-1">
          La Ley N.° 29733 pide contarle a cada persona qué haces con sus datos. Tus clientes la ven en su portal y la aceptan al crear su cuenta.
          {privacy.updated_at && <> Última modificación: {dateOf(privacy.updated_at)}.</>}
        </p>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      <p className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
        Te dejamos un modelo general. Revísalo con quien te asesore legalmente, sobre todo si guardas datos de salud.
      </p>

      {missing.length > 0 && (
        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3" data-privacy-missing>
          Falta completar: {missing.map((t) => t.label.toLowerCase()).join(', ')}. Mientras tanto, tus clientes verán &quot;(dato por completar)&quot;.{' '}
          {missing.some((t) => t.token !== '[Correo de privacidad]') && <Link href="/companies" className="font-bold underline">Completar en Empresa</Link>}
        </p>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-2 gap-6 items-start">
        <div className="space-y-4">
          <div className="max-w-md">
            <label className={labelClass} htmlFor="privacy-email">Correo para consultas sobre datos</label>
            <input id="privacy-email" type="email" maxLength={190} disabled={!canEdit} className={inputClass} placeholder="datos@tuempresa.com" value={email} onChange={(e) => setEmail(e.target.value)} />
            <span className="block text-xs text-slate-500 mt-1">Adonde tus clientes pueden escribir para ver, corregir o borrar sus datos.</span>
          </div>

          <div>
            <span className={labelClass}>Texto</span>
            <RichTextEditor key={version} ref={editor} value={body} disabled={!canEdit} minHeight={360} onChange={setBody} />
            {canEdit && (
              <div className="mt-2" data-privacy-tokens>
                <span className="block text-xs text-slate-500 mb-1">Datos que se completan solos con lo que tienes en Empresa (pulsa para agregarlos):</span>
                <div className="flex flex-wrap gap-1.5">
                  {privacy.tokens.map((t) => (
                    <button key={t.token} type="button" onClick={() => editor.current?.insert(t.token)} className="px-2 py-1 text-xs font-medium bg-brand-soft/15 text-brand-ink border border-brand-soft/50 rounded-lg hover:bg-brand-soft/30">
                      {t.label}
                    </button>
                  ))}
                </div>
              </div>
            )}
          </div>

          {canEdit && (
            <div className="flex flex-wrap items-center justify-between gap-3 pt-4 border-t border-slate-100">
              <button type="button" data-privacy-template disabled={isTemplate} onClick={() => restore(privacy.template)} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-brand-ink disabled:opacity-40">
                <RotateCcw size={13} /> Volver al modelo
              </button>
              <div className="flex gap-2">
                {dirty && <Button type="button" variant="secondary" onClick={() => { restore(privacy.body); setEmail(privacy.email); }}>Deshacer cambios</Button>}
                <Button type="button" variant="primary" data-save-privacy disabled={busy || !dirty} onClick={save}>{busy ? 'Guardando…' : 'Guardar'}</Button>
              </div>
            </div>
          )}
        </div>

        <div className="xl:sticky xl:top-4 space-y-2">
          <span className="text-xs font-bold text-slate-600">Así la verán tus clientes {dirty ? '(sin guardar)' : ''}</span>
          <div className="rounded-2xl border border-slate-200 bg-white p-5 max-h-[70vh] overflow-y-auto text-sm text-slate-700" data-privacy-preview>
            <RichTextView body={fillTokens(body, values)} className="[&_h2]:text-brand-ink [&_h3]:text-brand-ink [&_mark]:bg-amber-100 [&_mark]:px-1 [&_mark]:rounded" />
          </div>
        </div>
      </div>
    </section>
  );
}
