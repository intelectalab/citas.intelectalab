'use client';

import { useEffect, useState } from 'react';
import { UserPlus } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { money } from '@/features/sales/sales';
import type { LoyaltyBoardData, LoyaltyCustomerRow, LoyaltyRules } from '../loyalty';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  customers: LoyaltyCustomerRow[];
  rules: LoyaltyRules;
  currency: string;
  onSaved: (data: LoyaltyBoardData, notice: string) => void;
}

export default function ReferralDrawer({ isOpen, onClose, customers, rules, currency, onSaved }: Props) {
  const [referrerId, setReferrerId] = useState('');
  const [referredId, setReferredId] = useState('');
  const [bonus, setBonus] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setReferrerId('');
    setReferredId('');
    setBonus(String(Math.max(1, Math.round(15 / Math.max(0.01, rules.point_value)))));
    setError('');
  }, [isOpen, rules.point_value]);

  const options = customers.map((c) => ({
    value: c.id,
    label: `${c.name}${c.document_number ? ` · ${c.document_number}` : ''}`,
  }));
  const bonusPoints = Math.max(0, Number(bonus || 0));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (referrerId && referrerId === referredId) {
      setError('Elige dos clientes distintos.');
      return;
    }
    try {
      setSaving(true);
      setError('');
      const res = await api.post<LoyaltyBoardData>('/loyalty/referrals', {
        referrer_id: referrerId,
        referred_id: referredId,
        bonus_points: bonusPoints,
      });
      const credited = String(res.headers?.['x-referral-credited'] ?? '0') === '1';
      onSaved(
        res.data,
        credited
          ? 'Referido registrado. El bono ya se sumó a los puntos de quien lo recomendó.'
          : 'Referido registrado. El bono se sumará cuando complete su primera atención.',
      );
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo registrar el referido.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Registrar referido"
      subtitle="Quien recomienda recibe puntos de bono cuando el cliente nuevo completa su primera atención."
      icon={<UserPlus size={20} />}
    >
      <form id="referral-form" onSubmit={handleSubmit} className="space-y-4">
        <ErrorAlert message={error} />

        <Select
          label="Cliente que recomendó *"
          value={referrerId}
          onChange={(e) => setReferrerId(String(e.target.value))}
          options={options}
          placeholder="Elige al cliente"
        />

        <Select
          label="Cliente que llegó recomendado *"
          value={referredId}
          onChange={(e) => setReferredId(String(e.target.value))}
          options={options.filter((o) => o.value !== referrerId)}
          placeholder="Elige al cliente"
        />

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={labelClass} htmlFor="referral-bonus">Puntos de bono *</label>
            <input
              id="referral-bonus"
              inputMode="numeric"
              required
              value={bonus}
              onChange={(e) => setBonus(e.target.value.replace(/\D/g, '').slice(0, 6))}
              className={inputClass}
            />
          </div>
          <div className="rounded-xl bg-slate-50 border border-slate-200/80 px-3.5 py-2.5 flex flex-col justify-center">
            <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Equivale a</span>
            <span className="text-sm font-black text-brand-ink mt-0.5">{money(bonusPoints * rules.point_value, currency)}</span>
          </div>
        </div>

        <p className="text-xs text-slate-500">
          Si el cliente recomendado ya fue atendido, el bono se suma al guardar. Cada cliente solo puede tener a una persona que lo recomendó.
        </p>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" variant="primary" disabled={saving || !referrerId || !referredId}>
            {saving ? 'Guardando...' : 'Registrar referido'}
          </Button>
        </div>
      </form>
    </FormDrawer>
  );
}
