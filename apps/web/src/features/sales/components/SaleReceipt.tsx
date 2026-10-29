'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { Printer } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money, momentOf, SUNAT_LABELS, type SaleDetail } from '../sales';

const row = 'flex justify-between gap-4';

export default function SaleReceipt() {
  const params = useParams<{ id: string }>();
  const [sale, setSale] = useState<SaleDetail | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get(`/sales/${params.id}`)
      .then((res) => setSale(res.data))
      .catch((err) => setError(apiError(err, 'No se pudo cargar el comprobante.')));
  }, [params.id]);

  if (!sale) {
    return <p className={`p-10 text-sm text-center ${error ? 'text-red-600' : 'text-slate-500'}`}>{error || 'Cargando comprobante...'}</p>;
  }

  const invoice = sale.invoice;

  return (
    <div className="min-h-screen bg-[#F2F2F2] print:bg-white py-8 print:py-0">
      <div className="max-w-[340px] mx-auto mb-4 flex justify-end print:hidden">
        <button type="button" data-print onClick={() => window.print()} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors">
          <Printer size={16} /> Imprimir
        </button>
      </div>

      <article data-receipt className="max-w-[340px] mx-auto bg-white text-slate-900 px-6 py-7 shadow-sm print:shadow-none print:max-w-none print:px-2 print:py-0 text-[13px] leading-snug">
        <header className="text-center space-y-0.5">
          {sale.issuer.logo_url && <img src={sale.issuer.logo_url} alt="" data-receipt-logo className="mx-auto mb-2 max-h-14 max-w-[180px] object-contain grayscale print:grayscale" />}
          <h1 className="text-base font-extrabold">{sale.issuer.name}</h1>
          <p>{sale.issuer.tax_id ? `RUC ${sale.issuer.tax_id}` : 'RUC sin registrar'}</p>
          {sale.issuer.address && <p>{sale.issuer.address}</p>}
          <p>{sale.branch.name}</p>
        </header>

        <div className="my-4 py-3 border-y border-dashed border-slate-400 text-center">
          <p className="font-extrabold uppercase">{invoice ? `${invoice.doc_type_label}${invoice.doc_type === 'ticket' ? '' : ' electrónica'}` : 'Detalle de venta'}</p>
          <p className="text-base font-extrabold tabular-nums">{invoice?.number ?? sale.code}</p>
        </div>

        <dl className="space-y-0.5">
          <div className={row}><dt>Fecha</dt><dd>{momentOf(invoice?.issued_at ?? sale.created_at)}</dd></div>
          <div className={row}><dt>Cliente</dt><dd className="text-right">{invoice?.customer_name ?? sale.customer?.name ?? 'Cliente varios'}</dd></div>
          {invoice?.customer_doc_number && <div className={row}><dt>{invoice.doc_type === 'factura' ? 'RUC' : 'Documento'}</dt><dd>{invoice.customer_doc_number}</dd></div>}
          {invoice?.customer_address && <div className={row}><dt>Dirección</dt><dd className="text-right">{invoice.customer_address}</dd></div>}
          <div className={row}><dt>Venta</dt><dd>{sale.code}</dd></div>
        </dl>

        <table className="w-full my-4 border-y border-dashed border-slate-400">
          <thead>
            <tr className="text-left">
              <th className="py-1.5 font-bold">Descripción</th>
              <th className="py-1.5 font-bold text-right">Cant.</th>
              <th className="py-1.5 font-bold text-right">Importe</th>
            </tr>
          </thead>
          <tbody>
            {sale.items.map((item) => (
              <tr key={item.id} className="align-top">
                <td className="py-1 pr-2">{item.description}</td>
                <td className="py-1 text-right tabular-nums">{item.quantity}</td>
                <td className="py-1 text-right tabular-nums">{money(item.total, sale?.currency)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <dl className="space-y-0.5">
          {sale.coupon && <div className={row}><dt>Cupón {sale.coupon.code}</dt><dd className="tabular-nums">− {money(sale.coupon.discount, sale?.currency)}</dd></div>}
          <div className={row}><dt>Op. gravada</dt><dd className="tabular-nums">{money(sale.total - sale.tax, sale?.currency)}</dd></div>
          <div className={row}><dt>IGV {sale.tax_rate}%</dt><dd className="tabular-nums">{money(sale.tax, sale?.currency)}</dd></div>
          <div className={`${row} text-base font-extrabold pt-1`}><dt>Total</dt><dd className="tabular-nums">{money(sale.total, sale?.currency)}</dd></div>
        </dl>

        <div className="mt-4 pt-3 border-t border-dashed border-slate-400 space-y-0.5">
          <p className="font-bold">Pagos</p>
          {sale.payments.filter((p) => p.amount > 0).map((payment) => (
            <div key={payment.id} className={row}>
              <span>{payment.method_label}{payment.reference ? ` · ${payment.reference}` : ''}</span>
              <span className="tabular-nums">{money(payment.amount, sale?.currency)}</span>
            </div>
          ))}
          {sale.balance > 0 && <div className={`${row} font-bold`}><span>Saldo por cobrar</span><span className="tabular-nums">{money(sale.balance, sale?.currency)}</span></div>}
          {(sale.refunded ?? 0) > 0 && <div className={`${row} font-bold`}><span>Devuelto</span><span className="tabular-nums">− {money(sale.refunded, sale?.currency)}</span></div>}
        </div>

        <footer className="mt-5 text-center text-[11px] text-slate-600 space-y-1">
          {invoice && (
            <p data-sunat>
              {invoice.simulated
                ? 'Documento de prueba: no se envió a SUNAT y no tiene validez tributaria.'
                : invoice.sunat_status === 'not_applicable'
                  ? 'Comprobante interno. No es un comprobante de pago electrónico.'
                  : SUNAT_LABELS[invoice.sunat_status]}
            </p>
          )}
          {sale.status === 'void' && <p className="font-bold">VENTA ANULADA</p>}
          {sale.issuer.footer && <p data-receipt-footer className="whitespace-pre-line pt-1">{sale.issuer.footer}</p>}
          <p>Gracias por su preferencia.</p>
        </footer>
      </article>
    </div>
  );
}
