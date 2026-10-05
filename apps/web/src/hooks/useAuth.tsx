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

export interface User {
  id: number | string;
  first_name: string;
  last_name: string;
  email: string;
  is_owner?: boolean;
  is_saas_admin?: boolean;
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
  hasRole: (roleSlug: string) => boolean;
}

const AuthContext = createContext<AuthContextType>({
  user: null,
  loading: true,
  login: () => { },
  logout: async () => { },
  refetchUser: async () => { },
  hasFeature: () => false,
  hasRole: () => false,
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
      localStorage.removeItem('user');
      setUser(null);
      setLoading(false);
      window.location.href = '/login';
    }
  };

  const hasFeature = (featureKey: string): boolean => {
    if (user?.is_saas_admin) return true;
    return !!user?.features?.[featureKey];
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
        hasRole
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => useContext(AuthContext);