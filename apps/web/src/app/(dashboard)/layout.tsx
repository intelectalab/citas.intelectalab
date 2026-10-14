'use client';

import { useState, useEffect } from 'react';
import Sidebar from '../components/layout/Sidebar';
import Navbar from '../components/layout/Navbar';
import ScreenGate from '../components/layout/ScreenGate';
import { AuthProvider, useAuth } from '@/hooks/useAuth';
import { PlatformProvider } from '@/hooks/usePlatform';
import { useTheme } from '@/hooks/useTheme';

function StaffOnly({ children }: { children: React.ReactNode }) {
  const { user } = useAuth();
  const isCustomer = user?.account_type === 'customer';

  useEffect(() => {
    if (isCustomer) {
      window.location.replace('/portal');
    }
  }, [isCustomer]);

  if (isCustomer) return null;
  return <>{children}</>;
}

function ImpersonationBanner() {
  const { user, stopImpersonation } = useAuth();

  if (!user?.impersonator) return null;

  return (
    <div className="bg-amber-400 text-[#0B1B2B] px-6 py-2 flex items-center justify-between gap-4 text-xs font-semibold">
      <span>
        Sesión de soporte: estás dentro de <strong>{user.tenant?.name}</strong> como su administrador
        {user.impersonator.name ? ` (${user.impersonator.name})` : ''}. Lo que hagas queda registrado.
      </span>
      <button
        type="button"
        onClick={stopImpersonation}
        className="shrink-0 px-3 py-1 rounded-lg bg-[#022340] text-white font-bold hover:bg-[#03315a] transition-colors cursor-pointer"
      >
        Salir y volver a la consola
      </button>
    </div>
  );
}

export default function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    setMounted(true);
  }, []);

  useTheme();

  return (
    <AuthProvider>
      <PlatformProvider>
      <StaffOnly>
      <div className={`flex h-screen overflow-hidden ${mounted ? 'bg-[#0B192C]' : 'bg-[#0B192C]'}`}>
        <Sidebar isCollapsed={isCollapsed} setIsCollapsed={setIsCollapsed} />

        <div className="theme-scope flex-1 flex flex-col min-w-0 bg-[#F2F2F2] rounded-tl-3xl overflow-hidden shadow-inner">
          <ImpersonationBanner />
          <Navbar />
          <main className="flex-1 overflow-y-auto p-6">
            <ScreenGate>{children}</ScreenGate>
          </main>
        </div>
      </div>
      </StaffOnly>
      </PlatformProvider>
    </AuthProvider>
  );
}