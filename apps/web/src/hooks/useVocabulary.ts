'use client';

import { useAuth, TenantVocabulary } from '@/hooks/useAuth';

const DEFAULT_VOCABULARY: TenantVocabulary = {
  client: 'Cliente',
  clients: 'Clientes',
  pro: 'Profesional',
  pros: 'Profesionales',
  dependent: 'Dependiente',
  dependents: 'Dependientes',
};

export function useVocabulary(): TenantVocabulary {
  const { user } = useAuth();
  return { ...DEFAULT_VOCABULARY, ...(user?.tenant?.vocabulary ?? {}) };
}
