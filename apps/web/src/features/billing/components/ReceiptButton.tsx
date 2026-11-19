'use client';

import { useState } from 'react';
import { FileDown, Loader2 } from 'lucide-react';
import { api } from '@/lib/axios';

export default function ReceiptButton({ path }: { path: string }) {
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);

  const download = async () => {
    try {
      setBusy(true);
      setFailed(false);
      const res = await api.get(path, { responseType: 'blob' });
      const name = /filename="([^"]+)"/.exec(String(res.headers['content-disposition'] ?? ''))?.[1] ?? 'Constancia de pago.pdf';
      const url = URL.createObjectURL(res.data);
      const link = document.createElement('a');
      link.href = url;
      link.download = name;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
      setFailed(true);
    } finally {
      setBusy(false);
    }
  };

  return (
    <button type="button" data-receipt onClick={download} disabled={busy} title={failed ? 'No se pudo descargar. Inténtalo de nuevo.' : 'Descargar la constancia de pago (PDF)'} aria-label="Descargar constancia" className={`inline-flex items-center gap-1 text-[11px] font-bold ${failed ? 'text-red-600' : 'text-brand-accent hover:text-brand-ink'} disabled:opacity-60`}>
      {busy ? <Loader2 size={13} className="animate-spin" /> : <FileDown size={13} />} {failed ? 'Reintentar' : 'Constancia'}
    </button>
  );
}
