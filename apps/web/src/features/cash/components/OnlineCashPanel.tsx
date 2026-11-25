'use client';

import { useEffect, useState } from 'react';
import { Globe } from 'lucide-react';
import { api } from '@/lib/axios';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import DateField from '@/app/components/common/DateField';
import { PagedList } from '@/app/components/common/Pager';
import { ErrorAlert } from '@/app/components/common/Alert';
import { apiError } from '@/features/appointments/appointments';
import { money, type OnlineCashDay, type OnlineCashPayment } from '@/features/sales/sales';

const sectionTitle = 'text-[11px] font-bold text-slate-400 uppercase tracking-wider';

const timeOf = (iso: string) => new Date(iso).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit', hour12: false });

interface OnlineCashPanelProps {
  branch: { id: string; name: string } | null;
  date: string;
  onClose: () => void;
}

export default function OnlineCashPanel({ branch, date, onClose }: OnlineCashPanelProps) {
  const [day, setDay] = useState(date);
  const [data, setData] = useState<OnlineCashDay | null>(null);
  const [error, setError] = useState('');

  useEffect(() => { if (branch) setDay(date); }, [branch, date]);

  useEffect(() => {
    if (!branch || !day) return;
    let active = true;
    setError('');
    api.get('/cash/online', { params: { branch_id: branch.id, date: day } })
      .then((res) => { if (active) setData(res.data); })
      .catch((err) => { if (active) { setData(null); setError(apiError(err, 'No se pudieron cargar los pagos en línea.')); } });
    return () => { active = false; };
  }, [branch, day]);

  const totals = data?.totals;
  const currency = data?.currency;

  return (
    <FormDrawer isOpen={Boolean(branch)} onClose={onClose} title="Caja en línea" subtitle={branch?.name} icon={<Globe size={20} />}>
      <div className="space-y-5 pb-4" data-online-cash>
        <p className="text-sm text-slate-600">
          Lo que tus clientes pagaron solos por internet: links de pago y adelantos de reservas. No pasa por las manos de nadie, así que no se suma al efectivo de ningún turno.
        </p>

        <div className="max-w-[220px]">
          <span className={sectionTitle}>Día</span>
          <DateField ariaLabel="Día" value={day} max={date} clearable={false} onChange={(value) => value && setDay(value)} />
        </div>

        <ErrorAlert message={error} />

        {totals && (
          <div className="grid grid-cols-3 gap-2" data-online-totals>
            {([['Entró', totals.received], ['Devuelto', totals.refunded], ['Neto', totals.net]] as [string, number][]).map(([label, value]) => (
              <div key={label} className="rounded-xl border border-slate-200 px-3 py-3">
                <span className="block text-xs text-slate-500">{label}</span>
                <span className="block text-base font-black text-brand-ink tabular-nums" data-online-total={label}>{money(value, currency)}</span>
              </div>
            ))}
          </div>
        )}

        {data && (
          <section className="rounded-2xl border border-slate-200 overflow-hidden flex flex-col">
            <div className="px-5 py-3 bg-slate-50/80 border-b border-slate-100">
              <span className={sectionTitle}>Pagos del día</span>
            </div>
            <PagedList<OnlineCashPayment>
              items={data.payments}
              empty="Este día no hubo pagos en línea en esta sede."
              renderRow={(payment) => (
                <li key={payment.id} data-online-payment={payment.sale_code ?? payment.id} className="flex items-center gap-3 px-5 py-3">
                  <span className="flex-1 min-w-0">
                    <span className="block text-sm font-semibold text-slate-800 truncate">
                      {payment.method_label}{payment.sale_code ? ` · ${payment.sale_code}` : ''}
                    </span>
                    <span className="block text-xs text-slate-500 truncate">
                      {[timeOf(payment.at), payment.customer, payment.provider].filter(Boolean).join(' · ')}
                    </span>
                  </span>
                  <span className={`text-sm font-bold tabular-nums ${payment.amount < 0 ? 'text-rose-700' : 'text-emerald-700'}`}>
                    {payment.amount < 0 ? '−' : '+'} {money(Math.abs(payment.amount), currency)}
                  </span>
                </li>
              )}
            />
          </section>
        )}
      </div>
    </FormDrawer>
  );
}
