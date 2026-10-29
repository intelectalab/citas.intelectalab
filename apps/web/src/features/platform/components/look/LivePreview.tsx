'use client';

import { useEffect, useRef, useState } from 'react';
import { Monitor, Smartphone } from 'lucide-react';
import { PREVIEW_MESSAGE, type PlatformConfig } from '@/hooks/usePlatform';

export type PreviewPage = { id: string; label: string; path: string };

interface LivePreviewProps {
  pages: PreviewPage[];
  config: PlatformConfig;
  dirty: boolean;
}

const SIZES = { desktop: { width: 1280, height: 820 }, mobile: { width: 390, height: 780 } };

export default function LivePreview({ pages, config, dirty }: LivePreviewProps) {
  const [device, setDevice] = useState<'desktop' | 'mobile'>('desktop');
  const [pageId, setPageId] = useState(pages[0].id);
  const [width, setWidth] = useState(0);
  const box = useRef<HTMLDivElement>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  const page = pages.find((p) => p.id === pageId) ?? pages[0];
  const size = SIZES[device];
  const scale = width ? Math.min(1, width / size.width) : 0.4;

  useEffect(() => {
    if (!pages.some((p) => p.id === pageId)) setPageId(pages[0].id);
  }, [pages, pageId]);

  useEffect(() => {
    const element = box.current;
    if (!element) return;
    const observer = new ResizeObserver(([entry]) => setWidth(entry.contentRect.width));
    observer.observe(element);
    return () => observer.disconnect();
  }, []);

  const send = () => frame.current?.contentWindow?.postMessage({ type: PREVIEW_MESSAGE, config }, window.location.origin);

  useEffect(() => {
    send();
  });

  useEffect(() => {
    const ready = (event: MessageEvent) => {
      if (event.origin === window.location.origin && event.data?.type === `${PREVIEW_MESSAGE}-ready`) send();
    };
    window.addEventListener('message', ready);
    return () => window.removeEventListener('message', ready);
  });

  const separator = page.path.includes('?') ? '&' : '?';

  return (
    <div className="space-y-3" data-live-preview={page.id}>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="flex items-center gap-1.5 text-xs font-bold text-slate-600">
          <span className="w-2 h-2 rounded-full bg-emerald-500" /> Vista previa en vivo {dirty ? '(sin guardar)' : ''}
        </span>
        <div className="flex flex-wrap gap-2">
          {pages.length > 1 && (
            <div className="inline-flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label="Página de la vista previa">
              {pages.map((p) => (
                <button key={p.id} type="button" role="radio" aria-checked={p.id === page.id} data-preview-page={p.id} onClick={() => setPageId(p.id)} className={`px-2.5 py-1 rounded-md text-xs font-semibold ${p.id === page.id ? 'bg-brand text-white' : 'text-slate-600'}`}>
                  {p.label}
                </button>
              ))}
            </div>
          )}
          <div className="inline-flex rounded-lg border border-slate-200 p-0.5" role="radiogroup" aria-label="Tamaño de la vista previa">
            {([['desktop', 'Escritorio', Monitor], ['mobile', 'Móvil', Smartphone]] as const).map(([id, label, Icon]) => (
              <button key={id} type="button" role="radio" aria-checked={device === id} data-preview-device={id} onClick={() => setDevice(id)} className={`flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-semibold ${device === id ? 'bg-brand text-white' : 'text-slate-600'}`}>
                <Icon size={12} /> {label}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div ref={box} className="w-full">
        <div className="mx-auto rounded-2xl border border-slate-300 bg-slate-200 overflow-hidden shadow-sm" style={{ width: size.width * scale, height: size.height * scale }}>
          <iframe
            key={`${page.path}-${device}`}
            ref={frame}
            title="Vista previa"
            src={`${page.path}${separator}preview=platform`}
            onLoad={send}
            style={{ width: size.width, height: size.height, transform: `scale(${scale})`, transformOrigin: 'top left', pointerEvents: 'none', border: 0 }}
            tabIndex={-1}
          />
        </div>
      </div>
    </div>
  );
}
