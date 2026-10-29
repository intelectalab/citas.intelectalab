'use client';

import { useEffect, useState } from 'react';
import { CalendarPlus, CreditCard } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount, money } from '@/features/sales/sales';
import type { GiftCardRow, LoyaltyBoardData } from '../loyalty';

interface Props {
  giftCard: GiftCardRow | null;
  mode: 'redeem' | 'extend' | null;
  currency: string;
  onClose: () => void;
  onSaved: (data: LoyaltyBoardData, notice: string) => void;
}

export default function GiftCardActionDrawer({ giftCard, mode, currency, onClose, onSaved }: Props) {
  const [amount, setAmount] = useState('');
  const [days, setDays] = useState('30');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!giftCard || !mode) return;
    setAmount(giftCard.balance.toFixed(2));
    setDays('30');
    setError('');
  }, [giftCard, mode]);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!giftCard || !mode) return;

    try {
      setSaving(true);
      setError('');
      if (mode === 'redeem') {
        const res = await api.post<LoyaltyBoardData>(`/loyalty/gift-cards/${giftCard.id}/redeem`, {
          amount: Number(amount || 0),
        });
        onSaved(res.data, `Se descontaron ${money(Number(amount || 0), currency)} de la gift card ${giftCard.code}.`);
      } else {
        const res = await api.post<LoyaltyBoardData>(`/loyalty/gift-cards/${giftCard.id}/extend`, {
          days: Number(days || 0),
        });
        onSaved(res.data, `Se amplió la vigencia de la gift card ${giftCard.code}.`);
      }
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo actualizar la gift card.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={Boolean(giftCard && mode)}
      onClose={onClose}
      title={mode === 'redeem' ? `Descontar saldo · ${giftCard?.code ?? ''}` : `Ampliar vigencia · ${giftCard?.code ?? ''}`}
      subtitle={giftCard ? `Saldo disponible: ${money(giftCard.balance, currency)}` : undefined}
      icon={mode === 'redeem' ? <CreditCard size={20} /> : <CalendarPlus size={20} />}
    >
      {giftCard && (
        <form id="gc-action-form" onSubmit={handleSubmit} className="space-y-4">
          <ErrorAlert message={error} />

          <div className="rounded-xl bg-slate-50 border border-slate-200/80 p-3.5 space-y-1 text-xs text-slate-600">
            <div className="flex items-center justify-between">
              <span className="font-bold text-brand-ink font-mono">{giftCard.code}</span>
              <span className="font-bold text-emerald-700">Saldo: {money(giftCard.balance, currency)}</span>
            </div>
            {giftCard.recipient_name && <span className="block">Destinatario: {giftCard.recipient_name}</span>}
          </div>

          {mode === 'redeem' ? (
            <div>
              <label className={labelClass} htmlFor="gc-redeem-amount">Monto a descontar *</label>
              <input
                id="gc-redeem-amount"
                inputMode="decimal"
                required
                value={amount}
                onChange={(e) => setAmount(cleanAmount(e.target.value))}
                className={inputClass}
              />
              <p className="text-xs text-slate-500 mt-1.5">
                También puedes descontarla automáticamente al cobrar una cita o venta eligiendo &quot;Gift card&quot; como medio de pago.
              </p>
            </div>
          ) : (
            <div>
              <label className={labelClass} htmlFor="gc-extend-days">Días adicionales de vigencia *</label>
              <input
                id="gc-extend-days"
                inputMode="numeric"
                required
                value={days}
                onChange={(e) => setDays(e.target.value.replace(/\D/g, '').slice(0, 4))}
                className={inputClass}
              />
            </div>
          )}

          <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
            <Button type="button" variant="secondary" onClick={onClose}>
              Cancelar
            </Button>
            <Button type="submit" variant="primary" disabled={saving}>
              {saving ? 'Guardando...' : mode === 'redeem' ? 'Descontar saldo' : 'Ampliar vigencia'}
            </Button>
          </div>
        </form>
      )}
    </FormDrawer>
  );
}
