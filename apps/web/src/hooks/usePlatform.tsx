'use client';

import { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import { api } from '@/lib/axios';

export interface LookCard {
  icon: string;
  title: string;
  text: string;
}

export interface PlatformLook {
  brand: {
    short_name: string | null;
    primary: string | null;
    accent: string | null;
    menu: string | null;
    made_by: string | null;
    made_by_url: string | null;
    logo_url: string | null;
    logo_dark_url: string | null;
  };
  landing: {
    tag: string | null;
    title: string;
    highlight: string | null;
    description: string | null;
    primary_button: string;
    secondary_button: string;
    plans_title: string;
    plans_text: string | null;
    cards: LookCard[];
    image_url: string | null;
  };
  login: {
    tag: string | null;
    title: string;
    highlight: string | null;
    description: string | null;
    cards: LookCard[];
    background: 'brand' | 'pattern-dots' | 'pattern-grid' | 'pattern-waves' | 'image';
    panel_side: 'left' | 'right';
    image_url: string | null;
  };
  maintenance: { message: string | null; starts_at: string | null; ends_at: string | null } | null;
}

export interface PlatformConfig {
  product_name: string;
  support_email: string | null;
  accent_color: string;
  usage_warning_percent: number;
  usage_danger_percent: number;
  allow_tenant_signup: boolean;
  look: PlatformLook;
}

export const LOOK_DEFAULTS: PlatformLook = {
  brand: { short_name: null, primary: null, accent: null, menu: null, made_by: null, made_by_url: null, logo_url: null, logo_dark_url: null },
  landing: {
    tag: 'Plataforma Empresarial SaaS',
    title: 'Gestiona tu empresa desde una sola plataforma.',
    highlight: null,
    description: 'Selecciona el plan que necesitas, registra tu empresa y accede de forma inmediata a los módulos habilitados para tu suscripción.',
    primary_button: 'Ver planes',
    secondary_button: 'Ya tengo una cuenta',
    plans_title: 'Elige el plan para tu empresa',
    plans_text: 'Los módulos disponibles dependen del plan contratado.',
    cards: [],
    image_url: null,
  },
  login: {
    tag: 'ERP Multi-Tenant',
    title: 'Gestiona tus operaciones en un solo lugar.',
    highlight: null,
    description: 'Acceso centralizado para finanzas, inventario, clientes y servicios especializados.',
    cards: [{ icon: 'shield', title: 'Todo tu negocio conectado', text: 'Mantén el control de tus ventas, sucursales y citas en una sola plataforma.' }],
    background: 'brand',
    panel_side: 'left',
    image_url: null,
  },
  maintenance: null,
};

export const PLATFORM_DEFAULTS: PlatformConfig = {
  product_name: 'CoreERP',
  support_email: null,
  accent_color: '#10B981',
  usage_warning_percent: 70,
  usage_danger_percent: 90,
  allow_tenant_signup: true,
  look: LOOK_DEFAULTS,
};

export const PREVIEW_MESSAGE = 'platform-preview';

interface PlatformContextType {
  platform: PlatformConfig;
  preview: boolean;
  applyPlatform: (values: Partial<PlatformConfig>) => void;
}

const PlatformContext = createContext<PlatformContextType>({
  platform: PLATFORM_DEFAULTS,
  preview: false,
  applyPlatform: () => { },
});

const merge = (data: Partial<PlatformConfig>): PlatformConfig => ({
  ...PLATFORM_DEFAULTS,
  ...data,
  look: { ...LOOK_DEFAULTS, ...(data.look ?? {}) },
});

export const PlatformProvider = ({ children }: { children: ReactNode }) => {
  const [platform, setPlatform] = useState<PlatformConfig>(PLATFORM_DEFAULTS);
  const [preview, setPreview] = useState(false);

  useEffect(() => {
    const isPreview = window.parent !== window && new URLSearchParams(window.location.search).get('preview') === 'platform';
    setPreview(isPreview);

    if (isPreview) {
      const receive = (event: MessageEvent) => {
        if (event.origin === window.location.origin && event.data?.type === PREVIEW_MESSAGE) setPlatform(merge(event.data.config));
      };
      window.addEventListener('message', receive);
      window.parent.postMessage({ type: `${PREVIEW_MESSAGE}-ready` }, window.location.origin);
      return () => window.removeEventListener('message', receive);
    }

    api.get('/platform')
      .then((res) => setPlatform(merge(res.data)))
      .catch(() => setPlatform(PLATFORM_DEFAULTS));
  }, []);

  useEffect(() => {
    document.documentElement.style.setProperty('--accent', platform.accent_color);
  }, [platform.accent_color]);

  const applyPlatform = (values: Partial<PlatformConfig>) => setPlatform((prev) => ({ ...prev, ...values }));

  return (
    <PlatformContext.Provider value={{ platform, preview, applyPlatform }}>
      {children}
    </PlatformContext.Provider>
  );
};

export const usePlatform = () => useContext(PlatformContext);
