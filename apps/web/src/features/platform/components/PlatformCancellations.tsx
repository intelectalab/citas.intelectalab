'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import Badge from '@/app/components/common/Badge';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { PagedList } from '@/app/components/common/Pager';
import { api } from '@/lib/axios';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { momentOf } from '@/features/sales/sales';

type Offer = { enabled: boolean; percent: number; months: number; reasons: string[]; reason_list: { key: string; label: string }[] };
type Row = { id: string; tenant: { id: string; name: string }; reason: string; details: string | null; status: 'scheduled' | 'done' | 'resumed' | 'kept'; offer: string | null; offer_accepted: boolean; effective_at: string | null; requested_by: string | null; created_at: string };

const STATUS: Record<Row['status'], { label: string; tone: string }> = {
  scheduled: { label: 'Termina pronto', tone: 'bg-amber-50 text-amber-800' },
  done: { label: 'Dada de baja', tone: 'bg-slate-100 text-slate-700' },
  resumed: { label: 'Se quedó', tone: 'bg-emerald-50 text-emerald-700' },
  kept: { label: 'Aceptó la oferta', tone: 'bg-emerald-50 text-emerald-700' },
};

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs';
const input = 'w-24 text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand';

export default function PlatformCancellations() {
  const [offer, setOffer] = useState<Offer | null>(null);
  const [rows, setRows] = useState<Row[] | null>(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api.get('/admin/billing').then((res) => setOffer(res.data.cancel_offer)).catch((err) => setError(apiError(err, 'No se pudo cargar la oferta.')));
    api.get('/admin/billing/cancellations').then((res) => setRows(res.data.cancellations)).catch((err) => setError(apiError(err, 'No se pudieron cargar las bajas.')));
  }, []);

  const save = async () => {
    if (!offer) return;
    try {
      setBusy(true);
      setError('');
      const res = await api.put('/admin/billing/cancel-offer', { enabled: offer.enabled, percent: offer.percent, months: offer.months, reasons: offer.reasons });
      setOffer(res.data.cancel_offer);
      setNotice('Listo: se guardó la oferta para quedarse.');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4" data-cancellations>
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />

      {offer && (
        <section className={`${card} p-5 space-y-3`} data-cancel-offer-config>
          <div className="flex items-start justify-between gap-3">
            <div>
              <span className="block text-sm font-bold text-brand-ink">Oferta para quedarse</span>
              <p className="text-xs text-slate-500 mt-0.5">Cuando una empresa con plan mensual pide la baja por uno de estos motivos, se le ofrece un descuento antes de confirmar. Apagada, nadie la ve.</p>
            </div>
            <Switch checked={offer.enabled} onChange={(enabled) => { setOffer({ ...offer, enabled }); setNotice(''); }} label="Ofrecer descuento al dar de baja" />
          </div>
          {offer.enabled && (
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-2 text-sm text-slate-700">
                <input type="number" min={5} max={100} aria-label="Porcentaje" className={input} value={offer.percent} onChange={(e) => setOffer({ ...offer, percent: Number(e.target.value) })} /> % de descuento por
                <input type="number" min={1} max={12} aria-label="Meses" className={input} value={offer.months} onChange={(e) => setOffer({ ...offer, months: Number(e.target.value) })} /> meses
              </div>
              <div className="flex flex-wrap gap-2">
                {offer.reason_list.map((reason) => {
                  const on = offer.reasons.includes(reason.key);
                  return (
                    <button key={reason.key} type="button" aria-pressed={on} onClick={() => setOffer({ ...offer, reasons: on ? offer.reasons.filter((r) => r !== reason.key) : [...offer.reasons, reason.key] })} className={`px-3 py-1.5 rounded-lg text-xs font-semibold border ${on ? 'bg-brand text-white border-brand' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'}`}>
                      {reason.label}
                    </button>
                  );
                })}
              </div>
              <p className="text-xs text-slate-400">El descuento se aplica a los cobros con tarjeta y a los pagos con link. Si la empresa paga con Suscripciones de Mercado Pago, el monto de Mercado Pago no cambia.</p>
            </div>
          )}
          <div className="flex justify-end">
            <button type="button" data-save-offer disabled={busy} onClick={save} className="px-4 py-2 text-sm font-bold text-white bg-brand hover:bg-brand-hover rounded-xl disabled:opacity-60">{busy ? 'Guardando…' : 'Guardar oferta'}</button>
          </div>
        </section>
      )}

      <section className={`${card} overflow-hidden flex flex-col`}>
        {rows === null ? <p className="p-6 text-sm text-slate-500">Cargando...</p> : (
          <PagedList
            items={rows}
            empty="Ninguna empresa pidió la baja."
            renderRow={(row) => (
              <li key={row.id} className="flex flex-wrap sm:flex-nowrap items-center gap-3 px-5 py-3.5" data-cancellation={row.id}>
                <span className="flex-1 min-w-0">
                  <Link href={`/admin/tenants/${row.tenant.id}`} className="block text-sm font-bold text-slate-800 hover:text-brand-accent truncate">{row.tenant.name}</Link>
                  <span className="block text-xs text-slate-500 truncate">
                    {row.reason}{row.details ? ` · "${row.details}"` : ''} · pedido {momentOf(row.created_at)}{row.requested_by ? ` por ${row.requested_by}` : ''}
                    {row.effective_at && row.status === 'scheduled' ? ` · termina el ${formatDate(row.effective_at.slice(0, 10), { day: 'numeric', month: 'short' })}` : ''}
                    {row.offer ? ` · oferta: ${row.offer}` : ''}
                  </span>
                </span>
                <Badge tone={STATUS[row.status].tone} bold className="shrink-0">{STATUS[row.status].label}</Badge>
              </li>
            )}
          />
        )}
      </section>
    </div>
  );
}
