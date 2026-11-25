'use client';

import { useEffect, useState } from 'react';
import { AlertTriangle, Loader2, PowerOff } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import type { PlanPreview } from '../billing';

const longDate = (date: string | null | undefined) => (date ? formatDate(date, { day: 'numeric', month: 'long', year: 'numeric' }) : '');

export default function PlanChangePreview({ planId, cycle, onPreview }: { planId: string; cycle: 'monthly' | 'yearly'; onPreview?: (preview: PlanPreview | null) => void }) {
  const [preview, setPreview] = useState<PlanPreview | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!planId) return;
    let live = true;
    setLoading(true);
    setError('');
    const timer = window.setTimeout(() => {
      api.post('/billing/plan/preview', { plan_id: planId, billing_cycle: cycle })
        .then((res) => { if (live) { setPreview(res.data); onPreview?.(res.data); } })
        .catch((err) => { if (live) { setPreview(null); onPreview?.(null); setError(apiError(err, 'No se pudo calcular el cambio.')); } })
        .finally(() => { if (live) setLoading(false); });
    }, 250);
    return () => { live = false; window.clearTimeout(timer); };
  }, [planId, cycle, onPreview]);

  if (loading && !preview) {
    return <p className="flex items-center gap-2 text-xs text-slate-500"><Loader2 size={13} className="animate-spin" /> Calculando el cambio…</p>;
  }

  if (error) return <p className="text-xs text-red-600">{error}</p>;
  if (!preview || preview.kind === 'same') return null;

  const currency = preview.plan.currency;

  return (
    <div className={`rounded-xl border px-4 py-3 space-y-2 text-sm ${preview.blocked ? 'border-rose-200 bg-rose-50' : 'border-slate-200 bg-slate-50'}`} data-plan-preview={preview.kind}>
      {!preview.blocked && (
        <div className="grid grid-cols-2 gap-3">
          <div>
            <span className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider">Pagas hoy</span>
            <span className="block text-lg font-black text-brand-ink tabular-nums" data-preview-today>{money(preview.today, currency)}</span>
            {preview.prorated && preview.immediate && <span className="block text-xs text-slate-500">Por {preview.prorated.days_left} de {preview.prorated.days_period} días</span>}
            {preview.prorated && !preview.immediate && preview.prorated.amount > 0 && <span className="block text-xs text-slate-500" data-preview-prorated>Con tarjeta guardada pasas hoy por {money(preview.prorated.amount, currency)} ({preview.prorated.days_left} de {preview.prorated.days_period} días)</span>}
            {preview.credit && preview.credit.amount > 0 && (
              <span className="block text-xs text-emerald-700" data-preview-credit>
                {preview.immediate
                  ? `Ya descontamos ${money(preview.credit.amount, currency)} por los ${preview.credit.days_left} días que te quedan del mes.`
                  : `Con tarjeta guardada pagas hoy ${money(preview.credit.total, currency)}: el año menos ${money(preview.credit.amount, currency)} por los ${preview.credit.days_left} días que te quedan del mes.`}
              </span>
            )}
          </div>
          {preview.next_charge && (
            <div>
              <span className="block text-[11px] font-bold text-slate-500 uppercase tracking-wider">Próximo cobro</span>
              <span className="block text-lg font-black text-brand-ink tabular-nums" data-preview-next>{money(preview.next_charge.amount, currency)}</span>
              {preview.next_charge.date && <span className="block text-xs text-slate-500">El {longDate(preview.next_charge.date)}</span>}
            </div>
          )}
        </div>
      )}

      <p className={`text-xs ${preview.blocked ? 'text-rose-800' : 'text-slate-600'}`}>{preview.blocked && preview.exceeded.length > 0 ? `Para pasar al plan ${preview.plan.name} primero ajusta lo que usas:` : preview.message}</p>

      {(preview.warnings ?? []).length > 0 && (
        <ul className="space-y-1" data-preview-warnings>
          {(preview.warnings ?? []).map((text) => (
            <li key={text} className="flex items-start gap-2 text-xs text-amber-900"><AlertTriangle size={13} className="shrink-0 mt-0.5" /> <span>{text}</span></li>
          ))}
        </ul>
      )}

      {preview.exceeded.length > 0 && (
        <ul className="space-y-1" data-preview-exceeded>
          {preview.exceeded.map((row) => (
            <li key={row.key} className="flex items-start gap-2 text-xs text-rose-800">
              <AlertTriangle size={13} className="shrink-0 mt-0.5" />
              <span><b>{row.label}:</b> {row.key.endsWith('_month') ? `este mes van ${row.used}` : `usas ${row.used}`} y el plan permite {row.limit}. Para bajar, {row.fix}.</span>
            </li>
          ))}
        </ul>
      )}

      {preview.modules_off.length > 0 && (
        <div className="text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-preview-modules>
          <span className="flex items-center gap-1.5 font-bold"><PowerOff size={13} /> Se apagan con este plan</span>
          <span className="block mt-1">{preview.modules_off.map((m) => m.name).join(', ')}. Lo que ya registraste ahí se guarda y vuelve a verse si subes de plan otra vez.</span>
        </div>
      )}
    </div>
  );
}
