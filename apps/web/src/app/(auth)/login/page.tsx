'use client';

import { useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/axios';
import { useAuth } from '@/hooks/useAuth'; // Ruta correcta
import { motion, AnimatePresence } from 'framer-motion';
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
  LogIn
} from 'lucide-react';

export default function AuthPage() {
  const router = useRouter();
  const { login: authContextLogin } = useAuth();
  
  // Estado para alternar entre 'login' y 'register'
  const [authMode, setAuthMode] = useState<'login' | 'register'>('login');
  const [showPassword, setShowPassword] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Campos de Login
  const [loginEmail, setLoginEmail] = useState('admin@luxuryspa.pe');
  const [loginPassword, setLoginPassword] = useState('Admin123456!');

  // Campos de Registro (Persona + Empresa)
  const [regName, setRegName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regCompanyName, setRegCompanyName] = useState('');

  // Manejador de Login
  const handleLoginSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await api.post('/login', { 
        email: loginEmail, 
        password: loginPassword 
      });

      const token = response.data.access_token || response.data.token;
      const userData = response.data.user || response.data.data;

      if (!token) {
        setErrorMessage('Respuesta inválida del servidor');
        return;
      }

      localStorage.setItem('auth_token', token);
      if (userData) {
        localStorage.setItem('user', JSON.stringify(userData));
      }

      if (typeof authContextLogin === 'function') {
        try {
          await authContextLogin(token, userData);
        } catch {
          // Si el login del contexto no requiere argumentos
        }
      }

      router.push('/home');
    } catch (err: any) {
      setErrorMessage(
        err.response?.data?.message || 'Credenciales incorrectas o error al conectar con el servidor'
      );
    } finally {
      setIsLoading(false);
    }
  };

  // Manejador de Registro
  const handleRegisterSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const response = await api.post('/register-tenant', {
        name: regName,
        email: regEmail,
        password: regPassword,
        company_name: regCompanyName,
      });

      const token = response.data.token || response.data.access_token;
      const userData = response.data.user;

      if (token) {
        localStorage.setItem('auth_token', token);
        
        // 👇 AGREGAR ESTA LÍNEA PARA CONFIGURAR AXIOS DE INMEDIATO
        api.defaults.headers.common['Authorization'] = `Bearer ${token}`;

        if (userData) {
          localStorage.setItem('user', JSON.stringify(userData));
        }

        if (typeof authContextLogin === 'function') {
          try {
            await authContextLogin(token, userData);
          } catch {
            // Manejo silencioso si difiere la firma
          }
        }

        router.push('/home');
      } else {
        setAuthMode('login');
        setLoginEmail(regEmail);
      }
    } catch (err: any) {
      setErrorMessage(
        err.response?.data?.message || 'Error al completar el registro. Inténtalo nuevamente.'
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
        
        {/* PANEL IZQUIERDO */}
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
                  : 'Empieza a hacer crecer tu empresa hoy.'}
              </h2>
              <p className="text-slate-300 text-xs leading-relaxed font-medium">
                {authMode === 'login'
                  ? 'Acceso centralizado para finanzas, inventario, clientes y servicios especializados.'
                  : 'Crea tu cuenta corporativa en segundos sin necesidad de tarjeta de crédito.'}
              </p>
            </div>
          </div>

          <div className="relative z-10 bg-white/5 border border-white/10 rounded-2xl p-4 backdrop-blur-xs space-y-3">
            <div className="flex items-center gap-2.5 text-xs font-semibold text-[#84D0D9]">
              <ShieldCheck className="w-4 h-4" />
              <span>
                {authMode === 'login' ? 'Todo tu negocio conectado' : 'Prueba libre y rápida'}
              </span>
            </div>
            <p className="text-[11px] text-slate-300 leading-normal">
              {authMode === 'login'
                ? 'Mantén el control de tus ventas, sucursales y citas en una sola plataforma.'
                : 'Registra tu negocio ahora. Podrás configurar tus planes o módulos en cualquier momento.'}
            </p>
          </div>

          <div className="relative z-10 text-[11px] text-slate-400 font-medium flex items-center justify-between border-t border-white/10 pt-4">
            <span>IntelectaLab_ SaaS v1.0</span>
            <span className="text-slate-500">2026</span>
          </div>
        </div>

        {/* PANEL DERECHO */}
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
                    Ingresa tus credenciales para acceder al panel de control de tu empresa.
                  </p>
                </div>

                {errorMessage && (
                  <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
                    {errorMessage}
                  </div>
                )}

                <form onSubmit={handleLoginSubmit} className="space-y-4">
                  <div className="space-y-1.5">
                    <label className="block text-xs font-bold text-[#022340] uppercase tracking-wider">
                      Correo corporativo
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

                <div className="border-t border-slate-100 pt-4">
                  <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
                    <span>¿No tienes una cuenta de empresa?</span>
                    <button 
                      type="button"
                      onClick={() => router.push('/register')} // Redirige a la página de planes y registro
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
                      Registro de Empresa
                    </span>
                    <span className="text-xs text-slate-400 font-medium">Paso 1 de 1</span>
                  </div>

                  <h1 className="text-2xl font-black text-[#022340] tracking-tight">
                    Crea tu cuenta
                  </h1>
                  <p className="text-xs text-slate-500 font-medium">
                    Ingresa tus datos personales y los de tu negocio para comenzar.
                  </p>
                </div>

                {errorMessage && (
                  <div className="p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
                    {errorMessage}
                  </div>
                )}

                <form onSubmit={handleRegisterSubmit} className="space-y-3">
                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                      Tu Nombre Completo
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <User className="w-4 h-4" />
                      </div>
                      <input
                        type="text"
                        required
                        value={regName}
                        onChange={(e) => setRegName(e.target.value)}
                        placeholder="Juan Pérez"
                        className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                      Nombre de la Empresa / Marca
                    </label>
                    <div className="relative">
                      <div className="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <Building2 className="w-4 h-4" />
                      </div>
                      <input
                        type="text"
                        required
                        value={regCompanyName}
                        onChange={(e) => setRegCompanyName(e.target.value)}
                        placeholder="Mi Negocio S.A.C."
                        className="w-full pl-10 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 font-medium focus:outline-hidden focus:border-[#3F768C] focus:bg-white focus:ring-2 focus:ring-[#3F768C]/20 transition-all"
                      />
                    </div>
                  </div>

                  <div className="space-y-1">
                    <label className="block text-[11px] font-bold text-[#022340] uppercase tracking-wider">
                      Correo Corporativo
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
                        placeholder="admin@minegocio.com"
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
                        <span>Crear mi Empresa e Ingresar</span>
                        <ArrowRight className="w-4 h-4 text-[#84D0D9]" />
                      </>
                    )}
                  </button>
                </form>

                <div className="border-t border-slate-100 pt-3">
                  <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
                    <span>¿Ya registraste tu empresa?</span>
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
    </div>
  );
}