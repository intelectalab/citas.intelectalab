'use client';

import { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { api } from '@/lib/axios';

export interface UserFeatures {
  crm?: boolean;
  pos?: boolean;
  reports_basic?: boolean;
  reports_advanced?: boolean;
  audit?: boolean;
  [key: string]: boolean | undefined;
}

export interface TenantVocabulary {
  client: string;
  clients: string;
  pro: string;
  pros: string;
  dependent: string;
  dependents: string;
  [key: string]: string;
}

export interface UserTenant {
  id: string;
  name: string;
  code: string | null;
  business_type: string | null;
  business_type_name: string | null;
  color: string | null;
  vocabulary: TenantVocabulary;
  modules?: string[];
  rules?: Record<string, string | number | boolean>;
  dependents?: { enabled: boolean; kind: string | null; required: boolean };
}

export interface User {
  id: number | string;
  first_name: string;
  last_name: string;
  email: string;
  is_owner?: boolean;
  is_saas_admin?: boolean;
  // Soporte de la plataforma: ve las empresas y entra a su panel, sin cambiar planes ni catálogos.
  is_saas_support?: boolean;
  // Presente cuando la sesión la abrió la plataforma para dar soporte a esta empresa.
  impersonator?: { name: string } | null;
  // Dueño o administrador de su empresa: el único que cambia su configuración.
  is_tenant_admin?: boolean;
  account_type?: 'staff' | 'customer';
  tenant?: UserTenant | null;
  customer?: { id: string; code: string } | null;
  roles?: string[];
  plan_slug?: string | null;
  features?: UserFeatures;
}

interface AuthContextType {
  user: User | null;
  loading: boolean;
  login: (token: string, userData: User) => void;
  logout: () => Promise<void>;
  refetchUser: () => Promise<void>;
  hasFeature: (featureKey: string) => boolean;
  hasModule: (moduleKey: string) => boolean;
  hasRole: (roleSlug: string) => boolean;
  // Entrar al panel de una empresa desde la consola de la plataforma, y volver.
  startImpersonation: (tenantId: string) => Promise<void>;
  stopImpersonation: () => Promise<void>;
}

// Token del equipo de plataforma mientras está dentro del panel de una empresa.
const PLATFORM_TOKEN_KEY = 'platform_auth_token';

// Vuelve a la sesión de la plataforma. Devuelve false si no había una guardada.
const restorePlatformSession = (): boolean => {
  const platformToken = localStorage.getItem(PLATFORM_TOKEN_KEY);
  if (!platformToken) return false;

  localStorage.setItem('auth_token', platformToken);
  localStorage.removeItem(PLATFORM_TOKEN_KEY);
  localStorage.removeItem('user');
  window.location.href = '/admin/tenants';
  return true;
};

const AuthContext = createContext<AuthContextType>({
  user: null,
  loading: true,
  login: () => { },
  logout: async () => { },
  refetchUser: async () => { },
  hasFeature: () => false,
  hasModule: () => false,
  hasRole: () => false,
  startImpersonation: async () => { },
  stopImpersonation: async () => { },
});

export const AuthProvider = ({ children }: { children: ReactNode }) => {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchUser = async () => {
    const tokenGuardado = localStorage.getItem('auth_token');
    console.log('[useAuth] Intentando obtener usuario. Token actual en storage:', tokenGuardado);

    if (!tokenGuardado) {
      console.warn('[useAuth] Abortando GET /api/user porque el token es nulo.');
      setUser(null);
      localStorage.removeItem('user');
      setLoading(false);
      return;
    }

    try {
      const response = await api.get('/user');
      const userData = response.data.user || response.data.data || response.data;

      setUser(userData);
      localStorage.setItem('user', JSON.stringify(userData));
    } catch (error: any) {
      console.error('[useAuth] Error 401/Falló la petición con token:', tokenGuardado);
      setUser(null);

      if (error.response?.status === 401) {
        // La sesión de soporte caduca sola: se vuelve a la consola de la plataforma.
        if (restorePlatformSession()) return;

        console.warn('[useAuth] Token inválido o expirado. Limpiando sesión...');
        localStorage.removeItem('auth_token');
        localStorage.removeItem('user');
        setUser(null);

        if (window.location.pathname !== '/login') {
          window.location.href = '/login';
        }
      }
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    const savedUser = localStorage.getItem('user');
    if (savedUser) {
      try {
        setUser(JSON.parse(savedUser));
      } catch (e) {
        console.error('Error parseando usuario en localStorage:', e);
      }
    }

    fetchUser();
  }, []);

  const login = (token: string, userData: User) => {
    localStorage.setItem('auth_token', token);
    localStorage.setItem('user', JSON.stringify(userData));
    setUser(userData);
    setLoading(false);
  };

  const logout = async () => {
    const token = localStorage.getItem('auth_token');
    try {
      if (token) {
        await api.post('/logout', {});
      }
    } catch (error) {
      console.error('[useAuth] Error cerrando sesión en servidor:', error);
    } finally {
      localStorage.removeItem('auth_token');
      localStorage.removeItem(PLATFORM_TOKEN_KEY);
      localStorage.removeItem('user');
      setUser(null);
      setLoading(false);
      window.location.href = '/login';
    }
  };

  const startImpersonation = async (tenantId: string) => {
    const platformToken = localStorage.getItem('auth_token');
    const res = await api.post(`/tenants/${tenantId}/impersonate`);

    if (platformToken) localStorage.setItem(PLATFORM_TOKEN_KEY, platformToken);
    localStorage.setItem('auth_token', res.data.access_token);
    localStorage.removeItem('user');
    window.location.href = '/home';
  };

  const stopImpersonation = async () => {
    try {
      await api.post('/impersonation/stop', {});
    } catch (error) {
      console.error('[useAuth] Error cerrando la sesión de soporte:', error);
    }

    if (!restorePlatformSession()) {
      localStorage.removeItem('auth_token');
      localStorage.removeItem('user');
      window.location.href = '/login';
    }
  };

  const hasFeature = (featureKey: string): boolean => {
    if (user?.is_saas_admin) return true;
    return !!user?.features?.[featureKey];
  };

  // Módulos activos del tenant: su plan, menos lo que apagan el rubro y la empresa.
  // Solo decide qué se muestra; la API valida lo mismo con el middleware `module`.
  const hasModule = (moduleKey: string): boolean => {
    if (user?.is_saas_admin) return true;
    return user?.tenant?.modules?.includes(moduleKey) ?? false;
  };

  const hasRole = (roleSlug: string): boolean => {
    if (user?.is_saas_admin && roleSlug === 'saas_admin') return true;
    return user?.roles?.includes(roleSlug) ?? false;
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        loading,
        login,
        logout,
        refetchUser: fetchUser,
        hasFeature,
        hasModule,
        hasRole,
        startImpersonation,
        stopImpersonation
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);