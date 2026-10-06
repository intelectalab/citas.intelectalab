'use client';

import { useEffect, useRef, useState } from 'react';
import { Search, Bell, ChevronDown, LogOut, Moon, Sun, Users, LifeBuoy } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';

// Correo con el que la pantalla de ingreso abre tras "Cambiar de cuenta".
export const SWITCH_ACCOUNT_EMAIL_KEY = 'switch_account_email';

export default function Navbar() {
  const { user, logout, stopImpersonation } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const [isOpen, setIsOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  const userName = user?.first_name
  ? `${user.first_name} ${user.last_name || ''}`.trim()
  : 'Usuario';
  const initial = userName.charAt(0).toUpperCase();

  // Qué es esta cuenta: equipo de la plataforma, o su papel en la empresa.
  const roleLabel = user?.is_saas_admin
    ? 'Administrador SaaS'
    : user?.is_saas_support
      ? 'Soporte SaaS'
      : user?.is_owner
        ? 'Dueño'
        : user?.is_tenant_admin
          ? 'Administrador'
          : 'Personal';
  const context = user?.tenant?.name ?? (user?.is_saas_admin || user?.is_saas_support ? 'Plataforma SaaS' : '');

  useEffect(() => {
    if (!isOpen) return;

    const onClick = (event: MouseEvent) => {
      if (menuRef.current && !menuRef.current.contains(event.target as Node)) setIsOpen(false);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsOpen(false);
    };

    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [isOpen]);

  // Cierra esta sesión y deja el correo listo en la pantalla de ingreso: si ese
  // correo tiene cuenta en varias empresas, ahí se elige a cuál entrar.
  const handleSwitchAccount = async () => {
    if (user?.email) sessionStorage.setItem(SWITCH_ACCOUNT_EMAIL_KEY, user.email);
    await logout();
  };

  const itemClass =
    'w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors text-left cursor-pointer';

  return (
    <header className="h-16 bg-white border-b border-gray-200 px-6 flex items-center justify-between sticky top-0 z-20">

      <div className="flex items-center gap-2 bg-gray-100 px-3 py-1.5 rounded-full w-96 border border-transparent focus-within:border-[#3F768C] focus-within:bg-white transition-all">
        <Search size={18} className="text-gray-400" />
        <input
          type="text"
          placeholder="Buscar o preguntar..."
          className="bg-transparent border-none outline-none text-sm w-full text-gray-700 placeholder-gray-400"
        />
      </div>

      <div className="flex items-center gap-4">
        <button className="p-2 text-gray-600 hover:bg-gray-100 rounded-full transition-colors relative">
          <Bell size={20} />
          <span className="absolute top-1 right-1 w-2 h-2 bg-[#FF7A00] rounded-full"></span>
        </button>

        <div ref={menuRef} className="relative border-l border-gray-200 pl-4">
          <button
            type="button"
            onClick={() => setIsOpen((open) => !open)}
            aria-haspopup="menu"
            aria-expanded={isOpen}
            className="flex items-center gap-3 rounded-xl pl-1 pr-2 py-1 hover:bg-gray-100 transition-colors cursor-pointer"
          >
            <div className="w-8 h-8 rounded-full bg-[#022340] text-white flex items-center justify-center font-semibold text-sm">
              {initial}
            </div>
            <span className="text-left leading-tight">
              <span className="block text-sm font-semibold text-gray-700">{userName}</span>
              {context && <span className="block text-[11px] text-slate-400">{context}</span>}
            </span>
            <ChevronDown size={14} className={`text-slate-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
          </button>

          {isOpen && (
            <div role="menu" className="absolute right-0 top-full mt-2 w-72 bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-30">
              <div className="px-4 py-3.5 border-b border-slate-100">
                <span className="block text-sm font-bold text-slate-900">{userName}</span>
                <span className="block text-xs text-slate-500 truncate">{user?.email}</span>
                <span className="block text-xs text-slate-400 mt-0.5">{roleLabel}{context ? ` · ${context}` : ''}</span>
              </div>

              <div className="py-1.5 border-b border-slate-100">
                <button type="button" role="menuitem" onClick={toggleTheme} className={itemClass}>
                  {theme === 'dark' ? <Moon size={16} className="text-slate-400" /> : <Sun size={16} className="text-slate-400" />}
                  <span className="flex-1">Tema: {theme === 'dark' ? 'oscuro' : 'claro'}</span>
                  <span className="text-[11px] font-semibold text-slate-400">Cambiar</span>
                </button>
              </div>

              <div className="py-1.5">
                {user?.impersonator ? (
                  <button type="button" role="menuitem" onClick={stopImpersonation} className={itemClass}>
                    <LifeBuoy size={16} className="text-slate-400" /> Salir de la sesión de soporte
                  </button>
                ) : (
                  <button type="button" role="menuitem" onClick={handleSwitchAccount} className={itemClass}>
                    <Users size={16} className="text-slate-400" /> Cambiar de cuenta
                  </button>
                )}
                <button type="button" role="menuitem" onClick={logout} className={itemClass}>
                  <LogOut size={16} className="text-slate-400" /> Cerrar sesión
                </button>
              </div>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
