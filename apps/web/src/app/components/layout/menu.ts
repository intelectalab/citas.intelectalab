'use client';

import { useAuth } from '@/hooks/useAuth';
import { useVocabulary, type Vocabulary } from '@/hooks/useVocabulary';
import {
  CircleHelp,
  Home,
  ChartColumn,
  Users,
  Building2,
  Contact,
  FileText,
  Store,
  Ticket,
  Settings,
  Briefcase,
  Layers,
  ShieldCheck,
  Wrench,
  ListOrdered,
  CalendarDays,
  ClipboardList,
  ShoppingCart,
  Package,
  PackageOpen,
  Gift,
  Boxes,
  Mail,
  BadgeCheck,
  Megaphone,
  Workflow,
  CalendarClock,
  CalendarOff,
  Hourglass,
  Repeat,
  Timer,
  Clock,
  DoorOpen,
  Wallet,
  TicketPercent,
  Receipt,
  Coins,
  UserCog,
  History,
  ArrowRightLeft,
  Truck,
  Sparkles,
  CreditCard,
  Palette,
  Percent,
  LucideIcon
} from 'lucide-react';

export interface MenuItem {
  name: string;
  href: string;
  icon: LucideIcon;
  feature?: string;
  requireAdmin?: boolean;
  requireProfessional?: boolean;
  module?: string;
  alsoModule?: string;
  perm?: string;
  saasAdminOnly?: boolean;
  tenantOnly?: boolean;
  badge?: 'platform_transfers';
}

export interface MenuGroup {
  id: string;
  groupName?: string;
  groupIcon?: LucideIcon;
  requireAdmin?: boolean;
  feature?: string;
  items: MenuItem[];
}

export interface VisibleGroup {
  group: MenuGroup;
  items: MenuItem[];
}

const buildMenuGroups = (vocab: Vocabulary, hasModule: (key: string) => boolean): MenuGroup[] => [
  {
    id: 'home',
    items: [
      { name: 'Inicio', href: '/home', icon: Home },
      { name: 'Reportes', href: '/reports', icon: ChartColumn, perm: 'reports.view|reports.own', tenantOnly: true },
    ]
  },
  {
    id: 'saas-admin',
    groupName: 'Plataforma',
    groupIcon: ShieldCheck,
    requireAdmin: true,
    items: [
      { name: 'Tenants', href: '/admin/tenants', icon: Building2 },
      { name: 'Cobros', href: '/admin/cobros', icon: Wallet, saasAdminOnly: true, badge: 'platform_transfers' },
      { name: 'Reportes', href: '/admin/reports', icon: ChartColumn, saasAdminOnly: true },
      { name: 'Planes SaaS', href: '/admin/plans', icon: Ticket, saasAdminOnly: true },
      { name: 'Plantillas de rubros', href: '/admin/business-types', icon: Layers, saasAdminOnly: true },
      { name: 'Contactos', href: '/admin/contacts', icon: Users, saasAdminOnly: true },
      { name: 'Auditoría', href: '/admin/audit', icon: History, saasAdminOnly: true },
    ]
  },
  {
    id: 'agenda',
    groupName: 'Agenda',
    groupIcon: CalendarDays,
    items: [
      { name: 'Agenda', href: '/agenda', icon: CalendarDays, module: 'agenda', perm: 'agenda.view' },
      { name: 'Citas', href: '/appointments', icon: Ticket, module: 'agenda', perm: 'agenda.view' },
      { name: 'Cola sin cita', href: '/walkin', icon: ListOrdered, module: 'walkin', perm: 'walkin.view' },
      { name: 'Lista de espera', href: '/waitlist', icon: Hourglass, module: 'agenda', perm: 'waitlist.view' },
      { name: 'Controles y seguimiento', href: '/recalls', icon: Repeat, module: 'agenda', perm: 'recalls.view' },
      { name: 'Clases', href: '/modules/classes', icon: CalendarClock, module: 'classes', perm: 'classes.view' },
      { name: 'Bloqueos y ausencias', href: '/blocks', icon: CalendarOff, module: 'agenda', perm: 'blocks.view' },
      { name: 'Tiempos de atención', href: '/attention-times', icon: Timer, module: 'agenda', perm: 'attention.view' },
    ]
  },
  {
    id: 'clinical',
    groupName: 'Atención',
    groupIcon: Layers,
    items: [
      { name: vocab.clients, href: '/customers', icon: Users, module: 'crm', perm: 'customers.view' },
      { name: 'Presupuestos', href: '/quotes', icon: FileText, module: 'quotes', perm: 'quotes.view' },
      { name: 'Fichas de atención', href: '/records', icon: ClipboardList, module: 'records', perm: 'records.view' },
    ]
  },
  {
    id: 'catalog',
    groupName: 'Equipo y catálogo',
    groupIcon: Layers,
    items: [
      { name: 'Servicios', href: '/services', icon: Wrench, module: 'agenda', perm: 'services.view' },
      { name: vocab.pros, href: '/professionals', icon: Contact, module: 'agenda', perm: 'professionals.view' },
      { name: 'Horarios del equipo', href: '/schedules', icon: Clock, module: 'agenda', perm: 'schedules.view' },
      { name: 'Salas y equipos', href: '/rooms', icon: DoorOpen, module: 'resources', perm: 'rooms.view' },
    ]
  },
  {
    id: 'crm',
    groupName: 'Ventas',
    groupIcon: Briefcase,
    items: [
      { name: 'Punto de venta', href: '/pos', icon: ShoppingCart, module: 'pos', perm: 'pos.view' },
      { name: 'Ventas', href: '/sales', icon: Receipt, module: 'pos', perm: 'sales.view' },
      { name: 'Pagos', href: '/payments', icon: Coins, module: 'pos', perm: 'payments.view' },
      { name: 'Caja', href: '/cash', icon: Wallet, module: 'pos', perm: 'cash.view' },
      { name: 'Comisiones', href: '/commissions', icon: Percent, module: 'commissions', perm: 'commissions.view' },
    ]
  },
  {
    id: 'inventory',
    groupName: 'Inventario',
    groupIcon: Package,
    items: [
      { name: 'Productos y stock', href: '/products', icon: PackageOpen, module: 'inventory', perm: 'products.view' },
      { name: 'Kárdex', href: '/inventory/kardex', icon: History, module: 'inventory', perm: 'inventory.view' },
      { name: 'Traslados', href: '/inventory/transfers', icon: ArrowRightLeft, module: 'inventory', alsoModule: 'multi_branch', perm: 'inventory.view' },
      { name: 'Compras', href: '/inventory/purchases', icon: Truck, module: 'inventory', perm: 'inventory.view' },
    ]
  },
  {
    id: 'loyalty',
    groupName: 'Fidelización',
    groupIcon: Gift,
    items: [
      { name: 'Membresías', href: '/modules/memberships', icon: BadgeCheck, module: 'memberships', requireAdmin: true },
      { name: 'Paquetes', href: '/packages', icon: Boxes, module: 'packages', perm: 'packages.view' },
      { name: 'Puntos', href: '/loyalty/points', icon: Sparkles, module: 'loyalty', perm: 'loyalty.view' },
      { name: 'Gift cards', href: '/loyalty/gift-cards', icon: CreditCard, module: 'loyalty', perm: 'loyalty.view' },
    ]
  },
  {
    id: 'marketing',
    groupName: 'Marketing',
    groupIcon: Megaphone,
    items: [
      { name: 'Campañas', href: '/campaigns', icon: Megaphone, module: 'marketing', perm: 'campaigns.view' },
      { name: 'Automatizaciones', href: '/automations', icon: Workflow, module: 'automations', perm: 'automations.view' },
      { name: 'Mensajes', href: '/messages', icon: Mail, perm: 'messages.view' },
      { name: 'Cupones', href: '/coupons', icon: TicketPercent, module: 'pos', perm: 'coupons.view' },
    ]
  },
  {
    id: 'clinic-admin',
    groupName: 'Tu empresa',
    groupIcon: Building2,
    items: [
      { name: 'Sucursales', href: '/branches', icon: Store, perm: 'branches.view' },
      { name: 'Empresa', href: '/companies', icon: Building2, perm: 'company.view' },
      { name: 'Usuarios y roles', href: '/users', icon: UserCog, perm: 'users.view' },
      { name: 'Auditoría', href: '/audit', icon: History, perm: 'audit.view' },
    ]
  }
];

export type ModuleScreen = { name: string; needs?: string };

export const screensByModule = (vocab: Vocabulary): Record<string, ModuleScreen[]> => {
  const screens: Record<string, ModuleScreen[]> = {};
  for (const group of buildMenuGroups(vocab, () => true)) {
    for (const item of group.items) {
      if (!item.module) continue;
      for (const key of item.module.split('|')) {
        (screens[key] ??= []).push({ name: item.name, needs: item.alsoModule });
      }
    }
  }
  return screens;
};

const RECENT_KEY = 'menu.recent';
const RECENT_MAX = 6;

const recentKey = (userId?: string | number | null) => `${RECENT_KEY}.${userId ?? 'anon'}`;

export const readRecent = (userId?: string | number | null): string[] => {
  try {
    const value = JSON.parse(window.localStorage.getItem(recentKey(userId)) ?? '[]');
    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
  } catch {
    return [];
  }
};

export const recordRecent = (userId: string | number | null | undefined, href: string) => {
  try {
    const next = [href, ...readRecent(userId).filter((item) => item !== href)].slice(0, RECENT_MAX);
    window.localStorage.setItem(recentKey(userId), JSON.stringify(next));
  } catch {}
};

export const matchesPath = (href: string, pathname: string) => pathname === href || pathname.startsWith(`${href}/`);

export function useMenu() {
  const { user, hasFeature, hasModule, can, loading } = useAuth();
  const vocab = useVocabulary();

  const isSaasAdmin = Boolean(user?.is_saas_admin);
  const isSuperAdmin = Boolean(isSaasAdmin || user?.is_saas_support);
  const hasNoPlan = !isSuperAdmin && !user?.plan_slug;

  const groups: VisibleGroup[] = loading || hasNoPlan ? [] : buildMenuGroups(vocab, hasModule).flatMap((group) => {
    if (isSuperAdmin ? group.id !== 'saas-admin' && group.id !== 'home' : group.id === 'saas-admin') return [];
    if (group.feature && !hasFeature(group.feature)) return [];

    const items = group.items.filter((item) => {
      if (item.saasAdminOnly && !isSaasAdmin) return false;
      if (item.tenantOnly && isSuperAdmin) return false;
      if (item.requireAdmin && !user?.is_tenant_admin) return false;
      if (item.perm && !isSuperAdmin && !can(item.perm)) return false;
      if (item.feature && !hasFeature(item.feature)) return false;
      if (item.module && !hasModule(item.module)) return false;
      if (item.alsoModule && !hasModule(item.alsoModule)) return false;
      return true;
    });

    return items.length > 0 ? [{ group, items }] : [];
  });

  const help: MenuItem[] = !loading && !isSuperAdmin && user?.help_enabled ? [{ name: 'Autoayuda', href: '/help', icon: CircleHelp }] : [];

  const footer: MenuItem[] = loading ? []
    : isSaasAdmin ? [
        { name: 'Personalización', href: '/admin/personalization', icon: Palette },
        { name: 'Configuración', href: '/admin/configuration', icon: Settings },
      ]
    : !isSuperAdmin && can('settings.view') ? [
        { name: 'Personalización', href: '/personalization', icon: Palette },
        { name: 'Configuración', href: '/settings', icon: Settings },
      ]
    : [];

  return { groups, footer: [...footer, ...help], hasNoPlan, loading, userId: user?.id ?? null };
}
