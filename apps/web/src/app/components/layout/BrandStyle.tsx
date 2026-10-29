'use client';

import { useEffect } from 'react';
import { useAuth } from '@/hooks/useAuth';
import { usePlatform } from '@/hooks/usePlatform';
import { resolveColors, styleText, tokens, type TenantBranding } from '@/lib/brand';

const DARK_SCOPE = ":is(html[data-theme='dark'] .theme-scope, .theme-scope.force-dark)";

const setFavicon = (href: string | null) => {
  const link = document.querySelector<HTMLLinkElement>('link[rel="icon"]') ?? Object.assign(document.createElement('link'), { rel: 'icon' });
  if (!link.dataset.original) link.dataset.original = link.getAttribute('href') ?? '/favicon.ico';
  link.href = href ?? link.dataset.original;
  if (!link.parentNode) document.head.appendChild(link);
};

function BrandTag({ branding, force, scope }: { branding: Partial<TenantBranding> | null; force: boolean; scope: string }) {
  const vars = tokens(resolveColors(branding), force);
  if (!vars) return null;

  return (
    <style data-brand-scope={scope}>
      {`html:root{${styleText(vars.light)}}html:root ${DARK_SCOPE}{${styleText(vars.dark)}}`}
    </style>
  );
}

export function PlatformStyle() {
  const { platform } = usePlatform();
  const brand = platform.look.brand;
  const favicon = brand.logo_url ?? brand.logo_dark_url;

  useEffect(() => {
    if (document.documentElement.dataset.tenantFavicon === '1') return;
    setFavicon(favicon);
  }, [favicon]);

  useEffect(() => {
    const html = document.documentElement;
    if (!document.title || document.title === html.dataset.productTitle) {
      document.title = platform.product_name;
      html.dataset.productTitle = platform.product_name;
    }
  }, [platform.product_name]);

  return <BrandTag branding={brand} force={false} scope="platform" />;
}

export default function BrandStyle() {
  const { user } = useAuth();
  const tenant = user?.tenant ?? null;
  const { platform } = usePlatform();
  const favicon = tenant?.branding?.logo_url ?? platform.look.brand.logo_url ?? null;

  useEffect(() => {
    if (!tenant) return;
    document.documentElement.dataset.tenantFavicon = '1';
    setFavicon(favicon);
    return () => {
      delete document.documentElement.dataset.tenantFavicon;
    };
  }, [tenant, favicon]);

  if (!tenant) return null;

  return <BrandTag branding={tenant.branding ?? null} force scope="tenant" />;
}
