'use client';

import { useEffect, useRef } from 'react';

interface TurnstileApi {
  render: (element: HTMLElement, options: Record<string, unknown>) => string;
  reset: (widgetId?: string) => void;
  remove: (widgetId?: string) => void;
}

const SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function load(): Promise<void> {
  const ready = () => Boolean((window as unknown as { turnstile?: TurnstileApi }).turnstile);
  if (ready()) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const existing = document.querySelector<HTMLScriptElement>(`script[src="${SRC}"]`);
    const script = existing ?? document.createElement('script');
    script.addEventListener('load', () => resolve());
    script.addEventListener('error', () => reject(new Error('turnstile')));
    if (!existing) {
      script.src = SRC;
      script.async = true;
      document.head.appendChild(script);
    }
  });
}

export default function TurnstileBox({ siteKey, onToken, onUnavailable, resetKey = 0, error }: { siteKey: string | null | undefined; onToken: (token: string) => void; onUnavailable?: () => void; resetKey?: number; error?: string }) {
  const box = useRef<HTMLDivElement | null>(null);
  const widget = useRef<string | null>(null);
  const callback = useRef(onToken);
  callback.current = onToken;
  const unavailable = useRef(onUnavailable);
  unavailable.current = onUnavailable;

  useEffect(() => {
    if (!siteKey) return;
    let cancelled = false;
    load()
      .then(() => {
        const api = (window as unknown as { turnstile?: TurnstileApi }).turnstile;
        if (cancelled || !box.current || !api || widget.current) return;
        widget.current = api.render(box.current, {
          sitekey: siteKey,
          language: 'es',
          callback: (token: string) => callback.current(token),
          'expired-callback': () => callback.current(''),
          'error-callback': () => {
            callback.current('');
            unavailable.current?.();
          },
        });
      })
      .catch(() => {
        callback.current('');
        unavailable.current?.();
      });
    return () => {
      cancelled = true;
      const api = (window as unknown as { turnstile?: TurnstileApi }).turnstile;
      if (widget.current && api) api.remove(widget.current);
      widget.current = null;
    };
  }, [siteKey]);

  useEffect(() => {
    const api = (window as unknown as { turnstile?: TurnstileApi }).turnstile;
    if (resetKey > 0 && widget.current && api) {
      api.reset(widget.current);
      callback.current('');
    }
  }, [resetKey]);

  if (!siteKey) return null;

  return (
    <div className="space-y-1" data-turnstile>
      <div ref={box} />
      {error && <p className="text-[11px] font-semibold text-rose-600">{error}</p>}
    </div>
  );
}
