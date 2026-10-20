'use client';

import './globals.css';
import ErrorScreen from './components/common/ErrorScreen';

export default function GlobalError({ retry }: { error: Error & { digest?: string }; retry: () => void }) {
  return (
    <html lang="es">
      <body className="font-sans bg-[#F2F2F2] text-slate-900 antialiased">
        <ErrorScreen kind="failure" onRetry={retry} fullPage />
      </body>
    </html>
  );
}
