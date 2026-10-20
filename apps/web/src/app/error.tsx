'use client';

import ErrorScreen from './components/common/ErrorScreen';

export default function AppError({ retry }: { error: Error & { digest?: string }; retry: () => void }) {
  return <ErrorScreen kind="failure" onRetry={retry} fullPage />;
}
