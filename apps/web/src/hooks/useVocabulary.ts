'use client';

import { useAuth } from '@/hooks/useAuth';

export type GenderedKey = 'client' | 'pro' | 'dependent';
export type Gender = 'm' | 'f';

export interface Vocabulary {
  client: string;
  clients: string;
  pro: string;
  pros: string;
  dependent: string;
  dependents: string;
  client_gender: Gender;
  pro_gender: Gender;
  dependent_gender: Gender;
  pick: (key: GenderedKey, masculine: string, feminine: string) => string;
}

const DEFAULT_WORDS = {
  client: 'Cliente',
  clients: 'Clientes',
  pro: 'Profesional',
  pros: 'Profesionales',
  dependent: 'Dependiente',
  dependents: 'Dependientes',
};

export const suggestGender = (word: string): Gender => {
  const first = word.trim().split(/\s+/)[0]?.toLowerCase() ?? '';
  if (/(ista|iatra|euta|cida)$/.test(first)) return 'm';
  return /(a|ión|dad|triz)$/.test(first) ? 'f' : 'm';
};

const pluralWord = (word: string) => {
  if (!word) return word;
  const lower = word.toLowerCase();
  if (/[aeiouáéó]$/.test(lower)) return `${word}s`;
  if (/z$/.test(lower)) return `${word.slice(0, -1)}${word.slice(-1) === 'Z' ? 'CES' : 'ces'}`;
  if (/[ís]$/.test(lower) && !/és$/.test(lower)) return word;
  const plain = word.replace(/ón$/, 'on').replace(/ÓN$/, 'ON').replace(/én$/, 'en').replace(/án$/, 'an').replace(/és$/, 'es').replace(/ín$/, 'in');
  return `${plain}${word === word.toUpperCase() ? 'ES' : 'es'}`;
};

export const suggestPlural = (singular: string) => singular.trim().split(/\s+/).map(pluralWord).join(' ');

export const makeVocabulary = (words: Partial<Record<string, string>>): Vocabulary => {
  const merged = { ...DEFAULT_WORDS, ...Object.fromEntries(Object.entries(words).filter(([, value]) => typeof value === 'string' && value !== '')) } as Record<string, string>;
  const genderOf = (key: GenderedKey): Gender => (merged[`${key}_gender`] === 'f' || merged[`${key}_gender`] === 'm' ? (merged[`${key}_gender`] as Gender) : suggestGender(merged[key]));
  const genders = { client_gender: genderOf('client'), pro_gender: genderOf('pro'), dependent_gender: genderOf('dependent') };

  return {
    client: merged.client,
    clients: merged.clients,
    pro: merged.pro,
    pros: merged.pros,
    dependent: merged.dependent,
    dependents: merged.dependents,
    ...genders,
    pick: (key, masculine, feminine) => (genders[`${key}_gender`] === 'f' ? feminine : masculine),
  };
};

export function useVocabulary(): Vocabulary {
  const { user } = useAuth();
  return makeVocabulary(user?.tenant?.vocabulary ?? {});
}
