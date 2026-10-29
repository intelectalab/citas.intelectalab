'use client';

import { useEffect, useState } from 'react';
import { Gift } from 'lucide-react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import Button from '@/app/components/common/Button';
import Select from '@/app/components/common/Select';
import { ErrorAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import { cleanAmount } from '@/features/sales/sales';
import type { LoyaltyBoardData, LoyaltyCustomerRow, LoyaltyRules } from '../loyalty';

interface Props {
  isOpen: boolean;
  onClose: () => void;
  customers: LoyaltyCustomerRow[];
  rules: LoyaltyRules;
  onSaved: (data: LoyaltyBoardData, notice: string) => void;
}

export default function GiftCardDrawer({ isOpen, onClose, customers, rules, onSaved }: Props) {
  const [code, setCode] = useState('');
  const [initialAmount, setInitialAmount] = useState('100');
  const [purchaserId, setPurchaserId] = useState('');
  const [recipientId, setRecipientId] = useState('');
  const [recipientName, setRecipientName] = useState('');
  const [recipientEmail, setRecipientEmail] = useState('');
  const [validityDays, setValidityDays] = useState('365');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setCode('');
    setInitialAmount('100');
    setPurchaserId('');
    setRecipientId('');
    setRecipientName('');
    setRecipientEmail('');
    setValidityDays(String(rules.gift_card_validity_days ?? 365));
    setError('');
  }, [isOpen, rules.gift_card_validity_days]);

  const handleRecipientCustomer = (id: string) => {
    setRecipientId(id);
    const found = customers.find((c) => c.id === id);
    if (found) {
      setRecipientName(found.name);
      setRecipientEmail(found.email ?? '');
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const res = await api.post<LoyaltyBoardData>('/loyalty/gift-cards', {
        code: code.trim() || null,
        initial_amount: Number(initialAmount || 0),
        purchaser_customer_id: purchaserId || null,
        recipient_customer_id: recipientId || null,
        recipient_name: recipientName.trim() || null,
        recipient_email: recipientEmail.trim() || null,
        validity_days: validityDays !== '' ? Number(validityDays) : null,
      });
      onSaved(res.data, 'Gift card emitida y lista para usar.');
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo emitir la gift card.'));
    } finally {
      setSaving(false);
    }
  };

  const customerOptions = [
    { value: '', label: 'Sin vincular a un cliente registrado' },
    ...customers.map((c) => ({
      value: c.id,
      label: `${c.name}${c.document_number ? ` · ${c.document_number}` : ''}`,
    })),
  ];

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title="Emitir gift card"
      subtitle="Crea una tarjeta de regalo con saldo para canjear en citas o en el punto de venta."
      icon={<Gift size={20} />}
    >
      <form id="gift-card-form" onSubmit={handleSubmit} className="space-y-4">
        <ErrorAlert message={error} />

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className={labelClass} htmlFor="gc-amount">Monto inicial *</label>
            <input
              id="gc-amount"
              inputMode="decimal"
              required
              value={initialAmount}
              onChange={(e) => setInitialAmount(cleanAmount(e.target.value))}
              className={inputClass}
            />
          </div>
          <div>
            <label className={labelClass} htmlFor="gc-code">Código (opcional)</label>
            <input
              id="gc-code"
              maxLength={40}
              placeholder="Automático (GC-XXXXXX)"
              value={code}
              onChange={(e) => setCode(e.target.value.toUpperCase().replace(/\s/g, ''))}
              className={`${inputClass} uppercase font-mono placeholder:font-sans placeholder:normal-case`}
            />
          </div>
        </div>

        <Select
          label="Quién la compra (opcional)"
          value={purchaserId}
          onChange={(e) => setPurchaserId(String(e.target.value))}
          options={customerOptions}
        />

        <Select
          label="Destinatario registrado (opcional)"
          value={recipientId}
          onChange={(e) => handleRecipientCustomer(String(e.target.value))}
          options={customerOptions}
        />

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label className={labelClass} htmlFor="gc-recipient-name">Nombre del destinatario</label>
            <input
              id="gc-recipient-name"
              maxLength={150}
              placeholder="Para quién es el regalo"
              value={recipientName}
              onChange={(e) => setRecipientName(e.target.value)}
              className={inputClass}
            />
          </div>
          <div>
            <label className={labelClass} htmlFor="gc-recipient-email">Correo del destinatario</label>
            <input
              id="gc-recipient-email"
              type="email"
              maxLength={250}
              placeholder="correo@ejemplo.com"
              value={recipientEmail}
              onChange={(e) => setRecipientEmail(e.target.value)}
              className={inputClass}
            />
          </div>
        </div>

        <div>
          <label className={labelClass} htmlFor="gc-validity">Vigencia en días (0 = no vence)</label>
          <input
            id="gc-validity"
            inputMode="numeric"
            value={validityDays}
            onChange={(e) => setValidityDays(e.target.value.replace(/\D/g, '').slice(0, 4))}
            className={inputClass}
          />
          <p className="text-xs text-slate-500 mt-1">
            Al cobrar una cita o venta, podrás elegir &quot;Gift card&quot; como medio de pago y descontar el saldo total o parcialmente.
          </p>
        </div>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancelar
          </Button>
          <Button type="submit" variant="primary" disabled={saving || Number(initialAmount || 0) <= 0}>
            {saving ? 'Emitiendo...' : 'Emitir gift card'}
          </Button>
        </div>
      </form>
    </FormDrawer>
  );
}
