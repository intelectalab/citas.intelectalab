// Tipos del portal del cliente: lo que entrega /api/portal.

export interface PortalAppointment {
  id: string;
  code: string;
  status: string;
  status_name: string;
  // Hora de la sede, sin zona: 2026-10-19T09:00
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  price: string;
  service: { id: string; name: string } | null;
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  dependent: { id: string; name: string; species: string | null } | null;
  cancel_reason: string | null;
  // Hasta cuándo conviene confirmarla (hora de la sede) y si ya se pasó ese plazo.
  confirm_by?: string | null;
  confirmation_overdue?: boolean;
  // Aún no ocurre y sigue en pie (pendiente o confirmada).
  is_upcoming: boolean;
  can_confirm: boolean;
  can_cancel: boolean;
  can_reschedule: boolean;
}

export interface PortalPolicies {
  // La empresa ofrece reservar en línea (lo tiene en su plan y no lo apagó).
  booking_enabled: boolean;
  choose_professional: boolean;
  min_lead_minutes: number;
  max_days_ahead: number;
  auto_confirm: boolean;
  cancel_allowed: boolean;
  cancel_window_hours: number;
  reschedule_max: number;
  // Plazo para confirmar: horas antes de la cita (0 = sin plazo) y qué pasa si no se confirma.
  confirm_deadline_hours?: number;
  confirm_on_miss?: 'notify' | 'notify_release' | 'release';
  confirm_release_hours?: number;
}

export interface PortalDependent {
  id: string;
  name: string;
  species: string | null;
  breed: string | null;
}

export interface PortalOverview {
  customer: {
    id: string; code: string; first_name: string; last_name: string; phone: string | null; email: string | null;
    // Sigue con su contraseña inicial (su número de documento): debe crear la suya.
    must_change_password?: boolean;
  };
  policies: PortalPolicies;
  dependents: { enabled: boolean; required: boolean; items: PortalDependent[] };
  appointments: PortalAppointment[];
}

export interface PortalService {
  id: string;
  name: string;
  category: string | null;
  duration_minutes: number;
  price: string;
  is_virtual: boolean;
  // Exige adelanto: por ahora se agenda en la sede.
  needs_deposit: boolean;
  // Vacío = lo atiende cualquiera.
  professional_ids: string[];
}

export interface PortalCatalog {
  branches: { id: string; name: string }[];
  services: PortalService[];
  professionals: { id: string; display_name: string; branch_ids: string[] }[];
}

// Algo que el cliente espera: un servicio para el que no encontró horario.
export interface PortalWaitlistEntry {
  id: string;
  service: { id: string; name: string } | null;
  // Sin profesional: lo atiende cualquiera.
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  dependent: { id: string; name: string } | null;
  date_from: string | null;
  date_to: string | null;
  time_from: string | null;
  time_to: string | null;
  created_at: string | null;
  // Días con horarios libres que hoy le sirven; vacío = aún no hay.
  days: { date: string; groups: { professional: { id: string; display_name: string }; slots: string[] }[] }[];
}

export interface PortalAvailability {
  date: string;
  times: string[];
  professionals: { professional: { id: string; display_name: string }; slots: string[] }[];
}

// Primer mensaje útil de un error de la API, dicho para el cliente.
export const portalError = (error: any, fallback: string): string => {
  const errors = error?.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return String(first[0]);
  }
  return error?.response?.data?.message || fallback;
};
