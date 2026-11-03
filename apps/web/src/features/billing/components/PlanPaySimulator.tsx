'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { AlertTriangle, CheckCircle2, CreditCard, Loader2, XCircle } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { cycleLabel } from '../billing';

interface SimulatedPayment {
  id: string;
  kind: 'link' | 'card';
  status: 'pending' | 'paid' | 'failed' | 'cancelled' | 'expired';
  amount: number;
  currency: string;
  plan: string | null;
  billing_cycle: string;
  company: string | null;
  back: string;
}

export default function PlanPaySimulator() {
  const params = useParams<{ id: string }>();
  const [view, setView] = useState<SimulatedPayment | null>(null);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [busy, setBusy] = useState<'' | 'approved' | 'rejected'>('');

  useEffect(() => {
    api.get(`/plan-payments/${params.id}`)
      .then((res) => setView(res.data))
      .catch((err) => setError(apiError(err, 'Este pago no existe.')));
  }, [params.id]);

  const simulate = async (result: 'approved' | 'rejected') => {
    try {
      setBusy(result);
      setError('');
      const res = await api.post(`/plan-payments/${params.id}/simulate`, { result });
      setView(res.data.view);
      setMessage(res.data.message);
      if (res.data.status === 'paid') setTimeout(() => { window.location.href = res.data.view.back; }, 1500);
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  if (!view) {
    return (
      <div className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4">
        <p className={`text-sm ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Abriendo el pago...'}</p>
      </div>
    );
  }

  const isCard = view.kind === 'card';

  return (
    <div className="min-h-screen bg-[#F2F2F2] py-10 px-4 font-sans" data-plan-pay={view.status}>
      <div className="max-w-md mx-auto space-y-4">
        <p className="flex items-start gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">
          <AlertTriangle size={15} className="shrink-0 mt-0.5" />
          <span><span className="font-bold">Simulación de Mercado Pago.</span> Solo existe en las pruebas del sistema: no se cobra dinero.</span>
        </p>
        <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4">
          <div>
            <span className="block text-[11px] font-bold text-slate-400 uppercase tracking-wider">{isCard ? 'Autorizar tarjeta para el cobro automático' : 'Pago del plan'}</span>
            <span className="block text-3xl font-black text-brand-ink tabular-nums">{money(view.amount, view.currency)}</span>
            <span className="block text-sm text-slate-600 mt-1">Plan {view.plan} {cycleLabel(view.billing_cycle)} · {view.company}</span>
          </div>
          {view.status === 'pending' ? (
            <div className="space-y-2">
              <button type="button" data-sim="approved" disabled={busy !== ''} onClick={() => simulate('approved')} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
                {busy === 'approved' ? <Loader2 size={16} className="animate-spin" /> : <CreditCard size={16} />} {isCard ? 'Simular tarjeta autorizada' : 'Simular pago aprobado'}
              </button>
              <button type="button" data-sim="rejected" disabled={busy !== ''} onClick={() => simulate('rejected')} className="w-full px-5 py-3 text-sm font-semibold text-slate-700 border border-slate-200 bg-white rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50">
                {isCard ? 'Simular tarjeta rechazada' : 'Simular pago rechazado'}
              </button>
            </div>
          ) : (
            <div className="text-center space-y-2">
              <span className={`w-14 h-14 mx-auto rounded-full flex items-center justify-center ${view.status === 'paid' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-500'}`}>
                {view.status === 'paid' ? <CheckCircle2 size={28} /> : <XCircle size={28} />}
              </span>
              <p className="text-sm text-slate-600">{message || (view.status === 'paid' ? 'Listo.' : 'Este pago ya no está pendiente.')}</p>
            </div>
          )}
          {error && <p role="alert" className="text-sm text-red-600">{error}</p>}
          <a href={view.back} data-sim-back className="block text-center text-sm font-semibold text-brand-accent hover:underline">Volver a mi empresa</a>
        </section>
      </div>
    </div>
  );
}
