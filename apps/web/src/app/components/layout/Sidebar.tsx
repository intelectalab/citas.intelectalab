// apps/web/src/app/components/layout/Sidebar.tsx
'use client';

import { Dispatch, SetStateAction, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth, TenantVocabulary } from '@/hooks/useAuth';
import { useVocabulary } from '@/hooks/useVocabulary';
import { usePlatform } from '@/hooks/usePlatform';
import { 
  Home, 
  Users, 
  Building2, 
  Contact, 
  FileText, 
  Store, 
  Ticket, 
  Settings,
  PanelLeftClose,
  PanelLeftOpen,
  Target,
  ChevronDown,
  Briefcase,
  Layers,
  ShieldCheck,
  Wrench,
  ListOrdered,
  CalendarDays,
  ClipboardList,
  ShoppingCart,
  Package,
  Gift,
  Boxes,
  BadgeCheck,
  Megaphone,
  LucideIcon
} from 'lucide-react';

interface MenuItem {
  name: string;
  href: string;
  icon: LucideIcon;
  feature?: string;     
  requireAdmin?: boolean; 
  requireProfessional?: boolean;
  module?: string;
  // Solo administrador SaaS; el soporte SaaS no lo ve.
  saasAdminOnly?: boolean;
}

interface MenuGroup {
  id: string;
  groupName?: string;
  groupIcon?: LucideIcon;
  requireAdmin?: boolean;
  feature?: string;
  items: MenuItem[];
}

// El menú se arma con el vocabulario del rubro. Cada ítem con `module` solo se
// muestra si el tenant tiene ese módulo activo (plan, rubro y configuración).
// Los que apuntan a /modules/<clave> aún no tienen pantalla propia.
const buildMenuGroups = (vocab: TenantVocabulary): MenuGroup[] => [
  {
    id: 'home',
    items: [
      { name: 'Inicio', href: '/home', icon: Home },
    ]
  },
  {
    id: 'saas-admin',
    groupName: 'ADMINISTRACIÓN SAAS',
    groupIcon: ShieldCheck,
    requireAdmin: true,
    items: [
      { name: 'Tenants', href: '/admin/tenants', icon: Building2 },
      { name: 'Planes SaaS', href: '/admin/plans', icon: Ticket, saasAdminOnly: true },
      { name: 'Plantillas de rubros', href: '/admin/business-types', icon: Layers, saasAdminOnly: true },
      { name: 'Contactos', href: '/admin/contacts', icon: Users, saasAdminOnly: true },
    ]
  },
  {
    id: 'clinical',
    groupName: 'ATENCIÓN',
    groupIcon: Layers,
    items: [
      { name: vocab.clients, href: '/customers', icon: Users, module: 'crm' },
      { name: 'Citas', href: '/appointments', icon: Ticket, module: 'agenda' },
      { name: 'Cola sin cita', href: '/modules/walkin', icon: ListOrdered, module: 'walkin' },
      { name: 'Clases', href: '/modules/classes', icon: CalendarDays, module: 'classes' },
      { name: 'Servicios', href: '/services', icon: Wrench, module: 'agenda' },
      { name: 'Fichas de atención', href: '/modules/records', icon: ClipboardList, module: 'records' },
    ]
  },
  {
    id: 'crm',
    groupName: 'VENTAS',
    groupIcon: Briefcase,
    items: [
      { name: 'Oportunidades', href: '/opportunities', icon: Target, module: 'crm' },
      { name: 'Presupuestos', href: '/quotes', icon: FileText, module: 'quotes' },
      { name: 'Punto de venta', href: '/modules/pos', icon: ShoppingCart, module: 'pos' },
      { name: 'Inventario', href: '/modules/inventory', icon: Package, module: 'inventory' },
    ]
  },
  {
    id: 'loyalty',
    groupName: 'FIDELIZACIÓN',
    groupIcon: Gift,
    items: [
      { name: 'Paquetes', href: '/modules/packages', icon: Boxes, module: 'packages' },
      { name: 'Membresías', href: '/modules/memberships', icon: BadgeCheck, module: 'memberships' },
      { name: 'Puntos y gift cards', href: '/modules/loyalty', icon: Gift, module: 'loyalty' },
      { name: 'Marketing', href: '/modules/marketing', icon: Megaphone, module: 'marketing' },
    ]
  },
  {
    id: 'clinic-admin',
    groupName: 'ADMINISTRACIÓN DE SEDE',
    groupIcon: Building2,
    requireAdmin: true,
    items: [
      { name: vocab.pros, href: '/professionals', icon: Contact, module: 'agenda' },
      { name: 'Sucursales', href: '/branches', icon: Store },
      { name: 'Empresa', href: '/companies', icon: Building2 },
    ]
  }
];

interface SidebarProps {
  isCollapsed: boolean;
  setIsCollapsed: Dispatch<SetStateAction<boolean>>;
}

export default function Sidebar({ isCollapsed, setIsCollapsed }: SidebarProps) {
  const pathname = usePathname();
  const { user, hasFeature, hasModule, loading } = useAuth();
  const vocab = useVocabulary();
  const { platform } = usePlatform();
  const menuGroups = buildMenuGroups(vocab);

  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({
    'saas-admin': true,
    clinical: true,
    crm: true,
    loyalty: true,
    'clinic-admin': true,
  });

  const toggleGroup = (groupId: string) => {
    setOpenGroups((prev) => ({ ...prev, [groupId]: !prev[groupId] }));
  };

  if (loading) return <div className="w-60 bg-[#031B33] h-screen" />;

  // Equipo de la plataforma: administrador SaaS o soporte SaaS.
  const isSaasAdmin = Boolean(user?.is_saas_admin);
  const isSuperAdmin = Boolean(isSaasAdmin || user?.is_saas_support);

  const isProfessional = Boolean(
    user?.roles?.some((r: any) => r.slug === 'professional')
  );

  const hasNoPlan = !isSuperAdmin && !user?.plan_slug;

  return (
    <aside className={`bg-[#031B33] text-slate-300 flex flex-col h-screen border-r border-slate-800/80 transition-all duration-300 relative select-none ${isCollapsed ? 'w-16' : 'w-60'}`}>
      
      <div className={`p-4 flex items-center ${isCollapsed ? 'justify-center' : 'gap-3'}`}>
        <div className="w-8 h-8 bg-sky-500 rounded-lg flex items-center justify-center text-white font-black shadow-md shadow-sky-500/20 shrink-0">
          {platform.product_name.charAt(0).toUpperCase() || 'C'}
        </div>
        {!isCollapsed && (
          <span className="font-extrabold text-lg text-white tracking-wide truncate">
            {platform.product_name}
          </span>
        )}
      </div>

      {/* Caso: Usuario registrado pero SIN PLAN activo */}
      {hasNoPlan ? (
        <div className="flex-1 p-4 flex flex-col justify-center items-center text-center">
          {!isCollapsed ? (
            <div className="bg-slate-800/60 border border-slate-700/60 p-4 rounded-xl space-y-3">
              <span className="text-xs font-bold text-sky-400 uppercase tracking-widest">Sin Plan Activo</span>
              <p className="text-xs text-slate-300">¡Selecciona un plan para activar tus módulos del sistema!</p>
              <Link 
                href="/subscribe" 
                className="block w-full py-2 px-3 bg-sky-500 hover:bg-sky-400 text-white font-bold text-xs rounded-lg transition-colors shadow-lg shadow-sky-500/20"
              >
                Elegir Plan
              </Link>
            </div>
          ) : (
            <Link href="/subscribe" title="Elige un plan" className="p-2 bg-sky-500 text-white rounded-lg">
              <Ticket className="w-5 h-5" />
            </Link>
          )}
        </div>
      ) : (
        <nav className="flex-1 px-3 py-2 space-y-3 overflow-y-auto scrollbar-thin">
          {menuGroups.map((group) => {
            if (isSuperAdmin) {
              if (group.id !== 'saas-admin' && group.id !== 'home') return null;
            } else {
              if (group.id === 'saas-admin') return null;

              if (isProfessional) {
                if (group.id === 'clinic-admin' || group.id === 'crm' || group.id === 'loyalty') return null;
              } else {
                if (group.requireAdmin && !user?.is_owner && !user?.roles?.some((r: any) => r.slug === 'admin')) {
                  if (group.id === 'clinic-admin') return null;
                }
              }
            }

            // Validar si el grupo requiere características (features) del plan
            if (group.feature && !hasFeature(group.feature)) return null;

            const visibleItems = group.items.filter((item) => {
              if (item.saasAdminOnly && !isSaasAdmin) return false;
              if (item.feature && !hasFeature(item.feature)) return false;
              if (item.module && !hasModule(item.module)) return false;
              return true;
            });

            if (visibleItems.length === 0) return null;

            if (!group.groupName) {
              return (
                <div key={group.id} className="space-y-1">
                  {visibleItems.map((item) => {
                    const Icon = item.icon;
                    const isActive = pathname === item.href || pathname.startsWith(`${item.href}/`);
                    return (
                      <Link
                        key={item.name}
                        href={item.href}
                        title={isCollapsed ? item.name : undefined}
                        className={`group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition-all ${
                          isCollapsed ? 'justify-center' : ''
                        } ${
                          isActive
                            ? 'bg-sky-500/15 text-white border-l-4 border-sky-400 pl-2.5 shadow-sm'
                            : 'text-slate-300 hover:bg-slate-800/40 hover:text-slate-100 border-l-4 border-transparent'
                        }`}
                      >
                        <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-sky-400' : 'text-slate-400 group-hover:text-slate-200'}`} />
                        {!isCollapsed && <span className="truncate">{item.name}</span>}
                      </Link>
                    );
                  })}
                </div>
              );
            }

            const isGroupOpen = openGroups[group.id] ?? true;
            const GroupIcon = group.groupIcon;
            const containsActiveChild = visibleItems.some((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));

            return (
              <div key={group.id} className="space-y-1">
                {!isCollapsed && (
                  <button
                    onClick={() => toggleGroup(group.id)}
                    className={`w-full flex items-center justify-between px-2.5 py-1.5 rounded-lg text-xs font-bold tracking-wider uppercase transition-all cursor-pointer group/title ${
                      !isGroupOpen && containsActiveChild
                        ? 'bg-sky-500/15 text-white border-l-4 border-sky-400 pl-2 shadow-sm'
                        : 'text-slate-200 hover:bg-slate-800/50 hover:text-white border-l-4 border-transparent'
                    }`}
                  >
                    <div className="flex items-center gap-2">
                      {GroupIcon && <GroupIcon className="w-3.5 h-3.5 text-sky-400" />}
                      <span className="text-[11px] tracking-widest">{group.groupName}</span>
                    </div>
                    <motion.div animate={{ rotate: isGroupOpen ? 0 : -90 }} transition={{ duration: 0.15 }}>
                      <ChevronDown className="w-4 h-4 text-slate-400 group-hover/title:text-slate-200" />
                    </motion.div>
                  </button>
                )}

                {group.groupName && isCollapsed && <div className="my-2 border-t border-slate-800/60" />}

                <AnimatePresence initial={false}>
                  {(isGroupOpen || isCollapsed) && (
                    <motion.div
                      key={`content-${group.id}`}
                      initial={isCollapsed ? false : { height: 0, opacity: 0 }}
                      animate={{ height: 'auto', opacity: 1 }}
                      exit={isCollapsed ? undefined : { height: 0, opacity: 0 }}
                      transition={{ duration: 0.2 }}
                      className={`overflow-hidden space-y-1 ${!isCollapsed ? 'ml-3 pl-2 border-l border-slate-800/80' : ''}`}
                    >
                      {visibleItems.map((item) => {
                        const Icon = item.icon;
                        const isActive = pathname === item.href || pathname.startsWith(`${item.href}/`);

                        return (
                          <Link
                            key={item.name}
                            href={item.href}
                            title={isCollapsed ? item.name : undefined}
                            className={`group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition-all ${
                              isCollapsed ? 'justify-center py-2' : ''
                            } ${
                              isActive
                                ? 'bg-sky-500/15 text-white border-l-4 border-sky-400 pl-2.5 shadow-sm'
                                : 'text-slate-300 hover:bg-slate-800/40 hover:text-slate-100 border-l-4 border-transparent'
                            }`}
                          >
                            <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-sky-400' : 'text-slate-400 group-hover:text-slate-200'}`} />
                            {!isCollapsed && <span className="truncate tracking-tight">{item.name}</span>}
                          </Link>
                        );
                      })}
                    </motion.div>
                  )}
                </AnimatePresence>
              </div>
            );
          })}
        </nav>
      )}

      {/* Footer */}
      <div className="p-3 border-t border-slate-800/80 space-y-1">
        {/* Abajo va la configuración que le toca a cada quien: la de la plataforma
            para el administrador SaaS y la de su empresa para su administrador. */}
        {isSaasAdmin && (
          <Link
            href="/admin/configuration"
            title={isCollapsed ? 'Configuración' : undefined}
            className={`group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition-all ${isCollapsed ? 'justify-center' : ''} ${
              pathname.startsWith('/admin/configuration')
                ? 'bg-sky-500/15 text-white border-l-4 border-sky-400 pl-2.5 shadow-sm'
                : 'text-slate-300 hover:bg-slate-800/40 hover:text-slate-100 border-l-4 border-transparent'
            }`}
          >
            <Settings className={`w-4 h-4 shrink-0 ${pathname.startsWith('/admin/configuration') ? 'text-sky-400' : 'text-slate-400 group-hover:text-slate-200'}`} />
            {!isCollapsed && <span className="truncate">Configuración</span>}
          </Link>
        )}
        {user?.is_tenant_admin && (
          <Link
            href="/settings"
            title={isCollapsed ? 'Configuración' : undefined}
            className="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold text-slate-300 hover:bg-slate-800/40 hover:text-slate-100"
          >
            <Settings className="w-4 h-4 shrink-0 text-slate-400 group-hover:text-slate-200" />
            {!isCollapsed && <span className="truncate">Configuración</span>}
          </Link>
        )}

        <button
          onClick={() => setIsCollapsed(!isCollapsed)}
          className={`w-full flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-slate-400 hover:bg-slate-800/40 hover:text-white ${
            isCollapsed ? 'justify-center' : ''
          }`}
        >
          {isCollapsed ? <PanelLeftOpen className="w-4 h-4 shrink-0" /> : <PanelLeftClose className="w-4 h-4 shrink-0" />}
        </button>
      </div>
    </aside>
  );
}