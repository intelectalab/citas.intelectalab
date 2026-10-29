import {
  Award,
  BarChart3,
  Boxes,
  Building2,
  CalendarDays,
  CheckCircle2,
  Clock,
  FileText,
  Heart,
  Layers,
  MessageCircle,
  ShieldCheck,
  Sparkles,
  Star,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

export const LOOK_ICONS: Record<string, { icon: LucideIcon; label: string }> = {
  calendar: { icon: CalendarDays, label: 'Agenda' },
  users: { icon: Users, label: 'Personas' },
  wallet: { icon: Wallet, label: 'Cobros' },
  chart: { icon: BarChart3, label: 'Reportes' },
  shield: { icon: ShieldCheck, label: 'Seguridad' },
  sparkles: { icon: Sparkles, label: 'Novedad' },
  clock: { icon: Clock, label: 'Tiempo' },
  boxes: { icon: Boxes, label: 'Inventario' },
  heart: { icon: Heart, label: 'Cuidado' },
  star: { icon: Star, label: 'Calidad' },
  message: { icon: MessageCircle, label: 'Mensajes' },
  check: { icon: CheckCircle2, label: 'Listo' },
  building: { icon: Building2, label: 'Empresa' },
  layers: { icon: Layers, label: 'Módulos' },
  file: { icon: FileText, label: 'Documentos' },
  award: { icon: Award, label: 'Logros' },
};

export function LookIcon({ name, className }: { name: string; className?: string }) {
  const Icon = LOOK_ICONS[name]?.icon ?? Sparkles;
  return <Icon className={className} />;
}
