'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { motion, AnimatePresence } from 'framer-motion';
import { TenantSelectModal, LoginAccount } from '@/features/auth/components/TenantSelectModal';
import { 
  Building2, 
  Lock, 
  Mail, 
  Eye, 
  EyeOff, 
  ArrowRight, 
  ShieldCheck, 
  Sparkles,
  UserPlus,
  User,
  LogIn,
  KeyRound
} from 'lucide-react';

export default function AuthPage() {
  const router = useRouter();
  const { login: authContextLogin } = useAuth();
  
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [idleNotice, setIdleNotice] = useState<string | null>(null);

  const [loginEmail, setLoginEmail] = useState('admin@luxuryspa.pe');

  useEffect(() => {
    const reason = localStorage.getItem('logout_reason');
    if (reason?.startsWith('idle:')) {
      const minutes = Number(reason.split(':')[1]);
      const span = minutes % 60 === 0 ? `${minutes / 60} ${minutes === 60 ? 'hora' : 'horas'}` : `${minutes} minutos`;
      setIdleNotice(`Tu sesión se cerró porque ${minutes === 60 ? 'pasó' : 'pasaron'} ${span} sin actividad. Vuelve a ingresar para continuar.`);
    }
    localStorage.removeItem('logout_reason');
  }, []);

  useEffect(() => {
    const switchEmail = sessionStorage.getItem('switch_account_email');
    if (switchEmail) {
      setLoginEmail(switchEmail);
      sessionStorage.removeItem('switch_account_email');
    }
  }, []);
  const [loginPassword, setLoginPassword] = useState('Admin123456!');

  const [regFirstName, setRegFirstName] = useState('');
  const [regLastName, setRegLastName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regTenantCode, setRegTenantCode] = useState('');

  const [accounts, setAccounts] = useState<LoginAccount[]>([]);
  const [loadingAccountId, setLoadingAccountId] = useState<string | null>(null);
  const [selectError, setSelectError] = useState<string | null>(null);

  const completeSession = async (data: any) => {
    const token = data.access_token || data.token;
    const userData = data.user || data.data;

    if (!token) {
      throw new Error('Respuesta inválida del servidor');
    }

    localStorage.setItem('last_activity', String(Date.now()));
    localStorage.setItem('auth_token', token);
    api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    if (userData) {
      localStorage.setItem('user', JSON.stringify(userData));
    }

    if (typeof authContextLogin === 'function') {
      try {
        await authContextLogin(token, userData);
      } catch {
      }
    }

    router.push(userData?.account_type === 'customer' ? '/portal' : '/home');
  };

  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await api.post('/login', {
        email: loginEmail,
        password: loginPassword
      });

      if (response.data.requires_tenant_selection) {
        setSelectError(null);
        setAccounts(response.data.accounts ?? []);
        return;
      }

      await completeSession(response.data);
    } catch (err: any) {
      setErrorMessage(
        err.response?.data?.message || err.message || 'Credenciales incorrectas o error al conectar con el servidor'
      );
    } finally {
      setIsLoading(false);
    }
  };

  const handleSelectAccount = async (account: LoginAccount) => {
    setLoadingAccountId(account.account_id);
    setSelectError(null);

    try {
      const response = await api.post('/login', {
        email: loginEmail,
        password: loginPassword,
        account_id: account.account_id,
      });

      await completeSession(response.data);
    } catch (err: any) {
      setSelectError(
        err.response?.data?.message || err.message || 'No se pudo ingresar a la empresa seleccionada'
      );
      setLoadingAccountId(null);
    }
  };

  const handleRegisterSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await api.post('/register-customer', {
        tenant_code: regTenantCode,
        first_name: regFirstName,
        last_name: regLastName,
        email: regEmail,
        password: regPassword,
      });

      await completeSession(response.data);
    } catch (err: any) {
      const errors = err.response?.data?.errors;
      const firstError = errors ? (Object.values(errors)[0] as string[])?.[0] : null;
      setErrorMessage(
        firstError || err.response?.data?.message || err.message || 'Error al completar el registro. Inténtalo nuevamente.'
      );
    } finally {
      setIsLoading(false);
    }
  };

  const switchMode = (mode: 'login' | 'register') => {
    setErrorMessage(null);
    setAuthMode(mode);
  };

  return (
    <div className="min-h-screen w-full bg-slate-100/70 flex items-center justify-center p-4 md:p-8 font-sans text-[#1F2937]">
      <div className="w-full max-w-5xl bg-white rounded-3xl border border-slate-200/80 shadow-xl overflow-hidden grid grid-cols-1 lg:grid-cols-12 min-h-[620px]">
        
        <div className="hidden lg:flex lg:col-span-5 bg-gradient-to-br from-[#022340] via-[#012840] to-[#011B30] text-white p-10 flex-col justify-between relative overflow-hidden">
          <div className="absolute top-0 right-0 w-64 h-64 bg-[#84D0D9]/10 rounded-full blur-3xl pointer-events-none" />
          <div className="absolute bottom-0 left-0 w-48 h-48 bg-[#3F768C]/20 rounded-full blur-2xl pointer-events-none" />

          <div className="relative z-10 space-y-6">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-2xl bg-[#84D0D9]/20 border border-[#84D0D9]/30 flex items-center justify-center text-[#84D0D9]">
                <Building2 className="w-5 h-5 stroke-[2.5]" />
              </div>
              <span className="text-xl font-black tracking-tight text-white">
                IntelectaLab_
              </span>
            </div>

            <div className="space-y-2 pt-4">
              <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-[#84D0D9]/15 border border-[#84D0D9]/30 text-[#84D0D9] text-[11px] font-bold tracking-wider uppercase">
                <Sparkles className="w-3.5 h-3.5" /> ERP Multi-Tenant
              </span>
              <h2 className="text-2xl font-extrabold tracking-tight text-white leading-snug">
                {authMode === 'login'
                  ? 'Gestiona tus operaciones en un solo lugar.'
                  : 'Crea tu cuenta con el código de tu empresa.'}
              </h2>
              <p className="text-slate-300 text-xs leading-relaxed font-medium">
                {authMode === 'login'
                  ? 'Acceso centralizado para finanzas, inventario, clientes y servicios especializados.'
                  : 'La empresa que te atiende te entrega un código de 8 dígitos para registrarte.'}
              </p>
            </div>
          </div>

          <div className="relative z-10 bg-white/5 border border-white/10 rounded-2xl p-4 backdrop-blur-xs space-y-3">
            <div className="flex items-center gap-2.5 text-xs font-semibold text-[#84D0D9]">
              <ShieldCheck className="w-4 h-4" />
              <span>
                {authMode === 'login' ? 'Todo tu negocio conectado' : 'Una cuenta por empresa'}
              </span>
            </div>
            <p className="text-[11px] text-slate-300 leading-normal">
              {authMode === 'login'
                ? 'Mantén el control de tus ventas, sucursales y citas en una sola plataforma.'
                : 'Puedes usar el mismo correo en varias empresas. Tus datos de cada una se mantienen separados.'}
            </p>
          </div>

          <div className="relative z-10 text-[11px] text-slate-400 font-medium flex items-center justify-between border-t border-white/10 pt-4">
            <span>IntelectaLab_ SaaS v1.0</span>
            <span className="text-slate-500">2026</span>
          </div>
        </div>

        <div className="lg:col-span-7 p-8 sm:p-12 flex flex-col justify-between bg-white relative overflow-hidden">
          
          <AnimatePresence mode="wait">
            {authMode === 'login' ? (
              <motion.div
                key="login-form"
                initial={{ opacity: 0, x: 20 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: -20 }}
                transition={{ duration: 0.25 }}
                className="flex flex-col justify-between h-full space-y-6"
              >
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2 lg:hidden">
                      <div className="w-8 h-8 rounded-xl bg-[#022340] text-[#84D0D9] flex items-center justify-center font-bold">
                        <Building2 className="w-4 h-4" />
                      </div>
                      <span className="font-extrabold text-[#022340] text-lg">IntelectaLab_</span>
                    </div>
                    <span className="text-xs font-semibold text-slate-400">Acceso Seguro</span>
                  </div>

                  <h1 className="text-2xl sm:text-3xl font-black text-[#022340] tracking-tight pt-2">
                    Iniciar Sesión
                  </h1>
                  <p className="text-xs sm:text-sm text-slate-500 font-medium">
                    Ingresa con tu correo y contraseña.
                  </p>
                </div>

                {idleNotice && !errorMessage && (
                  <div data-notice="idle" className="p-3 rounded-xl bg-[#84D0D9]/15 border border-[#84D0D9]/50 text-[#022340] text-xs font-medium">
                    {idleNotice}
                  </div>
                )}

                {errorMessage && (
                  <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
                    {errorMessage}
                  </div>
                )}

                <form onSubmit={handleLoginSubmit} className="space-y-4">
                  <div className="space-y-1.5">
                    <label className="block text-xs font-bold text-[#022340] uppercase tracking-wider">
                      Correo electrónico
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <Mail className="w-4 h-4" />
                      </div>
                      <input
                        type="email"
                        required
                        value={loginEmail}
                        onChange={(e) => setLoginEmail(e.target.value)}
                        placeholder="usuario@empresa.com"
                        className="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="space-y-1.5">
                    <div className="flex items-center justify-between">
                      <label className="block text-xs font-bold text-[#022340] uppercase tracking-wider">
                        Contraseña
                      </label>
                      <Link
                        href="/forgot-password"
                        className="text-xs font-semibold text-[#3F768C] hover:text-[#022340] transition-colors"
                      >
                        ¿Olvidaste tu contraseña?
                      </Link>
                    </div>
                    
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <Lock className="w-4 h-4" />
                      </div>
                      <input
                        type={showPassword ? 'text' : 'password'}
                        required
                        value={loginPassword}
                        onChange={(e) => setLoginPassword(e.target.value)}
                        placeholder="••••••••"
                        className="w-full pl-10 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword(!showPassword)}
                        className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 transition-colors"
                      >
                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                      </button>
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={isLoading}
                    className="w-full bg-[#022340] hover:bg-[#012840] text-white font-bold py-3 px-6 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center justify-center gap-2 text-xs sm:text-sm shadow-md transition-all hover:shadow-lg active:scale-[0.99] disabled:opacity-70 cursor-pointer"
                  >
                    {isLoading ? (
                      <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    ) : (
                      <>
                        <span>Ingresar al sistema</span>
                        <ArrowRight className="w-4 h-4 text-[#84D0D9]" />
                      </>
                    )}
                  </button>
                </form>

                <div className="border-t border-slate-100 pt-4 space-y-2">
                  <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
                    <span>¿Tienes un código de empresa?</span>
                    <button
                      type="button"
                      onClick={() => switchMode('register')}
                      className="inline-flex items-center gap-1 text-[#3F768C] hover:text-[#022340] font-bold transition-colors cursor-pointer"
                    >
                      <KeyRound className="w-3.5 h-3.5" />
                      Crear cuenta de cliente
                    </button>
                  </div>
                  <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
                    <span>¿No tienes una cuenta de empresa?</span>
                    <button 
                      type="button"
                      onClick={() => router.push('/register')}
                      className="inline-flex items-center gap-1 text-[#3F768C] hover:text-[#022340] font-bold transition-colors cursor-pointer"
                    >
                      <UserPlus className="w-3.5 h-3.5" />
                      Registrar mi empresa
                    </button>
                  </div>
                </div>
              </motion.div>
            ) : (
              <motion.div
                key="register-form"
                initial={{ opacity: 0, x: -20 }}
                animate={{ opacity: 1, x: 0 }}
                exit={{ opacity: 0, x: 20 }}
                transition={{ duration: 0.25 }}
                className="flex flex-col justify-between h-full space-y-4"
              >
                <div className="space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-semibold text-[#3F768C] uppercase tracking-wider">
                      Registro de Cliente
                    </span>
                  </div>

                  <h1 className="text-2xl font-black text-[#022340] tracking-tight">
                    Crea tu cuenta
                  </h1>
                  <p className="text-xs text-slate-500 font-medium">
                    Ingresa tus datos y el código que te entregó la empresa.
                  </p>
                </div>

                {errorMessage && (
                  <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
                    {errorMessage}
                  </div>
                )}

                <form onSubmit={handleRegisterSubmit} className="space-y-3">
                  <div className="space-y-1">
                    <label htmlFor="reg-tenant-code" className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                      Código de Empresa
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <Building2 className="w-4 h-4" />
                      </div>
                      <input
                        id="reg-tenant-code"
                        type="text"
                        inputMode="numeric"
                        autoComplete="off"
                        required
                        pattern="[0-9]{8}"
                        maxLength={8}
                        value={regTenantCode}
                        onChange={(e) => setRegTenantCode(e.target.value.replace(/[^0-9]/g, ''))}
                        placeholder="8 dígitos"
                        className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div className="space-y-1">
                      <label htmlFor="reg-first-name" className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                        Nombres
                      </label>
                      <div className="relative">
                        <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                          <User className="w-4 h-4" />
                        </div>
                        <input
                          id="reg-first-name"
                          type="text"
                          required
                          value={regFirstName}
                          onChange={(e) => setRegFirstName(e.target.value)}
                          placeholder="Juan"
                          className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                        />
                      </div>
                    </div>

                    <div className="space-y-1">
                      <label htmlFor="reg-last-name" className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                        Apellidos
                      </label>
                      <div className="relative">
                        <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                          <User className="w-4 h-4" />
                        </div>
                        <input
                          id="reg-last-name"
                          type="text"
                          required
                          value={regLastName}
                          onChange={(e) => setRegLastName(e.target.value)}
                          placeholder="Pérez"
                          className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                        />
                      </div>
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                      Correo Electrónico
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <Mail className="w-4 h-4" />
                      </div>
                      <input
                        type="email"
                        required
                        value={regEmail}
                        onChange={(e) => setRegEmail(e.target.value)}
                        placeholder="tucorreo@gmail.com"
                        className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                      Contraseña
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <Lock className="w-4 h-4" />
                      </div>
                      <input
                        type={showPassword ? 'text' : 'password'}
                        required
                        minLength={8}
                        value={regPassword}
                        onChange={(e) => setRegPassword(e.target.value)}
                        placeholder="••••••••"
                        className="w-full pl-10 pr-10 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                      <button
                        type="button"
                        onClick={() => setShowPassword(!showPassword)}
                        className="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 transition-colors"
                      >
                        {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                      </button>
                    </div>
                  </div>

                  <button
                    type="submit"
                    disabled={isLoading}
                    className="w-full bg-[#022340] hover:bg-[#012840] text-white font-bold py-3 px-6 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center justify-center gap-2 text-xs sm:text-sm shadow-md transition-all hover:shadow-lg active:scale-[0.99] disabled:opacity-70 cursor-pointer mt-2"
                  >
                    {isLoading ? (
                      <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    ) : (
                      <>
                        <span>Crear mi cuenta e Ingresar</span>
                        <ArrowRight className="w-4 h-4 text-[#84D0D9]" />
                      </>
                    )}
                  </button>
                </form>

                <div className="border-t border-slate-100 pt-3">
                  <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
                    <span>¿Ya tienes una cuenta?</span>
                    <button 
                      type="button"
                      onClick={() => switchMode('login')}
                      className="inline-flex items-center gap-1 text-[#3F768C] hover:text-[#022340] font-bold transition-colors cursor-pointer"
                    >
                      <LogIn className="w-3.5 h-3.5" />
                      Iniciar Sesión
                    </button>
                  </div>
                </div>
              </motion.div>
            )}
          </AnimatePresence>

        </div>
      </div>

      <TenantSelectModal
        isOpen={accounts.length > 0}
        accounts={accounts}
        loadingAccountId={loadingAccountId}
        errorMessage={selectError}
        onSelect={handleSelectAccount}
        onClose={() => setAccounts([])}
      />
    </div>
  );
}