'use client';

import { Dispatch, SetStateAction, useEffect, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { motion, AnimatePresence } from 'framer-motion';
import { usePlatform } from '@/hooks/usePlatform';
import {
  Ticket,
  PanelLeftClose,
  PanelLeftOpen,
  ChevronDown,
  X,
} from 'lucide-react';
import { matchesPath, recordRecent, useMenu } from './menu';
import { useAuth } from '@/hooks/useAuth';
import { initials, isLight, resolveColors } from '@/lib/brand';

interface SidebarProps {
  isCollapsed: boolean;
  setIsCollapsed: Dispatch<SetStateAction<boolean>>;
  mobileOpen?: boolean;
  onClose?: () => void;
}

export default function Sidebar({ isCollapsed, setIsCollapsed, mobileOpen = false, onClose }: SidebarProps) {
  const pathname = usePathname();

  useEffect(() => {
    onClose?.();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname]);
  const { platform } = usePlatform();
  const { groups, footer, hasNoPlan, loading, userId } = useMenu();
  const { user } = useAuth();

  useEffect(() => {
    if (loading) return;
    const items = [...groups.flatMap((g) => g.items), ...footer];
    const current = items.filter((item) => matchesPath(item.href, pathname)).sort((a, b) => b.href.length - a.href.length)[0];
    if (current) recordRecent(userId, current.href);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname, loading, userId]);

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

  if (loading) return <div className="hidden lg:block w-60 bg-brand-menu h-screen" />;

  const platformBrand = platform.look.brand;
  const branding = user?.tenant ? user.tenant.branding : platformBrand;
  const lightMenu = isLight(resolveColors(branding).menu);
  const pickLogo = (b: { logo_url: string | null; logo_dark_url: string | null } | null | undefined) =>
    (lightMenu ? b?.logo_url ?? b?.logo_dark_url : b?.logo_dark_url ?? b?.logo_url) ?? null;
  const tenantLogo = user?.tenant ? pickLogo(branding) : null;
  const tenantMark = Boolean(user?.tenant && (tenantLogo || branding?.short_name));
  const logo = tenantMark ? tenantLogo : pickLogo(platformBrand);
  const mark = tenantMark ? branding?.short_name || initials(user!.tenant!.name) : platformBrand.short_name || platform.product_name.charAt(0).toUpperCase() || 'C';
  const title = tenantMark ? user!.tenant!.name : platform.product_name;

  return (
    <aside data-menu-tone={lightMenu ? 'light' : 'dark'} data-mobile-open={mobileOpen ? 'true' : 'false'} className={`app-menu bg-brand-menu text-slate-300 flex flex-col h-screen transition-all duration-300 select-none fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] shadow-2xl lg:shadow-none lg:max-w-none lg:relative lg:inset-auto lg:z-auto lg:translate-x-0 ${mobileOpen ? 'translate-x-0' : '-translate-x-full'} ${isCollapsed ? 'lg:w-16' : 'lg:w-60'}`}>

      <div className={`p-4 flex items-center ${isCollapsed ? 'justify-center' : 'gap-3'}`} data-menu-brand>
        {logo && !isCollapsed ? (
          <img src={logo} alt={title} className="h-9 max-w-full object-contain object-left" />
        ) : (
          <>
            <div className={`${mark.length > 2 ? 'min-w-8 px-1.5 text-[11px]' : 'w-8'} h-8 bg-gradient-to-br from-brand-soft to-brand-accent rounded-xl flex items-center justify-center text-brand-ink font-black shrink-0`}>
              {isCollapsed && logo ? <img src={logo} alt={title} className="w-6 h-6 object-contain" /> : mark}
            </div>
            {!isCollapsed && (
              <span className="font-bold text-[17px] text-white tracking-tight truncate">
                {title}
              </span>
            )}
          </>
        )}
        {onClose && (
          <button type="button" aria-label="Cerrar el menú" onClick={onClose} className="lg:hidden ml-auto p-1.5 rounded-lg text-slate-400 hover:text-white hover:bg-white/10">
            <X className="w-5 h-5" />
          </button>
        )}
      </div>

      {hasNoPlan ? (
        <div className="flex-1 p-4 flex flex-col justify-center items-center text-center">
          {!isCollapsed ? (
            <div className="bg-white/5 border border-white/10 p-4 rounded-xl space-y-3">
              <span className="text-xs font-semibold text-brand-soft">Sin Plan Activo</span>
              <p className="text-xs text-slate-300">¡Selecciona un plan para activar tus módulos del sistema!</p>
              <Link
                href="/subscribe"
                className="block w-full py-2 px-3 bg-brand-soft hover:bg-brand-soft/80 text-brand-ink font-bold text-xs rounded-lg transition-colors"
              >
                Elegir Plan
              </Link>
            </div>
          ) : (
            <Link href="/subscribe" title="Elige un plan" className="p-2 bg-brand-soft text-brand-ink rounded-lg">
              <Ticket className="w-5 h-5" />
            </Link>
          )}
        </div>
      ) : (
        <nav className="flex-1 px-3 py-1 space-y-0.5 overflow-y-auto sidebar-scroll">
          {groups.map(({ group, items: visibleItems }) => {
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
                        <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-brand-soft' : 'text-slate-500 group-hover:text-slate-300'}`} />
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
                      !isGroupOpen && containsActiveChild ? 'text-brand-soft' : 'text-slate-500 hover:text-slate-300'
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
                            <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-brand-soft' : 'text-slate-500 group-hover:text-slate-300'}`} />
                            {!isCollapsed && <span className="truncate">{item.name}</span>}
                            {item.badge === 'platform_transfers' && (user?.platform_pending?.transfers ?? 0) > 0 && (
                              <span data-menu-badge={item.badge} className={`${isCollapsed ? 'absolute ml-5 -mt-4' : 'ml-auto'} min-w-5 h-5 px-1.5 rounded-full bg-amber-400 text-[11px] font-bold text-[#0B1B2B] flex items-center justify-center`}>
                                {user?.platform_pending?.transfers}
                              </span>
                            )}
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

      <div className="p-3 border-t border-white/5 space-y-0.5">
        {footer.map((settings) => {
          const Icon = settings.icon;
          const isActive = pathname.startsWith(settings.href);
          return (
            <Link
              key={settings.href}
              href={settings.href}
              title={isCollapsed ? settings.name : undefined}
              className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-[13.5px] font-medium transition-colors ${isCollapsed ? 'justify-center' : ''} ${
                isActive ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
              }`}
            >
              <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-brand-soft' : 'text-slate-500 group-hover:text-slate-300'}`} />
              {!isCollapsed && <span className="truncate">{settings.name}</span>}
            </Link>
          );
        })}

        <button
          onClick={() => setIsCollapsed(!isCollapsed)}
          className={`w-full hidden lg:flex items-center gap-3 px-3 py-2 rounded-xl text-sm font-medium text-slate-500 hover:bg-white/5 hover:text-white ${
            isCollapsed ? 'justify-center' : ''
          }`}
        >
          {isCollapsed ? <PanelLeftOpen className="w-4 h-4 shrink-0" /> : <PanelLeftClose className="w-4 h-4 shrink-0" />}
        </button>
      </div>
    </aside>
  );
}
