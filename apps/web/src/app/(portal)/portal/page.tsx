'use client';

import { useEffect } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { Building2, CalendarDays, Loader2, LogOut } from 'lucide-react';

export default function PortalPage() {
  const { user, loading, logout } = useAuth();
  const isStaff = !!user && user.account_type !== 'customer';

  // El portal es solo para cuentas de cliente; el personal vuelve a su panel.
  useEffect(() => {
    if (isStaff) {
      window.location.replace('/home');
    }
  }, [isStaff]);

  if (loading || !user || isStaff) {
    return (
      <div className="min-h-screen flex flex-col items-center justify-center gap-3">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
        <p className="text-sm font-medium text-slate-500">Cargando tu portal...</p>
      </div>
    );
  }

  const tenant = user.tenant;
  const clientWord = tenant?.vocabulary?.client ?? 'Cliente';

  return (
    <div className="min-h-screen bg-slate-100/70 font-sans text-[#1F2937]">
      <header className="bg-white border-b border-slate-200">
        <div className="max-w-4xl mx-auto px-4 sm:px-6 py-4 flex items-center justify-between gap-4">
          <div className="flex items-center gap-3 min-w-0">
            <span
              className="w-10 h-10 rounded-2xl flex items-center justify-center text-white shrink-0"
              style={{ backgroundColor: tenant?.color ?? '#022340' }}
            >
              <Building2 className="w-5 h-5" />
            </span>
            <div className="min-w-0">
              <p className="text-base font-black text-[#022340] tracking-tight truncate">{tenant?.name}</p>
              <p className="text-[11px] text-slate-500 font-medium truncate">
                Portal de {clientWord.toLowerCase()}
                {tenant?.business_type_name ? ` · ${tenant.business_type_name}` : ''}
              </p>
            </div>
          </div>
          <button
            type="button"
            onClick={logout}
            className="inline-flex items-center gap-2 text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors cursor-pointer shrink-0"
          >
            <LogOut className="w-4 h-4" />
            Cerrar sesión
          </button>
        </div>
      </header>

      <main className="max-w-4xl mx-auto px-4 sm:px-6 py-8 space-y-6">
        <div className="space-y-1">
          <h1 className="text-2xl sm:text-3xl font-black text-[#022340] tracking-tight">
            Hola, {user.first_name}
          </h1>
          <p className="text-sm text-slate-500 font-medium">
            Estás en tu cuenta de {tenant?.name}
            {user.customer?.code ? ` · ${clientWord} ${user.customer.code}` : ''}.
          </p>
        </div>

        <section className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-6 flex items-start gap-4">
          <span className="p-2.5 bg-[#84D0D9]/20 text-[#022340] rounded-xl shrink-0">
            <CalendarDays className="w-5 h-5" />
          </span>
          <div className="space-y-1">
            <h2 className="text-sm font-bold text-[#022340]">Reserva de citas</h2>
            <p className="text-xs text-slate-500 font-medium leading-relaxed">
              Pronto podrás reservar y revisar tus citas con {tenant?.name} desde aquí.
            </p>
          </div>
        </section>
      </main>
    </div>
  );
}
