'use client';

import { useEffect, useRef, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
import Select from '@/app/components/common/Select';
import Switch from '@/app/components/common/Switch';
import { usePlatform } from '@/hooks/usePlatform';
import { X, ArrowRight, User, Mail, Lock, Eye, EyeOff } from 'lucide-react';

interface Plan {
  currency_symbol?: string;
  id: string;
  name: string;
  price_monthly: number;
  features?: Record<string, any>;
}

interface BusinessType {
  id: string;
  slug: string;
  name: string;
  description: string | null;
}

declare global {
  interface Window {
    turnstile?: {
      render: (element: HTMLElement, options: Record<string, unknown>) => string;
      reset: (widgetId?: string) => void;
      remove: (widgetId?: string) => void;
    };
  }
}

const HEARD_FROM = [
  { value: 'google', label: 'Buscando en Google' },
  { value: 'facebook', label: 'Facebook o Instagram' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'youtube', label: 'YouTube' },
  { value: 'referido', label: 'Me lo recomendaron' },
  { value: 'evento', label: 'En una feria o evento' },
  { value: 'blog', label: 'Leyendo el blog' },
  { value: 'otro', label: 'Otro' },
];

const rucCheck = (ruc: string) => {
  const weights = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const sum = weights.reduce((total, weight, index) => total + weight * Number(ruc[index]), 0);
  const digit = (11 - (sum % 11)) % 10;
  return digit === Number(ruc[10]);
};

const TURNSTILE_SRC ='https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function loadTurnstile(): Promise<void> {
  if (window.turnstile) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${TURNSTILE_SRC}"]`);
    const script = existing ?? document.createElement('script');
    script.addEventListener('load', () => resolve());
    script.addEventListener('error', () => reject(new Error('turnstile')));
    if (!existing) {
      script.src = TURNSTILE_SRC;
      script.async = true;
      document.head.appendChild(script);
    }
  });
}

interface RegisterModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedPlan: Plan | null;
}

export function RegisterModal({ isOpen, onClose, selectedPlan }: RegisterModalProps) {
  const router = useRouter();
  const { platform } = usePlatform();
  const [modalStep, setModalStep] = useState<number>(1);
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [businessTypes, setBusinessTypes] = useState<BusinessType[]>([]);

  const [formData, setFormData] = useState({
    business_type_id: '',
    ruc: '',
    company_name: '',
    commercial_name: '',
    phone: '',
    address: '',
    department: 'Lima',
    district: '',
    first_name: '',
    last_name: '',
    email: '',
    password: '',
    coupon: '',
    heard_from: '',
    sample_data: false,
    accept_terms: false,
  });
  const [couponText, setCouponText] = useState('');
  const [couponError, setCouponError] = useState('');
  const [formToken, setFormToken] = useState('');
  const [siteKey, setSiteKey] = useState<string | null>(null);
  const [turnstileToken, setTurnstileToken] = useState('');
  const [turnstileDown, setTurnstileDown] = useState(false);
  const [trap, setTrap] = useState('');
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const turnstileBox = useRef<HTMLDivElement | null>(null);
  const turnstileId = useRef<string | null>(null);

  const loadFormToken = () => {
    api.get('/register-tenant/start')
      .then((res) => {
        setFormToken(res.data.form_token ?? '');
        setSiteKey(res.data.turnstile_site_key || null);
      })
      .catch(() => setFormToken(''));
  };

  useEffect(() => {
    if (isOpen) loadFormToken();
  }, [isOpen]);

  useEffect(() => {
    if (!isOpen || modalStep !== 2 || !siteKey) return;
    let cancelled = false;
    loadTurnstile()
      .then(() => {
        if (cancelled || !turnstileBox.current || !window.turnstile || turnstileId.current) return;
        turnstileId.current = window.turnstile.render(turnstileBox.current, {
          sitekey: siteKey,
          language: 'es',
          callback: (token: string) => setTurnstileToken(token),
          'expired-callback': () => setTurnstileToken(''),
          'error-callback': () => {
            setTurnstileToken('');
            setTurnstileDown(true);
          },
        });
      })
      .catch(() => setTurnstileDown(true));
    return () => {
      cancelled = true;
      if (turnstileId.current && window.turnstile) window.turnstile.remove(turnstileId.current);
      turnstileId.current = null;
      setTurnstileToken('');
    };
  }, [isOpen, modalStep, siteKey]);

  useEffect(() => {
    api.get('/business-types')
      .then((res) => {
        setBusinessTypes(res.data);
        const params = new URLSearchParams(window.location.search);
        const rubro = params.get('rubro');
        const match = rubro ? (res.data as BusinessType[]).find((type) => type.slug === rubro) : null;
        const coupon = (params.get('cupon') ?? '').toUpperCase();
        setFormData((current) => ({ ...current, business_type_id: current.business_type_id || match?.id || '', coupon: current.coupon || coupon }));
      })
      .catch((err) => console.error('Error al cargar rubros:', err));
  }, []);

  if (!isOpen || !selectedPlan) return null;

  const selectedBusinessType = businessTypes.find((type) => type.id === formData.business_type_id);

  const checkCoupon = async () => {
    setCouponText('');
    setCouponError('');
    if (!formData.coupon.trim()) return;
    try {
      const res = await api.post('/web/coupon', { code: formData.coupon, plan_id: selectedPlan.id });
      const preview = res.data.preview;
      setCouponText(preview
        ? `${res.data.text}: tu primer cobro mensual sería ${preview.symbol} ${preview.first_charge} en lugar de ${preview.symbol} ${preview.price}.`
        : `${res.data.text}.`);
    } catch (err: any) {
      setCouponError(err.response?.data?.errors?.code?.[0] ?? err.response?.data?.message ?? 'No pudimos revisar el cupón.');
    }
  };

  const handleNextStep = (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.business_type_id) {
      setErrorMessage('Elige el rubro de tu negocio.');
      return;
    }
    const ruc = formData.ruc.replace(/\D/g, '');
    const phone = formData.phone.replace(/[\s-]/g, '').replace(/^\+?51/, '');
    const problems: Record<string, string> = {};
    if (!/^(10|15|17|20)\d{9}$/.test(ruc)) problems.ruc = 'El RUC tiene 11 números y empieza con 10, 15, 17 o 20.';
    else if (!rucCheck(ruc)) problems.ruc = 'Revisa el RUC: el último número no coincide con los demás. Puede que se haya escrito mal un número.';
    if (formData.phone.trim() && !/^9\d{8}$/.test(phone)) problems.phone = 'Escribe un celular de 9 dígitos que empiece con 9.';
    setFieldErrors(problems);
    if (Object.keys(problems).length > 0) {
      setErrorMessage(null);
      return;
    }
    setFormData((current) => ({ ...current, ruc }));
    setErrorMessage(null);
    setModalStep(2);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!formData.accept_terms) {
      setFieldErrors({ accept_terms: 'Para crear tu cuenta acepta los términos y la política de privacidad.' });
      return;
    }
    if (siteKey && !turnstileToken && !turnstileDown) {
      setFieldErrors({ turnstile_token: 'Confirma que no eres un robot antes de continuar.' });
      return;
    }
    setIsLoading(true);
    setErrorMessage(null);
    setFieldErrors({});

    try {
      const ref = new URLSearchParams(window.location.search).get('ref');
      let utm: Record<string, string> = {};
      try { utm = JSON.parse(localStorage.getItem('site_utm') ?? '{}') ?? {}; } catch {}
      const { coupon, heard_from, ...rest } = formData;
      const response = await api.post('/register-tenant', {
        ...rest,
        plan_id: selectedPlan.id,
        ...(ref ? { ref } : {}),
        ...(coupon.trim() ? { coupon: coupon.trim() } : {}),
        ...(heard_from ? { heard_from } : {}),
        utm,
        form_token: formToken,
        website: trap,
        ...(turnstileToken ? { turnstile_token: turnstileToken } : {}),
      });

      const token = response.data.access_token || response.data.token;
      const userData = response.data.user || response.data.data;

      if (token) {
        localStorage.setItem('last_activity', String(Date.now()));
        localStorage.setItem('auth_token', token);
        api.defaults.headers.common['Authorization'] = `Bearer ${token}`;

        if (userData) {
          localStorage.setItem('user', JSON.stringify(userData));
        }
        router.push('/home');
      }
    } catch (err: any) {
      const data = err.response?.data ?? {};
      const errors: Record<string, string[]> = data.errors ?? {};
      const firstErrors: Record<string, string> = Object.fromEntries(Object.entries(errors).map(([field, list]) => [field, list?.[0] ?? '']));
      const shownHere = ['email', 'turnstile_token', 'accept_terms'];
      const other = Object.entries(firstErrors).find(([field]) => !shownHere.includes(field));
      setFieldErrors(firstErrors);
      if (Object.keys(firstErrors).length > 0) {
        setErrorMessage(other ? other[1] : null);
      } else {
        setErrorMessage(data.message || 'Ocurrió un error al procesar el registro.');
      }
      if (data.code === 'SIGNUP_FORM_EXPIRED') loadFormToken();
      if (turnstileId.current && window.turnstile) {
        window.turnstile.reset(turnstileId.current);
        setTurnstileToken('');
      }
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 bg-brand/60 backdrop-blur-xs flex items-center justify-center p-4 z-50">
      <div className="bg-white rounded-3xl max-w-2xl w-full p-6 sm:p-8 border border-slate-200 shadow-2xl space-y-5 relative max-h-[90vh] overflow-y-auto">
        
        <button
          type="button"
          onClick={onClose}
          className="absolute top-5 right-5 text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
        >
          <X className="w-5 h-5" />
        </button>

        <div>
          <h2 className="text-xl font-extrabold text-brand-ink">Crear cuenta empresarial</h2>
          <p className="text-xs text-slate-500 mt-0.5">Completa los datos para activar tu empresa.</p>
        </div>

        <div className="grid grid-cols-2 gap-2 pt-1">
          <div className={`h-1.5 rounded-full transition-all ${modalStep >= 1 ? 'bg-brand' : 'bg-slate-200'}`} />
          <div className={`h-1.5 rounded-full transition-all ${modalStep >= 2 ? 'bg-brand' : 'bg-slate-200'}`} />
        </div>

        <div className="bg-slate-50 border border-slate-200/80 rounded-2xl p-4 flex items-center justify-between text-xs">
          <div>
            <span className="text-slate-400 font-medium block">Plan seleccionado</span>
            <span className="font-extrabold text-brand-ink text-sm">{selectedPlan.name}</span>
          </div>
          <div className="text-right">
            <span className="text-slate-400 font-medium block">Precio</span>
            <span className="font-extrabold text-brand-ink text-sm">{selectedPlan.currency_symbol ?? 'S/'} {selectedPlan.price_monthly} / mes</span>
          </div>
        </div>

        {errorMessage && (
          <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
            {errorMessage}
          </div>
        )}

        {modalStep === 1 && (
          <form onSubmit={handleNextStep} className="space-y-4">
            <div className="space-y-1">
              <label className="text-[11px] font-bold text-brand-ink uppercase">Rubro del negocio</label>
              <Select
                ariaLabel="Rubro del negocio"
                placeholder="Selecciona tu rubro"
                clearable={false}
                value={formData.business_type_id}
                onChange={(e) => setFormData({ ...formData, business_type_id: String(e.target.value) })}
                options={businessTypes.map((type) => ({ value: type.id, label: type.name }))}
              />
              <p className="text-[11px] text-slate-500 font-medium">
                {selectedBusinessType?.description
                  ? `${selectedBusinessType.description} Tu cuenta empieza con servicios y una ficha de ejemplo de este rubro; luego puedes cambiarlos.`
                  : 'Define los nombres, los módulos y el catálogo inicial de tu cuenta.'}
              </p>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">RUC</label>
                <input
                  type="text"
                  placeholder="20123456786"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.ruc}
                  inputMode="numeric"
                  maxLength={11}
                  aria-invalid={fieldErrors.ruc ? true : undefined}
                  onChange={(e) => { setFormData({ ...formData, ruc: e.target.value.replace(/\D/g, '') }); setFieldErrors(({ ruc: _r, ...others }) => others); }}
                  required
                />
                {fieldErrors.ruc && <p className="text-[11px] font-semibold text-rose-600" data-ruc-error>{fieldErrors.ruc}</p>}
              </div>
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Razón Social</label>
                <input
                  type="text"
                  placeholder="Empresa Ejemplo SAC"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.company_name}
                  onChange={(e) => setFormData({ ...formData, company_name: e.target.value })}
                  required
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Nombre Comercial</label>
                <input
                  type="text"
                  placeholder="Mi Empresa"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.commercial_name}
                  onChange={(e) => setFormData({ ...formData, commercial_name: e.target.value })}
                />
              </div>
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Celular</label>
                <input
                  type="tel"
                  inputMode="tel"
                  aria-invalid={fieldErrors.phone ? true : undefined}
                  placeholder="999 999 999"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.phone}
                  onChange={(e) => { setFormData({ ...formData, phone: e.target.value }); setFieldErrors(({ phone: _p, ...others }) => others); }}
                />
                {fieldErrors.phone && <p className="text-[11px] font-semibold text-rose-600" data-phone-error>{fieldErrors.phone}</p>}
              </div>
            </div>

            <div className="space-y-1">
              <label className="text-[11px] font-bold text-brand-ink uppercase">Dirección</label>
              <input
                type="text"
                placeholder="Av. Principal 123"
                className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                value={formData.address}
                onChange={(e) => setFormData({ ...formData, address: e.target.value })}
              />
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Departamento</label>
                <Select
                  ariaLabel="Departamento"
                  clearable={false}
                  value={formData.department}
                  onChange={(e) => setFormData({ ...formData, department: String(e.target.value) })}
                  options={['Lima', 'Arequipa', 'Trujillo'].map((name) => ({ value: name, label: name }))}
                />
              </div>
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Distrito</label>
                <input
                  type="text"
                  placeholder="Los Olivos"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.district}
                  onChange={(e) => setFormData({ ...formData, district: e.target.value })}
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-2 pt-3">
              <button
                type="button"
                onClick={onClose}
                className="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 transition-colors cursor-pointer border border-slate-200 rounded-xl"
              >
                Cancelar
              </button>
              <button
                type="submit"
                className="bg-brand hover:bg-brand-hover text-white font-bold py-2 px-5 rounded-xl text-xs shadow-md transition-all cursor-pointer flex items-center gap-2"
              >
                <span>Continuar</span>
                <ArrowRight className="w-3.5 h-3.5 text-brand-soft" />
              </button>
            </div>
          </form>
        )}

        {modalStep === 2 && (
          <form onSubmit={handleSubmit} className="space-y-4">
            <h3 className="text-sm font-extrabold text-brand-ink pb-1">Datos del administrador</h3>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Nombres</label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <User className="w-4 h-4" />
                  </div>
                  <input
                    type="text"
                    placeholder="Juan"
                    className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                    value={formData.first_name}
                    onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                    required
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="text-[11px] font-bold text-brand-ink uppercase">Apellidos</label>
                <div className="relative">
                  <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                    <User className="w-4 h-4" />
                  </div>
                  <input
                    type="text"
                    placeholder="Pérez Gómez"
                    className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                    value={formData.last_name}
                    onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                    required
                  />
                </div>
              </div>
            </div>

            <div className="space-y-1">
              <label className="text-[11px] font-bold text-brand-ink uppercase">Correo Corporativo</label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                  <Mail className="w-4 h-4" />
                </div>
                <input
                  type="email"
                  placeholder="admin@minegocio.com"
                  aria-invalid={fieldErrors.email ? true : undefined}
                  className={`w-full pl-10 pr-4 py-2 bg-slate-50 border rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium ${fieldErrors.email ? 'border-rose-400' : 'border-slate-200'}`}
                  value={formData.email}
                  onChange={(e) => {
                    setFormData({ ...formData, email: e.target.value });
                    setFieldErrors((current) => {
                      const next = { ...current };
                      delete next.email;
                      return next;
                    });
                  }}
                  required
                />
              </div>
              {fieldErrors.email && <p className="text-[11px] font-semibold text-rose-600" data-email-error>{fieldErrors.email}</p>}
            </div>

            <div className="space-y-1">
              <label className="text-[11px] font-bold text-brand-ink uppercase">Contraseña</label>
              <div className="relative">
                <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                  <Lock className="w-4 h-4" />
                </div>
                <input
                  type={showPassword ? 'text' : 'password'}
                  placeholder="••••••••"
                  className="w-full pl-10 pr-10 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.password}
                  onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                  required
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 transition-colors cursor-pointer"
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>

            <div className="space-y-1" data-register-coupon>
              <label className="text-[11px] font-bold text-brand-ink uppercase" htmlFor="register-coupon">Cupón (opcional)</label>
              <div className="flex gap-2">
                <input
                  id="register-coupon"
                  type="text"
                  placeholder="Ej. FERIA20"
                  className="flex-1 min-w-0 px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 uppercase focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.coupon}
                  onChange={(e) => { setFormData({ ...formData, coupon: e.target.value.toUpperCase() }); setCouponText(''); setCouponError(''); }}
                />
                <button type="button" onClick={checkCoupon} disabled={!formData.coupon.trim()} className="px-4 py-2 text-xs font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 disabled:opacity-50 cursor-pointer">Aplicar</button>
              </div>
              {couponText && <p className="text-[11px] font-semibold text-emerald-700" data-coupon-ok>{couponText}</p>}
              {couponError && <p className="text-[11px] font-semibold text-rose-600" data-coupon-error>{couponError}</p>}
            </div>

            <div className="space-y-1" data-register-heard>
              <label className="text-[11px] font-bold text-brand-ink uppercase">¿Cómo nos conociste? (opcional)</label>
              <Select
                ariaLabel="¿Cómo nos conociste?"
                placeholder="Elige una opción"
                value={formData.heard_from}
                onChange={(e) => setFormData({ ...formData, heard_from: String(e.target.value) })}
                options={HEARD_FROM}
              />
            </div>

            <div className="flex items-start gap-2.5 text-xs text-slate-600" data-register-sample>
              <Switch checked={formData.sample_data} onChange={(value) => setFormData({ ...formData, sample_data: value })} label="Cargar datos de ejemplo" />
              <span><span className="font-semibold text-slate-700">Cargar datos de ejemplo</span><span className="block text-[11px] text-slate-500">Clientes y citas de prueba para ver cómo funciona. Los borras con un clic desde Primeros pasos.</span></span>
            </div>

            <div className="space-y-1" data-register-terms>
              <div className="flex items-start gap-2.5 text-xs text-slate-600">
                <Switch checked={formData.accept_terms} onChange={(value) => { setFormData({ ...formData, accept_terms: value }); setFieldErrors(({ accept_terms: _t, ...others }) => others); }} label="Acepto los términos y la política de privacidad" />
                <span>Acepto los <a href="/legal/terminos" target="_blank" rel="noopener noreferrer" className="font-semibold text-brand-accent hover:text-brand-ink">términos y condiciones</a> y la <a href="/legal/privacidad" target="_blank" rel="noopener noreferrer" className="font-semibold text-brand-accent hover:text-brand-ink">política de privacidad</a>.</span>
              </div>
              {fieldErrors.accept_terms && <p className="text-[11px] font-semibold text-rose-600" data-terms-error>{fieldErrors.accept_terms}</p>}
            </div>

            <div aria-hidden="true" className="absolute -left-[9999px] top-0 h-0 w-0 overflow-hidden">
              <label htmlFor="register-website">Sitio web</label>
              <input
                id="register-website"
                type="text"
                name="website"
                tabIndex={-1}
                autoComplete="off"
                value={trap}
                onChange={(e) => setTrap(e.target.value)}
              />
            </div>

            {siteKey && (
              <div className="space-y-1" data-register-turnstile>
                <div ref={turnstileBox} />
                {fieldErrors.turnstile_token && <p className="text-[11px] font-semibold text-rose-600">{fieldErrors.turnstile_token}</p>}
              </div>
            )}

            <p data-trial-note className="text-xs text-slate-600 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
              Empiezas con una prueba gratis, sin tarjeta. Cuando quieras quedarte, activas tu plan desde Mi empresa.
            </p>

            <div className="flex items-center justify-between pt-3">
              <button
                type="button"
                onClick={() => setModalStep(1)}
                className="px-4 py-2 text-xs font-semibold text-slate-600 hover:text-slate-800 transition-colors cursor-pointer border border-slate-200 rounded-xl"
              >
                Atrás
              </button>
              <button
                type="submit"
                disabled={isLoading}
                className="bg-brand hover:bg-brand-hover text-white font-bold py-2.5 px-5 rounded-xl text-xs shadow-md transition-all disabled:opacity-70 cursor-pointer flex items-center gap-2"
              >
                {isLoading ? (
                  <div className="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin" />
                ) : (
                  <>
                    <span>Finalizar y Acceder</span>
                    <ArrowRight className="w-3.5 h-3.5 text-brand-soft" />
                  </>
                )}
              </button>
            </div>
          </form>
        )}

      </div>
    </div>
  );
}