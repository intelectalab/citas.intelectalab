'use client';

import OptionCard from '@/app/components/common/OptionCard';
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
import { taxNote } from '../services';
import { useVocabulary } from '@/hooks/useVocabulary';
import ServiceSuppliesFields, { toLines, validLines, type ServiceSuppliesData, type SupplyLine } from '@/features/inventory/components/ServiceSuppliesFields';

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
  video_url?: string | null;
  kind?: 'individual' | 'group';
  capacity?: number;
  recall_label?: string | null;
  recall_days?: number | null;
  recall_service_id?: string | null;
  branch_ids?: string[];
  branch_prices?: { branch_id: string; price: number }[];
  room_ids?: string[];
  professional_ids?: string[];
  description?: string | null;
  requires_record?: boolean;
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
  video_url: '',
  kind: 'individual' as 'individual' | 'group',
  capacity: 1,
  recall_label: '',
  recall_days: '' as number | '',
  recall_service_id: '',
  description: '',
  requires_record: false,
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

  const { hasModule, can, user } = useAuth();
  const usesRecords = hasModule('records');
  const canUseClasses = hasModule('classes');
  const [otherServices, setOtherServices] = useState<{ id: string; name: string }[]>([]);

  const [form, setForm] = useState(EMPTY_FORM);
  const [branches, setBranches] = useState<{ id: string; name: string }[]>([]);
  const [allBranches, setAllBranches] = useState(true);
  const [branchIds, setBranchIds] = useState<string[]>([]);
  const [samePrice, setSamePrice] = useState(true);
  const [branchPrices, setBranchPrices] = useState<Record<string, string>>({});
  const usesRooms = hasModule('resources');
  const [rooms, setRooms] = useState<{ id: string; name: string; branch_id: string; branch_name: string | null; kind: string; effective_status: string }[]>([]);
  const [roomIds, setRoomIds] = useState<string[]>([]);
  const vocab = useVocabulary();
  const [team, setTeam] = useState<{ id: string; display_name: string; inactive: boolean }[]>([]);
  const [allTeam, setAllTeam] = useState(true);
  const [teamIds, setTeamIds] = useState<string[]>([]);

  const usesSupplies = hasModule('inventory') && (can('inventory.view') || can('services.view'));
  const [supplies, setSupplies] = useState<ServiceSuppliesData | null>(null);
  const [suppliesError, setSuppliesError] = useState('');
  const [supplyLines, setSupplyLines] = useState<SupplyLine[]>([]);
  const [codeTouched, setCodeTouched] = useState(false);
  const [suppliesTouched, setSuppliesTouched] = useState(false);

  const sections = [
    { id: 1, title: '1. Información del Servicio' },
    { id: 2, title: '2. Duración, preparación y limpieza' },
    { id: 3, title: '3. Precios y Estado' },
    { id: 4, title: '4. Reserva y Seguimiento' },
    ...(usesSupplies && form.kind !== 'group' ? [{ id: 5, title: '5. Insumos que consume' }] : []),
  ];

  useEffect(() => {
    if (!isOpen || !usesSupplies) return;

    setSupplies(null);
    setSuppliesError('');
    setSupplyLines([]);
    setSuppliesTouched(false);
    api.get(`/inventory/services/${initialData?.id ?? 'new'}/supplies`)
      .then((res) => { setSupplies(res.data); setSupplyLines(toLines(res.data)); })
      .catch((err) => setSuppliesError(apiError(err, 'No se pudieron cargar los insumos de este servicio.')));
  }, [isOpen, usesSupplies, initialData?.id]);

  useEffect(() => {
    setCategories(initialCategories);
  }, [initialCategories]);

  useEffect(() => {
    if (isOpen) setCodeTouched(false);
  }, [isOpen, initialData?.id]);

  useEffect(() => {
    if (!isOpen || initialData || codeTouched) return;
    api.get('/services/next-code', { params: { category_id: form.category_id || undefined } })
      .then((res) => { if (res.data?.code) setForm((current) => ({ ...current, code: res.data.code })); })
      .catch(() => undefined);
  }, [isOpen, initialData, codeTouched, form.category_id]);

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
        video_url: initialData.video_url || '',
        kind: initialData.kind || 'individual',
        capacity: initialData.capacity || 1,
        recall_label: initialData.recall_label || '',
        recall_days: initialData.recall_days ?? '',
        recall_service_id: initialData.recall_service_id || '',
        description: initialData.description || '',
        requires_record: Boolean(initialData.requires_record),
      });
    } else {
      setForm(EMPTY_FORM);
    }
    setAllBranches(!(initialData?.branch_ids?.length));
    setBranchIds(initialData?.branch_ids ?? []);
    setSamePrice(!(initialData?.branch_prices?.length));
    setBranchPrices(Object.fromEntries((initialData?.branch_prices ?? []).map((row) => [row.branch_id, String(row.price)])));
    setRoomIds(initialData?.room_ids ?? []);
    setAllTeam(!(initialData?.professional_ids?.length));
    setTeamIds(initialData?.professional_ids ?? []);
    setSaveError('');
  }, [initialData, isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    api.get('/branches')
      .then((res) => {
        const list = (Array.isArray(res.data) ? res.data : res.data?.branches ?? []) as { id: string; name: string; status?: string }[];
        setBranches(list.filter((b) => b.status !== 'inactive').map((b) => ({ id: b.id, name: b.name })));
      })
      .catch(() => setBranches([]));
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen) return;
    api.get('/professionals')
      .then((res) => {
        const list = (Array.isArray(res.data) ? res.data : res.data?.data ?? []) as { id: string; display_name: string; status?: { slug?: string } | null }[];
        setTeam(list.map((p) => ({ id: p.id, display_name: p.display_name, inactive: p.status?.slug === 'inactive' })).sort((a, b) => a.display_name.localeCompare(b.display_name)));
      })
      .catch(() => setTeam([]));
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || !usesRooms) return;
    api.get('/rooms')
      .then((res) => setRooms((res.data?.rooms ?? []).filter((room: { kind: string }) => room.kind === 'room')))
      .catch(() => setRooms([]));
  }, [isOpen, usesRooms]);

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
        .then(res => setStatuses(res.data))
        .catch(err => console.error('Error cargando estados de servicios:', err));
    }
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || initialData || form.status_id || statuses.length === 0) return;
    const defaultStatus = statuses.find((s: any) => s.is_default) || statuses[0];
    setForm(prev => ({ ...prev, status_id: defaultStatus.id }));
  }, [isOpen, initialData, statuses, form.status_id]);

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
    if (!form.status_id) {
      setActiveAccordion(3);
      setSaveError('Elige el estado del servicio: Activo o Inactivo.');
      return;
    }
    if (branches.length > 1 && !allBranches && branchIds.length === 0) {
      setActiveAccordion(4);
      setSaveError('Marca al menos una sede donde se da el servicio, o elige "Todas las sedes".');
      return;
    }
    if (team.length > 1 && !allTeam && teamIds.filter((id) => team.some((p) => p.id === id)).length === 0) {
      setActiveAccordion(4);
      setSaveError(`Marca al menos ${vocab.pick('pro', 'un', 'una')} ${vocab.pro.toLowerCase()} que hace este servicio, o elige "${vocab.pick('pro', 'Todos', 'Todas')}".`);
      return;
    }
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
        ...(branches.length > 1 ? { branch_ids: allBranches ? [] : branchIds } : {}),
        ...(team.length > 1 ? { professional_ids: allTeam ? [] : teamIds.filter((id) => team.some((p) => p.id === id)) } : {}),
        ...(usesRooms && rooms.length > 0 ? { room_ids: form.kind === 'group' || form.is_virtual ? [] : roomIds.filter((id) => rooms.some((room) => room.id === id && (allBranches || branches.length <= 1 || branchIds.includes(room.branch_id)))) } : {}),
        ...(branches.length > 1 ? { branch_prices: samePrice ? [] : branches.filter((b) => allBranches || branchIds.includes(b.id)).map((b) => ({ branch_id: b.id, price: branchPrices[b.id] === undefined || branchPrices[b.id] === '' ? null : Number(branchPrices[b.id]) })) } : {}),
      };

      const res = initialData?.id
        ? await api.put(`/services/${initialData.id}`, payload)
        : await api.post('/services', payload);
      const saved = res?.data?.data ?? res?.data;
      const serviceId = initialData?.id ?? saved?.id;

      if (suppliesTouched && supplies?.can_edit && serviceId && form.kind !== 'group') {
        try {
          await api.put(`/inventory/services/${serviceId}/supplies`, { supplies: validLines(supplyLines) });
        } catch (error) {
          onSubmitSuccess?.(saved);
          setActiveAccordion(5);
          setSaveError(apiError(error, 'El servicio se guardó, pero no sus insumos.'));
          return;
        }
      }

      onSubmitSuccess?.(saved);
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
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              placeholder="Ej. Corte y Peinado"
                            />
                          </div>

                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Código interno</label>
                            <input
                              type="text"
                              maxLength={30}
                              value={form.code}
                              onChange={(e) => { setCodeTouched(true); setForm({ ...form, code: e.target.value }); }}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 font-mono focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              placeholder="Ej. COR-01"
                            />
                            {!initialData && <p className="text-xs text-slate-400 mt-1">Sigue el orden de la categoría. Puedes cambiarlo.</p>}
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

                          <div>
                            <label htmlFor="service-description" className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Descripción para el cliente</label>
                            <textarea
                              id="service-description"
                              rows={3}
                              maxLength={2000}
                              value={form.description}
                              onChange={(e) => setForm({ ...form, description: e.target.value })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none resize-y"
                              placeholder="Ej. Limpieza profunda con extracción y mascarilla hidratante."
                            />
                            <span className="block text-xs text-slate-400 mt-1">Se muestra en la reserva en línea y en el portal, debajo del nombre.</span>
                          </div>
                        </div>
                      )}

                      {sec.id === 2 && (
                        <div className="space-y-4">
                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Duración (minutos) *</label>
                            <input
                              type="number"
                              min="5"
                              max="720"
                              required
                              value={form.duration_minutes}
                              onChange={(e) => setForm({ ...form, duration_minutes: Number(e.target.value) })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            />
                          </div>
                          <div className="grid grid-cols-2 gap-3">
                            <div>
                              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Preparación antes (min)</label>
                              <input
                                type="number"
                                min="0"
                                value={form.buffer_before_minutes}
                                onChange={(e) => setForm({ ...form, buffer_before_minutes: Number(e.target.value) })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              />
                            </div>
                            <div>
                              <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Limpieza después (min)</label>
                              <input
                                type="number"
                                min="0"
                                value={form.buffer_after_minutes}
                                onChange={(e) => setForm({ ...form, buffer_after_minutes: Number(e.target.value) })}
                                className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                              />
                            </div>
                          </div>
                        </div>
                      )}

                      {sec.id === 3 && (
                        <div className="space-y-4">
                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Precio ({currencySymbol()}, {taxNote(user?.tenant?.rules)}) *</label>
                            <input
                              type="number"
                              step="0.01"
                              min="0"
                              required
                              value={form.price}
                              onChange={(e) => setForm({ ...form, price: Number(e.target.value) })}
                              className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                            />
                          </div>

                          <div>
                            <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Estado *</label>
                            <Select
                              ariaLabel="Estado"
                              placeholder={initialData && !initialData.status_id ? 'Sin estado: elige uno' : 'Seleccione estado...'}
                              value={form.status_id}
                              onChange={(e) => setForm({ ...form, status_id: String(e.target.value) })}
                              options={statuses.map((st) => ({ value: st.id, label: st.name }))}
                            />
                          </div>
                        </div>
                      )}

                      {sec.id === 4 && (
                        <div className="space-y-4">
                          {branches.length > 1 && (
                            <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-service-branches>
                              <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">¿En qué sedes se da?</span>
                              <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Sedes donde se da">
                                {([['all', 'Todas las sedes'], ['some', 'Solo en algunas']] as const).map(([value, label]) => (
                                  <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={(value === 'all') === allBranches}
                                    data-branch-mode={value}
                                    onClick={() => setAllBranches(value === 'all')}
                                    className={`px-3 py-2 text-sm font-semibold rounded-lg border transition-colors ${(value === 'all') === allBranches ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 text-slate-600 hover:border-slate-300'}`}
                                  >
                                    {label}
                                  </button>
                                ))}
                              </div>
                              {!allBranches && (
                                <div className="space-y-1.5">
                                  {branches.map((branch) => (
                                    <label key={branch.id} className="flex items-center gap-2.5 cursor-pointer">
                                      <input
                                        type="checkbox"
                                        checked={branchIds.includes(branch.id)}
                                        onChange={(e) => setBranchIds(e.target.checked ? [...branchIds, branch.id] : branchIds.filter((id) => id !== branch.id))}
                                        className="rounded border-slate-300 text-brand-ink focus:ring-brand"
                                      />
                                      <span className="text-sm text-slate-800">{branch.name}</span>
                                    </label>
                                  ))}
                                  <span className="block text-xs text-slate-400">En las demás sedes no se puede agendar ni aparece en el portal ni en el kiosko.</span>
                                </div>
                              )}
                            </div>
                          )}
                          {branches.length > 1 && (
                            <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-service-branch-prices>
                              <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">¿Cuesta lo mismo en todas las sedes?</span>
                              <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Precio por sede">
                                {([['same', 'Sí, el mismo precio'], ['each', 'Cambia por sede']] as const).map(([value, label]) => (
                                  <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={(value === 'same') === samePrice}
                                    data-price-mode={value}
                                    onClick={() => setSamePrice(value === 'same')}
                                    className={`px-3 py-2 text-sm font-semibold rounded-lg border transition-colors ${(value === 'same') === samePrice ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 text-slate-600 hover:border-slate-300'}`}
                                  >
                                    {label}
                                  </button>
                                ))}
                              </div>
                              {!samePrice && (
                                <div className="space-y-2">
                                  {branches.filter((b) => allBranches || branchIds.includes(b.id)).map((branch) => (
                                    <div key={branch.id} className="flex items-center justify-between gap-3">
                                      <span className="text-sm text-slate-800 min-w-0 truncate">{branch.name}</span>
                                      <input
                                        type="number"
                                        min="0"
                                        step="0.01"
                                        aria-label={`Precio en ${branch.name}`}
                                        placeholder={String(form.price)}
                                        value={branchPrices[branch.id] ?? ''}
                                        onChange={(e) => setBranchPrices({ ...branchPrices, [branch.id]: e.target.value })}
                                        className="w-32 text-sm text-right border border-slate-200 rounded-lg px-3 py-2 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
                                      />
                                    </div>
                                  ))}
                                  <span className="block text-xs text-slate-400">Si dejas una sede vacía, cobra el precio general ({form.price}). La cita toma el precio de la sede donde se agenda.</span>
                                </div>
                              )}
                            </div>
                          )}
                          {team.length > 1 && (
                            <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-service-professionals>
                              <div>
                                <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">{`${vocab.pros} que lo hacen`}</span>
                                <span className="block text-xs text-slate-400 mt-1">Solo a quienes marques se les puede agendar este servicio, también en la reserva en línea y en la cola sin cita.</span>
                              </div>
                              <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label={`${vocab.pros} que lo hacen`}>
                                {([['all', vocab.pick('pro', 'Todos', 'Todas')], ['some', vocab.pick('pro', 'Solo algunos', 'Solo algunas')]] as const).map(([value, label]) => (
                                  <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={(value === 'all') === allTeam}
                                    data-team-mode={value}
                                    onClick={() => setAllTeam(value === 'all')}
                                    className={`px-3 py-2 text-sm font-semibold rounded-lg border transition-colors ${(value === 'all') === allTeam ? 'border-brand bg-brand-soft/20 text-brand-ink' : 'border-slate-200 text-slate-600 hover:border-slate-300'}`}
                                  >
                                    {label}
                                  </button>
                                ))}
                              </div>
                              {!allTeam && (
                                <div className="space-y-1.5">
                                  {team.filter((p) => !p.inactive || teamIds.includes(p.id)).map((p) => (
                                    <label key={p.id} className="flex items-center gap-2.5 cursor-pointer" data-service-professional={p.id}>
                                      <input
                                        type="checkbox"
                                        checked={teamIds.includes(p.id)}
                                        onChange={(e) => setTeamIds(e.target.checked ? [...teamIds, p.id] : teamIds.filter((id) => id !== p.id))}
                                        className="rounded border-slate-300 text-brand-ink focus:ring-brand"
                                      />
                                      <span className="text-sm text-slate-800">{p.display_name}</span>
                                      {p.inactive && <span className="text-xs text-amber-700">Inactivo</span>}
                                    </label>
                                  ))}
                                </div>
                              )}
                            </div>
                          )}
                          {usesRooms && form.kind !== 'group' && !form.is_virtual && rooms.length > 0 && (() => {
                            const visible = rooms.filter((room) => allBranches || branches.length <= 1 || branchIds.includes(room.branch_id));
                            const groups = Array.from(new Set(visible.map((room) => room.branch_id)));
                            return (
                              <div className="rounded-xl border border-slate-200 p-4 space-y-3" data-service-rooms>
                                <div>
                                  <span className="block text-xs font-semibold uppercase tracking-wider text-slate-600">¿En qué salas se hace?</span>
                                  <span className="block text-xs text-slate-400 mt-1">Si marcas salas, solo se ofrecen las horas en que alguna de ellas está libre y la cita queda con una. Sin salas marcadas, se agenda como siempre.</span>
                                </div>
                                {groups.map((branchId) => (
                                  <div key={branchId} className="space-y-1.5">
                                    {groups.length > 1 && <span className="block text-xs font-semibold text-slate-500">{visible.find((room) => room.branch_id === branchId)?.branch_name}</span>}
                                    {visible.filter((room) => room.branch_id === branchId).map((room) => (
                                      <label key={room.id} className="flex items-center gap-2.5 cursor-pointer" data-service-room={room.id}>
                                        <input
                                          type="checkbox"
                                          checked={roomIds.includes(room.id)}
                                          onChange={(e) => setRoomIds(e.target.checked ? [...roomIds, room.id] : roomIds.filter((id) => id !== room.id))}
                                          className="rounded border-slate-300 text-brand-ink focus:ring-brand"
                                        />
                                        <span className="text-sm text-slate-800">{room.name}</span>
                                        {room.effective_status !== 'active' && <span className="text-xs text-amber-700">Fuera de uso</span>}
                                      </label>
                                    ))}
                                  </div>
                                ))}
                                {groups.length > 1 && <span className="block text-xs text-slate-400">En una sede sin salas marcadas, el servicio se agenda como siempre.</span>}
                              </div>
                            );
                          })()}
                          <div className="space-y-2.5">
                            {[
                              { key: 'online_booking' as const, label: 'Se puede reservar en línea', hint: 'Aparece en el portal del cliente y en la reserva pública.' },
                              { key: 'requires_deposit' as const, label: 'Exige adelanto', hint: 'La reserva se confirma cuando el cliente paga el adelanto.' },
                              { key: 'is_virtual' as const, label: 'Atención por videollamada', hint: 'No ocupa sala ni equipo.' },
                              ...(usesRecords ? [{ key: 'requires_record' as const, label: 'Exige la ficha de atención firmada', hint: 'No se puede dar la cita por atendida sin la ficha firmada, aunque la regla general esté apagada. Necesita una plantilla de ficha para este servicio.' }] : []),
                            ].map((option) => (
                              <label key={option.key} className="flex items-start gap-2.5 cursor-pointer">
                                <input
                                  type="checkbox"
                                  checked={form[option.key]}
                                  onChange={(e) => setForm({ ...form, [option.key]: e.target.checked })}
                                  className="mt-0.5 rounded border-slate-300 text-brand-ink focus:ring-brand"
                                />
                                <span>
                                  <span className="block text-sm font-medium text-slate-800">{option.label}</span>
                                  <span className="block text-xs text-slate-400">{option.hint}</span>
                                </span>
                              </label>
                            ))}
                          </div>

                          {form.is_virtual && (
                            <div data-service-video>
                              <label htmlFor="service-video-url" className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">Enlace de la videollamada (opcional)</label>
                              <input id="service-video-url" maxLength={500} placeholder="https://meet.google.com/..." value={form.video_url} onChange={(e) => setForm({ ...form, video_url: e.target.value })} className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand text-slate-800 bg-white" />
                              <span className="block text-xs text-slate-400 mt-1">Una sala fija para este servicio. Sale en el correo de la cita, en el portal y en el enlace de la cita. En cada cita se puede poner otro.</span>
                            </div>
                          )}

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
                                    <OptionCard
                                      key={key}
                                      type="button"
                                      role="radio"
                                      aria-checked={form.deposit_type === key}
                                      data-deposit-type={key}
                                      onClick={() => setForm({ ...form, deposit_type: key, deposit_value: key === 'percent' ? '30' : String(Math.min(50, price) || '') })} selected={form.deposit_type === key} className="text-left rounded-xl border px-3.5 py-2.5 transition-colors"
                                    >
                                      <span className="block text-sm font-bold text-brand-ink">{title}</span>
                                      <span className="block text-xs text-slate-500">{hint}</span>
                                    </OptionCard>
                                  ))}
                                </div>
                                <div className="flex items-center gap-2">
                                  <input
                                    id="deposit-value"
                                    inputMode="decimal"
                                    aria-label="Valor del adelanto"
                                    value={form.deposit_value}
                                    onChange={(e) => setForm({ ...form, deposit_value: cleanAmount(e.target.value) })}
                                    className="w-28 text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
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
                                    className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
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
                                  className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
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
                                  className="w-full text-sm border border-slate-200 rounded-lg px-3.5 py-2.5 text-slate-800 focus:ring-2 focus:ring-brand/20 focus:border-brand outline-none"
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

                      {sec.id === 5 && (
                        <ServiceSuppliesFields
                          data={supplies}
                          error={suppliesError}
                          lines={supplyLines}
                          onChange={(lines) => { setSupplyLines(lines); setSuppliesTouched(true); }}
                          servicePrice={Number(form.price || 0)}
                        />
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
              className="px-5 py-2.5 text-xs font-semibold uppercase tracking-wider text-white bg-brand hover:bg-brand/90 rounded-lg transition-colors shadow-sm disabled:opacity-50"
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