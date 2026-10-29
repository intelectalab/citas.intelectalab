export interface BrandColors {
  primary: string;
  accent: string;
  menu: string;
}

export interface TenantBranding {
  primary: string | null;
  accent: string | null;
  menu: string | null;
  short_name: string | null;
  logo_url: string | null;
  logo_dark_url: string | null;
}

export const DEFAULT_BRAND: BrandColors = { primary: '#022340', accent: '#3F768C', menu: '#0B192C' };

const HEX = /^#[0-9a-f]{6}$/i;

export const isHex = (value: string | null | undefined): value is string => Boolean(value && HEX.test(value));

type Rgb = [number, number, number];

const toRgb = (hex: string): Rgb => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)) as Rgb;

const toHex = ([r, g, b]: Rgb) =>
  `#${[r, g, b].map((v) => Math.round(Math.min(255, Math.max(0, v))).toString(16).padStart(2, '0')).join('')}`.toUpperCase();

export const mix = (hex: string, other: string, amount: number): string => {
  const a = toRgb(hex);
  const b = toRgb(other);
  return toHex([0, 1, 2].map((i) => a[i] + (b[i] - a[i]) * amount) as Rgb);
};

const luminance = (hex: string) => {
  const [r, g, b] = toRgb(hex).map((v) => {
    const c = v / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};

export const contrast = (a: string, b: string) => {
  const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
};

export const isLight = (hex: string) => contrast(hex, '#000000') > contrast(hex, '#FFFFFF');

export const darkenUntil = (hex: string, against: string, ratio: number): string => {
  let color = hex;
  for (let step = 0; step < 40 && contrast(color, against) < ratio; step++) color = mix(color, '#000000', 0.06);
  return color;
};

export const lightenUntil = (hex: string, against: string, ratio: number): string => {
  let color = hex;
  for (let step = 0; step < 40 && contrast(color, against) < ratio; step++) color = mix(color, '#FFFFFF', 0.06);
  return color;
};

export const PRIMARY_MIN = 4.5;
export const ACCENT_MIN = 4.5;

export interface ColorCheck {
  ok: boolean;
  ratio: number;
  suggestion: string;
}

export const checkPrimary = (hex: string): ColorCheck => {
  const ratio = contrast(hex, '#FFFFFF');
  return { ok: ratio >= PRIMARY_MIN, ratio, suggestion: darkenUntil(hex, '#FFFFFF', PRIMARY_MIN) };
};

export const checkAccent = (hex: string): ColorCheck => {
  const ratio = contrast(hex, '#FFFFFF');
  return { ok: ratio >= ACCENT_MIN, ratio, suggestion: darkenUntil(hex, '#FFFFFF', ACCENT_MIN) };
};

export const resolveColors = (branding: Partial<TenantBranding> | null | undefined): BrandColors => ({
  primary: isHex(branding?.primary) ? branding!.primary! : DEFAULT_BRAND.primary,
  accent: isHex(branding?.accent) ? branding!.accent! : DEFAULT_BRAND.accent,
  menu: isHex(branding?.menu) ? branding!.menu! : DEFAULT_BRAND.menu,
});

export const isCustom = (branding: Partial<TenantBranding> | null | undefined) =>
  isHex(branding?.primary) || isHex(branding?.accent) || isHex(branding?.menu);

const DARK_BG = '#0A1626';

const DEFAULT_DARK = {
  '--brand-solid': '#2F6F9F',
  '--brand-solid-hover': '#3A80B5',
  '--brand-ink': '#DBE7F3',
  '--brand-link': '#84D0D9',
  '--brand-dark-tint': '#84D0D91F',
  '--brand-dark-border': '#5AA0D0',
};

export const sameColors = (a: BrandColors, b: BrandColors) =>
  a.primary.toUpperCase() === b.primary.toUpperCase() && a.accent.toUpperCase() === b.accent.toUpperCase() && a.menu.toUpperCase() === b.menu.toUpperCase();

export const tokens = (colors: BrandColors, force = false) => {
  const { primary, accent, menu } = colors;
  const darkSolid = lightenUntil(primary, DARK_BG, 2.2);

  if (sameColors(colors, DEFAULT_BRAND)) {
    if (!force) return null;
    return {
      light: {
        '--brand-solid': '#022340',
        '--brand-solid-hover': '#03315A',
        '--brand-ink': '#022340',
        '--brand-link': '#3F768C',
        '--brand-accent-solid': '#3F768C',
        '--brand-soft': '#84D0D9',
        '--brand-menu': '#0B192C',
      },
      dark: DEFAULT_DARK,
    };
  }

  return {
    light: {
      '--brand-solid': primary,
      '--brand-solid-hover': mix(primary, '#FFFFFF', 0.12),
      '--brand-ink': darkenUntil(primary, '#FFFFFF', 7),
      '--brand-link': accent,
      '--brand-accent-solid': accent,
      '--brand-soft': mix(accent, '#FFFFFF', 0.45),
      '--brand-menu': menu,
    },
    dark: {
      '--brand-solid': darkSolid,
      '--brand-solid-hover': mix(darkSolid, '#FFFFFF', 0.12),
      '--brand-ink': mix(primary, '#FFFFFF', 0.86),
      '--brand-link': lightenUntil(accent, DARK_BG, 4.5),
      '--brand-dark-tint': `${mix(accent, '#FFFFFF', 0.45)}1F`,
      '--brand-dark-border': lightenUntil(primary, DARK_BG, 3),
    },
  };
};

export const previewStyle = (colors: BrandColors, dark: boolean): Record<string, string> => {
  const vars = tokens(colors, true)!;
  return dark ? { ...vars.light, ...vars.dark } : vars.light;
};

export const styleText = (vars: Record<string, string>) =>
  Object.entries(vars).map(([key, value]) => `${key}:${value}`).join(';');

export const initials = (name: string) =>
  name.split(/\s+/).filter((word) => word.length > 2 || /^[A-ZÁÉÍÓÚÑ]/.test(word)).slice(0, 3).map((word) => word[0]).join('').toUpperCase().slice(0, 4);

export interface LogoPalette {
  colors: BrandColors | null;
  found: string[];
  mono: boolean;
}

const hue = ([r, g, b]: Rgb) => {
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  if (max === min) return 0;
  const d = max - min;
  const h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
  return (h * 60 + 360) % 360;
};

const saturation = ([r, g, b]: Rgb) => {
  const max = Math.max(r, g, b) / 255;
  const min = Math.min(r, g, b) / 255;
  const l = (max + min) / 2;
  if (max === min) return 0;
  return l > 0.5 ? (max - min) / (2 - max - min) : (max - min) / (max + min);
};

export const paletteFromImage = (image: HTMLImageElement): LogoPalette => {
  const size = 64;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const context = canvas.getContext('2d', { willReadFrequently: true });
  if (!context) return { colors: null, found: [], mono: false };

  const scale = Math.min(size / image.naturalWidth, size / image.naturalHeight);
  const w = Math.max(1, Math.round(image.naturalWidth * scale));
  const h = Math.max(1, Math.round(image.naturalHeight * scale));
  context.drawImage(image, 0, 0, w, h);
  const data = context.getImageData(0, 0, w, h).data;

  const groups = new Map<number, { count: number; exact: Map<string, number>; samples: Map<string, Map<string, number>> }>();
  let total = 0;

  for (let i = 0; i < data.length; i += 4) {
    if (data[i + 3] < 200) continue;
    const rgb: Rgb = [data[i], data[i + 1], data[i + 2]];
    const max = Math.max(...rgb);
    const min = Math.min(...rgb);
    if (min > 235 || max < 25 || saturation(rgb) < 0.18) continue;

    const bucket = Math.round(hue(rgb) / 24) % 15;
    const key = toHex(rgb.map((v) => Math.round(v / 8) * 8) as Rgb);
    const group = groups.get(bucket) ?? { count: 0, exact: new Map(), samples: new Map() };
    group.count++;
    group.exact.set(key, (group.exact.get(key) ?? 0) + 1);
    const sample = group.samples.get(key) ?? new Map<string, number>();
    const exact = toHex(rgb);
    sample.set(exact, (sample.get(exact) ?? 0) + 1);
    group.samples.set(key, sample);
    groups.set(bucket, group);
    total++;
  }

  if (total === 0) return { colors: null, found: [], mono: false };

  const ranked = [...groups.entries()]
    .sort((a, b) => b[1].count - a[1].count)
    .map(([bucket, group]) => {
      const key = [...group.exact.entries()].sort((a, b) => b[1] - a[1])[0][0];
      const color = [...group.samples.get(key)!.entries()].sort((a, b) => b[1] - a[1])[0][0];
      return { bucket, share: group.count / total, color };
    });

  const main = ranked[0];
  const distance = (a: number, b: number) => Math.min(Math.abs(a - b), 15 - Math.abs(a - b));
  const second = ranked.slice(1).filter((r) => r.share >= 0.05).sort((a, b) => distance(b.bucket, main.bucket) - distance(a.bucket, main.bucket))[0];
  const mono = !second;
  const accentBase = second?.color ?? mix(main.color, '#FFFFFF', 0.35);

  return {
    found: ranked.slice(0, 4).map((r) => r.color),
    mono,
    colors: {
      primary: main.color,
      accent: accentBase,
      menu: mix(darkenUntil(main.color, '#FFFFFF', 12), '#000000', 0.35),
    },
  };
};
