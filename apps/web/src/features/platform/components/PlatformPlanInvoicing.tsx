'use client';

import { useEffect, useState } from 'react';
import { FileText, Loader2 } from 'lucide-react';
import { api } from '@/lib/axios';
import OptionCard from '@/app/components/common/OptionCard';
import SectionTitle from '@/app/components/common/SectionTitle';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { apiError } from '@/features/appointments/appointments';

interface InvoicingConfig {
  provider: string;
  connected: boolean;
  mode: 'simulated' | 'live';
  has_token: boolean;
  ruc: string | null;
  legal_name: string | null;
  address: string | null;
  series: { boleta: string; factura: string };
  defaults: { ruc: string | null; legal_name: string | null; address: string | null };
  pending: number;
  rejected: number;
}

const card = 'bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 space-y-4';

export default function PlatformPlanInvoicing() {
  const [config, setConfig] = useState<InvoicingConfig | null>(null);
  const [form, setForm] = useState({ mode: 'simulated' as 'simulated' | 'live', ruc: '', legal_name: '', address: '', route: '', token: '', boleta: 'B001', factura: 'F001' });
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState(false);

  const fill = (data: InvoicingConfig) => {
    setConfig(data);
    setForm({ mode: data.mode, ruc: data.ruc ?? data.defaults.ruc ?? '', legal_name: data.legal_name ?? data.defaults.legal_name ?? '', address: data.address ?? data.defaults.address ?? '', route: '', token: '', boleta: data.series.boleta, factura: data.series.factura });
  };

  useEffect(() => {
    api.get('/admin/billing/invoicing').then((res) => fill(res.data)).catch((err) => setError(apiError(err, 'No se pudo cargar la facturación del plan.')));
  }, []);

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.put('/admin/billing/invoicing', {
        mode: form.mode, ruc: form.ruc || null, legal_name: form.legal_name || null, address: form.address || null,
        route: form.route || null, token: form.token || null, series: { boleta: form.boleta, factura: form.factura },
      });
      fill(res.data);
      setNotice(form.mode === 'live' ? 'Listo: las boletas y facturas de los planes se enviarán a SUNAT por Nubefact.' : 'Listo: las boletas y facturas de los planes se numeran, pero no se envían a SUNAT (simulación).');
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className={card} data-plan-invoicing>
      <SectionTitle><FileText size={16} className="text-brand-accent" /> Boletas y facturas de los planes</SectionTitle>
      <p className="text-sm text-slate-500">Cada pago de un plan emite una boleta o factura de la plataforma a la empresa que paga, con los datos que ella deja en Mi empresa → Plan. Sin Nubefact conectado, se numeran como simulación.</p>
      {config && (config.pending > 0 || config.rejected > 0) && (
        <p className="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">{config.pending} en envío a SUNAT · {config.rejected} rechazados. Los pendientes se vuelven a enviar solos cada 10 minutos.</p>
      )}
      <SuccessAlert message={notice} />
      <ErrorAlert message={error} />
      {config && (
        <div className="space-y-4">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Cómo se emiten">
            {([['simulated', 'Simulación (no se envía a SUNAT)'], ['live', 'Emitir de verdad con Nubefact']] as const).map(([mode, text]) => (
              <OptionCard key={mode} type="button" role="radio" aria-checked={form.mode === mode} data-invoicing-mode={mode} selected={form.mode === mode} onClick={() => setForm({ ...form, mode })} className="rounded-xl border px-3.5 py-2.5 text-sm font-bold text-brand-ink text-left">
                {text}
              </OptionCard>
            ))}
          </div>
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div><label className={labelClass} htmlFor="pi-ruc">RUC de la plataforma</label><input id="pi-ruc" inputMode="numeric" maxLength={11} value={form.ruc} onChange={(e) => setForm({ ...form, ruc: e.target.value.replace(/\D/g, '') })} className={inputClass} /></div>
            <div><label className={labelClass} htmlFor="pi-name">Razón social</label><input id="pi-name" maxLength={200} value={form.legal_name} onChange={(e) => setForm({ ...form, legal_name: e.target.value })} className={inputClass} /></div>
            <div><label className={labelClass} htmlFor="pi-address">Dirección fiscal</label><input id="pi-address" maxLength={255} value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} className={inputClass} /></div>
          </div>
          <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div><label className={labelClass} htmlFor="pi-b">Serie de boletas</label><input id="pi-b" maxLength={4} value={form.boleta} onChange={(e) => setForm({ ...form, boleta: e.target.value.toUpperCase() })} className={inputClass} /></div>
            <div><label className={labelClass} htmlFor="pi-f">Serie de facturas</label><input id="pi-f" maxLength={4} value={form.factura} onChange={(e) => setForm({ ...form, factura: e.target.value.toUpperCase() })} className={inputClass} /></div>
          </div>
          {form.mode === 'live' && (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div><label className={labelClass} htmlFor="pi-route">Ruta de Nubefact</label><input id="pi-route" value={form.route} onChange={(e) => setForm({ ...form, route: e.target.value })} className={inputClass} placeholder={config.has_token ? 'Ya guardada: déjala vacía para no cambiarla' : 'https://api.nubefact.com/api/v1/...'} /></div>
              <div><label className={labelClass} htmlFor="pi-token">Token de Nubefact</label><input id="pi-token" type="password" autoComplete="off" value={form.token} onChange={(e) => setForm({ ...form, token: e.target.value })} className={inputClass} placeholder={config.has_token ? 'Ya guardado' : ''} /></div>
            </div>
          )}
          <button type="button" data-invoicing-save disabled={busy} onClick={save} className="inline-flex items-center justify-center gap-2 px-5 py-2.5 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover disabled:opacity-50">
            {busy && <Loader2 size={15} className="animate-spin" />} Guardar
          </button>
        </div>
      )}
    </section>
  );
}
