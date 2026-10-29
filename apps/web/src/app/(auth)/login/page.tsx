'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth';
import { usePlatform } from '@/hooks/usePlatform';
import { LookIcon } from '@/features/platform/lookIcons';
import { LOGIN_PATTERNS } from '@/features/platform/loginPatterns';
import Switch from '@/app/components/common/Switch';
import RichTextView from '@/app/components/common/RichTextView';
import { motion, AnimatePresence } from 'framer-motion';
import { TenantSelectModal, LoginAccount } from '@/features/auth/components/TenantSelectModal';
import { 
  Building2, 
  Lock, 
  Mail, 
  Eye, 
  EyeOff, 
  ArrowRight, 
  Sparkles,
  UserPlus,
  User,
  LogIn,
  KeyRound
} from 'lucide-react';

export default function AuthPage() {
  const router = useRouter();
  const { login: authContextLogin } = useAuth();
  const { platform } = usePlatform();
  const { brand, login: look } = platform.look;
  const panelBackground = look.background === 'image' && look.image_url
    ? { backgroundImage: `url("${look.image_url}")`, backgroundSize: 'cover', backgroundPosition: 'center' }
    : LOGIN_PATTERNS[look.background];
  
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [idleNotice, setIdleNotice] = useState<string | null>(null);

  const [loginEmail, setLoginEmail] = useState('');

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
  const [loginPassword, setLoginPassword] = useState('');

  const [regFirstName, setRegFirstName] = useState('');
  const [regLastName, setRegLastName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regTenantCode, setRegTenantCode] = useState('');
  const [regCompany, setRegCompany] = useState<{ code: string; name: string; privacy: string } | null>(null);
  const [regPrivacy, setRegPrivacy] = useState(false);
  const [regPrivacyOpen, setRegPrivacyOpen] = useState(false);

  useEffect(() => {
    if (regTenantCode.length !== 8) return;
    let active = true;
    api.get(`/signup-info/${regTenantCode}`)
      .then((res) => { if (active) setRegCompany({ code: regTenantCode, name: res.data.name, privacy: res.data.privacy?.html ?? '' }); })
      .catch(() => { if (active) setRegCompany(null); });
    return () => { active = false; };
  }, [regTenantCode]);

  const regInfo = regCompany && regCompany.code === regTenantCode ? regCompany : null;

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
    if (regInfo && !regPrivacy) {
      setErrorMessage(`Para crear tu cuenta, acepta la política de privacidad de ${regInfo.name}.`);
      return;
    }
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await api.post('/register-customer', {
        tenant_code: regTenantCode,
        first_name: regFirstName,
        last_name: regLastName,
        email: regEmail,
        password: regPassword,
        accept_privacy: regPrivacy,
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
        
        <div
          className={`hidden lg:flex lg:col-span-5 ${look.background === 'image' && look.image_url ? 'bg-brand' : 'bg-gradient-to-br from-brand via-brand-hover to-[#011B30]'} text-white p-10 flex-col justify-between relative overflow-hidden ${look.panel_side === 'right' ? 'lg:order-last' : ''}`}
          style={panelBackground}
          data-login-panel={look.background}
        >
          {look.background === 'image' && look.image_url && <div className="absolute inset-0 bg-gradient-to-t from-black/75 via-black/40 to-black/20 pointer-events-none" />}
          {look.background !== 'image' && (
            <>
              <div className="absolute top-0 right-0 w-64 h-64 bg-brand-soft/10 rounded-full blur-3xl pointer-events-none" />
              <div className="absolute bottom-0 left-0 w-48 h-48 bg-brand-accent/20 rounded-full blur-2xl pointer-events-none" />
            </>
          )}

          <div className="relative z-10 space-y-6">
            <div className="flex items-center gap-3">
              {(brand.logo_dark_url ?? brand.logo_url) ? (
                <img src={(brand.logo_dark_url ?? brand.logo_url)!} alt={platform.product_name} className="h-10 max-w-48 object-contain" data-login-logo />
              ) : (
                <>
                  <div className="min-w-10 h-10 px-1.5 rounded-2xl bg-brand-soft/20 border border-brand-soft/30 flex items-center justify-center text-brand-soft text-xs font-black">
                    {brand.short_name ?? <Building2 className="w-5 h-5 stroke-[2.5]" />}
                  </div>
                  <span className="text-xl font-black tracking-tight text-white" data-product-name>
                    {platform.product_name}
                  </span>
                </>
              )}
            </div>

            <div className="space-y-2 pt-4">
              {authMode === 'login' ? (
                <>
                  {look.tag && (
                    <span className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-soft/15 border border-brand-soft/30 text-brand-soft text-[11px] font-bold tracking-wider uppercase">
                      <Sparkles className="w-3.5 h-3.5" /> {look.tag}
                    </span>
                  )}
                  <h2 className="text-2xl font-extrabold tracking-tight text-white leading-snug" data-login-title>
                    {look.title}{look.highlight && <> <span className="text-brand-soft">{look.highlight}</span></>}
                  </h2>
                  {look.description && <p className="text-slate-300 text-xs leading-relaxed font-medium">{look.description}</p>}
                </>
              ) : (
                <>
                  <h2 className="text-2xl font-extrabold tracking-tight text-white leading-snug">Crea tu cuenta con el código de tu empresa.</h2>
                  <p className="text-slate-300 text-xs leading-relaxed font-medium">La empresa que te atiende te entrega un código de 8 dígitos para registrarte.</p>
                </>
              )}
            </div>
          </div>

          <div className="relative z-10 space-y-3" data-login-cards>
            {(authMode === 'login' ? look.cards : [{ icon: 'shield', title: 'Una cuenta por empresa', text: 'Puedes usar el mismo correo en varias empresas. Tus datos de cada una se mantienen separados.' }]).map((card, index) => (
              <div key={index} className="bg-white/5 border border-white/10 rounded-2xl p-4 backdrop-blur-xs space-y-2">
                <div className="flex items-center gap-2.5 text-xs font-semibold text-brand-soft">
                  <LookIcon name={card.icon} className="w-4 h-4" />
                  <span>{card.title}</span>
                </div>
                {card.text && <p className="text-[11px] text-slate-300 leading-normal">{card.text}</p>}
              </div>
            ))}
          </div>

          <div className="relative z-10 text-[11px] text-slate-400 font-medium flex items-center justify-between gap-2 border-t border-white/10 pt-4">
            <span>© {new Date().getFullYear()} {platform.product_name}</span>
            {brand.made_by && (
              brand.made_by_url
                ? <a href={brand.made_by_url} target="_blank" rel="noreferrer" className="text-slate-300 hover:text-white underline">{brand.made_by}</a>
                : <span className="text-slate-300">{brand.made_by}</span>
            )}
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
                      {brand.logo_url ? (
                        <img src={brand.logo_url} alt={platform.product_name} className="h-8 max-w-40 object-contain" />
                      ) : (
                        <>
                          <div className="min-w-8 h-8 px-1 rounded-xl bg-brand text-brand-soft flex items-center justify-center font-bold text-[10px]">
                            {brand.short_name ?? <Building2 className="w-4 h-4" />}
                          </div>
                          <span className="font-extrabold text-brand-ink text-lg">{platform.product_name}</span>
                        </>
                      )}
                    </div>
                    <span className="text-xs font-semibold text-slate-400">Acceso Seguro</span>
                  </div>

                  <h1 className="text-2xl sm:text-3xl font-black text-brand-ink tracking-tight pt-2">
                    Iniciar Sesión
                  </h1>
                  <p className="text-xs sm:text-sm text-slate-500 font-medium">
                    Ingresa con tu correo y contraseña.
                  </p>
                </div>

                {idleNotice && !errorMessage && (
                  <div data-notice="idle" className="p-3 rounded-xl bg-brand-soft/15 border border-brand-soft/50 text-brand-ink text-xs font-medium">
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
                    <label className="block text-xs font-bold text-brand-ink uppercase tracking-wider">
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
                        className="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="space-y-1.5">
                    <div className="flex items-center justify-between">
                      <label className="block text-xs font-bold text-brand-ink uppercase tracking-wider">
                        Contraseña
                      </label>
                      <Link
                        href="/forgot-password"
                        className="text-xs font-semibold text-brand-accent hover:text-brand-ink transition-colors"
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
                        className="w-full pl-10 pr-10 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-800 font-medium placeholder-slate-400 focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
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
                    className="w-full bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center justify-center gap-2 text-xs sm:text-sm shadow-md transition-all hover:shadow-lg active:scale-[0.99] disabled:opacity-70 cursor-pointer"
                  >
                    {isLoading ? (
                      <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    ) : (
                      <>
                        <span>Ingresar al sistema</span>
                        <ArrowRight className="w-4 h-4 text-brand-soft" />
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
                      className="inline-flex items-center gap-1 text-brand-accent hover:text-brand-ink font-bold transition-colors cursor-pointer"
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
                      className="inline-flex items-center gap-1 text-brand-accent hover:text-brand-ink font-bold transition-colors cursor-pointer"
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
                    <span className="text-xs font-semibold text-brand-accent uppercase tracking-wider">
                      Registro de Cliente
                    </span>
                  </div>

                  <h1 className="text-2xl font-black text-brand-ink tracking-tight">
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
                    <label htmlFor="reg-tenant-code" className="block text-[11px] font-bold text-brand-ink uppercase tracking-wider">
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
                        onChange={(e) => { setRegTenantCode(e.target.value.replace(/[^0-9]/g, '')); setRegPrivacy(false); setRegPrivacyOpen(false); }}
                        placeholder="8 dígitos"
                        className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
                      />
                    </div>
                    {regInfo && <p className="text-[11px] text-slate-500 font-medium" data-signup-company>Te registras en <span className="font-bold text-brand-ink">{regInfo.name}</span></p>}
                  </div>

                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div className="space-y-1">
                      <label htmlFor="reg-first-name" className="block text-[11px] font-bold text-brand-ink uppercase tracking-wider">
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
                          className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
                        />
                      </div>
                    </div>

                    <div className="space-y-1">
                      <label htmlFor="reg-last-name" className="block text-[11px] font-bold text-brand-ink uppercase tracking-wider">
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
                          className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
                        />
                      </div>
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-brand-ink uppercase tracking-wider">
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
                        className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-brand-ink uppercase tracking-wider">
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
                        className="w-full pl-10 pr-10 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-brand-accent focus:bg-white focus:ring-2 focus:ring-brand-accent/20 transition-all"
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

                  {regInfo && (
                    <div className="rounded-xl border border-slate-200 bg-slate-50 px-3.5 py-2.5 space-y-2" data-signup-privacy>
                      <div className="flex items-center justify-between gap-3">
                        <span className="text-xs text-slate-700 font-medium">
                          Acepto la política de privacidad de {regInfo.name}.{' '}
                          <button type="button" data-action="read-signup-privacy" onClick={() => setRegPrivacyOpen((open) => !open)} className="font-bold text-brand-accent hover:text-brand-ink">
                            {regPrivacyOpen ? 'Ocultarla' : 'Leerla'}
                          </button>
                        </span>
                        <Switch checked={regPrivacy} onChange={setRegPrivacy} label={`Acepto la política de privacidad de ${regInfo.name}`} />
                      </div>
                      {regPrivacyOpen && (
                        <div className="max-h-56 overflow-y-auto rounded-lg bg-white border border-slate-200 p-3 text-xs text-slate-700">
                          <RichTextView body={regInfo.privacy} className="[&_h2]:text-brand-ink [&_h3]:text-brand-ink" />
                        </div>
                      )}
                    </div>
                  )}

                  <button
                    type="submit"
                    disabled={isLoading}
                    className="w-full bg-brand hover:bg-brand-hover text-white font-bold py-3 px-6 rounded-tl-xl rounded-br-xl rounded-tr-sm rounded-bl-sm flex items-center justify-center gap-2 text-xs sm:text-sm shadow-md transition-all hover:shadow-lg active:scale-[0.99] disabled:opacity-70 cursor-pointer mt-2"
                  >
                    {isLoading ? (
                      <div className="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    ) : (
                      <>
                        <span>Crear mi cuenta e Ingresar</span>
                        <ArrowRight className="w-4 h-4 text-brand-soft" />
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
                      className="inline-flex items-center gap-1 text-brand-accent hover:text-brand-ink font-bold transition-colors cursor-pointer"
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