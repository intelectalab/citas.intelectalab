'use client';

import { useState, useEffect } from 'react';
import FormDrawer from '@/app/components/ui/forms/FormDrawer';
import { api } from '@/lib/axios';
import { Briefcase, ChevronDown, Plus } from 'lucide-react';
import FormServiceCategory from './FormServiceCategory';

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
}

interface FormServiceProps {
  isOpen: boolean;
  onClose: () => void;
  initialData?: Service | null;
  categories: ServiceCategory[];
  onSubmitSuccess?: () => void;
}

export default function FormService({
  isOpen,
  onClose,
  initialData,
  categories: initialCategories,
  onSubmitSuccess = () => {},
}: FormServiceProps) {
  const [loading, setLoading] = useState(false);
  const [categories, setCategories] = useState<ServiceCategory[]>(initialCategories);
  const [statuses, setStatuses] = useState<any[]>([]);
  const [showCategoryModal, setShowCategoryModal] = useState(false);
  const [activeAccordion, setActiveAccordion] = useState<number | string>(1);

  const [form, setForm] = useState({
    name: '',
    category_id: '',
    duration_minutes: 30,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    price: 0,
    status_id: '',
  });

  const sections = [
    { id: 1, title: '1. Información del Servicio' },
    { id: 2, title: '2. Tiempos y Tiempos de Espera (Buffers)' },
    { id: 3, title: '3. Precios y Estado' },
  ];

  useEffect(() => {
    setCategories(initialCategories);
  }, [initialCategories]);

  useEffect(() => {
    if (initialData) {
      setForm({
        name: initialData.name || '',
        category_id: initialData.category_id || '',
        duration_minutes: initialData.duration_minutes || 30,
        buffer_before_minutes: initialData.buffer_before_minutes || 0,
        buffer_after_minutes: initialData.buffer_after_minutes || 0,
        price: initialData.price || 0,
        status_id: initialData.status_id || '',
      });
    } else {
      setForm({
        name: '',
        category_id: '',
        duration_minutes: 30,
        buffer_before_minutes: 0,
        buffer_after_minutes: 0,
        price: 0,
        status_id: '',
      });
    }
  }, [initialData, isOpen]);

  useEffect(() => {
    if (isOpen) {
      // Cargar los estados dinámicos para el módulo de servicios
      api.get('/statuses', {
        params: { module: 'services' }
      })
        .then(res => {
          setStatuses(res.data);
          // Si no hay status_id seleccionado, seleccionamos por defecto el 'is_default' o el primero
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
      if (initialData?.id) {
        await api.put(`/services/${initialData.id}`, form);
      } else {
        await api.post('/services', form);
      }
      onSubmitSuccess?.();
      onClose();
    } catch (error) {
      console.error('Error guardando servicio:', error);
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
                            <select
                              value={form.category_id}
                              onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="">Sin categoría</option>
                              {categories.map((cat, index) => {
                                const optionKey = cat.value ? `cat-${cat.value}` : `cat-index-${index}`;
                                return (
                                  <option key={optionKey} value={cat.value}>
                                    {cat.label}
                                  </option>
                                );
                              })}
                            </select>
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
                            <select
                              value={form.status_id}
                              onChange={(e) => setForm({ ...form, status_id: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 bg-white text-slate-800 focus:ring-2 focus:ring-[#030336]/20 focus:border-[#030336] outline-none"
                            >
                              <option value="">Seleccione estado...</option>
                              {statuses.map((st) => (
                                <option key={st.id} value={st.id}>
                                  {st.name}
                                </option>
                              ))}
                            </select>
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