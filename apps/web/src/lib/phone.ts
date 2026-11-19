const PREFIXES = ['591', '593', '595', '598', '502', '503', '504', '505', '506', '507', '51', '52', '54', '55', '56', '57', '58', '34', '1'];

export const formatPhone = (value?: string | null): string => {
  if (!value) return '';
  if (!value.startsWith('+')) return value;
  const digits = value.slice(1).replace(/\D/g, '');
  const prefix = PREFIXES.find((p) => digits.startsWith(p) && digits.length - p.length >= 6) ?? digits.slice(0, 2);
  const rest = digits.slice(prefix.length).replace(/(\d{3})(?=\d)/g, '$1 ');
  return `+${prefix} ${rest}`;
};
