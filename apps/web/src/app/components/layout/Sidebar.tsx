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
  CalendarClock,
  CalendarOff,
  Hourglass,
  Repeat,
  Timer,
  Clock,
  DoorOpen,
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
    groupName: 'Plataforma',
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
    id: 'agenda',
    groupName: 'Agenda',
    groupIcon: CalendarDays,
    items: [
      { name: 'Agenda', href: '/agenda', icon: CalendarDays, module: 'agenda' },
      { name: 'Citas', href: '/appointments', icon: Ticket, module: 'agenda' },
      { name: 'Cola sin cita', href: '/modules/walkin', icon: ListOrdered, module: 'walkin' },
      { name: 'Lista de espera', href: '/waitlist', icon: Hourglass, module: 'agenda' },
      { name: 'Controles y seguimiento', href: '/recalls', icon: Repeat, module: 'agenda' },
      { name: 'Clases', href: '/modules/classes', icon: CalendarClock, module: 'classes' },
      { name: 'Bloqueos y ausencias', href: '/blocks', icon: CalendarOff, module: 'agenda' },
      { name: 'Tiempos de atención', href: '/attention-times', icon: Timer, module: 'agenda' },
    ]
  },
  {
    id: 'clinical',
    groupName: 'Atención',
    groupIcon: Layers,
    items: [
      { name: vocab.clients, href: '/customers', icon: Users, module: 'crm' },
      { name: 'Fichas de atención', href: '/records', icon: ClipboardList, module: 'records' },
    ]
  },
  {
    // Lo que la empresa ofrece y con qué lo atiende.
    id: 'catalog',
    groupName: 'Equipo y catálogo',
    groupIcon: Layers,
    items: [
      { name: 'Servicios', href: '/services', icon: Wrench, module: 'agenda' },
      { name: vocab.pros, href: '/professionals', icon: Contact, module: 'agenda', requireAdmin: true },
      { name: 'Horarios del equipo', href: '/schedules', icon: Clock, module: 'agenda', requireAdmin: true },
      { name: 'Salas y equipos', href: '/rooms', icon: DoorOpen, module: 'resources' },
    ]
  },
  {
    id: 'crm',
    groupName: 'Ventas',
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
    groupName: 'Fidelización',
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
    groupName: 'Tu empresa',
    groupIcon: Building2,
    requireAdmin: true,
    items: [
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
    agenda: true,
    clinical: true,
    crm: true,
    loyalty: true,
    'clinic-admin': true,
  });

  const toggleGroup = (groupId: string) => {
    setOpenGroups((prev) => ({ ...prev, [groupId]: !prev[groupId] }));
  };

  if (loading) return <div className="w-60 bg-[#0B192C] h-screen" />;

  // Equipo de la plataforma: administrador SaaS o soporte SaaS.
  const isSaasAdmin = Boolean(user?.is_saas_admin);
  const isSuperAdmin = Boolean(isSaasAdmin || user?.is_saas_support);

  const isProfessional = Boolean(
    user?.roles?.some((r: any) => r.slug === 'professional')
  );

  const hasNoPlan = !isSuperAdmin && !user?.plan_slug;

  return (
    <aside className={`bg-[#0B192C] text-slate-300 flex flex-col h-screen transition-all duration-300 relative select-none ${isCollapsed ? 'w-16' : 'w-60'}`}>
      
      <div className={`p-4 flex items-center ${isCollapsed ? 'justify-center' : 'gap-3'}`}>
        <div className="w-8 h-8 bg-gradient-to-br from-[#84D0D9] to-[#3F768C] rounded-xl flex items-center justify-center text-[#022340] font-black shrink-0">
          {platform.product_name.charAt(0).toUpperCase() || 'C'}
        </div>
        {!isCollapsed && (
          <span className="font-bold text-[17px] text-white tracking-tight truncate">
            {platform.product_name}
          </span>
        )}
      </div>

      {/* Caso: Usuario registrado pero SIN PLAN activo */}
      {hasNoPlan ? (
        <div className="flex-1 p-4 flex flex-col justify-center items-center text-center">
          {!isCollapsed ? (
            <div className="bg-white/5 border border-white/10 p-4 rounded-xl space-y-3">
              <span className="text-xs font-semibold text-[#84D0D9]">Sin Plan Activo</span>
              <p className="text-xs text-slate-300">¡Selecciona un plan para activar tus módulos del sistema!</p>
              <Link 
                href="/subscribe" 
                className="block w-full py-2 px-3 bg-[#84D0D9] hover:bg-[#9bdbe3] text-[#022340] font-bold text-xs rounded-lg transition-colors"
              >
                Elegir Plan
              </Link>
            </div>
          ) : (
            <Link href="/subscribe" title="Elige un plan" className="p-2 bg-[#84D0D9] text-[#022340] rounded-lg">
              <Ticket className="w-5 h-5" />
            </Link>
          )}
        </div>
      ) : (
        <nav className="flex-1 px-3 py-1 space-y-0.5 overflow-y-auto sidebar-scroll">
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
              // Ítems de administración dentro de un grupo que ve todo el equipo.
              if (item.requireAdmin && !user?.is_tenant_admin) return false;
              if (item.feature && !hasFeature(item.feature)) return false;
              if (item.module && !hasModule(item.module)) return false;
              return true;
            });

            if (visibleItems.length === 0) return null;

            if (!group.groupName) {
              return (
                <div key={group.id} className="space-y-0.5">
                  {visibleItems.map((item) => {
                    const Icon = item.icon;
                    const isActive = pathname === item.href || pathname.startsWith(`${item.href}/`);
                    return (
                      <Link
                        key={item.name}
                        href={item.href}
                        title={isCollapsed ? item.name : undefined}
                        className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-[13.5px] font-medium transition-colors ${
                          isCollapsed ? 'justify-center' : ''
                        } ${
                          isActive
                            ? 'bg-white/10 text-white'
                            : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
                        }`}
                      >
                        <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-[#84D0D9]' : 'text-slate-500 group-hover:text-slate-300'}`} />
                        {!isCollapsed && <span className="truncate">{item.name}</span>}
                      </Link>
                    );
                  })}
                </div>
              );
            }

            const isGroupOpen = openGroups[group.id] ?? true;
            const containsActiveChild = visibleItems.some((item) => pathname === item.href || pathname.startsWith(`${item.href}/`));

            return (
              <div key={group.id} className="space-y-0.5">
                {!isCollapsed && (
                  <button
                    onClick={() => toggleGroup(group.id)}
                    data-group={group.id}
                    className={`w-full flex items-center justify-between px-3 pt-4 pb-1.5 text-[12.5px] font-semibold transition-colors cursor-pointer group/title ${
                      !isGroupOpen && containsActiveChild ? 'text-[#84D0D9]' : 'text-slate-500 hover:text-slate-300'
                    }`}
                  >
                    <span>{group.groupName}</span>
                    <motion.div animate={{ rotate: isGroupOpen ? 0 : -90 }} transition={{ duration: 0.15 }}>
                      <ChevronDown className="w-3.5 h-3.5 text-slate-600 group-hover/title:text-slate-400" />
                    </motion.div>
                  </button>
                )}

                {group.groupName && isCollapsed && <div className="my-2 border-t border-white/5" />}

                <AnimatePresence initial={false}>
                  {(isGroupOpen || isCollapsed) && (
                    <motion.div
                      key={`content-${group.id}`}
                      initial={isCollapsed ? false : { height: 0, opacity: 0 }}
                      animate={{ height: 'auto', opacity: 1 }}
                      exit={isCollapsed ? undefined : { height: 0, opacity: 0 }}
                      transition={{ duration: 0.2 }}
                      className="overflow-hidden space-y-0.5"
                    >
                      {visibleItems.map((item) => {
                        const Icon = item.icon;
                        const isActive = pathname === item.href || pathname.startsWith(`${item.href}/`);

                        return (
                          <Link
                            key={item.name}
                            href={item.href}
                            title={isCollapsed ? item.name : undefined}
                            className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-[13.5px] font-medium transition-colors ${
                              isCollapsed ? 'justify-center py-2' : ''
                            } ${
                              isActive
                                ? 'bg-white/10 text-white'
                                : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
                            }`}
                          >
                            <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-[#84D0D9]' : 'text-slate-500 group-hover:text-slate-300'}`} />
                            {!isCollapsed && <span className="truncate">{item.name}</span>}
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
      <div className="p-3 border-t border-white/5 space-y-0.5">
        {/* Abajo va la configuración que le toca a cada quien: la de la plataforma
            para el administrador SaaS y la de su empresa para su administrador. */}
        {isSaasAdmin && (
          <Link
            href="/admin/configuration"
            title={isCollapsed ? 'Configuración' : undefined}
            className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-[13.5px] font-medium transition-colors ${isCollapsed ? 'justify-center' : ''} ${
              pathname.startsWith('/admin/configuration')
                ? 'bg-white/10 text-white'
                : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
            }`}
          >
            <Settings className={`w-4 h-4 shrink-0 ${pathname.startsWith('/admin/configuration') ? 'text-[#84D0D9]' : 'text-slate-500 group-hover:text-slate-300'}`} />
            {!isCollapsed && <span className="truncate">Configuración</span>}
          </Link>
        )}
        {user?.is_tenant_admin && (
          <Link
            href="/settings"
            title={isCollapsed ? 'Configuración' : undefined}
            className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-[13.5px] font-medium transition-colors ${isCollapsed ? 'justify-center' : ''} ${
              pathname.startsWith('/settings') ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
            }`}
          >
            <Settings className={`w-4 h-4 shrink-0 ${pathname.startsWith('/settings') ? 'text-[#84D0D9]' : 'text-slate-500 group-hover:text-slate-300'}`} />
            {!isCollapsed && <span className="truncate">Configuración</span>}
          </Link>
        )}

        <button
          onClick={() => setIsCollapsed(!isCollapsed)}
          className={`w-full flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-medium text-slate-500 hover:bg-white/5 hover:text-white ${
            isCollapsed ? 'justify-center' : ''
          }`}
        >
          {isCollapsed ? <PanelLeftOpen className="w-4 h-4 shrink-0" /> : <PanelLeftClose className="w-4 h-4 shrink-0" />}
        </button>
      </div>
    </aside>
  );
}