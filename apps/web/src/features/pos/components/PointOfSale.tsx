'use client';

import { ErrorAlert, SuccessAlert } from '@/app/components/common/Alert';
import { inputClass, labelClass } from '@/app/components/ui/forms/styles';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { Boxes, CalendarClock, CalendarPlus, Minus, Package, Plus, Receipt, Search, ShoppingCart, Sparkles, TicketPercent, Trash2, X } from 'lucide-react';
import SchedulePanel, { type ScheduleSource } from '@/features/packages/components/SchedulePanel';
import { sessionsLabel, type ScheduleDetails } from '@/features/packages/packages';
import { api } from '@/lib/axios';
import Select from '@/app/components/common/Select';
import { Pager, usePaged } from '@/app/components/common/Pager';
import { CustomerLink, ProfessionalLink } from '@/app/components/common/PersonLink';
import { AppModal } from '@/app/components/ui/modals/AppModal';
import CustomerPicker, { type PickedCustomer } from '@/features/customers/components/CustomerPicker';
import { apiError } from '@/features/appointments/appointments';
import ChargePanel from '@/features/sales/components/ChargePanel';
import { money, momentOf, type ChargeContext } from '@/features/sales/sales';
import { emptyCart, whenOf, type Cart, type CartItem, type CartPreview, type LoadedCart, type PendingAppointment, type PendingSale, type PosBoard } from '../pos';
import WalkInPanel, { type BookedWalkIn } from './WalkInPanel';

type Tab = 'pending' | 'services' | 'products' | 'packages';

type PendingEntry = { kind: 'sale'; sale: PendingSale } | { kind: 'appointment'; appointment: PendingAppointment };

const STORAGE_KEY = 'pos-cart';

const tileClass = 'rounded-2xl border border-slate-200/80 bg-white p-4 flex flex-col gap-2 min-h-[8.5rem]';
const addButton = 'mt-auto self-start flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold rounded-lg bg-[#022340] text-white hover:bg-[#03315a] transition-colors disabled:bg-slate-100 disabled:text-slate-400';
const stepButton = 'p-1 rounded-md border border-slate-200 text-slate-600 hover:bg-slate-50 disabled:opacity-40';

const readStored = (): Cart | null => {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    return raw ? { ...emptyCart(), ...JSON.parse(raw) } : null;
  } catch {
    return null;
  }
};

const store = (cart: Cart) => {
  try {
    if (cart.items.length || cart.saleId) sessionStorage.setItem(STORAGE_KEY, JSON.stringify(cart));
    else sessionStorage.removeItem(STORAGE_KEY);
  } catch {
  }
};

const fullName = (customer: PickedCustomer | null) => (customer ? `${customer.first_name} ${customer.last_name}` : '');

const toLine = ({ type, id, quantity, professional_id, details }: CartItem) => ({
  type,
  id,
  quantity,
  professional_id: professional_id || null,
  ...(type === 'package' && details ? { details } : {}),
});

export default function PointOfSale() {
  const [board, setBoard] = useState<PosBoard | null>(null);
  const [branchId, setBranchId] = useState<string>('');
  const [tab, setTab] = useState<Tab>('pending');
  const [query, setQuery] = useState('');
  const [cart, setCart] = useState<Cart>(emptyCart);
  const [preview, setPreview] = useState<CartPreview | null>(null);
  const [previewError, setPreviewError] = useState('');
  const [previewing, setPreviewing] = useState(false);
  const [couponInput, setCouponInput] = useState('');
  const [couponError, setCouponError] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [saving, setSaving] = useState(false);
  const [charging, setCharging] = useState<string | null>(null);
  const [charged, setCharged] = useState<ChargeContext | null>(null);
  const [walkInOpen, setWalkInOpen] = useState(false);
  const [pendingSwitch, setPendingSwitch] = useState<{ message: string; run: () => void } | null>(null);
  const [scheduleIndex, setScheduleIndex] = useState<number | null>(null);
  const [scheduleInitial, setScheduleInitial] = useState<ScheduleDetails | null>(null);
  const restored = useRef(false);

  const load = useCallback((branch?: string) => {
    api.get('/pos', { params: branch ? { branch_id: branch } : {} })
      .then((res) => {
        const data: PosBoard = res.data;
        setBoard(data);
        setBranchId(data.branch_id ?? '');
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el punto de venta.')));
  }, []);

  const loadSale = useCallback(async (saleId: string) => {
    try {
      const res = await api.get(`/pos/sales/${saleId}`);
      const data: LoadedCart = res.data;
      setCart({
        saleId: data.sale_id,
        code: data.code,
        editBlocked: data.edit_blocked,
        customer: data.customer,
        coupon: data.coupon_code ?? '',
        items: data.items.map((item) => ({ ...item, label: '', unit_price: 0, details: item.details ?? null })),
      });
      setCouponInput('');
      setCouponError('');
      if (data.branch_id !== branchId) load(data.branch_id);
      setNotice('');
    } catch (err) {
      setError(apiError(err, 'No se pudo abrir la venta.'));
    }
  }, [branchId, load]);

  useEffect(() => {
    if (restored.current) return;
    restored.current = true;

    const query = typeof window !== 'undefined' ? new URLSearchParams(window.location.search) : null;
    const wanted = query?.get('venta') ?? null;
    const saved = readStored();

    if (query?.get('tab') === 'paquetes') {
      setTab('packages');
      if (!wanted) window.history.replaceState(null, '', '/pos');
    }

    if (wanted) {
      window.history.replaceState(null, '', '/pos');
      load();
      loadSale(wanted);
      return;
    }

    if (saved) setCart(saved);
    load(undefined);
  }, [load, loadSale]);

  useEffect(() => { store(cart); }, [cart]);

  useEffect(() => {
    if (!branchId || cart.items.length === 0) {
      setPreview(null);
      setPreviewError('');
      return;
    }

    setPreviewing(true);
    const timer = setTimeout(() => {
      api.post('/pos/preview', {
        branch_id: branchId,
        sale_id: cart.saleId,
        customer_id: cart.customer?.id ?? null,
        coupon_code: cart.coupon || null,
        items: cart.items.map(toLine),
      })
        .then((res) => {
          const data: CartPreview = res.data;
          setPreview(data);
          setPreviewError('');
          if (!cart.customer && data.customer) setCart((current) => ({ ...current, customer: data.customer }));
        })
        .catch((err) => {
          const errors = err?.response?.data?.errors ?? {};
          if (errors.coupon_code && cart.coupon) {
            setCouponError(errors.coupon_code[0]);
            setCart((current) => ({ ...current, coupon: '' }));
            return;
          }
          setPreviewError(apiError(err, 'No se pudo calcular el total.'));
        })
        .finally(() => setPreviewing(false));
    }, 250);

    return () => clearTimeout(timer);
  }, [branchId, cart.items, cart.customer, cart.coupon, cart.saleId]);

  const can = board?.can;
  const currency = board?.currency;
  const inCart = useMemo(() => new Set(cart.items.filter((i) => i.type === 'appointment').map((i) => i.id)), [cart.items]);
  const hasAppointment = inCart.size > 0;
  const branchName = board?.branches.find((b) => b.id === branchId)?.name ?? '';
  const term = query.trim().toLowerCase();

  const pendingEntries: PendingEntry[] = useMemo(() => [
    ...(board?.pending.sales ?? []).filter((s) => s.id !== cart.saleId).map((sale) => ({ kind: 'sale' as const, sale })),
    ...(board?.pending.appointments ?? []).map((appointment) => ({ kind: 'appointment' as const, appointment })),
  ].filter((entry) => !term || (entry.kind === 'sale'
    ? `${entry.sale.code} ${entry.sale.customer?.name ?? ''} ${entry.sale.detail}`
    : `${entry.appointment.code} ${entry.appointment.customer?.name ?? ''} ${entry.appointment.service ?? ''} ${entry.appointment.professional?.name ?? ''}`).toLowerCase().includes(term)),
  [board, cart.saleId, term]);

  const services = (board?.services ?? []).filter((s) => !term || `${s.name} ${s.category ?? ''}`.toLowerCase().includes(term));
  const products = (board?.products ?? []).filter((p) => !term || `${p.name} ${p.sku} ${p.category ?? ''}`.toLowerCase().includes(term));
  const plans = (board?.packages ?? []).filter((p) => !term || `${p.name} ${p.items.map((i) => i.name).join(' ')}`.toLowerCase().includes(term));

  const pendingPage = usePaged(pendingEntries);
  const servicePage = usePaged(services);
  const productPage = usePaged(products);
  const planPage = usePaged(plans);

  const scheduleItem = scheduleIndex !== null ? cart.items[scheduleIndex] ?? null : null;
  const schedulePlan = scheduleItem ? (board?.packages ?? []).find((p) => p.id === scheduleItem.id) ?? null : null;
  const scheduleSource: ScheduleSource | null = useMemo(() => (schedulePlan ? {
    kind: 'plan',
    id: schedulePlan.id,
    name: schedulePlan.name,
    frequency: schedulePlan.frequency,
    custom_days: schedulePlan.custom_days,
    repeat_weeks: schedulePlan.repeat_weeks,
    professional_mode: schedulePlan.professional_mode,
  } : null), [schedulePlan]);

  const resetCart = (message = '') => {
    setCart(emptyCart());
    setPreview(null);
    setPreviewError('');
    setCouponInput('');
    setCouponError('');
    setNotice(message);
  };

  const addItem = (item: CartItem, customer?: PickedCustomer | null) => {
    setNotice('');
    setCart((current) => {
      const existing = current.items.findIndex((i) => i.type === item.type && i.id === item.id && item.type !== 'service');
      if (existing >= 0 && item.type === 'appointment') return current;
      const items = existing >= 0
        ? current.items.map((i, index) => (index === existing ? { ...i, quantity: Math.min(999, i.quantity + 1) } : i))
        : [...current.items, item];
      return { ...current, items, customer: customer ?? current.customer };
    });
  };

  const addAppointment = (appointment: PendingAppointment) => {
    const customer = appointment.customer;
    const item: CartItem = { type: 'appointment', id: appointment.id, quantity: 1, label: appointment.service ?? 'Servicio', unit_price: appointment.price };

    if (cart.items.length > 0 && cart.customer && customer && cart.customer.id !== customer.id) {
      setPendingSwitch({
        message: `El carrito es de ${fullName(cart.customer)}. Una venta es de un solo cliente: ¿vaciarlo para cobrarle a ${customer.name}?`,
        run: () => { resetCart(); setCart({ ...emptyCart(), items: [item] }); },
      });
      return;
    }

    setNotice('');
    setCart((current) => ({
      ...current,
      customer: current.items.length > 0 ? current.customer : null,
      items: [...current.items, item],
    }));
  };

  const addPackage = (planId: string) => {
    const plan = (board?.packages ?? []).find((p) => p.id === planId);
    if (!plan) return;
    const index = cart.items.length;
    setNotice('');
    setCart((current) => ({ ...current, items: [...current.items, { type: 'package', id: plan.id, quantity: 1, label: plan.name, unit_price: plan.price, details: null }] }));
    if (plan.booking_mode === 'scheduled') {
      if (cart.customer) { setScheduleInitial(null); setScheduleIndex(index); }
      else setNotice(`Elige al cliente y luego pulsa "Agendar sesiones" en el carrito: ${plan.name} se agenda al venderlo.`);
    }
  };

  const saveSchedule = (details: ScheduleDetails) => {
    if (scheduleIndex === null) return;
    changeItem(scheduleIndex, { details });
    setScheduleIndex(null);
    setNotice(`${sessionsLabel(details.slots.length)} con fecha. Las citas se crean al cobrar el paquete.`);
  };

  const openSale = (sale: PendingSale) => {
    if (cart.items.length > 0) {
      setPendingSwitch({
        message: `El carrito tiene cosas sin guardar. ¿Dejarlas de lado y abrir la venta ${sale.code}?`,
        run: () => loadSale(sale.id),
      });
      return;
    }
    loadSale(sale.id);
  };

  const changeItem = (index: number, changes: Partial<CartItem>) =>
    setCart((current) => ({ ...current, items: current.items.map((item, i) => (i === index ? { ...item, ...changes } : item)) }));

  const removeItem = (index: number) =>
    setCart((current) => {
      const items = current.items.filter((_, i) => i !== index);
      const keepCustomer = items.some((i) => i.type === 'appointment') || current.customer;
      return { ...current, items, customer: keepCustomer ? current.customer : null };
    });

  const applyCoupon = () => {
    if (!couponInput) return;
    setCouponError('');
    setCart((current) => ({ ...current, coupon: couponInput }));
    setCouponInput('');
  };

  const payload = () => ({
    branch_id: branchId,
    customer_id: cart.customer?.id ?? null,
    coupon_code: cart.coupon || null,
    items: cart.items.map(toLine),
  });

  const save = async (): Promise<string | null> => {
    try {
      setSaving(true);
      setError('');
      const res = cart.saleId ? await api.put(`/pos/sales/${cart.saleId}`, payload()) : await api.post('/pos/sales', payload());
      const loaded: LoadedCart = res.data.cart;
      setCart((current) => ({ ...current, saleId: loaded.sale_id, code: loaded.code, editBlocked: loaded.edit_blocked }));
      return loaded.sale_id;
    } catch (err) {
      setError(apiError(err, 'No se pudo guardar la venta.'));
      return null;
    } finally {
      setSaving(false);
    }
  };

  const leavePending = async () => {
    const code = cart.code;
    const id = await save();
    if (!id) return;
    const res = await api.get(`/pos/sales/${id}`).catch(() => null);
    resetCart(`La venta ${res?.data?.code ?? code ?? ''} quedó por cobrar: la encuentras en "Por cobrar".`);
    load(branchId);
  };

  const charge = async () => {
    const id = cart.editBlocked && cart.saleId ? cart.saleId : await save();
    if (!id) return;
    setCharged(null);
    setCharging(id);
  };

  const closeCharge = () => {
    const paid = charged?.sale?.status === 'paid';
    const code = charged?.sale?.code ?? cart.code;
    const sold = (charged?.packages ?? []).map((p) => `${p.code} (${p.name})${p.created.length ? `: ${sessionsLabel(p.created.length)} agendadas` : ''}${p.failed.length ? `; ${p.failed.length} no se pudieron agendar porque el horario se ocupó, agéndalas desde el paquete` : ''}`);
    setCharging(null);
    setCharged(null);
    if (paid) {
      resetCart(`Venta ${code} cobrada.${sold.length ? ` Paquete ${sold.join(' · ')}. Lo ves en Paquetes y en la página del cliente.` : ''}`);
    } else {
      setNotice(`La venta ${code ?? ''} quedó por cobrar. Puedes cobrarla ahora o después, desde "Por cobrar".`);
    }
    load(branchId);
  };

  const booked = (walkIn: BookedWalkIn) => {
    setWalkInOpen(false);
    const run = (fresh: boolean) => {
      setCart((current) => ({
        ...(fresh ? emptyCart() : current),
        customer: walkIn.customer,
        items: [...(fresh ? [] : current.items), { type: 'appointment', id: walkIn.id, quantity: 1, label: walkIn.service.name, unit_price: walkIn.service.price }],
      }));
      setNotice(`Cita ${walkIn.code} agendada para las ${walkIn.starts_at} con ${walkIn.professional} (${walkIn.status_name.toLowerCase()}). Ya está en el carrito.`);
    };
    if (cart.items.length > 0 && cart.customer && cart.customer.id !== walkIn.customer.id) {
      setPendingSwitch({ message: `La cita quedó agendada. El carrito es de ${fullName(cart.customer)}: ¿vaciarlo para cobrarle a ${fullName(walkIn.customer)}?`, run: () => run(true) });
    } else {
      run(false);
    }
    load(branchId);
  };

  const lines = cart.items.map((item, index) => {
    const line = preview && preview.lines.length === cart.items.length ? preview.lines[index] : null;
    return { item, index, line };
  });
  const totals = preview?.totals;
  const lineCount = cart.items.reduce((sum, item) => sum + item.quantity, 0);
  const canSave = cart.saleId ? Boolean(can?.edit) : Boolean(can?.create);
  const ready = cart.items.length > 0 && Boolean(totals) && !previewError && !previewing;
  const professionals = board?.professionals ?? [];

  const tabs: { id: Tab; label: string; count?: number }[] = [
    { id: 'pending', label: 'Por cobrar', count: (board?.pending.sales.filter((s) => s.id !== cart.saleId).length ?? 0) + (board?.pending.appointments.length ?? 0) },
    { id: 'services', label: 'Servicios' },
    ...(board?.products_ready ? [{ id: 'products' as Tab, label: 'Productos' }] : []),
    ...(board?.packages_enabled ? [{ id: 'packages' as Tab, label: 'Paquetes' }] : []),
  ];

  return (
    <div className="space-y-6 mb-8" data-pos>
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[#022340]">Punto de venta</h1>
          <p className="text-sm text-slate-500 mt-1">Cobra citas, servicios y productos en una sola venta, o déjala por cobrar.</p>
        </div>
        <div className="flex flex-wrap items-center gap-3">
          {board && board.branches.length > 1 && (
            <div className="w-64" data-branch-select title={cart.items.length ? 'Vacía el carrito para cambiar de sede' : undefined}>
              <Select
                ariaLabel="Sede"
                value={branchId}
                clearable={false}
                disabled={cart.items.length > 0}
                onChange={(e) => { setTab('pending'); load(String(e.target.value)); }}
                options={board.branches.map((b) => ({ value: b.id, label: b.name }))}
              />
            </div>
          )}
          {can?.walk_in && (
            <button type="button" data-walk-in-open onClick={() => setWalkInOpen(true)} className="flex items-center gap-1.5 px-4 py-2.5 text-sm font-bold border border-[#022340] text-[#022340] rounded-xl hover:bg-[#84D0D9]/15 transition-colors">
              <CalendarPlus size={16} /> Agendar para ahora
            </button>
          )}
        </div>
      </div>

      <ErrorAlert message={error} />
      <SuccessAlert message={notice} />

      {board && !branchId && (
        <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">Tu empresa aún no tiene una sucursal activa donde vender.</p>
      )}

      <div className="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_26rem] gap-6 items-start">
        <section className="bg-white rounded-3xl border border-slate-200/80 p-5 space-y-4" data-catalog>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap gap-2" role="tablist">
              {tabs.map((t) => (
                <button
                  key={t.id}
                  type="button"
                  role="tab"
                  aria-selected={tab === t.id}
                  data-tab={t.id}
                  onClick={() => { setTab(t.id); setQuery(''); }}
                  className={`flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-xl transition-colors ${tab === t.id ? 'bg-[#022340] text-white' : 'text-slate-600 hover:bg-slate-100'}`}
                >
                  {t.label}
                  {t.count !== undefined && <span className={`px-2 py-0.5 text-xs rounded-full ${tab === t.id ? 'bg-white/15' : 'bg-slate-100'}`}>{t.count}</span>}
                </button>
              ))}
            </div>
            <div className="relative w-full sm:w-64">
              <Search size={15} className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                aria-label="Buscar en el catálogo"
                placeholder={tab === 'pending' ? 'Cliente, cita o venta' : tab === 'services' ? 'Buscar servicio' : tab === 'packages' ? 'Buscar paquete' : 'Nombre o código'}
                className={`${inputClass} pl-9`}
                value={query}
                onChange={(e) => setQuery(e.target.value)}
              />
            </div>
          </div>

          {tab === 'pending' && (
            <>
              {pendingEntries.length === 0 ? (
                <p className="text-sm text-slate-500 py-6 text-center">
                  {term ? 'Nada coincide con lo que buscas.' : 'No hay nada por cobrar en esta sede: ni citas de hoy sin pagar, ni atendidas sin cobrar, ni ventas pendientes.'}
                </p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3" data-pending>
                  {pendingPage.rows.map((entry) => entry.kind === 'sale' ? (
                    <article key={entry.sale.id} className={`${tileClass} border-amber-200 bg-amber-50/40`} data-pending-sale={entry.sale.code}>
                      <div className="flex items-start justify-between gap-2">
                        <span className="flex items-center gap-1.5 text-sm font-bold text-[#022340]"><Receipt size={15} /> Venta {entry.sale.code}</span>
                        <span className="px-2 py-0.5 text-xs rounded-full font-semibold bg-amber-100 text-amber-800">{(entry.sale.deposit ?? 0) > 0 ? 'Adelanto pagado' : entry.sale.status === 'partial' ? 'Pago parcial' : 'Por cobrar'}</span>
                      </div>
                      <span className="text-sm font-semibold text-slate-800">
                        {entry.sale.customer ? <CustomerLink id={entry.sale.customer.id}>{entry.sale.customer.name}</CustomerLink> : 'Sin cliente'}
                      </span>
                      <span className="text-xs text-slate-500 line-clamp-2">{entry.sale.detail}</span>
                      {(entry.sale.deposit ?? 0) > 0 && <span className="text-xs font-semibold text-[#3F768C]">Adelanto en línea {money(entry.sale.deposit, entry.sale.currency)} · falta {money(entry.sale.balance, entry.sale.currency)}</span>}
                      <span className="text-xs text-slate-400">{momentOf(entry.sale.created_at)}{entry.sale.by ? ` · ${entry.sale.by}` : ''}</span>
                      <div className="flex items-center justify-between gap-2 mt-auto">
                        <span className="text-sm font-black text-[#022340] tabular-nums">{money(entry.sale.balance, entry.sale.currency)}</span>
                        <button type="button" data-open-sale={entry.sale.code} onClick={() => openSale(entry.sale)} className={addButton.replace('mt-auto self-start ', '')}>
                          Abrir
                        </button>
                      </div>
                    </article>
                  ) : (
                    <article key={entry.appointment.id} className={tileClass} data-pending-appointment={entry.appointment.code}>
                      <div className="flex items-start justify-between gap-2">
                        <span className="text-sm font-bold text-[#022340]">{whenOf(entry.appointment.starts_at, entry.appointment.today)}</span>
                        <span className="px-2 py-0.5 text-xs rounded-full font-semibold bg-slate-100 text-slate-600">{entry.appointment.status_name}</span>
                      </div>
                      <span className="text-sm font-semibold text-slate-800">
                        {entry.appointment.customer ? <CustomerLink id={entry.appointment.customer.id}>{entry.appointment.customer.name}</CustomerLink> : 'Sin cliente'}
                      </span>
                      <span className="text-xs text-slate-500">
                        {entry.appointment.service} · {entry.appointment.professional ? <ProfessionalLink id={entry.appointment.professional.id}>{entry.appointment.professional.name}</ProfessionalLink> : '—'} · {entry.appointment.code}
                      </span>
                      <div className="flex items-center justify-between gap-2 mt-auto">
                        <span className="text-sm font-black text-[#022340] tabular-nums">{money(entry.appointment.price, entry.appointment.currency)}</span>
                        <button
                          type="button"
                          data-add-appointment={entry.appointment.code}
                          disabled={inCart.has(entry.appointment.id) || !!cart.editBlocked}
                          onClick={() => addAppointment(entry.appointment)}
                          className={addButton.replace('mt-auto self-start ', '')}
                        >
                          {inCart.has(entry.appointment.id) ? 'En el carrito' : <><Plus size={13} /> Agregar</>}
                        </button>
                      </div>
                    </article>
                  ))}
                </div>
              )}
              {pendingPage.pages > 1 && <Pager page={pendingPage.page} pages={pendingPage.pages} onChange={pendingPage.setPage} />}
            </>
          )}

          {tab === 'services' && (
            <>
              <p className="flex items-start gap-2 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2" data-services-hint>
                <Sparkles size={14} className="shrink-0 mt-0.5" />
                <span>
                  Lo que vendes aquí no ocupa la agenda. Si alguien va a atender al cliente (por ejemplo, un corte para quien llegó sin reserva),
                  {can?.walk_in
                    ? <> usa <button type="button" onClick={() => setWalkInOpen(true)} className="font-bold underline underline-offset-2">Agendar para ahora</button> para que quede en la agenda y en los tiempos de atención.</>
                    : ' pide a recepción que lo agende para ahora: así queda en la agenda y luego lo cobras desde "Por cobrar".'}
                </span>
              </p>
              {services.length === 0 ? (
                <p className="text-sm text-slate-500 py-6 text-center">{term ? 'Ningún servicio coincide.' : 'Tu empresa aún no tiene servicios activos.'}</p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3" data-services>
                  {servicePage.rows.map((service) => (
                    <article key={service.id} className={tileClass} data-service={service.name}>
                      <span className="text-sm font-bold text-[#022340]">{service.name}</span>
                      <span className="text-xs text-slate-500">{[service.category, `${service.duration_minutes} min`].filter(Boolean).join(' · ')}</span>
                      <div className="flex items-center justify-between gap-2 mt-auto">
                        <span className="text-sm font-black text-[#022340] tabular-nums">{money(service.price, currency)}</span>
                        <button
                          type="button"
                          data-add-service={service.name}
                          disabled={!!cart.editBlocked}
                          onClick={() => addItem({ type: 'service', id: service.id, quantity: 1, professional_id: null, label: service.name, unit_price: service.price })}
                          className={addButton.replace('mt-auto self-start ', '')}
                        >
                          <Plus size={13} /> Agregar
                        </button>
                      </div>
                    </article>
                  ))}
                </div>
              )}
              {servicePage.pages > 1 && <Pager page={servicePage.page} pages={servicePage.pages} onChange={servicePage.setPage} />}
            </>
          )}

          {tab === 'products' && (
            <>
              {products.length === 0 ? (
                <p className="text-sm text-slate-500 py-6 text-center">
                  {term ? 'Ningún producto coincide.' : 'Aún no tienes productos para vender.'}
                  {!term && can?.products && <> <Link href="/products" className="font-semibold text-[#3F768C] underline underline-offset-2">Regístralos en Productos</Link>.</>}
                </p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3" data-products>
                  {productPage.rows.map((product) => (
                    <article key={product.id} className={tileClass} data-product={product.name}>
                      <span className="flex items-center gap-1.5 text-sm font-bold text-[#022340]"><Package size={14} className="text-[#3F768C] shrink-0" /> {product.name}</span>
                      <span className="text-xs text-slate-500">{[product.category, product.sku].filter(Boolean).join(' · ')}</span>
                      <div className="flex items-center justify-between gap-2 mt-auto">
                        <span className="text-sm font-black text-[#022340] tabular-nums">{money(product.price, currency)}</span>
                        <button
                          type="button"
                          data-add-product={product.name}
                          disabled={!!cart.editBlocked}
                          onClick={() => addItem({ type: 'product', id: product.id, quantity: 1, label: product.name, unit_price: product.price })}
                          className={addButton.replace('mt-auto self-start ', '')}
                        >
                          <Plus size={13} /> Agregar
                        </button>
                      </div>
                    </article>
                  ))}
                </div>
              )}
              {productPage.pages > 1 && <Pager page={productPage.page} pages={productPage.pages} onChange={productPage.setPage} />}
            </>
          )}

          {tab === 'packages' && (
            <>
              {plans.length === 0 ? (
                <p className="text-sm text-slate-500 py-6 text-center">
                  {term ? 'Ningún paquete coincide.' : 'Aún no tienes paquetes a la venta.'}
                  {!term && can?.packages && <> <Link href="/packages" className="font-semibold text-[#3F768C] underline underline-offset-2">Créalos en Paquetes → Catálogo</Link>.</>}
                </p>
              ) : (
                <div className="grid grid-cols-1 md:grid-cols-2 gap-3" data-packages-catalog>
                  {planPage.rows.map((plan) => (
                    <article key={plan.id} className={tileClass} data-plan={plan.name}>
                      <span className="flex items-center gap-1.5 text-sm font-bold text-[#022340]"><Boxes size={14} className="text-[#3F768C] shrink-0" /> {plan.name}</span>
                      <span className="text-xs text-slate-500">{plan.items.map((i) => `${i.sessions} × ${i.name}`).join(' · ')}</span>
                      <span className="flex items-center gap-1 text-xs text-slate-500">
                        {plan.booking_mode === 'scheduled' && <CalendarClock size={12} className="text-[#3F768C]" />}
                        {plan.schedule_label} · vale {plan.validity_days} días
                      </span>
                      <div className="flex items-center justify-between gap-2 mt-auto">
                        <span>
                          <span className="block text-sm font-black text-[#022340] tabular-nums">{money(plan.price, plan.currency)}</span>
                          {plan.savings > 0 && <span className="block text-[11px] font-semibold text-emerald-700">Ahorra {money(plan.savings, plan.currency)}</span>}
                        </span>
                        <button type="button" data-add-plan={plan.name} disabled={!!cart.editBlocked} onClick={() => addPackage(plan.id)} className={addButton.replace('mt-auto self-start ', '')}>
                          <Plus size={13} /> Agregar
                        </button>
                      </div>
                    </article>
                  ))}
                </div>
              )}
              {planPage.pages > 1 && <Pager page={planPage.page} pages={planPage.pages} onChange={planPage.setPage} />}
            </>
          )}
        </section>

        <aside className="bg-white rounded-3xl rounded-tr-[2.5rem] border border-slate-200/80 p-5 space-y-5 xl:sticky xl:top-4" data-cart>
          <div className="flex items-start justify-between gap-3">
            <div>
              <h2 className="flex items-center gap-2 text-lg font-black text-[#022340]"><ShoppingCart size={18} /> Carrito</h2>
              <p className="text-xs text-slate-500 mt-0.5" data-cart-title>
                {cart.code ? `Venta ${cart.code} · ${cart.editBlocked ? 'con pagos' : 'por cobrar'}` : 'Venta nueva'}{branchName ? ` · ${branchName}` : ''}
              </p>
            </div>
            {(cart.items.length > 0 || cart.saleId) && (
              <button type="button" data-clear-cart onClick={() => resetCart()} className="flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-red-600 transition-colors">
                <X size={13} /> {cart.saleId ? 'Cerrar venta' : 'Vaciar'}
              </button>
            )}
          </div>

          {cart.editBlocked && (
            <p className="text-sm text-slate-600 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2" data-edit-blocked>{cart.editBlocked} Puedes cobrar el saldo.</p>
          )}

          <CustomerPicker
            value={cart.customer}
            onChange={(customer) => setCart((current) => ({ ...current, customer }))}
            required={false}
            locked={hasAppointment || !!cart.editBlocked}
            lockedHint={hasAppointment ? 'Es el cliente de la cita' : undefined}
          />
          {!cart.customer && <p className="-mt-3 text-xs text-slate-400">Opcional: sin cliente, la boleta sale a nombre de &quot;Cliente varios&quot;.</p>}

          <div className="space-y-2" data-cart-lines>
            {cart.items.length === 0 ? (
              <p className="text-sm text-slate-400 border border-dashed border-slate-200 rounded-xl px-4 py-6 text-center">
                Agrega lo que el cliente va a pagar: una cita de &quot;Por cobrar&quot;, un servicio o un producto.
              </p>
            ) : lines.map(({ item, index, line }) => (
              <div key={`${item.type}-${item.id}-${index}`} className="rounded-xl border border-slate-200 px-3.5 py-3 space-y-2" data-cart-line={line?.description ?? item.label}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <span className="block text-sm font-semibold text-slate-800">{line?.description ?? item.label}</span>
                    <span className="block text-xs text-slate-500">
                      {item.type === 'appointment'
                        ? <>Cita {line?.appointment?.code ?? ''}{line?.appointment ? ` · ${line.appointment.starts_at.slice(11)}` : ''}{line?.professional ? <> · <ProfessionalLink id={line.professional.id}>{line.professional.name}</ProfessionalLink></> : ''}</>
                        : item.type === 'package'
                          ? (() => {
                            const plan = (board?.packages ?? []).find((p) => p.id === item.id);
                            const sessions = line?.package?.sessions ?? plan?.sessions;
                            const label = line?.package?.schedule_label ?? plan?.schedule_label;
                            return `Paquete${sessions ? ` · ${sessionsLabel(sessions)}` : ''}${label ? ` · ${label}` : ''}`;
                          })()
                          : `${item.type === 'service' ? 'Servicio' : 'Producto'} · ${money(line?.unit_price ?? item.unit_price, currency)} c/u`}
                    </span>
                  </div>
                  <div className="text-right shrink-0">
                    <span className="block text-sm font-bold text-slate-800 tabular-nums">{money(line?.total ?? item.unit_price * item.quantity, currency)}</span>
                  </div>
                </div>

                {item.type === 'package' && (board?.packages ?? []).find((p) => p.id === item.id)?.booking_mode === 'scheduled' && (
                  <div className={`flex items-center justify-between gap-2 rounded-lg px-2.5 py-2 text-xs ${item.details?.slots.length ? 'bg-[#84D0D9]/15 text-[#022340]' : 'bg-amber-50 text-amber-800'}`} data-package-schedule>
                    <span className="flex items-center gap-1.5 font-semibold">
                      <CalendarClock size={13} />
                      {item.details?.slots.length ? `${item.details.slots.length} de ${(board?.packages ?? []).find((p) => p.id === item.id)?.sessions ?? item.details.slots.length} con fecha` : 'Sin fechas todavía'}
                    </span>
                    {!cart.editBlocked && (
                      <button type="button" data-schedule-line disabled={!cart.customer} title={cart.customer ? undefined : 'Elige primero al cliente'} onClick={() => { setScheduleInitial(item.details ?? null); setScheduleIndex(index); }}
                        className="font-bold underline underline-offset-2 disabled:opacity-50 disabled:no-underline">
                        {item.details?.slots.length ? 'Cambiar fechas' : 'Agendar sesiones'}
                      </button>
                    )}
                  </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                  {item.type !== 'appointment' && item.type !== 'package' && (
                    <div className="flex items-center gap-1.5" data-quantity>
                      <button type="button" aria-label="Uno menos" data-qty-minus disabled={item.quantity <= 1 || !!cart.editBlocked} onClick={() => changeItem(index, { quantity: item.quantity - 1 })} className={stepButton}><Minus size={13} /></button>
                      <span className="w-7 text-center text-sm font-semibold tabular-nums" data-qty>{item.quantity}</span>
                      <button type="button" aria-label="Uno más" data-qty-plus disabled={item.quantity >= (item.type === 'service' ? 99 : 999) || !!cart.editBlocked} onClick={() => changeItem(index, { quantity: item.quantity + 1 })} className={stepButton}><Plus size={13} /></button>
                    </div>
                  )}
                  {item.type === 'service' && professionals.length > 0 && (
                    <div className="flex-1 min-w-[9rem]">
                      <Select
                        variant="chip"
                        ariaLabel="Quién lo atendió"
                        placeholder="¿Quién atendió? (opcional)"
                        value={item.professional_id ?? ''}
                        disabled={!!cart.editBlocked}
                        onChange={(e) => changeItem(index, { professional_id: String(e.target.value) || null })}
                        options={professionals.map((p) => ({ value: p.id, label: p.display_name }))}
                      />
                    </div>
                  )}
                  {!cart.editBlocked && (
                    <button type="button" title="Quitar del carrito" data-remove-line onClick={() => removeItem(index)} className="ml-auto p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                      <Trash2 size={15} />
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>

          {cart.items.length > 0 && !cart.editBlocked && (
            <div data-cart-coupon>
              <label className={labelClass} htmlFor="pos-coupon">Cupón</label>
              {cart.coupon ? (
                <div className="flex items-center justify-between gap-3 border border-emerald-200 bg-emerald-50/60 rounded-lg px-3.5 py-2.5">
                  <span className="flex items-center gap-1.5 text-sm font-bold text-emerald-800"><TicketPercent size={15} /> {cart.coupon}{preview?.coupon ? ` · ${preview.coupon.benefit}` : ''}</span>
                  <button type="button" data-coupon-remove onClick={() => setCart((current) => ({ ...current, coupon: '' }))} className="flex items-center gap-1 text-xs font-semibold text-slate-600 hover:text-red-600 transition-colors">
                    <X size={13} /> Quitar
                  </button>
                </div>
              ) : (
                <div className="flex items-center gap-2">
                  <input
                    id="pos-coupon"
                    maxLength={40}
                    placeholder="Código que trae el cliente"
                    className={`${inputClass} uppercase placeholder:normal-case`}
                    value={couponInput}
                    onChange={(e) => { setCouponInput(e.target.value.toUpperCase().replace(/\s/g, '')); setCouponError(''); }}
                    onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); applyCoupon(); } }}
                  />
                  <button type="button" data-coupon-apply disabled={!couponInput} onClick={applyCoupon} className="shrink-0 px-4 py-2.5 text-sm font-semibold border border-slate-200 rounded-lg text-slate-700 hover:bg-slate-50 transition-colors disabled:opacity-50">
                    Aplicar
                  </button>
                </div>
              )}
              {couponError && <p role="alert" data-coupon-error className="text-xs text-red-600 mt-1.5">{couponError}</p>}
            </div>
          )}

          {cart.items.length > 0 && (
            <div className="space-y-1.5 text-sm border-t border-dashed border-slate-200 pt-4" data-cart-totals>
              <div className="flex justify-between text-slate-500"><span>{lineCount} {lineCount === 1 ? 'ítem' : 'ítems'}</span><span className="tabular-nums">{totals ? money(totals.subtotal, totals.currency) : '—'}</span></div>
              {totals && totals.discount > 0 && (
                <div className="flex justify-between text-emerald-700"><span>Cupón {cart.coupon}</span><span className="tabular-nums">− {money(totals.discount, totals.currency)}</span></div>
              )}
              {totals && (
                <>
                  <div className="flex justify-between text-slate-500"><span>Op. gravada</span><span className="tabular-nums">{money(totals.total - totals.tax, totals.currency)}</span></div>
                  <div className="flex justify-between text-slate-500"><span>IGV {totals.tax_rate}%{totals.prices_include_tax ? ' (incluido en el precio)' : ''}</span><span className="tabular-nums">{money(totals.tax, totals.currency)}</span></div>
                </>
              )}
              <div className="flex items-center justify-between pt-2 border-t border-slate-200">
                <span className="font-bold text-[#022340]">Total</span>
                <span className="text-2xl font-black text-[#022340] tabular-nums" data-cart-total>{totals ? money(totals.total, totals.currency) : previewing ? 'Calculando...' : '—'}</span>
              </div>
            </div>
          )}

          {previewError && <p role="alert" data-preview-error className="text-sm text-red-600 bg-red-50 border border-red-100 rounded-lg px-3 py-2">{previewError}</p>}

          {cart.items.length > 0 && (
            <div className="flex flex-wrap items-center gap-3 pt-1">
              {!cart.editBlocked && canSave && (
                <button type="button" data-leave-pending disabled={!ready || saving} onClick={leavePending} className="px-4 py-2.5 text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 transition-colors disabled:opacity-50">
                  {cart.saleId ? 'Guardar cambios' : 'Dejar por cobrar'}
                </button>
              )}
              {can?.charge && (canSave || cart.editBlocked) && (
                <button type="button" data-charge-cart disabled={!ready || saving} onClick={charge} className="flex-1 px-5 py-2.5 text-sm font-bold bg-[#022340] text-white rounded-xl hover:bg-[#03315a] shadow-sm transition-colors disabled:opacity-50">
                  {saving ? 'Guardando...' : `Cobrar ${totals ? money(totals.total, totals.currency) : ''}`}
                </button>
              )}
            </div>
          )}
          {cart.items.length > 0 && !can?.charge && (
            <p className="text-xs text-slate-500">Tu cuenta deja ventas por cobrar; el cobro lo hace quien tiene permiso de cobrar.</p>
          )}
        </aside>
      </div>

      <ChargePanel saleId={charging} appointmentId={null} onClose={closeCharge} onChanged={setCharged} />

      <SchedulePanel
        isOpen={scheduleIndex !== null && scheduleSource !== null}
        onClose={() => setScheduleIndex(null)}
        source={scheduleSource}
        branchId={branchId || null}
        customerId={cart.customer?.id ?? null}
        initial={scheduleInitial}
        confirmLabel={(count) => `Guardar ${sessionsLabel(count)}`}
        onConfirm={saveSchedule}
      />

      <WalkInPanel
        isOpen={walkInOpen}
        onClose={() => setWalkInOpen(false)}
        branchId={branchId || null}
        services={board?.services ?? []}
        customer={cart.customer}
        customerLocked={Boolean(cart.customer && cart.items.length > 0)}
        onBooked={booked}
      />

      <AppModal
        isOpen={pendingSwitch !== null}
        type="confirm"
        title="¿Cambiar el carrito?"
        message={pendingSwitch?.message ?? ''}
        confirmText="Sí, cambiar"
        cancelText="No, dejarlo así"
        onConfirm={() => { const run = pendingSwitch?.run; setPendingSwitch(null); run?.(); }}
        onClose={() => setPendingSwitch(null)}
      />
    </div>
  );
}
