'use client';

import { CalendarDays, Home, Receipt, Users } from 'lucide-react';
import { isLight, previewStyle, type BrandColors } from '@/lib/brand';

interface BrandPreviewProps {
  colors: BrandColors;
  dark: boolean;
  logo: string | null;
  logoDark: string | null;
  mark: string;
  tenantName: string;
}

const MENU = [
  { name: 'Inicio', icon: Home },
  { name: 'Agenda', icon: CalendarDays, active: true },
  { name: 'Clientes', icon: Users },
  { name: 'Ventas', icon: Receipt },
];

export default function BrandPreview({ colors, dark, logo, logoDark, mark, tenantName }: BrandPreviewProps) {
  const lightMenu = isLight(colors.menu);
  const menuLogo = lightMenu ? logo ?? logoDark : logoDark ?? logo;

  return (
    <div className="space-y-4" data-brand-preview={dark ? 'dark' : 'light'}>
      <div className={`theme-scope ${dark ? 'force-dark' : ''} bg-[#F2F2F2] rounded-2xl border border-slate-200 overflow-hidden`} style={previewStyle(colors, dark)}>
        <div className="flex min-h-56">
          <div className="menu-preview bg-brand-menu text-slate-300 w-36 shrink-0 p-3 space-y-1" data-menu-tone={lightMenu ? 'light' : 'dark'} data-preview-menu>
            <div className="flex items-center gap-2 pb-3">
              {menuLogo
                ? <img src={menuLogo} alt={tenantName} className="h-7 max-w-full object-contain object-left" />
                : (
                  <>
                    <span className="min-w-7 h-7 px-1 rounded-lg bg-gradient-to-br from-brand-soft to-brand-accent text-brand-ink text-[10px] font-black flex items-center justify-center">{mark}</span>
                    <span className="text-xs font-bold text-white truncate">{tenantName}</span>
                  </>
                )}
            </div>
            {MENU.map(({ name, icon: Icon, active }) => (
              <span key={name} className={`flex items-center gap-2 px-2 py-1.5 rounded-lg text-[11px] font-medium ${active ? 'bg-white/10 text-white' : 'text-slate-400'}`}>
                <Icon className={`w-3.5 h-3.5 ${active ? 'text-brand-soft' : 'text-slate-500'}`} /> {name}
              </span>
            ))}
          </div>

          <div className="flex-1 min-w-0 p-4 space-y-3">
            <div className="flex items-center justify-between gap-2">
              <span className="text-base font-black text-brand-ink">Citas</span>
              <span className="px-3 py-1.5 rounded-lg bg-brand text-white text-[11px] font-bold" data-preview-button>Nueva cita</span>
            </div>
            <div className="flex gap-3 border-b border-slate-200 text-[11px]">
              <span className="pb-1.5 border-b-2 border-brand text-brand-ink font-bold">Hoy</span>
              <span className="pb-1.5 text-slate-500">Semana</span>
              <span className="pb-1.5 text-slate-500">Mes</span>
            </div>
            <div className="bg-white rounded-xl border border-slate-200 p-3 space-y-2">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-bold text-brand-ink">Juliana Díaz</span>
                <span className="px-2 py-0.5 rounded-full bg-brand-soft/20 text-brand-ink text-[10px] font-bold">Confirmada</span>
              </div>
              <span className="block text-[11px] text-slate-500">Control · 10:00 a. m.</span>
              <div className="flex items-center justify-between gap-2">
                <span className="text-[11px] font-bold text-brand-accent underline" data-preview-link>Ver ficha</span>
                <span className="w-8 h-4 rounded-full bg-brand relative"><span className="absolute right-0.5 top-0.5 w-3 h-3 rounded-full bg-white" /></span>
              </div>
            </div>
          </div>
        </div>
      </div>

      {!dark && (
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3" style={previewStyle(colors, false)}>
          <div className="bg-white rounded-2xl border border-slate-200 p-3 space-y-2" data-preview-portal>
            <span className="block text-[10px] font-bold text-slate-400">Portal de tus clientes</span>
            <div className="flex items-center gap-2">
              {logo
                ? <img src={logo} alt={tenantName} className="h-6 max-w-28 object-contain" />
                : <span className="min-w-6 h-6 px-1 rounded-md bg-brand text-white text-[9px] font-black flex items-center justify-center">{mark}</span>}
            </div>
            <span className="block text-sm font-black text-brand-ink">Hola, Juliana</span>
            <span className="inline-block px-3 py-1 rounded-lg bg-brand text-white text-[11px] font-bold">Reservar cita</span>
          </div>

          <div className="bg-white rounded-2xl border border-slate-200 overflow-hidden" data-preview-email>
            <div className="h-2" style={{ backgroundColor: colors.primary }} />
            <div className="p-3 space-y-2">
              <span className="block text-[10px] font-bold text-slate-400">Tus correos</span>
              {logo ? <img src={logo} alt={tenantName} className="h-6 max-w-28 object-contain" /> : <span className="text-xs font-bold text-slate-700">{tenantName}</span>}
              <span className="block text-[11px] text-slate-600">Te recordamos tu cita del martes.</span>
              <span className="inline-block px-3 py-1 rounded-lg text-white text-[11px] font-bold" style={{ backgroundColor: colors.primary }}>Ver mi cita</span>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
