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

/**
 * Palabras que usa el tenant según su rubro: cliente, paciente, alumno o tutor;
 * profesional, médico, estilista, etc. El backend ya entrega el vocabulario
 * resuelto (base, rubro y lo que la empresa sobrescribe).
 */
export function useVocabulary(): TenantVocabulary {
  const { user } = useAuth();
  return { ...DEFAULT_VOCABULARY, ...(user?.tenant?.vocabulary ?? {}) };
}
