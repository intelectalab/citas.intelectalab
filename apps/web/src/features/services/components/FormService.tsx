'use client';

import { useState, useEffect } from 'react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { Briefcase, ChevronDown, Plus } from 'lucide-react';
import FormServiceCategory from './FormServiceCategory';
import Select from '@/app/components/common/Select';
import { cleanAmount, money } from '@/features/sales/sales';
import { apiError } from '@/features/appointments/appointments';
import { currencySymbol } from '@/lib/currency';

interface ServiceCategory {
  label: string;
  value: string;
}

interface Service {
  id: string;
  category_id: string | null;
  name: string;
  duration_minutes: number;
  buffer_before_minutes: number;
  buffer_after_minutes: number;
  price: number;
  status_id?: string | null;
  code?: string | null;
  requires_deposit?: boolean;
  deposit_type?: 'percent' | 'amount';
  deposit_value?: number | string;
  online_booking?: boolean;
  is_virtual?: boolean;
  kind?: 'individual' | 'group';
  capacity?: number;
  recall_label?: string | null;
  recall_days?: number | null;
  recall_service_id?: string | null;
}

const EMPTY_FORM = {
  name: '',
  code: '',
  category_id: '',
  duration_minutes: 30,
  buffer_before_minutes: 0,
  buffer_after_minutes: 0,
  price: 0,
  status_id: '',
  requires_deposit: false,
  deposit_type: 'percent' as 'percent' | 'amount',
  deposit_value: '30',
  online_booking: true,
  is_virtual: false,
  kind: 'individual' as 'individual' | 'group',
  capacity: 1,
  recall_label: '',
  recall_days: '' as number | '',
  recall_service_id: '',
};

interface FormServiceProps {
  isOpen: boolean;
  onClose: () => void;
  initialData?: Service | null;
  categories: ServiceCategory[];
  onSubmitSuccess?: (service?: any) => void;
}

export default function FormService({
  isOpen,
  onClose,
  initialData,
  categories: initialCategories,
  onSubmitSuccess = () => {},
}: FormServiceProps) {
  const [loading, setLoading] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [categories, setCategories] = useState<ServiceCategory[]>(initialCategories);
  const [statuses, setStatuses] = useState<any[]>([]);
  const [showCategoryModal, setShowCategoryModal] = useState(false);
  const [activeAccordion, setActiveAccordion] = useState<number | string>(1);

  const { hasModule } = useAuth();
  const canUseClasses = hasModule('classes');
  const [otherServices, setOtherServices] = useState<{ id: string; name: string }[]>([]);

  const [form, setForm] = useState(EMPTY_FORM);

  const sections = [
    { id: 1, title: '1. Información del Servicio' },
    { id: 2, title: '2. Tiempos y Tiempos de Espera (Buffers)' },
    { id: 3, title: '3. Precios y Estado' },
    { id: 4, title: '4. Reserva y Seguimiento' },
  ];

  useEffect(() => {
    setCategories(initialCategories);
  }, [initialCategories]);

  useEffect(() => {
    if (initialData) {
      setForm({
        name: initialData.name || '',
        code: initialData.code || '',
        category_id: initialData.category_id || '',
        duration_minutes: initialData.duration_minutes || 30,
        buffer_before_minutes: initialData.buffer_before_minutes || 0,
        buffer_after_minutes: initialData.buffer_after_minutes || 0,
        price: initialData.price || 0,
        status_id: initialData.status_id || '',
        requires_deposit: Boolean(initialData.requires_deposit),
        deposit_type: initialData.deposit_type ?? 'percent',
        deposit_value: initialData.deposit_value !== undefined && initialData.deposit_value !== null ? String(Number(initialData.deposit_value)) : '30',
        online_booking: initialData.online_booking ?? true,
        is_virtual: Boolean(initialData.is_virtual),
        kind: initialData.kind || 'individual',
        capacity: initialData.capacity || 1,
        recall_label: initialData.recall_label || '',
        recall_days: initialData.recall_days ?? '',
        recall_service_id: initialData.recall_service_id || '',
      });
    } else {
      setForm(EMPTY_FORM);
    }
    setSaveError('');
  }, [initialData, isOpen]);

  useEffect(() => {
    if (!isOpen) return;

    api.get('/services')
      .then((res) => setOtherServices((res.data as { id: string; name: string }[]).filter((item) => item.id !== initialData?.id)))
      .catch((err) => console.error('Error cargando servicios:', err));
  }, [isOpen, initialData]);

  useEffect(() => {
    if (isOpen) {
      api.get('/statuses', {
        params: { module: 'services' }
      })
        .then(res => {
          setStatuses(res.data);
          if (!form.status_id && res.data.length > 0) {
            const defaultStatus = res.data.find((s: any) => s.is_default) || res.data[0];
            setForm(prev => ({ ...prev, status_id: defaultStatus.id }));
          }
        })
        .catch(err => console.error('Error cargando estados de servicios:', err));
    }
  }, [isOpen]);

  const handleCategoryCreated = (newCategoryData: any) => {
    const cat = newCategoryData?.data || newCategoryData;
    const rawId = (cat?.id && cat?.id !== 'null') ? cat.id : null;
    const catName = cat?.name || cat?.title || cat?.label;

    if (!rawId || !catName) return;

    const catId = String(rawId);
    const formattedCat = { label: catName, value: catId };

    setCategories((prev) => {
      if (prev.some((c) => c.value === catId || c.label.toLowerCase() === catName.toLowerCase())) return prev;
      return [...prev, formattedCat];
    });

    setForm((prev) => ({
      ...prev,
      category_id: catId,
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      setLoading(true);
      setSaveError('');
      const hasRecall = form.recall_label.trim() !== '' && form.recall_days !== '';
      const payload = {
        ...form,
        capacity: form.kind === 'group' ? form.capacity : 1,
        recall_label: hasRecall ? form.recall_label.trim() : null,
        recall_days: hasRecall ? form.recall_days : null,
        recall_service_id: hasRecall ? form.recall_service_id || null : null,
        deposit_value: Number(form.deposit_value || 0),
      };

      const res = initialData?.id
        ? await api.put(`/services/${initialData.id}`, payload)
        : await api.post('/services', payload);
      onSubmitSuccess?.(res?.data?.data ?? res?.data);
      onClose();
    } catch (error) {
      setSaveError(apiError(error, 'No se pudo guardar el servicio.'));
    } finally {
      setLoading(false);
    }
  };

  const isEditing = Boolean(initialData?.id);

  return (
    <>
      <FormDrawer
        isOpen={isOpen}
        onClose={onClose}
        title={isEditing ? 'Editar Servicio' : 'Nuevo Servicio'}
        subtitle="Complete los detalles de duración, precios y estados del servicio."
        icon={<Briefcase size={20} />}
      >
        <form onSubmit={handleSubmit} className="flex flex-col h-full justify-between">
          <div className="divide-y divide-slate-200 pb-24 overflow-y-auto px-1">
            {sections.map((sec) => {
              const isOpenSection = activeAccordion === sec.id;

              return (
                <div key={sec.id} className="bg-white transition-all duration-300">
                  <button
                    type="button"
                    onClick={() => setActiveAccordion(isOpenSection ? 0 : sec.id)}
                    className="w-full flex items-center justify-between py-4 px-2 bg-white hover:bg-slate-50/85 transition-colors text-left"
                  >
                    <span className="text-xs font-bold uppercase tracking-wider text-slate-800">{sec.title}</span>
                    <div className={`p-1 rounded-full bg-slate-100 text-slate-600 transition-transform duration-200 ${isOpenSection ? 'rotate-180' : ''}`}>
                      <ChevronDown size={14} />
                    </div>
                  </button>

                  <div
                    className={`grid transition-all duration-300 ease-in-out overflow-hidden ${
                      isOpenSection ? 'grid-rows-[1fr] opacity-100 pb-6 pt-2 px-2' : 'grid-rows-[0fr] opacity-0'
                    }`}
                  >
                    <div className="overflow-hidden space-y-4">
                      {sec.id === 1 && (
                        <div className="space-y-4">
                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombre del Servicio *</label>
                            <input
                              type="text"
                              required
                              value={form.name}
                              onChange={(e) => setForm({ ...form, name: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              placeholder="Ej. Corte y Peinado"
                            />
                          </div>

                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Código interno</label>
                            <input
                              type="text"
                              maxLength={30}
                              value={form.code}
                              onChange={(e) => setForm({ ...form, code: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 font-mono focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              placeholder="Ej. COR-01"
                            />
                          </div>

                          <div>
                            <div className="flex items-center justify-between mb-1.5">
                              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600">Categoría</label>
                              <button
                                type="button"
                                onClick={() => setShowCategoryModal(true)}
                                className="inline-flex items-center gap-1 text-xs font-bold text-sky-600 hover:text-sky-700 transition-colors"
                              >
                                <Plus size={14} /> Nueva categoría
                              </button>
                            </div>
                            <Select
                              ariaLabel="Categoría"
                              placeholder="Sin categoría"
                              value={form.category_id}
                              onChange={(e) => setForm({ ...form, category_id: String(e.target.value) })}
                              options={categories.filter((cat) => cat.value).map((cat) => ({ value: cat.value, label: cat.label }))}
                            />
                          </div>
                        </div>
                      )}

                      {sec.id === 2 && (
                        <div className="space-y-4">
                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Duración (minutos) *</label>
                            <input
                              type="number"
                              min="1"
                              required
                              value={form.duration_minutes}
                              onChange={(e) => setForm({ ...form, duration_minutes: Number(e.target.value) })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            />
                          </div>
                          <div className="grid grid-cols-2 gap-3">
                            <div>
                              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Buffer Antes (mins)</label>
                              <input
                                type="number"
                                min="0"
                                value={form.buffer_before_minutes}
                                onChange={(e) => setForm({ ...form, buffer_before_minutes: Number(e.target.value) })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              />
                            </div>
                            <div>
                              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Buffer Después (mins)</label>
                              <input
                                type="number"
                                min="0"
                                value={form.buffer_after_minutes}
                                onChange={(e) => setForm({ ...form, buffer_after_minutes: Number(e.target.value) })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                              />
                            </div>
                          </div>
                        </div>
                      )}

                      {sec.id === 3 && (
                        <div className="space-y-4">
                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Precio ($) *</label>
                            <input
                              type="number"
                              step="0.01"
                              min="0"
                              required
                              value={form.price}
                              onChange={(e) => setForm({ ...form, price: Number(e.target.value) })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            />
                          </div>

                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Estado</label>
                            <Select
                              ariaLabel="Estado"
                              placeholder="Seleccione estado..."
                              value={form.status_id}
                              onChange={(e) => setForm({ ...form, status_id: String(e.target.value) })}
                              options={statuses.map((st) => ({ value: st.id, label: st.name }))}
                            />
                          </div>
                        </div>
                      )}

                      {sec.id === 4 && (
                        <div className="space-y-4">
                          <div className="space-y-2.5">
                            {[
                              { key: 'online_booking' as const, label: 'Se puede reservar en línea', hint: 'Aparece en el portal del cliente y en la reserva pública.' },
                              { key: 'requires_deposit' as const, label: 'Exige adelanto', hint: 'La reserva se confirma cuando el cliente paga el adelanto.' },
                              { key: 'is_virtual' as const, label: 'Atención por videollamada', hint: 'No ocupa sala ni equipo.' },
                            ].map((option) => (
                              <label key={option.key} className="flex items-start gap-2.5 cursor-pointer">
                                <input
                                  type="checkbox"
                                  checked={form[option.key]}
                                  onChange={(e) => setForm({ ...form, [option.key]: e.target.checked })}
                                  className="mt-0.5 rounded border-slate-300 text-[#030336] focus:ring-[#030336]"
                                />
                                <span>
                                  <span className="block text-sm font-medium text-slate-800">{option.label}</span>
                                  <span className="block text-xs text-slate-400">{option.hint}</span>
                                </span>
                              </label>
                            ))}
                          </div>

                          {form.requires_deposit && (() => {
                            const value = Number(form.deposit_value || 0);
                            const price = Number(form.price || 0);
                            const deposit = form.deposit_type === 'percent' ? Math.round(price * Math.min(value, 100)) / 100 : Math.min(value, price);
                            return (
                              <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-deposit-amount>
                                <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">¿Cuánto es el adelanto?</span>
                                <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Tipo de adelanto">
                                  {([
                                    ['percent', 'Un porcentaje', 'Ej. 30 % del precio'],
                                    ['amount', 'Un monto fijo', `Ej. ${money(50)}, cueste lo que cueste`],
                                  ] as const).map(([key, title, hint]) => (
                                    <button
                                      key={key}
                                      type="button"
                                      role="radio"
                                      aria-checked={form.deposit_type === key}
                                      data-deposit-type={key}
                                      onClick={() => setForm({ ...form, deposit_type: key, deposit_value: key === 'percent' ? '30' : String(Math.min(50, price) || '') })}
                                      className={`text-left rounded-xl border px-3.5 py-2.5 transition-colors ${form.deposit_type === key ? 'border-[#022340] bg-[#84D0D9]/15 ring-2 ring-[#022340]/10' : 'border-slate-200 hover:bg-slate-50'}`}
                                    >
                                      <span className="block text-sm font-bold text-[#022340]">{title}</span>
                                      <span className="block text-xs text-slate-500">{hint}</span>
                                    </button>
                                  ))}
                                </div>
                                <div className="flex items-center gap-2">
                                  <input
                                    id="deposit-value"
                                    inputMode="decimal"
                                    aria-label="Valor del adelanto"
                                    value={form.deposit_value}
                                    onChange={(e) => setForm({ ...form, deposit_value: cleanAmount(e.target.value) })}
                                    className="w-28 text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                                  />
                                  <span className="text-sm text-slate-600">{form.deposit_type === 'percent' ? '% del precio' : currencySymbol()}</span>
                                </div>
                                <p className="text-xs text-slate-500" data-deposit-example>
                                  {price > 0 && value > 0
                                    ? `Con el precio de ${money(price)}, el cliente paga ${money(deposit)} al reservar en línea y ${money(Math.max(0, price - deposit))} en la sede.`
                                    : 'Escribe el precio y el adelanto para ver el ejemplo.'}
                                </p>
                              </div>
                            );
                          })()}

                          {(canUseClasses || form.kind === 'group') && (
                            <div className="grid grid-cols-2 gap-3">
                              <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Modalidad</label>
                                <Select
                                  ariaLabel="Modalidad"
                                  clearable={false}
                                  value={form.kind}
                                  onChange={(e) => setForm({ ...form, kind: e.target.value as 'individual' | 'group' })}
                                  options={[{ value: 'individual', label: 'Individual' }, { value: 'group', label: 'Clase grupal' }]}
                                />
                              </div>
                              {form.kind === 'group' && (
                                <div>
                                  <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Cupo por clase</label>
                                  <input
                                    type="number"
                                    min="1"
                                    value={form.capacity}
                                    onChange={(e) => setForm({ ...form, capacity: Number(e.target.value) })}
                                    className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                                  />
                                </div>
                              )}
                            </div>
                          )}

                          <div className="border-t border-slate-100 pt-4 space-y-3">
                            <div>
                              <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">Control de seguimiento</span>
                              <span className="block text-xs text-slate-400">Se sugiere al completar la atención. Déjalo vacío si el servicio no lo necesita.</span>
                            </div>
                            <div className="grid grid-cols-3 gap-3">
                              <div className="col-span-2">
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Nombre del control</label>
                                <input
                                  type="text"
                                  maxLength={150}
                                  required={form.recall_days !== ''}
                                  value={form.recall_label}
                                  onChange={(e) => setForm({ ...form, recall_label: e.target.value })}
                                  className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                                  placeholder="Ej. Profilaxis semestral"
                                />
                              </div>
                              <div>
                                <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">En (días)</label>
                                <input
                                  type="number"
                                  min="1"
                                  required={form.recall_label.trim() !== ''}
                                  value={form.recall_days}
                                  onChange={(e) => setForm({ ...form, recall_days: e.target.value === '' ? '' : Number(e.target.value) })}
                                  className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                                  placeholder="180"
                                />
                              </div>
                            </div>
                            <div>
                              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Servicio del control</label>
                              <Select
                                ariaLabel="Servicio del control"
                                placeholder="Este mismo servicio"
                                value={form.recall_service_id}
                                onChange={(e) => setForm({ ...form, recall_service_id: String(e.target.value) })}
                                options={otherServices.map((item) => ({ value: item.id, label: item.name }))}
                              />
                            </div>
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })}
          </div>

          <div className="absolute bottom-0 left-0 right-0 bg-white border-t border-slate-200 p-4 flex items-center justify-end gap-3 z-10">
            {saveError && <p role="alert" data-service-error className="mr-auto text-sm text-red-600">{saveError}</p>}
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2.5 text-xs font-semibold uppercase tracking-wider text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors"
            >
              Cancelar
            </button>
            <button
              type="submit"
              disabled={loading}
              className="px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-white bg-[#030336] hover:bg-[#030336]/90 rounded-lg transition-colors shadow-sm disabled:opacity-50"
            >
              {loading ? 'Guardando...' : isEditing ? 'Actualizar servicio' : 'Crear servicio'}
            </button>
          </div>
        </form>
      </FormDrawer>

      <FormServiceCategory
        isOpen={showCategoryModal}
        onClose={() => setShowCategoryModal(false)}
        existingCategories={categories}
        onCategoryCreated={handleCategoryCreated}
      />
    </>
  );
}