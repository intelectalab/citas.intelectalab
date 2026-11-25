'use client';

import { useEffect } from 'react';
import { reportError } from '@/lib/reportError';

export default function ErrorReporter() {
  useEffect(() => {
    const onError = (event: ErrorEvent) => reportError(event.error ?? event.message, { file: event.filename, line: event.lineno });
    const onRejection = (event: PromiseRejectionEvent) => {
      const reason = event.reason;
      if (reason && typeof reason === 'object' && 'isAxiosError' in reason) return;
      reportError(reason, { kind: 'Promesa sin atender' });
    };
    window.addEventListener('error', onError);
    window.addEventListener('unhandledrejection', onRejection);
    return () => {
      window.removeEventListener('error', onError);
      window.removeEventListener('unhandledrejection', onRejection);
    };
  }, []);

  return null;
}
