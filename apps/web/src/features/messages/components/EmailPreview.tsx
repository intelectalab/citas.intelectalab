'use client';

import { useEffect, useRef, useState } from 'react';
import { Loader2 } from 'lucide-react';
import { api } from '@/lib/axios';

interface EmailPreviewProps {
  subject?: string;
  body?: string;
  design?: { color: string; show_name: boolean; footer: string };
  version?: number;
  title?: string;
}

export default function EmailPreview({ subject, body, design, version = 0, title = 'Así le llega (con datos de ejemplo)' }: EmailPreviewProps) {
  const [html, setHtml] = useState('');
  const [shownSubject, setShownSubject] = useState('');
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);
  const [height, setHeight] = useState(320);
  const frame = useRef<HTMLIFrameElement>(null);
  const key = JSON.stringify({ subject, body, design, version });

  useEffect(() => {
    let alive = true;
    const payload = JSON.parse(key);
    const timer = window.setTimeout(() => {
      setLoading(true);
      api.post('/messaging/preview', { subject: payload.subject ?? '', body: payload.body ?? '', ...(payload.design ?? {}) })
        .then((res) => { if (!alive) return; setHtml(res.data.html); setShownSubject(res.data.subject); setFailed(false); })
        .catch(() => { if (alive) setFailed(true); })
        .finally(() => { if (alive) setLoading(false); });
    }, 350);
    return () => { alive = false; window.clearTimeout(timer); };
  }, [key]);

  const fit = () => {
    const doc = frame.current?.contentDocument;
    if (doc?.body) setHeight(Math.min(Math.max(doc.documentElement.scrollHeight, 200), 1400));
  };

  return (
    <div className="rounded-2xl border border-slate-200 overflow-hidden" data-email-preview>
      <div className="flex items-center justify-between gap-2 px-5 py-2.5 bg-slate-50/80 border-b border-slate-100">
        <span className="text-xs font-bold text-slate-500">{title}</span>
        {loading && <Loader2 size={14} className="animate-spin text-slate-400" />}
      </div>
      {subject !== undefined && <div className="px-5 py-3 text-sm font-bold text-brand-ink border-b border-slate-100" data-preview-subject>{shownSubject || 'Sin asunto'}</div>}
      {failed ? (
        <p className="px-5 py-4 text-sm text-slate-500">No se pudo mostrar la vista previa. Revisa tu conexión.</p>
      ) : (
        <iframe ref={frame} title="Vista previa del correo" sandbox="allow-same-origin" srcDoc={html} onLoad={fit} className="w-full block bg-[#f1f5f9]" style={{ height }} />
      )}
    </div>
  );
}
