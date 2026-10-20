'use client';

import { useRouter } from 'next/navigation';
import { ArrowLeft, Home, RotateCw, SearchX, TriangleAlert, type LucideIcon } from 'lucide-react';
import Button from './Button';

const KINDS: Record<'not-found' | 'failure', { code: string; icon: LucideIcon; title: string; message: string }> = {
  'not-found': {
    code: '404',
    icon: SearchX,
    title: 'No encontramos esta página',
    message: 'Puede que la dirección esté mal escrita o que la página ya no exista. Vuelve atrás o entra al inicio para seguir.',
  },
  failure: {
    code: 'Error',
    icon: TriangleAlert,
    title: 'Algo salió mal',
    message: 'Ocurrió un problema al mostrar esta pantalla. Inténtalo de nuevo; si sigue pasando, avísale al administrador de tu empresa.',
  },
};

function homePath() {
  try {
    const user = JSON.parse(localStorage.getItem('user') ?? 'null');
    if (!user) return '/login';
    return user.account_type === 'customer' ? '/portal' : '/home';
  } catch {
    return '/login';
  }
}

interface ErrorScreenProps {
  kind: 'not-found' | 'failure';
  onRetry?: () => void;
  fullPage?: boolean;
}

export default function ErrorScreen({ kind, onRetry, fullPage = false }: ErrorScreenProps) {
  const router = useRouter();
  const { code, icon: Icon, title, message } = KINDS[kind];

  return (
    <div data-error-screen={kind} className={`flex items-center justify-center px-4 ${fullPage ? 'min-h-screen bg-[#F2F2F2]' : 'min-h-[70vh]'}`}>
      <div className="w-full max-w-lg bg-white rounded-3xl border border-slate-200 shadow-sm p-8 sm:p-10 text-center">
        <div className="mx-auto mb-6 w-16 h-16 rounded-2xl bg-[#84D0D9]/25 text-[#3F768C] flex items-center justify-center">
          <Icon size={30} />
        </div>
        <p className="text-sm font-bold text-[#3F768C] mb-2">{code}</p>
        <h1 className="text-2xl font-extrabold text-[#022340] mb-3">{title}</h1>
        <p className="text-sm text-slate-500 leading-relaxed mb-8">{message}</p>
        <div className="flex flex-col-reverse sm:flex-row gap-3 justify-center">
          {onRetry ? (
            <Button variant="secondary" type="button" onClick={() => router.push(homePath())} className="inline-flex items-center justify-center gap-2">
              <Home size={16} /> Ir al inicio
            </Button>
          ) : (
            <Button variant="secondary" type="button" onClick={() => router.back()} className="inline-flex items-center justify-center gap-2">
              <ArrowLeft size={16} /> Volver atrás
            </Button>
          )}
          {onRetry ? (
            <Button variant="primary" type="button" onClick={onRetry} className="inline-flex items-center justify-center gap-2">
              <RotateCw size={16} /> Intentar de nuevo
            </Button>
          ) : (
            <Button variant="primary" type="button" onClick={() => router.push(homePath())} className="inline-flex items-center justify-center gap-2">
              <Home size={16} /> Ir al inicio
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
