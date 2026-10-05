// apps/web/src/app/components/layout/Sidebar.tsx
'use client';

import { Dispatch, SetStateAction, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { motion, AnimatePresence } from 'framer-motion';
import { useAuth } from '@/hooks/useAuth';
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
  LucideIcon
} from 'lucide-react';

interface MenuItem {
  name: string;
  href: string;
  icon: LucideIcon;
  feature?: string;     
  requireAdmin?: boolean; 
  requireProfessional?: boolean;
}

interface MenuGroup {
  id: string;
  groupName?: string;
  groupIcon?: LucideIcon;
  requireAdmin?: boolean;
  feature?: string;
  items: MenuItem[];
}

const menuGroups: MenuGroup[] = [
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
      { name: 'Planes SaaS', href: '/admin/plans', icon: Ticket },
      { name: 'Contactos', href: '/admin/contacts', icon: Users },
    ]
  },
  {
    id: 'clinical',
    groupName: 'GESTIÓN CLÍNICA',
    groupIcon: Layers,
    items: [
      { name: 'Pacientes', href: '/patients', icon: Users },
      { name: 'Citas', href: '/appointments', icon: Ticket },
      { name: 'Servicios Médicos', href: '/services', icon: Wrench },
    ]
  },
  {
    id: 'crm',
    groupName: 'CRM',
    groupIcon: Briefcase,
    feature: 'crm',
    items: [
      { name: 'Oportunidades', href: '/opportunities', icon: Target },
      { name: 'Cotizaciones', href: '/quotes', icon: FileText },
    ]
  },
  {
    id: 'clinic-admin',
    groupName: 'ADMINISTRACIÓN DE SEDE',
    groupIcon: Building2,
    requireAdmin: true,
    items: [
      { name: 'Profesionales', href: '/professionals', icon: Contact },
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
  const { user, hasFeature, loading } = useAuth();

  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({
    'saas-admin': true,
    clinical: true,
    crm: true,
    'clinic-admin': true,
  });

  const toggleGroup = (groupId: string) => {
    setOpenGroups((prev) => ({ ...prev, [groupId]: !prev[groupId] }));
  };

  if (loading) return <div className="w-60 bg-[#031B33] h-screen" />;

  const isSuperAdmin = Boolean(
    user?.is_saas_admin || 
    user?.roles?.some((r: any) => r.slug === 'saas_admin')
  );

  const isProfessional = Boolean(
    user?.roles?.some((r: any) => r.slug === 'professional')
  );

  const hasNoPlan = !isSuperAdmin && !user?.plan_slug;

  return (
    <aside className={`bg-[#031B33] text-slate-300 flex flex-col h-screen border-r border-slate-800/80 transition-all duration-300 relative select-none ${isCollapsed ? 'w-16' : 'w-60'}`}>
      
      <div className={`p-4 flex items-center ${isCollapsed ? 'justify-center' : 'gap-3'}`}>
        <div className="w-8 h-8 bg-sky-500 rounded-lg flex items-center justify-center text-white font-black shadow-md shadow-sky-500/20 shrink-0">
          C
        </div>
        {!isCollapsed && (
          <span className="font-extrabold text-lg text-white tracking-wide truncate">
            CoreERP
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
                if (group.id === 'clinic-admin' || group.id === 'crm') return null;
              } else {
                if (group.requireAdmin && !user?.is_owner && !user?.roles?.some((r: any) => r.slug === 'admin')) {
                  if (group.id === 'clinic-admin') return null;
                }
              }
            }

            // Validar si el grupo requiere características (features) del plan
            if (group.feature && !hasFeature(group.feature)) return null;

            const visibleItems = group.items.filter((item) => {
              if (item.feature && !hasFeature(item.feature)) return false;
              return true;
            });

            if (visibleItems.length === 0) return null;

            if (!group.groupName) {
              return (
                <div key={group.id} className="space-y-1">
                  {visibleItems.map((item) => {
                    const Icon = item.icon;
                    const isActive = pathname === item.href;
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
            const containsActiveChild = visibleItems.some((item) => pathname === item.href);

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
                        const isActive = pathname === item.href;

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
        <Link
          href="/settings"
          title={isCollapsed ? 'Configuración' : undefined}
          className="group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold text-slate-300 hover:bg-slate-800/40 hover:text-slate-100"
        >
          <Settings className="w-4 h-4 shrink-0 text-slate-400 group-hover:text-slate-200" />
          {!isCollapsed && <span className="truncate">Configuración</span>}
        </Link>

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