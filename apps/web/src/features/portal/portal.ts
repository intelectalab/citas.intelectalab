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
}

export interface PortalDependent {
  id: string;
  name: string;
  species: string | null;
  breed: string | null;
}

export interface PortalOverview {
  customer: { id: string; code: string; first_name: string; last_name: string; phone: string | null; email: string | null };
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
