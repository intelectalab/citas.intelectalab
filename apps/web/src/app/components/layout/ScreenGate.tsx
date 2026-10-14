'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { ShieldAlert } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';

// Permiso que hace falta para abrir cada pantalla. Debe coincidir con el `perm`
// de su ítem en el menú. `exact` protege solo esa dirección y no las que cuelgan
// de ella (la página de un profesional se abre desde su nombre en cualquier pantalla).
const SCREENS: { path: string; perm: string; exact?: boolean }[] = [
  { path: '/agenda', perm: 'agenda.view' },
  { path: '/appointments', perm: 'agenda.view' },
  { path: '/waitlist', perm: 'waitlist.view' },
  { path: '/recalls', perm: 'recalls.view' },
  { path: '/blocks', perm: 'blocks.view' },
  { path: '/attention-times', perm: 'attention.view' },
  { path: '/customers', perm: 'customers.view' },
  { path: '/records', perm: 'records.view' },
  { path: '/services', perm: 'services.view' },
  { path: '/professionals', perm: 'professionals.view', exact: true },
  { path: '/schedules', perm: 'professionals.view' },
  { path: '/rooms', perm: 'rooms.view' },
  { path: '/sales', perm: 'sales.view' },
  { path: '/payments', perm: 'sales.view' },
  { path: '/cash', perm: 'cash.view' },
  { path: '/coupons', perm: 'coupons.view' },
  { path: '/branches', perm: 'branches.view' },
  { path: '/companies', perm: 'company.view' },
  { path: '/users', perm: 'users.view' },
  { path: '/settings', perm: 'settings.view' },
];

// Si alguien escribe la dirección de una pantalla que su rol no le permite ver,
// en vez de la pantalla ve una explicación. La API rechaza lo mismo por su lado.
export default function ScreenGate({ children }: { children: React.ReactNode }) {
  const pathname = usePathname();
  const { user, loading, can } = useAuth();

  const screen = SCREENS.find((s) => pathname === s.path || (!s.exact && pathname.startsWith(`${s.path}/`)));
  // El equipo de la plataforma no tiene rol en una empresa; y mientras la sesión no
  // traiga los permisos no se bloquea nada.
  const isStaffOfTenant = Boolean(user?.tenant) && !user?.is_saas_admin && !user?.is_saas_support;

  if (!screen || loading || !isStaffOfTenant || !user?.permissions || can(screen.perm)) {
    return <>{children}</>;
  }

  return (
    <div className="max-w-2xl mx-auto pt-10 font-sans" data-screen-denied={screen.perm}>
      <div className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-8 flex items-start gap-4">
        <span className="p-3 bg-[#84D0D9]/20 text-[#022340] rounded-2xl shrink-0"><ShieldAlert size={24} /></span>
        <div className="space-y-2">
          <h1 className="text-xl font-black text-[#022340] tracking-tight">Tu cuenta no tiene acceso a esta pantalla</h1>
          <p className="text-sm text-slate-600">
            {user.role_name ? `Tu rol (${user.role_name}) no la incluye. ` : ''}Si la necesitas para tu trabajo, pídesela a quien administra tu empresa: puede encendértela desde Usuarios y roles.
          </p>
          <Link href="/home" className="inline-flex items-center gap-2 pt-2 text-sm font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
            Volver al inicio
          </Link>
        </div>
      </div>
    </div>
  );
}
