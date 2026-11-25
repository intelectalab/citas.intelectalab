'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { ArrowLeft, BookOpenText, CheckCircle2, Loader2, Printer } from 'lucide-react';
import { api } from '@/lib/axios';
import { usePlatform } from '@/hooks/usePlatform';
import { makeVocabulary } from '@/hooks/useVocabulary';
import Select from '@/app/components/common/Select';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import TurnstileBox from '@/app/components/common/TurnstileBox';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { KIND_HELP, longDate, type Complaint, type Issuer } from '../complaints';

type Book = { issuer: Issuer; logo: string | null; vocabulary: Record<string, string> | null; currency: string; days: number; documents: string[]; captcha_site_key?: string | null };

const EMPTY = {
  name: '', document_type: 'DNI', document_number: '', address: '', phone: '', email: '',
  is_minor: false, guardian_name: '', guardian_document_type: 'DNI', guardian_document_number: '',
  good_type: 'servicio', good_description: '', amount: '', kind: '', detail: '', request: '', accepted: false,
};

type Form = typeof EMPTY;

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-5 sm:p-6 space-y-4';
const check = 'mt-0.5 w-4 h-4 rounded border-slate-300 accent-brand cursor-pointer shrink-0';

function Field({ id, label, error, children }: { id: string; label: string; error?: string; children: React.ReactNode }) {
  return (
    <div>
      <label className={labelClass} htmlFor={id}>{label}</label>
      {children}
      {error && <p className="mt-1 text-xs font-medium text-rose-600" data-field-error={id}>{error}</p>}
    </div>
  );
}

export default function ComplaintBook({ code }: { code?: string }) {
  const { platform } = usePlatform();
  const [book, setBook] = useState<Book | null>(null);
  const [missing, setMissing] = useState('');
  const [form, setForm] = useState<Form>(EMPTY);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [captcha, setCaptcha] = useState('');
  const [captchaRound, setCaptchaRound] = useState(0);
  const [captchaDown, setCaptchaDown] = useState(false);
  const [done, setDone] = useState<Complaint | null>(null);
  const endpoint = code ? `/complaints-book/${code}` : '/complaints-book';

  useEffect(() => {
    api.get(endpoint).then((res) => setBook(res.data)).catch((err) => setMissing(apiError(err, 'No encontramos este Libro de Reclamaciones.')));
  }, [endpoint]);

  const vocab = makeVocabulary(book?.vocabulary ?? {});
  const set = <K extends keyof Form>(key: K, value: Form[K]) => {
    setForm((f) => ({ ...f, [key]: value }));
    setErrors((e) => ({ ...e, [key]: '' }));
  };

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!form.kind) {
      setErrors((x) => ({ ...x, kind: 'Elige si es un reclamo o una queja.' }));
      return;
    }
    try {
      setBusy(true);
      setError('');
      const res = await api.post(endpoint, { ...form, amount: form.amount === '' ? null : form.amount, ...(captcha ? { turnstile_token: captcha } : {}) });
      setDone(res.data.complaint);
      window.scrollTo({ top: 0 });
    } catch (err: any) {
      const list = err.response?.data?.errors as Record<string, string[]> | undefined;
      if (list) setErrors(Object.fromEntries(Object.entries(list).map(([k, v]) => [k, v[0]])));
      setError(apiError(err, 'No se pudo enviar. Revisa los datos e inténtalo de nuevo.'));
      if (book?.captcha_site_key) setCaptchaRound((value) => value + 1);
    } finally {
      setBusy(false);
    }
  };

  const issuer = book?.issuer;
  const docOptions = (book?.documents ?? ['DNI']).map((d) => ({ value: d, label: d === 'CE' ? 'Carné de extranjería' : d }));

  const header = (
    <div className={`${card} !space-y-3`} data-complaint-issuer>
      <div className="flex items-center gap-3">
        {book?.logo ? <img src={book.logo} alt="" className="h-10 w-auto max-w-[120px] object-contain" /> : <span className="p-2.5 rounded-2xl bg-brand-soft/20 text-brand-ink"><BookOpenText size={20} /></span>}
        <div className="min-w-0">
          <h1 className="text-xl sm:text-2xl font-black text-brand-ink tracking-tight">Libro de Reclamaciones</h1>
          <p className="text-sm font-semibold text-slate-600 truncate">{issuer?.name ?? platform.product_name}</p>
        </div>
      </div>
      <dl className="grid grid-cols-1 sm:grid-cols-3 gap-2 text-sm">
        {([['Razón social', issuer?.legal_name], ['RUC', issuer?.tax_id], ['Dirección', issuer?.address]] as [string, string | null | undefined][]).map(([label, value]) => (
          <div key={label} className="rounded-xl bg-slate-50 px-3 py-2">
            <dt className="text-[11px] font-bold uppercase tracking-wider text-slate-500">{label}</dt>
            <dd className="font-semibold text-slate-800">{value || '—'}</dd>
          </div>
        ))}
      </dl>
    </div>
  );

  if (missing) {
    return (
      <Page home={!code}>
        <div className={card}><p className="text-sm text-slate-600">{missing}</p></div>
      </Page>
    );
  }

  if (!book) {
    return <div className="min-h-screen flex items-center justify-center"><Loader2 className="w-8 h-8 text-brand-accent animate-spin" /></div>;
  }

  if (done) {
    return (
      <Page home={!code}>
        {header}
        <div className={card} data-complaint-done={done.code}>
          <div className="flex items-start gap-3">
            <CheckCircle2 className="w-7 h-7 text-emerald-600 shrink-0" />
            <div>
              <h2 className="text-lg font-black text-brand-ink">Recibimos tu {done.kind_label.toLowerCase()}</h2>
              <p className="text-sm text-slate-600">Tu código es <strong className="text-slate-900" data-complaint-code>{done.code}</strong>. Te enviamos una copia a {done.consumer.email}. Te responderemos a ese correo hasta el <strong>{longDate(done.due_on)}</strong> ({book.days} días hábiles).</p>
            </div>
          </div>
          <dl className="rounded-2xl border border-slate-200 divide-y divide-slate-100 text-sm">
            {([
              ['Fecha', longDate(done.created_on)],
              ['Nombre', done.consumer.name],
              ['Documento', `${done.consumer.document_type} ${done.consumer.document_number}`],
              ['Padre, madre o apoderado', done.guardian ? `${done.guardian.name} (${done.guardian.document_type} ${done.guardian.document_number})` : null],
              [done.good_type === 'producto' ? 'Producto' : 'Servicio', done.good_description],
              ['Monto', done.amount !== null ? money(done.amount, done.currency) : null],
              ['Detalle', done.detail],
              ['Pedido', done.request],
            ] as [string, string | null][]).filter(([, v]) => v).map(([label, value]) => (
              <div key={label} className="flex flex-col sm:flex-row sm:justify-between gap-1 px-4 py-2.5">
                <dt className="text-xs text-slate-500 shrink-0">{label}</dt>
                <dd className="font-semibold text-slate-800 sm:text-right whitespace-pre-line break-words">{value}</dd>
              </div>
            ))}
          </dl>
          <button type="button" onClick={() => window.print()} className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 print:hidden">
            <Printer size={15} /> Imprimir constancia
          </button>
        </div>
      </Page>
    );
  }

  return (
    <Page home={!code}>
      {header}
      <p className="text-sm text-slate-600 px-1">
        {code ? `Si eres ${vocab.client.toLowerCase()} de ${issuer?.name} y algo no salió bien, déjalo aquí.` : 'Si algo no salió bien con el servicio de la plataforma, déjalo aquí.'} Es el Libro de Reclamaciones virtual que pide el Código de Protección y Defensa del Consumidor. Respondemos en un máximo de {book.days} días hábiles.
      </p>

      <form onSubmit={submit} className="space-y-4" data-complaint-form noValidate>
        <section className={card}>
          <h2 className="text-sm font-black text-brand-ink">1. Tus datos</h2>
          <Field id="name" label="Nombre completo" error={errors.name}>
            <input id="name" className={inputClass} maxLength={150} value={form.name} onChange={(e) => set('name', e.target.value)} />
          </Field>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field id="document_type" label="Tipo de documento" error={errors.document_type}>
              <Select ariaLabel="Tipo de documento" clearable={false} value={form.document_type} onChange={(e) => set('document_type', String(e.target.value))} options={docOptions} />
            </Field>
            <Field id="document_number" label="Número de documento" error={errors.document_number}>
              <input id="document_number" className={inputClass} maxLength={20} value={form.document_number} onChange={(e) => set('document_number', e.target.value)} />
            </Field>
          </div>
          <Field id="address" label="Domicilio" error={errors.address}>
            <input id="address" className={inputClass} maxLength={255} value={form.address} onChange={(e) => set('address', e.target.value)} />
          </Field>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <Field id="phone" label="Teléfono" error={errors.phone}>
              <input id="phone" className={inputClass} maxLength={30} inputMode="tel" value={form.phone} onChange={(e) => set('phone', e.target.value)} />
            </Field>
            <Field id="email" label="Correo" error={errors.email}>
              <input id="email" type="email" className={inputClass} maxLength={190} value={form.email} onChange={(e) => set('email', e.target.value)} placeholder="Aquí te llegará la respuesta" />
            </Field>
          </div>
          <label className="flex items-start gap-2.5 text-sm text-slate-700 cursor-pointer">
            <input type="checkbox" data-complaint-minor className={check} checked={form.is_minor} onChange={(e) => set('is_minor', e.target.checked)} />
            Soy menor de edad
          </label>
          {form.is_minor && (
            <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 space-y-4" data-complaint-guardian>
              <p className="text-xs font-semibold text-slate-600">Datos del padre, madre o apoderado</p>
              <Field id="guardian_name" label="Nombre completo" error={errors.guardian_name}>
                <input id="guardian_name" className={inputClass} maxLength={150} value={form.guardian_name} onChange={(e) => set('guardian_name', e.target.value)} />
              </Field>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <Field id="guardian_document_type" label="Tipo de documento" error={errors.guardian_document_type}>
                  <Select ariaLabel="Tipo de documento del apoderado" clearable={false} value={form.guardian_document_type} onChange={(e) => set('guardian_document_type', String(e.target.value))} options={docOptions} />
                </Field>
                <Field id="guardian_document_number" label="Número de documento" error={errors.guardian_document_number}>
                  <input id="guardian_document_number" className={inputClass} maxLength={20} value={form.guardian_document_number} onChange={(e) => set('guardian_document_number', e.target.value)} />
                </Field>
              </div>
            </div>
          )}
        </section>

        <section className={card}>
          <h2 className="text-sm font-black text-brand-ink">2. Lo que contrataste</h2>
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <Field id="good_type" label="Es un" error={errors.good_type}>
              <Select ariaLabel="Producto o servicio" clearable={false} value={form.good_type} onChange={(e) => set('good_type', String(e.target.value))} options={[{ value: 'servicio', label: 'Servicio' }, { value: 'producto', label: 'Producto' }]} />
            </Field>
            <div className="sm:col-span-2">
              <Field id="good_description" label={form.good_type === 'producto' ? '¿Qué producto?' : '¿Qué servicio?'} error={errors.good_description}>
                <input id="good_description" className={inputClass} maxLength={500} value={form.good_description} onChange={(e) => set('good_description', e.target.value)} />
              </Field>
            </div>
          </div>
          <Field id="amount" label={`Monto (${book.currency}, si lo sabes)`} error={errors.amount}>
            <input id="amount" className={inputClass} inputMode="decimal" value={form.amount} onChange={(e) => set('amount', e.target.value.replace(',', '.'))} placeholder="Por ejemplo 80.50" />
          </Field>
        </section>

        <section className={card}>
          <h2 className="text-sm font-black text-brand-ink">3. Qué pasó</h2>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3" role="radiogroup" aria-label="Tipo">
            {(['reclamo', 'queja'] as const).map((kind) => (
              <OptionCard key={kind} type="button" role="radio" aria-checked={form.kind === kind} data-complaint-kind={kind} selected={form.kind === kind} onClick={() => set('kind', kind)} className="text-left rounded-2xl border p-4 transition-colors">
                <span className="block text-sm font-bold text-slate-800">{kind === 'reclamo' ? 'Reclamo' : 'Queja'}</span>
                <span className="block text-xs text-slate-500 mt-1">{KIND_HELP[kind]}</span>
              </OptionCard>
            ))}
          </div>
          {errors.kind && <p className="text-xs font-medium text-rose-600" data-field-error="kind">{errors.kind}</p>}
          <Field id="detail" label="Detalle: cuéntanos qué pasó" error={errors.detail}>
            <textarea id="detail" rows={4} maxLength={5000} className={inputClass} value={form.detail} onChange={(e) => set('detail', e.target.value)} />
          </Field>
          <Field id="request" label="Pedido: ¿qué pides para solucionarlo?" error={errors.request}>
            <textarea id="request" rows={3} maxLength={3000} className={inputClass} value={form.request} onChange={(e) => set('request', e.target.value)} />
          </Field>
          <label className="flex items-start gap-2.5 text-sm text-slate-700 cursor-pointer">
            <input type="checkbox" data-complaint-accept className={check} checked={form.accepted} onChange={(e) => set('accepted', e.target.checked)} />
            Declaro que los datos son verdaderos y acepto que se usen solo para atender este reclamo o queja.
          </label>
          {errors.accepted && <p className="text-xs font-medium text-rose-600" data-field-error="accepted">{errors.accepted}</p>}
        </section>

        <TurnstileBox siteKey={book?.captcha_site_key} onToken={setCaptcha} onUnavailable={() => setCaptchaDown(true)} resetKey={captchaRound} error={errors.turnstile_token} />
        <ErrorAlert message={error} />
        <p className="text-xs text-slate-500 px-1">Una vez enviado no se puede cambiar ni borrar. Presentar un reclamo no impide acudir a otras vías de solución ni es requisito para denunciar ante el Indecopi.</p>
        <button type="submit" disabled={busy || Boolean(book?.captcha_site_key && !captcha && !captchaDown)} data-complaint-submit className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
          {busy && <Loader2 size={15} className="animate-spin" />} Enviar
        </button>
      </form>
    </Page>
  );
}

function Page({ children, home }: { children: React.ReactNode; home: boolean }) {
  return (
    <div className="min-h-screen bg-slate-100/70 px-4 py-6 sm:py-10 font-sans text-[#1F2937]">
      <div className="max-w-2xl mx-auto space-y-4">
        {children}
        {home && <Link href="/" className="inline-flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink print:hidden">
          <ArrowLeft className="w-3.5 h-3.5" /> Volver al inicio
        </Link>}
      </div>
    </div>
  );
}
