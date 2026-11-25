'use client';

import { useEffect } from 'react';
import { reportError } from '@/lib/reportError';
import './globals.css';
import ErrorScreen from './components/common/ErrorScreen';

export default function GlobalError({ error, retry }: { error: Error & { digest?: string }; retry: () => void }) {
  useEffect(() => { reportError(error, { kind: error.digest ? `Error de pantalla ${error.digest}` : undefined }); }, [error]);

  return (
    <html lang="es">
      <body className="font-sans bg-[#F2F2F2] text-slate-900 antialiased">
        <ErrorScreen kind="failure" onRetry={retry} fullPage />
      </body>
    </html>
  );
}
