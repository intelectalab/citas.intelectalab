'use client';

import { useEffect, useState } from 'react';
import { Truck } from 'lucide-react';
import Button from '@/app/components/common/Button';
import Switch from '@/app/components/common/Switch';
import { ErrorAlert } from '@/app/components/common/Alert';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import type { InventoryBoardData, Supplier } from '../inventory';

interface SupplierDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  initialData: Supplier | null;
  onSaved: (board: InventoryBoardData, message: string) => void;
}

const empty = {
  name: '',
  documentNumber: '',
  contactName: '',
  phone: '',
  email: '',
  address: '',
  active: true,
};

export default function SupplierDrawer({
  isOpen,
  onClose,
  initialData,
  onSaved,
}: SupplierDrawerProps) {
  const [form, setForm] = useState(empty);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (!isOpen) return;
    setError('');
    setForm(
      initialData
        ? {
            name: initialData.name,
            documentNumber: initialData.document_number ?? '',
            contactName: initialData.contact_name ?? '',
            phone: initialData.phone ?? '',
            email: initialData.email ?? '',
            address: initialData.address ?? '',
            active: initialData.status === 'active',
          }
        : empty,
    );
  }, [isOpen, initialData]);

  const set = (patch: Partial<typeof empty>) => setForm((curr) => ({ ...curr, ...patch }));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setSaving(true);
      setError('');
      const payload = {
        name: form.name.trim(),
        document_number: form.documentNumber.trim() || null,
        contact_name: form.contactName.trim() || null,
        phone: form.phone.trim() || null,
        email: form.email.trim() || null,
        address: form.address.trim() || null,
        status: form.active ? 'active' : 'inactive',
      };

      const res = initialData
        ? await api.put(`/inventory/suppliers/${initialData.id}`, payload)
        : await api.post('/inventory/suppliers', payload);

      onSaved(res.data, initialData ? 'Proveedor actualizado.' : 'Proveedor registrado.');
      onClose();
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar el proveedor.'));
    } finally {
      setSaving(false);
    }
  };

  const formId = 'inventory-supplier-form';

  return (
    <FormDrawer
      isOpen={isOpen}
      onClose={onClose}
      title={initialData ? `Editar: ${initialData.name}` : 'Nuevo proveedor'}
      subtitle="Empresa o distribuidor al que le compras productos e insumos"
      icon={<Truck size={20} />}
    >
      <div className="flex flex-col h-full justify-between">
        <form id={formId} onSubmit={handleSubmit} className="space-y-4 overflow-y-auto flex-1 pr-1 pb-4">
          <div>
            <label className={labelClass} htmlFor="supplier-name">Nombre o razón social *</label>
            <input
              id="supplier-name"
              required
              maxLength={200}
              placeholder="Ej. Distribuidora Cosmética S.A.C."
              className={inputClass}
              value={form.name}
              onChange={(e) => set({ name: e.target.value })}
            />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="supplier-doc">RUC / Documento</label>
              <input
                id="supplier-doc"
                maxLength={30}
                placeholder="20123456789"
                className={inputClass}
                value={form.documentNumber}
                onChange={(e) => set({ documentNumber: e.target.value })}
              />
            </div>
            <div>
              <label className={labelClass} htmlFor="supplier-phone">Teléfono / WhatsApp</label>
              <input
                id="supplier-phone"
                maxLength={40}
                placeholder="999 888 777"
                className={inputClass}
                value={form.phone}
                onChange={(e) => set({ phone: e.target.value })}
              />
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className={labelClass} htmlFor="supplier-contact">Persona de contacto</label>
              <input
                id="supplier-contact"
                maxLength={150}
                placeholder="Ej. Ana Torres"
                className={inputClass}
                value={form.contactName}
                onChange={(e) => set({ contactName: e.target.value })}
              />
            </div>
            <div>
              <label className={labelClass} htmlFor="supplier-email">Correo electrónico</label>
              <input
                id="supplier-email"
                type="email"
                maxLength={190}
                placeholder="ventas@proveedor.com"
                className={inputClass}
                value={form.email}
                onChange={(e) => set({ email: e.target.value })}
              />
            </div>
          </div>

          <div>
            <label className={labelClass} htmlFor="supplier-address">Dirección</label>
            <input
              id="supplier-address"
              maxLength={255}
              placeholder="Av. Principal 123, Lima"
              className={inputClass}
              value={form.address}
              onChange={(e) => set({ address: e.target.value })}
            />
          </div>

          <div className="flex items-center justify-between gap-3 border border-slate-200 rounded-xl px-4 py-3">
            <div>
              <span className="block text-sm font-semibold text-slate-800">Proveedor activo</span>
              <span className="block text-xs text-slate-500">Apágalo si ya no le compras a este proveedor.</span>
            </div>
            <Switch checked={form.active} onChange={(active) => set({ active })} label="Proveedor activo" />
          </div>

          <ErrorAlert message={error} />
        </form>

        <div className="flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          <Button type="button" onClick={onClose} variant="secondary">
            Cancelar
          </Button>
          <Button type="submit" form={formId} disabled={saving} variant="primary">
            {saving ? 'Guardando...' : initialData ? 'Guardar cambios' : 'Crear proveedor'}
          </Button>
        </div>
      </div>
    </FormDrawer>
  );
}
