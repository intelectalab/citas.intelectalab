'use client';

import { useEffect } from 'react';
import { reportError } from '@/lib/reportError';
import ErrorScreen from '../components/common/ErrorScreen';

export default function DashboardError({ error, retry }: { error: Error & { digest?: string }; retry: () => void }) {
  useEffect(() => { reportError(error, { kind: error.digest ? `Error de pantalla ${error.digest}` : undefined }); }, [error]);

  return <ErrorScreen kind="failure" onRetry={retry} />;
}
