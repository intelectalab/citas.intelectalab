'use client';

import { useEffect, useState } from 'react';
import { Loader2, Minus, PackagePlus, Plus, Trash2, Undo2 } from 'lucide-react';
import OptionCard from '@/app/components/common/OptionCard';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { apiError, formatDate } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import { cycleLabel, type AddonPreview, type PlanAddonsInfo } from '../billing';

interface Props {
  info: PlanAddonsInfo;
  onDone: (data: Record<string, unknown>) => void | Promise<void>;
}

const longDate = (date: string) => formatDate(date, { day: 'numeric', month: 'long' });

export default function PlanAddons({ info, onDone }: Props) {
  const [open, setOpen] = useState(false);
  const [choice, setChoice] = useState('');
  const [quantity, setQuantity] = useState(1);
  const [preview, setPreview] = useState<AddonPreview | null>(null);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [confirming, setConfirming] = useState('');

  const selected = info.available.find((addon) => addon.id === choice);
  const room = selected ? Math.max(0, selected.max_quantity - selected.owned) : 0;

  useEffect(() => {
    if (!open || !choice) return;
    let alive = true;
    api.post('/billing/addons/preview', { addon_id: choice, quantity })
      .then((res) => { if (alive) { setPreview(res.data); setError(''); } })
      .catch((err) => { if (alive) setError(apiError(err, 'No se pudo calcular el monto.')); });
    return () => { alive = false; };
  }, [open, choice, quantity]);

  if (!info.mine.length && !info.available.length) return null;

  const start = () => {
    const first = info.available.find((addon) => addon.owned < addon.max_quantity);
    setChoice(first?.id ?? '');
    setQuantity(1);
    setPreview(null);
    setError('');
    setOpen(true);
  };

  const run = async (key: string, call: () => Promise<{ data: Record<string, unknown> }>) => {
    try {
      setBusy(key);
      setError('');
      const res = await call();
      setOpen(false);
      setConfirming('');
      await onDone(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo completar. Inténtalo de nuevo.'));
    } finally {
      setBusy('');
    }
  };

  return (
    <div className="pt-3 border-t border-slate-100 space-y-2" data-plan-addons>
      <div className="flex items-center justify-between gap-3">
        <span className="text-sm font-bold text-brand-ink">Complementos</span>
        {info.available.length > 0 && (
          <button type="button" data-addon-open onClick={start} className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-accent hover:text-brand-ink cursor-pointer">
            <PackagePlus size={14} /> Sumar complemento
          </button>
        )}
      </div>

      {info.mine.length === 0 ? (
        <p className="text-xs text-slate-500">Si te quedas corto, puedes sumar a tu plan, por ejemplo, una sede o un usuario más sin cambiar de plan.</p>
      ) : (
        <ul className="space-y-1.5">
          {info.mine.map((addon) => (
            <li key={addon.id} data-addon-row={addon.id} className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
              <span className="flex-1 min-w-0">
                <span className="font-semibold text-slate-800">{addon.name}{addon.quantity > 1 ? ` × ${addon.quantity}` : ''}</span>
                <span className="block text-xs text-slate-500">
                  {money(addon.price * addon.quantity, addon.currency)} {cycleLabel(info.billing_cycle)}
                  {addon.extra ? ` · ${addon.extra.label}: +${addon.extra.amount.toLocaleString('es-PE')}` : ''}
                  {addon.ends_at ? ` · Se quita el ${longDate(addon.ends_at)}` : ''}
                </span>
              </span>
              {addon.ends_at ? (
                <button type="button" data-addon-keep={addon.id} disabled={busy !== ''} onClick={() => run(`keep-${addon.id}`, () => api.post(`/billing/addons/${addon.id}/keep`))} className="inline-flex items-center gap-1 text-xs font-semibold text-brand-accent hover:text-brand-ink">
                  {busy === `keep-${addon.id}` ? <Loader2 size={12} className="animate-spin" /> : <Undo2 size={12} />} Seguir con él
                </button>
              ) : confirming === addon.id ? (
                <span className="flex flex-wrap items-center gap-2 text-xs" data-addon-confirm>
                  <span className="text-slate-600">Se quita al terminar el periodo; lo pagado no se devuelve.</span>
                  <button type="button" data-addon-remove-yes disabled={busy !== ''} onClick={() => run(`remove-${addon.id}`, () => api.delete(`/billing/addons/${addon.id}`))} className="px-2.5 py-1 font-semibold text-rose-700 border border-rose-200 rounded-lg hover:bg-rose-50">
                    {busy === `remove-${addon.id}` ? <Loader2 size={12} className="animate-spin" /> : 'Sí, quitar'}
                  </button>
                  <button type="button" onClick={() => setConfirming('')} className="px-2.5 py-1 font-semibold text-slate-600 hover:bg-slate-50 rounded-lg">No</button>
                </span>
              ) : (
                <button type="button" data-addon-remove={addon.id} onClick={() => setConfirming(addon.id)} title="Quitar el complemento" aria-label="Quitar el complemento" className="p-1.5 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors">
                  <Trash2 size={15} />
                </button>
              )}
            </li>
          ))}
        </ul>
      )}

      {!open && <ErrorAlert message={error} />}

      <FormDrawer isOpen={open} onClose={() => setOpen(false)} title="Sumar complemento" subtitle="Se suma a tu plan y a tu cobro" icon={<PackagePlus size={18} />}>
        <div className="space-y-4" data-addon-panel>
          <div className="space-y-2" role="radiogroup" aria-label="Complemento">
            {info.available.map((addon) => {
              const full = addon.owned >= addon.max_quantity;
              return (
                <OptionCard key={addon.id} type="button" role="radio" aria-checked={choice === addon.id} disabled={full} data-addon-option={addon.id} selected={choice === addon.id} onClick={() => { setChoice(addon.id); setQuantity(1); }} className={`w-full text-left rounded-xl border px-4 py-3 ${full ? 'opacity-50' : ''}`}>
                  <span className="flex items-center justify-between gap-3">
                    <span className="text-sm font-bold text-brand-ink">{addon.name}{addon.owned ? ` · ya tienes ${addon.owned}` : ''}</span>
                    <span className="text-sm font-black text-brand-ink tabular-nums whitespace-nowrap">{money(addon.price, addon.currency)} {cycleLabel(info.billing_cycle)}</span>
                  </span>
                  {addon.description && <span className="block text-xs text-slate-500 mt-1">{addon.description}</span>}
                </OptionCard>
              );
            })}
          </div>

          {selected && room > 1 && (
            <div className="flex items-center gap-3" data-addon-quantity>
              <span className="text-sm font-semibold text-slate-700">Cuántos</span>
              <button type="button" aria-label="Uno menos" disabled={quantity <= 1} onClick={() => setQuantity(quantity - 1)} className="p-2 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-40"><Minus size={14} /></button>
              <span className="w-8 text-center text-sm font-black tabular-nums">{quantity}</span>
              <button type="button" aria-label="Uno más" disabled={quantity >= room} onClick={() => setQuantity(quantity + 1)} className="p-2 border border-slate-200 rounded-lg hover:bg-slate-50 disabled:opacity-40"><Plus size={14} /></button>
            </div>
          )}

          {preview && preview.addon.id === choice && (
            <p className={`text-xs rounded-lg px-3 py-2 border ${preview.blocked ? 'text-amber-800 bg-amber-50 border-amber-200' : 'text-slate-600 bg-slate-50 border-slate-200'}`} data-addon-preview>{preview.message}</p>
          )}
          <ErrorAlert message={error} />
          <button type="button" data-addon-confirm-add disabled={busy !== '' || !choice || !preview || preview.blocked || preview.addon.id !== choice} onClick={() => run('add', () => api.post('/billing/addons', { addon_id: choice, quantity }))} className="w-full flex items-center justify-center gap-2 px-5 py-3 text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover shadow-sm transition-colors disabled:opacity-50">
            {busy === 'add' && <Loader2 size={15} className="animate-spin" />}
            {preview && preview.today > 0 ? `Pagar ${money(preview.today, preview.addon.currency)} y sumar` : 'Sumar a mi plan'}
          </button>
        </div>
      </FormDrawer>
    </div>
  );
}
