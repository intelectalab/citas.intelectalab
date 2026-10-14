'use client';

import { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { api } from '@/lib/axios';

export interface PlatformConfig {
  product_name: string;
  support_email: string | null;
  accent_color: string;
  usage_warning_percent: number;
  usage_danger_percent: number;
  allow_tenant_signup: boolean;
}

export const PLATFORM_DEFAULTS: PlatformConfig = {
  product_name: 'CoreERP',
  support_email: null,
  accent_color: '#10B981',
  usage_warning_percent: 70,
  usage_danger_percent: 90,
  allow_tenant_signup: true,
};

interface PlatformContextType {
  platform: PlatformConfig;
  applyPlatform: (values: Partial<PlatformConfig>) => void;
}

const PlatformContext = createContext<PlatformContextType>({
  platform: PLATFORM_DEFAULTS,
  applyPlatform: () => { },
});

export const PlatformProvider = ({ children }: { children: ReactNode }) => {
  const [platform, setPlatform] = useState<PlatformConfig>(PLATFORM_DEFAULTS);

  useEffect(() => {
    api.get('/platform')
      .then((res) => setPlatform({ ...PLATFORM_DEFAULTS, ...res.data }))
      .catch(() => setPlatform(PLATFORM_DEFAULTS));
  }, []);

  useEffect(() => {
    document.documentElement.style.setProperty('--accent', platform.accent_color);
  }, [platform.accent_color]);

  const applyPlatform = (values: Partial<PlatformConfig>) => setPlatform((prev) => ({ ...prev, ...values }));

  return (
    <PlatformContext.Provider value={{ platform, applyPlatform }}>
      {children}
    </PlatformContext.Provider>
  );
};

export const usePlatform = () => useContext(PlatformContext);
