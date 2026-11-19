'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { Landmark, Plus, Trash2 } from 'lucide-react';
import { api } from '@/lib/axios';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { apiError } from '@/features/appointments/appointments';

type Account = { id?: string; bank: string; holder: string; account_number: string; cci?: string };
type Config = { enabled: boolean; accounts: Account[]; yape_phone: string | null; plin_phone: string | null; hold_hours: number; review_text: string };

const input = 'w-full text-sm border border-slate-200 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand text-slate-800 disabled:bg-slate-50';
const label = 'block text-[11px] font-bold text-slate-500 uppercase tracking-wider mb-1';

export default function TransferSettings({ canSave, hasQr }: { canSave: boolean; hasQr: { yape: boolean; plin: boolean } }) {
  const [config, setConfig] = useState<Config | null>(null);
  const [gateway, setGateway] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    api.get('/integrations/transfer')
      .then((res) => { setConfig(res.data.transfer); setGateway(res.data.gateway); })
      .catch((err) => setError(apiError(err, 'No se pudo cargar la configuración de transferencias.')));
  }, []);

  if (!config) return error ? <ErrorAlert message={error} /> : null;

  const set = (patch: Partial<Config>) => { setConfig({ ...config, ...patch }); setNotice(''); };
  const setAccount = (index: number, patch: Partial<Account>) => set({ accounts: config.accounts.map((a, i) => (i === index ? { ...a, ...patch } : a)) });

  const save = async () => {
    try {
      setBusy(true);
      setError('');
      const res = await api.put('/integrations/transfer', config);
      setConfig(res.data.transfer);
      setNotice(res.data.message);
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar.'));
    } finally {
      setBusy(false);
    }
  };

  const way = !config.enabled ? 'Solo con la pasarela' : gateway && gateway !== 'simulated' ? 'Con la pasarela o con comprobante (el cliente elige)' : 'Solo con comprobante';

  return (
    <div className="space-y-3 pt-4 border-t border-slate-100" data-card="transfer-settings">
      <div className="flex items-start justify-between gap-3">
        <div>
          <span className="flex items-center gap-1.5 text-[11px] font-bold text-slate-600 uppercase tracking-wider"><Landmark size={13} /> Pagos en línea con transferencia y comprobante</span>
          <p className="text-xs text-slate-500 mt-1">
            En los links de pago, adelantos y paquetes en línea, el cliente puede transferir o yapear y subir la foto del comprobante. Tu equipo lo aprueba en <Link href="/payments/vouchers" className="font-semibold text-brand-accent hover:underline">Ventas → Comprobantes</Link>. Hoy: <b>{way}</b>.
          </p>
        </div>
        <Switch checked={config.enabled} disabled={!canSave} onChange={(enabled) => set({ enabled })} label="Recibir comprobantes de transferencia" />
      </div>

      {config.enabled && (
        <div className="space-y-4 rounded-xl border border-slate-200 p-4" data-transfer-config>
          <div className="space-y-2">
            <span className={label}>Cuentas bancarias</span>
            {config.accounts.length === 0 && <p className="text-xs text-slate-500">Sin cuentas. Agrega una, o usa solo Yape o Plin.</p>}
            {config.accounts.map((account, index) => (
              <div key={account.id ?? index} className="grid grid-cols-1 sm:grid-cols-[1fr_1fr_1fr_1fr_auto] gap-2 items-end" data-transfer-account={index}>
                <div><label className={label}>Banco</label><input className={input} disabled={!canSave} value={account.bank} onChange={(e) => setAccount(index, { bank: e.target.value })} placeholder="BCP" /></div>
                <div><label className={label}>Titular</label><input className={input} disabled={!canSave} value={account.holder} onChange={(e) => setAccount(index, { holder: e.target.value })} /></div>
                <div><label className={label}>N.º de cuenta</label><input className={input} disabled={!canSave} value={account.account_number} onChange={(e) => setAccount(index, { account_number: e.target.value })} /></div>
                <div><label className={label}>CCI (opcional)</label><input className={input} disabled={!canSave} value={account.cci ?? ''} onChange={(e) => setAccount(index, { cci: e.target.value })} /></div>
                {canSave && (
                  <button type="button" aria-label="Quitar cuenta" onClick={() => set({ accounts: config.accounts.filter((_, i) => i !== index) })} className="p-2 text-red-500 hover:bg-red-50 rounded-lg"><Trash2 size={16} /></button>
                )}
              </div>
            ))}
            {canSave && config.accounts.length < 6 && (
              <button type="button" data-action="add-account" onClick={() => set({ accounts: [...config.accounts, { bank: '', holder: '', account_number: '', cci: '' }] })} className="inline-flex items-center gap-1.5 text-xs font-bold text-brand-accent hover:text-brand-ink"><Plus size={14} /> Agregar cuenta</button>
            )}
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label className={label} htmlFor="transfer-yape">Celular de Yape</label>
              <input id="transfer-yape" className={input} disabled={!canSave} inputMode="tel" value={config.yape_phone ?? ''} onChange={(e) => set({ yape_phone: e.target.value })} placeholder={hasQr.yape ? 'Se mostrará tu QR' : 'Opcional'} />
            </div>
            <div>
              <label className={label} htmlFor="transfer-plin">Celular de Plin</label>
              <input id="transfer-plin" className={input} disabled={!canSave} inputMode="tel" value={config.plin_phone ?? ''} onChange={(e) => set({ plin_phone: e.target.value })} placeholder={hasQr.plin ? 'Se mostrará tu QR' : 'Opcional'} />
            </div>
            <div>
              <label className={label} htmlFor="transfer-hold">Horas que se aparta el horario</label>
              <input id="transfer-hold" type="number" min={1} max={168} className={input} disabled={!canSave} value={config.hold_hours} onChange={(e) => set({ hold_hours: Number(e.target.value) })} />
              <span className="text-[10px] text-slate-400 mt-1 block">En un adelanto, el horario queda apartado mientras revisan el comprobante, como mucho estas horas.</span>
            </div>
            <div>
              <label className={label} htmlFor="transfer-review">Cuánto demoran en revisar</label>
              <input id="transfer-review" className={input} disabled={!canSave} maxLength={60} value={config.review_text} onChange={(e) => set({ review_text: e.target.value })} placeholder="en unas horas" />
              <span className="text-[10px] text-slate-400 mt-1 block">Así lo lee el cliente: &quot;La empresa lo revisa {config.review_text || 'en unas horas'}&quot;.</span>
            </div>
          </div>
          <p className="text-xs text-slate-500">Yape y Plin usan el QR que subiste arriba{hasQr.yape || hasQr.plin ? '' : ' (aún no subes ninguno)'}; el celular es opcional si ya tienes QR.</p>
        </div>
      )}

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />
      {canSave && (
        <div className="flex justify-end">
          <button type="button" data-action="save-transfer" disabled={busy} onClick={save} className="px-4 py-2 text-sm font-bold text-white bg-brand hover:bg-brand-hover rounded-xl transition-colors disabled:opacity-60">
            {busy ? 'Guardando…' : 'Guardar transferencias'}
          </button>
        </div>
      )}
    </div>
  );
}
