// Tipos y utilidades que comparten la agenda, la lista de citas y sus paneles.

export interface Appointment {
  id: string;
  code: string;
  status: string;
  status_name: string;
  next_statuses: string[];
  can_reschedule: boolean;
  // Hora de la sede, sin zona: 2026-10-19T09:00
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  price: string;
  source: string;
  notes: string | null;
  reschedule_count: number;
  override_reason: string | null;
  cancel_reason: string | null;
  customer: { id: string; code: string; name: string; phone: string | null } | null;
  dependent: { id: string; name: string; species: string | null } | null;
  service: { id: string; name: string } | null;
  professional: { id: string; display_name: string; color_code: string | null } | null;
  branch: { id: string; name: string } | null;
  timing: AppointmentTiming;
  // Ficha de atención: si el servicio la usa y en qué estado está la de esta cita.
  record: { uses: boolean; status: 'draft' | 'signed' | 'locked' | null };
  // Hasta qué hora ocupa al profesional: antes de lo asignado si la atención terminó antes.
  occupied_until: string;
  history?: AppointmentStep[];
  // Solo al abrir una cita: cuánto suelen durar las atenciones de su cliente.
  customer_timing?: TimingSummary;
}

// Tiempos reales de la cita, en segundos. `running` dice cuál sigue corriendo.
export interface AppointmentTiming {
  planned_seconds: number | null;
  wait_seconds: number | null;
  service_seconds: number | null;
  running: 'wait' | 'service' | null;
  // La duración de la atención es fiable y entra en los promedios.
  measured: boolean;
}

// Promedios de un grupo de citas atendidas, en minutos.
export interface TimingSummary {
  completed: number;
  measured: number;
  avg_minutes: number | null;
  planned_minutes: number | null;
  diff_minutes: number | null;
  total_minutes: number | null;
  avg_wait_minutes: number | null;
}

export interface AppointmentStep {
  from: string | null;
  to: string;
  to_name: string;
  reason: string | null;
  by: string | null;
  at: string;
}

export interface AppointmentContext {
  branches: { id: string; name: string }[];
  professionals: { id: string; display_name: string; color_code: string | null; branch_ids: string[] }[];
  services: { id: string; name: string; duration_minutes: number; price: string; professional_ids: string[] }[];
  can_override: boolean;
  slot_interval: number;
}

// Color de cada fase. Salen de la paleta para que el tema oscuro los alcance.
export const STATUS_STYLES: Record<string, { badge: string; block: string; dot: string }> = {
  pending:     { badge: 'bg-amber-50 text-amber-700',     block: 'bg-amber-50 border-amber-200 text-amber-800',       dot: 'bg-amber-500' },
  confirmed:   { badge: 'bg-sky-50 text-sky-700',         block: 'bg-sky-50 border-sky-200 text-sky-700',             dot: 'bg-sky-500' },
  checked_in:  { badge: 'bg-violet-50 text-violet-600',   block: 'bg-violet-50 border-violet-100 text-violet-600',    dot: 'bg-violet-500' },
  in_progress: { badge: 'bg-teal-50 text-teal-600',       block: 'bg-teal-50 border-teal-100 text-teal-600',          dot: 'bg-teal-500' },
  completed:   { badge: 'bg-emerald-50 text-emerald-700', block: 'bg-emerald-50 border-emerald-200 text-emerald-800', dot: 'bg-emerald-500' },
  cancelled:   { badge: 'bg-slate-100 text-slate-500',    block: 'bg-slate-100 border-slate-200 text-slate-500',      dot: 'bg-slate-400' },
  no_show:     { badge: 'bg-rose-50 text-rose-700',       block: 'bg-rose-50 border-rose-200 text-rose-700',          dot: 'bg-rose-500' },
};

export const statusStyle = (status: string) => STATUS_STYLES[status] ?? STATUS_STYLES.cancelled;

// Fases que ya no ocupan el horario.
export const RELEASED = ['cancelled', 'no_show'];

// Cómo se llama la acción que lleva a cada fase, según de dónde viene la cita.
export const actionLabel = (to: string, from: string): string => {
  switch (to) {
    case 'pending': return 'Volver a pendiente';
    case 'confirmed': return from === 'checked_in' ? 'Deshacer llegada' : 'Confirmar';
    case 'checked_in': return 'Registrar llegada';
    case 'in_progress': return 'Iniciar atención';
    case 'completed': return 'Dar por atendida';
    case 'cancelled': return 'Cancelar cita';
    case 'no_show': return 'No asistió';
    default: return to;
  }
};

export const toMinutes = (time: string): number => {
  const [h, m] = time.split(':').map(Number);
  return h * 60 + m;
};

export const fromMinutes = (minutes: number): string =>
  `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;

// 754 -> "12:34"; desde una hora, "1:02:34".
export const formatClock = (seconds: number): string => {
  const total = Math.max(0, Math.floor(seconds));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return h > 0
    ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`
    : `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
};

// 24.5 -> "25 min"; 95 -> "1 h 35 min".
export const formatMinutes = (minutes: number | null | undefined): string => {
  if (minutes === null || minutes === undefined) return '—';
  const total = Math.round(Math.abs(minutes));
  if (total < 60) return `${total} min`;
  return `${Math.floor(total / 60)} h ${String(total % 60).padStart(2, '0')} min`;
};

export const timeOf = (local: string): string => local.slice(11, 16);
export const dateOf = (local: string): string => local.slice(0, 10);

// Fecha de hoy en el navegador, como AAAA-MM-DD.
export const todayISO = (): string => {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
};

export const addDays = (date: string, days: number): string => {
  const [y, m, d] = date.split('-').map(Number);
  const next = new Date(y, m - 1, d + days);
  return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-${String(next.getDate()).padStart(2, '0')}`;
};

// "lunes, 19 de octubre de 2026"
export const formatDate = (date: string, options?: Intl.DateTimeFormatOptions): string => {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('es-PE', options ?? { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
};

export const formatDateTime = (local: string): string =>
  `${formatDate(dateOf(local), { day: '2-digit', month: 'short', year: 'numeric' })}, ${timeOf(local)}`;

// Primer mensaje útil de un error de la API.
export const apiError = (error: any, fallback: string): string => {
  const errors = error?.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return String(first[0]);
  }
  return error?.response?.data?.message || fallback;
};
