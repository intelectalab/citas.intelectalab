'use client';

import { useEffect, useState } from 'react';
import { Award, Gift, MinusCircle, PlusCircle, Sparkles, UserPlus } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import OptionCard from '@/app/components/common/OptionCard';
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
  initialCustomer?: LoyaltyCustomerRow | null;
  initialType?: 'earn' | 'redeem' | 'adjust' | 'referral';
  rules: LoyaltyRules;
  currency: string;
  onSaved: (data: LoyaltyBoardData, notice: string) => void;
}

const MOVEMENT_TYPES: { id: 'earn' | 'redeem' | 'referral' | 'adjust'; title: string; hint: string; icon: React.ReactNode }[] = [
  { id: 'earn', title: 'Sumar puntos', hint: 'Bonificación o puntos ganados por el cliente.', icon: <PlusCircle size={18} /> },
  { id: 'redeem', title: 'Canjear puntos', hint: 'Descuenta puntos por un beneficio o atención.', icon: <Gift size={18} /> },
  { id: 'referral', title: 'Premio por referido', hint: 'Puntos otorgados por recomendar a otro cliente.', icon: <UserPlus size={18} /> },
  { id: 'adjust', title: 'Ajuste manual', hint: 'Corrección de saldo a favor o en contra.', icon: <Award size={18} /> },
];

export default function PointsAdjustDrawer({
  isOpen,
  onClose,
  customers,
  initialCustomer = null,
  initialType = 'earn',
  rules,
  currency,
  onSaved,
}: Props) {
  const [customerId, setCustomerId] = useState('');
  const [type, setType] = useState<'earn' | 'redeem' | 'adjust' | 'referral'>('earn');
  const [direction, setDirection] = useState<'add' | 'subtract'>('add');
  const [points, setPoints] = useState('10');
  const [reason, setReason] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setCustomerId(initialCustomer?.id ?? customers[0]?.id ?? '');
    setType(initialType);
    setDirection('add');
    setPoints('10');
    setReason('');
    setError('');
  }, [isOpen, initialCustomer, initialType, customers]);

  const selectedCustomer = customers.find((c) => c.id === customerId) ?? null;
  const ptsNumber = Math.max(0, Number(points || 0));
  const isSubtract = type === 'redeem' || (type === 'adjust' && direction === 'subtract');
  const nextBalance = selectedCustomer ? Math.max(0, selectedCustomer.points + (isSubtract ? -ptsNumber : ptsNumber)) : 0;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const res = await api.post<LoyaltyBoardData>('/loyalty/points/adjust', {
        customer_id: customerId,
        type,
        direction: type === 'adjust' ? direction : undefined,
        points: ptsNumber,
        reason: reason.trim(),
      });
      onSaved(res.data, 'Movimiento de puntos registrado.');
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo registrar el movimiento de puntos.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Movimiento de puntos"
      subtitle="Suma, canjea o corrige el saldo de puntos de un cliente."
      icon={<Sparkles size={20} />}
    >
      <form id="points-adjust-form" onSubmit={handleSubmit} className="space-y-4">
        <ErrorAlert message={error} />

        <div>
          <span className={labelClass}>Tipo de movimiento</span>
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2.5" role="radiogroup" aria-label="Tipo de movimiento de puntos">
            {MOVEMENT_TYPES.map((option) => {
              const active = type === option.id;
              return (
                <OptionCard
                  key={option.id}
                  type="button"
                  role="radio"
                  aria-checked={active}
                  selected={active}
                  onClick={() => {
                    setType(option.id);
                    setError('');
                  }}
                  className="flex items-start gap-3 text-left rounded-xl border px-3.5 py-2.5 transition-colors"
                >
                  <span className={`p-2 rounded-lg shrink-0 ${active ? 'bg-brand text-brand-soft' : 'bg-slate-100 text-slate-500'}`}>
                    {option.icon}
                  </span>
                  <span>
                    <span className="block text-sm font-bold text-brand-ink">{option.title}</span>
                    <span className="block text-xs text-slate-500 mt-0.5 leading-snug">{option.hint}</span>
                  </span>
                </OptionCard>
              );
            })}
          </div>
        </div>

        <Select
          label="Cliente *"
          value={customerId}
          onChange={(e) => setCustomerId(String(e.target.value))}
          options={customers.map((c) => ({
            value: c.id,
            label: `${c.name}${c.document_number ? ` · ${c.document_number}` : ''} (${c.points} pts)`,
          }))}
        />

        {type === 'adjust' && (
          <div className="grid grid-cols-2 gap-2.5">
            <OptionCard
              type="button"
              selected={direction === 'add'}
              onClick={() => setDirection('add')}
              className="flex items-center gap-2.5 rounded-xl border px-3.5 py-2.5 text-left"
            >
              <PlusCircle size={16} className={direction === 'add' ? 'text-brand-ink' : 'text-slate-400'} />
              <span className="text-sm font-bold text-brand-ink">Sumar al saldo</span>
            </OptionCard>
            <OptionCard
              type="button"
              selected={direction === 'subtract'}
              onClick={() => setDirection('subtract')}
              className="flex items-center gap-2.5 rounded-xl border px-3.5 py-2.5 text-left"
            >
              <MinusCircle size={16} className={direction === 'subtract' ? 'text-brand-ink' : 'text-slate-400'} />
              <span className="text-sm font-bold text-brand-ink">Restar del saldo</span>
            </OptionCard>
          </div>
        )}

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={labelClass} htmlFor="points-qty">Puntos *</label>
            <input
              id="points-qty"
              inputMode="numeric"
              required
              value={points}
              onChange={(e) => setPoints(e.target.value.replace(/\D/g, '').slice(0, 6))}
              className={inputClass}
            />
          </div>

          <div className="rounded-xl bg-slate-50 border border-slate-200/80 px-3.5 py-2.5 flex flex-col justify-center">
            <span className="text-[11px] font-bold uppercase tracking-wider text-slate-400">Valor equivalente</span>
            <span className="text-sm font-black text-brand-ink mt-0.5">
              {money(ptsNumber * rules.point_value, currency)}
            </span>
          </div>
        </div>

        {selectedCustomer && (
          <div className="rounded-xl bg-brand-soft/15 border border-brand-soft/50 px-3.5 py-2.5 flex items-center justify-between text-xs text-brand-ink">
            <span>Saldo actual: <strong>{selectedCustomer.points} pts</strong> ({money(selectedCustomer.redeemable_value, currency)})</span>
            <span>Nuevo saldo: <strong>{nextBalance} pts</strong></span>
          </div>
        )}

        <div>
          <label className={labelClass} htmlFor="points-reason">Motivo *</label>
          <input
            id="points-reason"
            required
            maxLength={255}
            placeholder={
              type === 'referral'
                ? 'Ej. Recomendó a María Pérez'
                : type === 'redeem'
                  ? 'Ej. Canje por descuento en sesión'
                  : 'Ej. Cortesía de bienvenida o ajuste de saldo'
            }
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            className={inputClass}
          />
        </div>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" variant="primary" disabled={saving || !customerId || ptsNumber <= 0 || !reason.trim()}>
            {saving ? 'Guardando...' : 'Guardar movimiento'}
          </Button>
        </div>
      </form>
    </FormDrawer>
  );
}
