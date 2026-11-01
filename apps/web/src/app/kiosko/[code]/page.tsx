'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import {
  CheckCircle2,
  Clock,
  Sparkles,
  User,
  Users,
  AlertCircle,
  RotateCcw,
  Phone,
  ArrowRight,
  ArrowLeft,
} from 'lucide-react';
import { api } from '@/lib/axios';

type KioskData = {
  tenant: {
    name: string;
    logo_url: string | null;
    vocabulary: { client: string; pro: string };
  };
  branch: {
    id: string;
    name: string;
    address: string | null;
  };
  branches: { id: string; name: string }[];
  services: { id: string; name: string; duration_minutes: number; price_label: string }[];
  waiting_count: number;
  max_wait: number;
};

type KioskChoices = {
  first_free: { wait_minutes: number | null };
  professionals: { id: string; display_name: string; wait_minutes: number | null; estimated_at: string | null }[];
};

type SuccessTicket = {
  ticket: string;
  position: number | null;
  wait_minutes: number | null;
  estimated_at: string | null;
  service_name: string;
  branch_name: string;
};

const waitText = (minutes: number | null) => {
  if (minutes === null) return 'Sin espacio hoy';
  if (minutes <= 0) return 'Pasas ahora';
  return `Espera de unos ${minutes} min`;
};

export default function KioskPage() {
  const params = useParams<{ code: string }>();
  const code = params?.code || '';

  const [loading, setLoading] = useState(true);
  const [data, setData] = useState<KioskData | null>(null);
  const [loadError, setLoadError] = useState('');

  const [step, setStep] = useState<'phone' | 'service' | 'pro' | 'done'>('phone');
  const [phone, setPhone] = useState('');
  const [name, setName] = useState('');
  const [knownCustomer, setKnownCustomer] = useState(false);
  const [selectedServiceId, setSelectedServiceId] = useState('');
  const [selectedProId, setSelectedProId] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState('');
  const [ticket, setTicket] = useState<SuccessTicket | null>(null);
  const [choices, setChoices] = useState<KioskChoices | null>(null);

  const loadKiosk = async () => {
    try {
      setLoading(true);
      setLoadError('');
      const res = await api.get<KioskData>(`/kiosk/${code}`);
      setData(res.data);
    } catch (err: any) {
      setLoadError(
        err?.response?.data?.message || 'No se pudo cargar el kiosko. Verifica el código de empresa.',
      );
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (code) {
      loadKiosk();
    }
  }, [code]);

  useEffect(() => {
    if (step !== 'done') return;
    const timer = setTimeout(() => {
      resetForm();
    }, 15000);
    return () => clearTimeout(timer);
  }, [step]);

  const resetForm = () => {
    setStep('phone');
    setPhone('');
    setName('');
    setKnownCustomer(false);
    setSelectedServiceId('');
    setSelectedProId('');
    setSubmitError('');
    setTicket(null);
    setChoices(null);
    loadKiosk();
  };

  const handlePhoneLookup = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitError('');
    const cleanPhone = phone.replace(/\D/g, '');
    if (cleanPhone.length !== 9) {
      setSubmitError('Ingresa los 9 dígitos de tu celular.');
      return;
    }

    try {
      const res = await api.post<{ found: boolean }>(`/kiosk/${code}/lookup`, {
        phone: cleanPhone,
      });
      setKnownCustomer(Boolean(res.data.found));
      setStep('service');
    } catch {
      setStep('service');
    }
  };

  const handleRegister = async () => {
    if (!selectedServiceId) {
      setSubmitError('Selecciona el servicio que buscas.');
      return;
    }
    if (!knownCustomer && !name.trim()) {
      setSubmitError('Escribe tu nombre para llamarte.');
      return;
    }

    setSubmitting(true);
    setSubmitError('');

    try {
      const cleanPhone = phone.replace(/\D/g, '');
      const res = await api.post<SuccessTicket>(`/kiosk/${code}/queue`, {
        branch_id: data?.branch.id,
        phone: cleanPhone,
        name: knownCustomer ? null : name.trim(),
        service_id: selectedServiceId,
        professional_id: selectedProId || null,
      });

      setTicket(res.data);
      setStep('done');
    } catch (err: any) {
      const resp = err?.response?.data;
      if (resp?.code === 'WAIT_TOO_LONG') {
        setSubmitError(
          resp.message ||
            'La espera estimada en este momento supera el tiempo máximo aceptado. Acércate a recepción.',
        );
      } else if (resp?.code === 'DUPLICATE') {
        setSubmitError(
          resp.message || `Ya estás registrado en la cola de hoy con el turno ${resp.ticket}.`,
        );
      } else {
        setSubmitError(resp?.message || 'No se pudo generar el turno. Acércate a recepción.');
      }
    } finally {
      setSubmitting(false);
    }
  };

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-900 p-6 text-white">
        <div className="text-center">
          <div className="mx-auto mb-3 h-10 w-10 animate-spin rounded-full border-4 border-teal-500 border-t-transparent" />
          <p className="text-sm font-medium text-slate-300">Cargando kiosko...</p>
        </div>
      </div>
    );
  }

  if (loadError || !data) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-slate-900 p-6 text-white">
        <div className="max-w-md rounded-2xl border border-slate-800 bg-slate-800/80 p-8 text-center shadow-xl">
          <AlertCircle size={48} className="mx-auto mb-4 text-rose-500" />
          <h2 className="mb-2 text-xl font-bold text-white">Kiosko no disponible</h2>
          <p className="mb-6 text-sm text-slate-300">{loadError}</p>
          <button
            type="button"
            onClick={loadKiosk}
            className="rounded-xl bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white shadow-md hover:bg-teal-700"
          >
            Reintentar
          </button>
        </div>
      </div>
    );
  }

  const selectedService = data.services.find((s) => s.id === selectedServiceId);
  const chosenName = selectedProId ? choices?.professionals.find((p) => p.id === selectedProId)?.display_name : null;

  const goToPro = async () => {
    if (!selectedServiceId) {
      setSubmitError('Elige un servicio antes de continuar.');
      return;
    }
    if (!knownCustomer && !name.trim()) {
      setSubmitError('Escribe tu nombre.');
      return;
    }
    setSubmitError('');
    try {
      const res = await api.get<KioskChoices>(`/kiosk/${code}/choices`, { params: { branch_id: data.branch.id, service_id: selectedServiceId } });
      setChoices(res.data);
      setSelectedProId('');
      setStep('pro');
    } catch (err: any) {
      setSubmitError(err?.response?.data?.message || 'No se pudo revisar la espera. Acércate a recepción.');
    }
  };

  return (
    <div className="flex min-h-screen flex-col bg-slate-950 text-white select-none">
      <header className="flex items-center justify-between border-b border-slate-800/80 bg-slate-900/60 px-6 py-4 backdrop-blur-md">
        <div className="flex items-center gap-3">
          {data.tenant.logo_url ? (
            <img
              src={data.tenant.logo_url}
              alt={data.tenant.name}
              className="h-10 w-auto max-w-[140px] object-contain rounded-md"
            />
          ) : (
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-600 font-bold text-white shadow-md">
              {data.tenant.name.slice(0, 2).toUpperCase()}
            </div>
          )}
          <div>
            <h1 className="text-base font-bold text-white">{data.tenant.name}</h1>
            <p className="text-xs text-slate-400">
              {data.branch.name}{data.branch.address ? ` · ${data.branch.address}` : ''}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-4">
          <div className="flex items-center gap-1.5 rounded-full border border-slate-800 bg-slate-900 px-3.5 py-1 text-xs text-slate-300">
            <Users size={14} className="text-teal-400" />
            <span>
              {data.waiting_count === 0
                ? 'Sin espera en este momento'
                : `${data.waiting_count} en espera`}
            </span>
          </div>

          {step !== 'phone' && step !== 'done' && (
            <button
              type="button"
              onClick={resetForm}
              className="flex items-center gap-1 rounded-lg border border-slate-700 px-3 py-1 text-xs font-medium text-slate-300 hover:bg-slate-800"
            >
              <RotateCcw size={12} />
              <span>Reiniciar</span>
            </button>
          )}
        </div>
      </header>

      <main className="flex flex-1 items-center justify-center p-6">
        <div className="w-full max-w-xl">
          {submitError && (
            <div className="mb-6 rounded-2xl border border-rose-500/40 bg-rose-950/60 p-4 text-center text-sm font-medium text-rose-200">
              {submitError}
            </div>
          )}

          {step === 'phone' && (
            <div className="rounded-3xl border border-slate-800 bg-slate-900/90 p-8 shadow-2xl backdrop-blur-xl">
              <div className="mb-6 text-center">
                <span className="inline-flex rounded-full bg-teal-500/10 px-3 py-1 text-xs font-semibold text-teal-400">
                  Bienvenido
                </span>
                <h2 className="mt-2 text-2xl font-extrabold text-white">
                  Toma tu turno sin cita
                </h2>
                <p className="mt-1 text-sm text-slate-400">
                  Ingresa tu número de celular para reconocerte y avisarte
                </p>
              </div>

              <form onSubmit={handlePhoneLookup} className="space-y-6">
                <div>
                  <div className="relative">
                    <span className="absolute left-4 top-1/2 -translate-y-1/2 text-lg font-bold text-slate-500">
                      +51
                    </span>
                    <input
                      type="tel"
                      inputMode="numeric"
                      pattern="[0-9]*"
                      maxLength={9}
                      value={phone}
                      onChange={(e) => setPhone(e.target.value.replace(/\D/g, ''))}
                      placeholder="987 654 321"
                      autoFocus
                      className="w-full rounded-2xl border border-slate-700 bg-slate-950 py-4 pl-16 pr-4 text-center font-mono text-2xl font-bold tracking-widest text-white placeholder-slate-600 focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-500/30"
                    />
                  </div>
                </div>

                <button
                  type="submit"
                  disabled={phone.replace(/\D/g, '').length !== 9}
                  className="flex w-full items-center justify-center gap-2 rounded-2xl bg-teal-600 py-4 text-base font-bold text-white shadow-lg shadow-teal-900/40 transition hover:bg-teal-500 disabled:opacity-40"
                >
                  <span>Continuar</span>
                  <ArrowRight size={18} />
                </button>
              </form>
            </div>
          )}

          {step === 'service' && (
            <div className="rounded-3xl border border-slate-800 bg-slate-900/90 p-8 shadow-2xl backdrop-blur-xl">
              <div className="mb-6 flex items-center justify-between">
                <div>
                  <h2 className="text-xl font-bold text-white">
                    {knownCustomer ? '¡Qué bueno verte de nuevo!' : '¿Cómo te llamas?'}
                  </h2>
                  <p className="text-xs text-slate-400">
                    Elige el servicio que deseas atender hoy
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setStep('phone')}
                  className="rounded-xl border border-slate-700 p-2 text-slate-400 hover:bg-slate-800"
                >
                  <ArrowLeft size={16} />
                </button>
              </div>

              <div className="space-y-4">
                {!knownCustomer && (
                  <div>
                    <label className="mb-1 block text-xs font-medium text-slate-300">
                      Tu nombre y apellido:
                    </label>
                    <input
                      type="text"
                      value={name}
                      onChange={(e) => setName(e.target.value)}
                      placeholder="Ej. Carlos Mendoza"
                      className="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3 text-sm text-white placeholder-slate-600 focus:border-teal-500 focus:outline-none"
                    />
                  </div>
                )}

                <div>
                  <label className="mb-2 block text-xs font-medium text-slate-300">
                    Selecciona el servicio:
                  </label>
                  <div className="grid max-h-[300px] grid-cols-1 gap-2.5 overflow-y-auto pr-1">
                    {data.services.map((s) => (
                      <button
                        key={s.id}
                        type="button"
                        onClick={() => setSelectedServiceId(s.id)}
                        className={`flex items-center justify-between rounded-2xl border p-4 text-left transition ${
                          selectedServiceId === s.id
                            ? 'border-teal-500 bg-teal-500/10 ring-2 ring-teal-500/30'
                            : 'border-slate-800 bg-slate-950/60 hover:border-slate-700 hover:bg-slate-800/40'
                        }`}
                      >
                        <div>
                          <div className="font-semibold text-white">{s.name}</div>
                          <div className="text-xs text-slate-400">{s.duration_minutes} min</div>
                        </div>
                        <div className="text-right">
                          <div className="font-bold text-teal-400">{s.price_label}</div>
                        </div>
                      </button>
                    ))}
                  </div>
                </div>

                <div className="pt-2">
                  <button
                    type="button"
                    onClick={goToPro}
                    disabled={!selectedServiceId || (!knownCustomer && !name.trim())}
                    className="flex w-full items-center justify-center gap-2 rounded-2xl bg-teal-600 py-3.5 text-base font-bold text-white shadow-lg transition hover:bg-teal-500 disabled:opacity-40"
                  >
                    <span>Continuar</span>
                    <ArrowRight size={18} />
                  </button>
                </div>
              </div>
            </div>
          )}

          {step === 'pro' && (
            <div className="rounded-3xl border border-slate-800 bg-slate-900/90 p-8 shadow-2xl backdrop-blur-xl">
              <div className="mb-6 flex items-center justify-between">
                <div>
                  <h2 className="text-xl font-bold text-white">¿Con quién te quieres atender?</h2>
                  <p className="text-xs text-slate-400">
                    Para {selectedService?.name}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => setStep('service')}
                  className="rounded-xl border border-slate-700 p-2 text-slate-400 hover:bg-slate-800"
                >
                  <ArrowLeft size={16} />
                </button>
              </div>

              <div className="space-y-3">
                <button
                  type="button"
                  onClick={() => setSelectedProId('')}
                  className={`flex w-full items-center justify-between rounded-2xl border p-4 text-left transition ${
                    selectedProId === ''
                      ? 'border-teal-500 bg-teal-500/10 ring-2 ring-teal-500/30'
                      : 'border-slate-800 bg-slate-950/60 hover:border-slate-700'
                  }`}
                >
                  <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-teal-500/20 text-teal-400">
                      <Sparkles size={20} />
                    </div>
                    <div>
                      <div className="font-semibold text-white">Quien se libere primero</div>
                      <div className="text-xs text-teal-400 font-medium">{waitText(choices?.first_free.wait_minutes ?? null)}</div>
                    </div>
                  </div>
                </button>

                {(choices?.professionals ?? []).map((p) => (
                  <button
                    key={p.id}
                    type="button"
                    disabled={p.wait_minutes === null}
                    onClick={() => setSelectedProId(p.id)}
                    className={`flex w-full items-center justify-between rounded-2xl border p-4 text-left transition disabled:opacity-40 ${
                      selectedProId === p.id
                        ? 'border-teal-500 bg-teal-500/10 ring-2 ring-teal-500/30'
                        : 'border-slate-800 bg-slate-950/60 hover:border-slate-700'
                    }`}
                  >
                    <div className="flex items-center gap-3">
                      <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-800 text-slate-400">
                        <User size={18} />
                      </div>
                      <div>
                        <div className="font-semibold text-white">{p.display_name}</div>
                        <div className="text-xs text-slate-400">{waitText(p.wait_minutes)}</div>
                      </div>
                    </div>
                  </button>
                ))}

                <div className="pt-3">
                  <button
                    type="button"
                    onClick={handleRegister}
                    disabled={submitting}
                    className="flex w-full items-center justify-center gap-2 rounded-2xl bg-teal-600 py-3.5 text-base font-bold text-white shadow-lg transition hover:bg-teal-500 disabled:opacity-50"
                  >
                    {submitting ? 'Generando turno...' : 'Confirmar y obtener turno'}
                  </button>
                </div>
              </div>
            </div>
          )}

          {step === 'done' && ticket && (
            <div className="rounded-3xl border border-teal-500/30 bg-slate-900/95 p-8 text-center shadow-2xl backdrop-blur-xl">
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-teal-500/20 text-teal-400">
                <CheckCircle2 size={36} />
              </div>

              <span className="text-sm font-semibold text-teal-400">¡Listo, ya estás en la cola!</span>

              <div className="my-5 rounded-2xl border border-slate-800 bg-slate-950/80 py-6">
                <div className="text-xs font-medium text-slate-400">Tu turno</div>
                <div className="mt-1 font-mono text-5xl font-black tracking-tight text-white">
                  {ticket.ticket}
                </div>
                <div className="mt-2 text-sm text-slate-300">{ticket.service_name}</div>
              </div>

              <div className="grid grid-cols-2 gap-3 text-left">
                <div className="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                  <div className="flex items-center gap-1.5 text-xs text-slate-400">
                    <Clock size={13} />
                    <span>Espera estimada</span>
                  </div>
                  <div className="mt-1 text-base font-bold text-amber-400">
                    {ticket.wait_minutes === null
                      ? 'Por confirmar'
                      : ticket.wait_minutes <= 0
                      ? 'Pasa de inmediato'
                      : `~${ticket.wait_minutes} min`}
                  </div>
                  {ticket.estimated_at && (
                    <div className="text-[11px] text-slate-500">Hora aprox: {ticket.estimated_at}</div>
                  )}
                </div>

                <div className="rounded-xl border border-slate-800 bg-slate-950/60 p-3">
                  <div className="flex items-center gap-1.5 text-xs text-slate-400">
                    <User size={13} />
                    <span>Atención con</span>
                  </div>
                  <div className="mt-1 text-sm font-bold text-white truncate">
                    {chosenName || 'Quien se libere primero'}
                  </div>
                  <div className="text-[11px] text-slate-500">
                    {ticket.position && ticket.position > 1 ? `${ticket.position - 1} ${ticket.position === 2 ? 'persona' : 'personas'} antes que tú` : 'Eres quien sigue'}
                  </div>
                </div>
              </div>

              <p className="mt-6 text-xs text-slate-400">
                Toma asiento. Te llamaremos por tu turno cuando te toque.
              </p>

              <button
                type="button"
                onClick={resetForm}
                className="mt-6 w-full rounded-xl bg-slate-800 py-3 text-xs font-semibold text-slate-200 hover:bg-slate-700"
              >
                Listo, tomar asiento
              </button>
            </div>
          )}
        </div>
      </main>

      <footer className="border-t border-slate-900 bg-slate-950 px-6 py-3 text-center text-xs text-slate-500">
        Kiosko de autorregistro · Powered by ERP SaaS
      </footer>
    </div>
  );
}
