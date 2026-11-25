'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { ChevronDown, LogOut, Menu, Moon, Sun, Users, LifeBuoy, UserRound } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { useTheme } from '@/hooks/useTheme';
import NotificationsBell from '@/features/notifications/components/NotificationsBell';
import HelpButton from '@/features/help/components/HelpButton';
import GlobalSearch from './GlobalSearch';
import QuickCreate from './QuickCreate';
import BranchFocus from './BranchFocus';

export const SWITCH_ACCOUNT_EMAIL_KEY = 'switch_account_email';

export default function Navbar({ onOpenMenu }: { onOpenMenu?: () => void }) {
  const { user, logout, stopImpersonation } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const [isOpen, setIsOpen] = useState(false);
  const menuRef = useRef<HTMLDivElement>(null);

  const userName = user?.first_name
  ? `${user.first_name} ${user.last_name || ''}`.trim()
  : 'Usuario';
  const initial = userName.charAt(0).toUpperCase();

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
      if ((event.target as Element)?.closest?.('[data-floating]')) return;
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

  const handleSwitchAccount = async () => {
    if (user?.email) sessionStorage.setItem(SWITCH_ACCOUNT_EMAIL_KEY, user.email);
    await logout();
  };

  const itemClass =
    'w-full flex items-center gap-3 px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50 transition-colors text-left cursor-pointer';

  return (
    <header className="h-16 bg-white border-b border-gray-200 px-3 sm:px-6 flex items-center justify-between gap-2 sm:gap-0 sticky top-0 z-20">

      {onOpenMenu && (
        <button type="button" aria-label="Abrir el menú" data-open-menu onClick={onOpenMenu} className="lg:hidden p-2 -ml-1 rounded-xl text-slate-600 hover:bg-gray-100">
          <Menu size={22} />
        </button>
      )}

      {user?.tenant ? <GlobalSearch /> : <div className="hidden md:block w-96" />}

      <div className="flex items-center gap-2 sm:gap-4 ml-auto md:ml-0">
        <BranchFocus />
        <QuickCreate />
        <HelpButton />
        <NotificationsBell />

        <div ref={menuRef} className="relative border-l border-gray-200 pl-2 sm:pl-4">
          <button
            type="button"
            onClick={() => setIsOpen((open) => !open)}
            aria-haspopup="menu"
            aria-expanded={isOpen}
            className="flex items-center gap-3 rounded-xl pl-1 pr-2 py-1 hover:bg-gray-100 transition-colors cursor-pointer"
          >
            <div className="w-8 h-8 rounded-full bg-brand text-white flex items-center justify-center font-semibold text-sm">
              {initial}
            </div>
            <span className="hidden sm:block text-left leading-tight">
              <span className="block text-sm font-semibold text-gray-700">{userName}</span>
              {context && <span className="block text-[11px] text-slate-400">{context}</span>}
            </span>
            <ChevronDown size={14} className={`text-slate-400 transition-transform ${isOpen ? 'rotate-180' : ''}`} />
          </button>

          {isOpen && (
            <div role="menu" className="absolute right-0 top-full mt-2 w-72 max-w-[calc(100vw-1.5rem)] bg-white border border-slate-200 rounded-2xl shadow-xl overflow-hidden z-30">
              <div className="px-4 py-3.5 border-b border-slate-100">
                <span className="block text-sm font-bold text-slate-900">{userName}</span>
                <span className="block text-xs text-slate-500 truncate">{user?.email}</span>
                <span className="block text-xs text-slate-400 mt-0.5">{roleLabel}{context ? ` · ${context}` : ''}</span>
              </div>

              {(user?.branch_choices?.length ?? 0) > 1 && (
                <div className="sm:hidden px-4 py-3 border-b border-slate-100">
                  <BranchFocus inline />
                </div>
              )}

              <div className="py-1.5 border-b border-slate-100">
                {!user?.impersonator && (
                  <Link href="/account" role="menuitem" data-action="my-account" onClick={() => setIsOpen(false)} className={itemClass}>
                    <UserRound size={16} className="text-slate-400" /> Mi cuenta
                  </Link>
                )}
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
