export interface PortalAppointment {
  id: string;
  source?: string;
  code: string;
  status: string;
  status_name: string;
  starts_at: string;
  ends_at: string;
  duration_minutes: number;
  price: string;
  service: { id: string; name: string } | null;
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  dependent: { id: string; name: string; species: string | null } | null;
  cancel_reason: string | null;
  confirm_by?: string | null;
  confirmation_overdue?: boolean;
  is_upcoming: boolean;
  pay_on_arrival?: boolean;
  deposit?: { amount: number; currency: string; balance: number; outcome: 'refund_due' | 'retained' | 'refunded' | null; refund_hours: number } | null;
  package?: { id: string; code: string; name: string; status: string; late_policy: string } | null;
  is_virtual?: boolean;
  can_confirm: boolean;
  can_cancel: boolean;
  can_reschedule: boolean;
}

export interface PortalPolicies {
  booking_enabled: boolean;
  choose_professional: boolean;
  min_lead_minutes: number;
  max_days_ahead: number;
  auto_confirm: boolean;
  cancel_allowed: boolean;
  cancel_window_hours: number;
  late_cancel_counts?: boolean;
  reschedule_max: number;
  confirm_deadline_hours?: number;
  confirm_on_miss?: 'notify' | 'notify_release' | 'release';
  confirm_release_hours?: number;
  pay_before_service?: boolean;
  arrive_minutes?: number;
  deposit_minutes?: number;
  deposit_refund_hours?: number;
  miss?: { action: 'none' | 'note' | 'block'; limit: number; block_days: number };
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
    must_change_password?: boolean;
  };
  policies: PortalPolicies;
  booking_block?: { misses: number; blocked: boolean; blocked_until: string | null; limit: number | null; message: string | null } | null;
  dependents: { enabled: boolean; required: boolean; items: PortalDependent[] };
  appointments: PortalAppointment[];
  consents?: { email: { granted: boolean }; whatsapp: { granted: boolean } } | null;
  look?: {
    texts: { subtitle: string; welcome: string; book_title: string; book_text: string; no_booking_text: string; profile_note: string };
    contact: { phone: string | null; email: string | null; branches: { id: string; name: string; address: string | null; phone: string | null }[] } | null;
  };
  privacy_accepted_at?: string | null;
}

export interface PortalService {
  id: string;
  branch_ids?: string[];
  name: string;
  category: string | null;
  duration_minutes: number;
  price: string;
  is_virtual: boolean;
  needs_deposit: boolean;
  package?: { code: string; name: string; available: number } | null;
  deposit_amount?: number;
  deposit_label?: string | null;
  professional_ids: string[];
}

export interface PortalCatalog {
  branches: { id: string; name: string }[];
  services: PortalService[];
  professionals: { id: string; display_name: string; branch_ids: string[] }[];
}

export interface PortalWaitlistEntry {
  id: string;
  service: { id: string; name: string } | null;
  professional: { id: string; display_name: string } | null;
  branch: { id: string; name: string } | null;
  dependent: { id: string; name: string } | null;
  date_from: string | null;
  date_to: string | null;
  time_from: string | null;
  time_to: string | null;
  created_at: string | null;
  days: { date: string; groups: { professional: { id: string; display_name: string }; slots: string[] }[] }[];
}

export interface PortalAvailability {
  date: string;
  times: string[];
  professionals: { professional: { id: string; display_name: string }; slots: string[] }[];
}

export const portalError = (error: any, fallback: string): string => {
  const errors = error?.response?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first[0]) return String(first[0]);
  }
  return error?.response?.data?.message || fallback;
};
