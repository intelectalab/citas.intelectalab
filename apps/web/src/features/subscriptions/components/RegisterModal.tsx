'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
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

interface RegisterModalProps {
  isOpen: boolean;
  onClose: () => void;
  selectedPlan: Plan | null;
}

export function RegisterModal({ isOpen, onClose, selectedPlan }: RegisterModalProps) {
  const router = useRouter();
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
  });

  useEffect(() => {
    api.get('/business-types')
      .then((res) => setBusinessTypes(res.data))
      .catch((err) => console.error('Error al cargar rubros:', err));
  }, []);

  if (!isOpen || !selectedPlan) return null;

  const selectedBusinessType = businessTypes.find((type) => type.id === formData.business_type_id);

  const handleNextStep = (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setModalStep(2);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await api.post('/register-tenant', {
        ...formData,
        plan_id: selectedPlan.id,
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
      setErrorMessage(
        err.response?.data?.message || 'Ocurrió un error al procesar el registro.'
      );
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
              <select
                className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                value={formData.business_type_id}
                onChange={(e) => setFormData({ ...formData, business_type_id: e.target.value })}
                required
              >
                <option value="" disabled>Selecciona tu rubro</option>
                {businessTypes.map((type) => (
                  <option key={type.id} value={type.id}>{type.name}</option>
                ))}
              </select>
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
                  placeholder="20123456789"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.ruc}
                  onChange={(e) => setFormData({ ...formData, ruc: e.target.value })}
                  required
                />
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
                <label className="text-[11px] font-bold text-brand-ink uppercase">Teléfono</label>
                <input
                  type="text"
                  placeholder="+51 999 999 999"
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.phone}
                  onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                />
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
                <select
                  className="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.department}
                  onChange={(e) => setFormData({ ...formData, department: e.target.value })}
                >
                  <option value="Lima">Lima</option>
                  <option value="Arequipa">Arequipa</option>
                  <option value="Trujillo">Trujillo</option>
                </select>
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
                  className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 focus:outline-hidden focus:border-brand-accent focus:bg-white font-medium"
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  required
                />
              </div>
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