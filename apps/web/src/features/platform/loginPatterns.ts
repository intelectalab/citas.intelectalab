import type { CSSProperties } from 'react';

const WAVES = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='40' viewBox='0 0 120 40'%3E%3Cpath d='M0 20 Q15 5 30 20 T60 20 T90 20 T120 20' fill='none' stroke='white' stroke-opacity='0.09' stroke-width='2'/%3E%3C/svg%3E\")";

export const LOGIN_PATTERNS: Record<string, CSSProperties | undefined> = {
  brand: undefined,
  'pattern-dots': {
    backgroundImage: 'radial-gradient(rgba(255,255,255,0.14) 1.5px, transparent 1.5px), linear-gradient(135deg, var(--brand-solid), var(--brand-solid-hover))',
    backgroundSize: '18px 18px, 100% 100%',
  },
  'pattern-grid': {
    backgroundImage: 'linear-gradient(rgba(255,255,255,0.07) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.07) 1px, transparent 1px), linear-gradient(135deg, var(--brand-solid), var(--brand-solid-hover))',
    backgroundSize: '28px 28px, 28px 28px, 100% 100%',
  },
  'pattern-waves': {
    backgroundImage: `${WAVES}, linear-gradient(135deg, var(--brand-solid), var(--brand-solid-hover))`,
    backgroundSize: '120px 40px, 100% 100%',
  },
  image: undefined,
};

export const LOGIN_BACKGROUNDS: { id: string; label: string }[] = [
  { id: 'brand', label: 'Degradado del color principal' },
  { id: 'pattern-dots', label: 'Puntos' },
  { id: 'pattern-grid', label: 'Cuadrícula' },
  { id: 'pattern-waves', label: 'Ondas' },
  { id: 'image', label: 'Imagen propia' },
];
