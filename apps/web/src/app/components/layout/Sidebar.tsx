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
} from 'lucide-react';
import { matchesPath, recordRecent, useMenu } from './menu';

interface SidebarProps {
  isCollapsed: boolean;
  setIsCollapsed: Dispatch<SetStateAction<boolean>>;
}

export default function Sidebar({ isCollapsed, setIsCollapsed }: SidebarProps) {
  const pathname = usePathname();
  const { platform } = usePlatform();
  const { groups, settings, hasNoPlan, loading, userId } = useMenu();

  useEffect(() => {
    if (loading) return;
    const items = [...groups.flatMap((g) => g.items), ...(settings ? [settings] : [])];
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

  if (loading) return <div className="w-60 bg-[#0B192C] h-screen" />;

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

      <div className="p-3 border-t border-white/5 space-y-0.5">
        {settings && (() => {
          const Icon = settings.icon;
          const isActive = pathname.startsWith(settings.href);
          return (
            <Link
              href={settings.href}
              title={isCollapsed ? settings.name : undefined}
              className={`group flex items-center gap-3 px-3 py-2 rounded-xl text-[13.5px] font-medium transition-colors ${isCollapsed ? 'justify-center' : ''} ${
                isActive ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-white/5 hover:text-slate-100'
              }`}
            >
              <Icon className={`w-4 h-4 shrink-0 ${isActive ? 'text-[#84D0D9]' : 'text-slate-500 group-hover:text-slate-300'}`} />
              {!isCollapsed && <span className="truncate">{settings.name}</span>}
            </Link>
          );
        })()}

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
