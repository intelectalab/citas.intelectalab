'use client';

import { useCallback, useEffect, useState } from 'react';
import { DoorOpen, Download, Gift, Loader2, RotateCcw } from 'lucide-react';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import type { CancellationInfo, DataExport } from '../billing';

type Sheet = { sheet: string; columns: { header: string; money: boolean }[]; rows: (string | number | null)[][] };

const day = (iso: string) => new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' });

const timeLeft = (iso: string | null) => {
  if (!iso) return '';
  const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86400000);
  return days <= 1 ? 'el enlace vence hoy' : `el enlace sirve ${days} días más`;
};

export function DownloadAllButton({ className = '' }: { className?: string }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [queued, setQueued] = useState<boolean | null>(null);
  const [exports, setExports] = useState<DataExport[]>([]);

  const refresh = useCallback(() => api.get('/billing/exports')
    .then((res) => { setQueued(Boolean(res.data.ready)); setExports(res.data.items ?? []); })
    .catch(() => setQueued(false)), []);

  useEffect(() => { refresh(); }, [refresh]);

  const working = exports.some((row) => row.status === 'queued' || row.status === 'running');

  useEffect(() => {
    if (!working) return;
    const timer = window.setInterval(refresh, 5000);
    return () => window.clearInterval(timer);
  }, [working, refresh]);

  const request = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.post('/billing/exports');
      setExports(res.data.exports ?? []);
      setNotice(res.data.message ?? '');
    } catch (err) {
      setError(apiError(err, 'No se pudo preparar el archivo.'));
    } finally {
      setBusy(false);
    }
  };

  if (queued) {
    const latest = exports[0];
    return (
      <span className="inline-flex flex-col gap-1" data-exports>
        <button type="button" data-download-all disabled={busy || working} onClick={request} className={`inline-flex items-center gap-1.5 text-xs font-semibold text-brand-accent hover:text-brand-ink disabled:opacity-60 ${className}`}>
          {busy || working ? <Loader2 size={13} className="animate-spin" /> : <Download size={13} />} {working ? 'Preparando tus datos…' : 'Preparar archivo con todos mis datos (Excel)'}
        </button>
        {latest?.url && (
          <a href={latest.url} data-export-link className="text-xs font-semibold text-brand-ink underline">
            Descargar el archivo del {day(latest.created_at)} ({timeLeft(latest.expires_at)})
          </a>
        )}
        {latest?.status === 'failed' && <span className="text-xs text-red-600">{latest.error}</span>}
        {notice && !latest?.url && <span className="text-xs text-slate-500">{notice}</span>}
        {error && <span className="text-xs text-red-600">{error}</span>}
      </span>
    );
  }

  const download = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.get('/billing/export');
      const { default: writeExcelFile } = await import('write-excel-file/browser');
      const sheets = (res.data.sheets as Sheet[]).map((sheet) => ({
        sheet: sheet.sheet,
        columns: sheet.columns.map(() => ({ width: 22 })),
        data: [
          sheet.columns.map((column) => ({ value: column.header, fontWeight: 'bold' as const })),
          ...sheet.rows.map((row) => row.map((value) => (value === null || value === '' ? null : typeof value === 'number' ? { value, type: Number } : { value: String(value), type: String }))),
        ],
      }));
      const stamp = new Date().toISOString().slice(0, 10);
      await writeExcelFile(sheets as never).toFile(`Datos de ${res.data.company} ${stamp}.xlsx`);
    } catch (err) {
      setError(apiError(err, 'No se pudieron descargar los datos.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <span className="inline-flex flex-col gap-1">
      <button type="button" data-download-all disabled={busy} onClick={download} className={`inline-flex items-center gap-1.5 text-xs font-semibold text-brand-accent hover:text-brand-ink disabled:opacity-60 ${className}`}>
        {busy ? <Loader2 size={13} className="animate-spin" /> : <Download size={13} />} Descargar todos mis datos (Excel)
      </button>
      {error && <span className="text-xs text-red-600">{error}</span>}
    </span>
  );
}

export default function CancelPlan({ info, currency, onChanged }: { info: CancellationInfo; currency?: string; onChanged: (message: string) => Promise<void> | void }) {
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');
  const [details, setDetails] = useState('');
  const [confirm, setConfirm] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');

  const offer = reason ? info.offers[reason] : undefined;
  const immediate = new Date(info.effective_at).getTime() <= Date.now() + 60000;

  const send = async (path: string, body: object, done: string, key: string) => {
    try {
      setBusy(key);
      setError('');
      await api.post(path, body);
      setOpen(false);
      await onChanged(done);
    } catch (err) {
      setError(apiError(err, 'No se pudo completar.'));
    } finally {
      setBusy('');
    }
  };

  if (info.scheduled) {
    return (
      <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 space-y-2" data-cancel-scheduled>
        <p className="text-sm text-amber-900">
          <b>Pediste la baja.</b> Todo sigue funcionando hasta el {day(info.scheduled.effective_at)}; después tu empresa queda en solo lectura y tus datos se conservan.
        </p>
        <div className="flex flex-wrap items-center gap-3">
          <button type="button" data-resume disabled={busy !== ''} onClick={() => send('/billing/resume', {}, 'Listo: anulaste la baja y tu plan sigue como siempre.', 'resume')} className="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold text-white bg-brand hover:bg-brand-hover rounded-lg disabled:opacity-60">
            {busy === 'resume' ? <Loader2 size={13} className="animate-spin" /> : <RotateCcw size={13} />} Seguir con mi plan
          </button>
          <DownloadAllButton />
        </div>
        <ErrorAlert message={error} />
      </div>
    );
  }

  return (
    <>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-2">
        <DownloadAllButton />
        <button type="button" data-cancel-plan onClick={() => { setOpen(true); setError(''); }} className="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-500 hover:text-rose-700">
          <DoorOpen size={13} /> Dar de baja mi empresa
        </button>
      </div>

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title="Dar de baja mi empresa" subtitle="Antes de irte, revisa qué pasa" icon={<DoorOpen size={18} />}>
        <div className="space-y-5" data-cancel-panel>
          <ul className="text-sm text-slate-600 space-y-1.5 list-disc pl-5">
            {immediate ? (
              <li>No tienes un periodo pagado por delante: <b>la baja es inmediata</b> y no se te cobra nada más.</li>
            ) : (
              <li>Todo sigue funcionando hasta el <b>{day(info.effective_at)}</b>. No se te cobra otro periodo.</li>
            )}
            <li>Después, tu empresa queda en solo lectura: puedes entrar, ver y descargar tus datos, pero no agendar ni vender.</li>
            <li>Tus datos se conservan. Si vuelves y pagas tu plan, todo queda como estaba.</li>
            {!immediate && <li>Hasta esa fecha puedes arrepentirte con un clic.</li>}
          </ul>

          <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 space-y-1">
            <span className="block text-sm font-bold text-brand-ink">Descarga tus datos antes</span>
            <span className="block text-xs text-slate-500">Un Excel con clientes, citas, ventas, pagos, servicios, profesionales y productos.</span>
            <DownloadAllButton />
          </div>

          <div className="space-y-2">
            <span className="block text-[11px] font-bold text-slate-600 uppercase tracking-wider">¿Por qué te vas?</span>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2" role="radiogroup" aria-label="Motivo">
              {info.reasons.map((item) => (
                <OptionCard key={item.key} type="button" role="radio" aria-checked={reason === item.key} data-cancel-reason={item.key} selected={reason === item.key} onClick={() => setReason(item.key)} className="rounded-xl border px-3.5 py-2.5 text-left text-sm font-semibold text-brand-ink">
                  {item.label}
                </OptionCard>
              ))}
            </div>
            <textarea aria-label="Cuéntanos más" maxLength={500} rows={3} value={details} onChange={(e) => setDetails(e.target.value)} placeholder="Cuéntanos más, si quieres. Nos ayuda a mejorar." className="w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand" />
          </div>

          {offer && (
            <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 space-y-2" data-cancel-offer>
              <span className="flex items-center gap-1.5 text-sm font-bold text-emerald-800"><Gift size={15} /> Antes de irte: {offer.text}</span>
              <span className="block text-xs text-emerald-800">Pagarías {money(offer.price, currency)} al mes durante ese tiempo y luego el precio normal.</span>
              <button type="button" data-offer-accept disabled={busy !== ''} onClick={() => send('/billing/cancel', { reason, details, accept_offer: true }, `Listo: aplicamos ${offer.text}. Gracias por quedarte.`, 'offer')} className="px-3.5 py-2 text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg disabled:opacity-60">
                {busy === 'offer' ? 'Aplicando…' : 'Me quedo con el descuento'}
              </button>
            </div>
          )}

          <label className="flex items-start gap-2 text-sm text-slate-700">
            <input type="checkbox" data-cancel-confirm-check checked={confirm} onChange={(e) => setConfirm(e.target.checked)} className="mt-0.5 rounded border-slate-300 accent-brand cursor-pointer" />
            {immediate ? 'Entiendo que mi empresa queda en solo lectura desde ahora.' : `Entiendo que después del ${day(info.effective_at)} mi empresa queda en solo lectura.`}
          </label>

          <ErrorAlert message={error} />

          <button type="button" data-cancel-submit disabled={busy !== '' || !reason || !confirm} onClick={() => send('/billing/cancel', { reason, details, accept_offer: false, confirm: true }, immediate ? 'Listo: tu empresa quedó dada de baja. Tus datos se conservan y puedes volver pagando tu plan.' : `Listo: pediste la baja. Todo sigue funcionando hasta el ${day(info.effective_at)}.`, 'cancel')} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold text-rose-700 border border-rose-200 rounded-xl hover:bg-rose-50 disabled:opacity-50">
            {busy === 'cancel' ? <Loader2 size={15} className="animate-spin" /> : <DoorOpen size={15} />} Dar de baja
          </button>
        </div>
      </FormDrawer>
    </>
  );
}
