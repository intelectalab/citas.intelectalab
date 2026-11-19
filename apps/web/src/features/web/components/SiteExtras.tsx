'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { Cookie, MessageCircle } from 'lucide-react';
import { API_URL, type SitePayload } from '../web';

const CONSENT_KEY = 'cookie_consent';

type Consent = 'all' | 'necessary' | null;

function readConsent(): Consent {
  try {
    const value = localStorage.getItem(CONSENT_KEY);
    return value === 'all' || value === 'necessary' ? value : null;
  } catch {
    return null;
  }
}

function pageOf(path: string): { page: string; business_type?: string } {
  if (path === '/') return { page: 'home' };
  if (path.startsWith('/precios')) return { page: 'pricing' };
  const rubro = path.match(/^\/rubros\/([a-z0-9_-]+)/);
  if (rubro) return { page: 'business', business_type: rubro[1] };
  if (path.startsWith('/rubros')) return { page: 'business' };
  if (/^\/blog\/[^/]+/.test(path)) return { page: 'post' };
  if (path.startsWith('/blog')) return { page: 'blog' };
  return { page: 'legal' };
}

function loadAnalytics(ga4: string | null, pixel: string | null) {
  const w = window as unknown as Record<string, any>;
  if (ga4 && !w.__ga4Loaded) {
    w.__ga4Loaded = true;
    const script = document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(ga4)}`;
    script.dataset.analytics = 'ga4';
    document.head.appendChild(script);
    w.dataLayer = w.dataLayer || [];
    w.gtag = function gtag() { w.dataLayer.push(arguments); };
    w.gtag('js', new Date());
    w.gtag('config', ga4);
  }
  if (pixel && !w.__pixelLoaded) {
    w.__pixelLoaded = true;
    const fbq: any = function (...args: unknown[]) { fbq.callMethod ? fbq.callMethod(...args) : fbq.queue.push(args); };
    fbq.queue = [];
    fbq.loaded = true;
    fbq.version = '2.0';
    w.fbq = w.fbq || fbq;
    w._fbq = w._fbq || fbq;
    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    script.dataset.analytics = 'meta';
    document.head.appendChild(script);
    w.fbq('init', pixel);
    w.fbq('track', 'PageView');
  }
}

export default function SiteExtras({ site }: { site: SitePayload }) {
  const pathname = usePathname();
  const [consent, setConsent] = useState<Consent>(null);
  const [ready, setReady] = useState(false);
  const [showBanner, setShowBanner] = useState(false);
  const lastPath = useRef('');
  const hasTrackers = Boolean(site.analytics.ga4_id || site.analytics.meta_pixel_id);

  useEffect(() => {
    const stored = readConsent();
    setConsent(stored);
    setShowBanner(site.cookie_banner && !stored);
    setReady(true);
    const reopen = () => setShowBanner(true);
    window.addEventListener('cookie-settings', reopen);
    return () => window.removeEventListener('cookie-settings', reopen);
  }, [site.cookie_banner]);

  useEffect(() => {
    if (ready && consent === 'all') loadAnalytics(site.analytics.ga4_id, site.analytics.meta_pixel_id);
  }, [ready, consent, site.analytics.ga4_id, site.analytics.meta_pixel_id]);

  useEffect(() => {
    if (lastPath.current === pathname) return;
    lastPath.current = pathname;
    const params = new URLSearchParams(window.location.search);
    const utm: Record<string, string> = {};
    ['source', 'medium', 'campaign', 'term', 'content'].forEach((key) => {
      const value = params.get(`utm_${key}`);
      if (value) utm[key] = value.slice(0, 80);
    });
    try {
      if (Object.keys(utm).length && !localStorage.getItem('site_utm')) localStorage.setItem('site_utm', JSON.stringify(utm));
    } catch {}
    const referrer = document.referrer && !document.referrer.startsWith(window.location.origin) ? document.referrer : '';
    fetch(`${API_URL}/web/visit`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ ...pageOf(pathname), utm_source: utm.source ?? null, referrer: referrer || null }),
      keepalive: true,
    }).catch(() => {});
    const w = window as unknown as Record<string, any>;
    if (pathname.startsWith('/precios') && typeof w.gtag === 'function') w.gtag('event', 'view_pricing');
  }, [pathname]);

  const choose = (value: 'all' | 'necessary') => {
    try { localStorage.setItem(CONSENT_KEY, value); } catch {}
    if (consent === 'all' && value === 'necessary') {
      window.location.reload();
      return;
    }
    setConsent(value);
    setShowBanner(false);
  };

  return (
    <>
      {site.whatsapp && (
        <a
          href={`https://wa.me/${site.whatsapp}?text=${encodeURIComponent(site.whatsapp_message)}`}
          target="_blank"
          rel="noopener noreferrer"
          aria-label="Escríbenos por WhatsApp"
          data-site-whatsapp
          className="fixed right-4 bottom-4 z-40 w-12 h-12 rounded-full bg-emerald-500 hover:bg-emerald-600 text-white shadow-lg flex items-center justify-center"
          style={showBanner ? { bottom: '9rem' } : undefined}
        >
          <MessageCircle size={22} />
        </a>
      )}

      {showBanner && (
        <div role="dialog" aria-label="Aviso de cookies" data-cookie-banner className="fixed inset-x-0 bottom-0 z-50 p-3 sm:p-4">
          <div className="max-w-3xl mx-auto bg-white border border-slate-200 shadow-2xl rounded-2xl p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center gap-3">
            <Cookie size={22} className="text-brand-accent shrink-0 hidden sm:block" />
            <p className="text-xs sm:text-sm text-slate-600 flex-1">
              {site.cookie_text} <Link href="/legal/cookies" className="font-semibold text-brand-accent hover:text-brand-ink">Más información</Link>
            </p>
            <div className="flex gap-2 shrink-0">
              <button type="button" data-cookie-necessary onClick={() => choose('necessary')} className="flex-1 sm:flex-none px-4 py-2 text-xs sm:text-sm font-semibold text-slate-700 border border-slate-200 rounded-xl hover:bg-slate-50 cursor-pointer">{hasTrackers ? 'Solo las necesarias' : 'Entendido'}</button>
              {hasTrackers && (
                <button type="button" data-cookie-accept onClick={() => choose('all')} className="flex-1 sm:flex-none px-4 py-2 text-xs sm:text-sm font-bold bg-brand text-white rounded-xl hover:bg-brand-hover cursor-pointer">Aceptar</button>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
}
