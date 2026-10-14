export interface HourRange {
  opens: string;
  closes: string;
}

export type BranchHoursMap = Record<number, HourRange[]>;

export interface Branch {
  id: string;
  name: string;
  address: string | null;
  district: string | null;
  department: string | null;
  phone: string | null;
  timezone: string;
  status: 'active' | 'inactive';
  has_hours: boolean;
  today: HourRange[] | null;
  open_days: number;
  professionals_count: number;
  rooms_count: number;
  appointments_today: number;
  upcoming: number;
}

export interface BranchProfessional {
  id: string;
  display_name: string;
  inactive: boolean;
  ranges: { weekday: number; starts: string; ends: string; outside: boolean }[];
}

export interface Room {
  id: string;
  name: string;
  description: string | null;
  kind: 'room' | 'equipment';
  status: 'active' | 'inactive';
  branch_id: string;
  branch_name: string | null;
  effective_status?: 'active' | 'inactive';
  auto_down?: boolean;
  down_equipment?: string[];
  parent_id?: string | null;
  parent_name?: string | null;
}

export interface BranchDetailData extends Branch {
  hours: BranchHoursMap;
  professionals: BranchProfessional[];
  rooms: Room[];
  links_equipment?: boolean;
  uses_rooms: boolean;
  can_manage: boolean;
}

export interface BranchList {
  branches: Branch[];
  active: number;
  limit: number | null;
  multi_branch: boolean;
  can_add: boolean;
  can_manage: boolean;
  uses_rooms: boolean;
}

export const WEEK: { weekday: number; label: string; short: string }[] = [
  { weekday: 1, label: 'Lunes', short: 'Lun' },
  { weekday: 2, label: 'Martes', short: 'Mar' },
  { weekday: 3, label: 'Miércoles', short: 'Mié' },
  { weekday: 4, label: 'Jueves', short: 'Jue' },
  { weekday: 5, label: 'Viernes', short: 'Vie' },
  { weekday: 6, label: 'Sábado', short: 'Sáb' },
  { weekday: 0, label: 'Domingo', short: 'Dom' },
];

export const TIMEZONES: { value: string; label: string }[] = [
  { value: 'America/Lima', label: 'Perú (Lima)' },
  { value: 'America/Bogota', label: 'Colombia (Bogotá)' },
  { value: 'America/Guayaquil', label: 'Ecuador (Guayaquil)' },
  { value: 'America/La_Paz', label: 'Bolivia (La Paz)' },
  { value: 'America/Santiago', label: 'Chile (Santiago)' },
  { value: 'America/Argentina/Buenos_Aires', label: 'Argentina (Buenos Aires)' },
  { value: 'America/Mexico_City', label: 'México (Ciudad de México)' },
];

export const ROOM_KINDS: Record<Room['kind'], string> = { room: 'Sala', equipment: 'Equipo' };

export const rangesLabel = (ranges: HourRange[]): string => ranges.map((r) => `${r.opens} a ${r.closes}`).join(' y ');

export const todayLabel = (branch: Pick<Branch, 'today'>): string =>
  branch.today === null ? 'Sin horario definido' : branch.today.length === 0 ? 'Hoy no abre' : rangesLabel(branch.today);

export const addressLabel = (branch: Pick<Branch, 'address' | 'district'>): string =>
  [branch.address, branch.district].filter(Boolean).join(', ');
