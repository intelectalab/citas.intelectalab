import type { TenantBranding } from '@/lib/brand';

export type PortalTextKey = 'subtitle' | 'welcome' | 'book_title' | 'book_text' | 'no_booking_text' | 'profile_note';

export type PortalTexts = Record<PortalTextKey, string>;

export interface PortalLookData {
  texts: PortalTexts;
  defaults: PortalTexts;
  show_contact: boolean;
  privacy: {
    body: string;
    custom: boolean;
    email: string;
    updated_at: string | null;
    template: string;
    tokens: { token: string; label: string }[];
    company: Record<string, string>;
    missing: string[];
  };
}

export interface DocumentsLook {
  show_logo: boolean;
  footer: string;
  footer_auto: boolean;
  auto_footer: string;
  quote_terms: string;
  default_terms: string;
  signature: { name: string; role: string; has_image: boolean; version: number | null };
}

export interface CodeType {
  type: string;
  label: string;
  prefix: string;
  default: string;
  next: string;
  digits: number;
}

export interface BrandPayload {
  branding: TenantBranding;
  tenant: { name: string };
  portal: PortalLookData;
  documents: DocumentsLook;
  codes: CodeType[];
  can_edit: boolean;
}

export const TEXT_FIELDS: { key: PortalTextKey; label: string; hint: string; max: number; long?: boolean }[] = [
  { key: 'subtitle', label: 'Debajo del nombre de tu empresa', hint: 'Se ve arriba, junto a tu logo.', max: 60 },
  { key: 'welcome', label: 'Mensaje de bienvenida (opcional)', hint: 'Sale en Inicio, debajo del saludo. Por ejemplo: "Recuerda traer tu DNI y llegar 10 minutos antes".', max: 300, long: true },
  { key: 'book_title', label: 'Título para invitar a reservar', hint: 'Cuando la persona no tiene citas próximas y puede reservar en línea.', max: 60 },
  { key: 'book_text', label: 'Texto para invitar a reservar', hint: 'Debajo de ese título.', max: 160, long: true },
  { key: 'no_booking_text', label: 'Si no tienes reserva en línea', hint: 'Qué debe hacer la persona para sacar una cita.', max: 220, long: true },
  { key: 'profile_note', label: 'Nota en "Mis datos"', hint: 'A quién acudir para corregir sus datos.', max: 220, long: true },
];

export const fillTokens = (body: string, values: Record<string, string>, missing = '(dato por completar)') =>
  body.replace(/\[([^\]\s][^\]]*)\]/g, (match, key: string) => (key in values ? (values[key]?.trim() ? escapeHtml(values[key]) : `<mark>${missing}</mark>`) : match));

const escapeHtml = (text: string) => text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]!));
