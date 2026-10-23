'use client';

import Button from '@/app/components/common/Button';
import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import { CheckCircle2, Loader2, MailX } from 'lucide-react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';

interface State {
  company: string;
  name: string;
  unsubscribed: boolean;
}

export default function Unsubscribe() {
  const { token } = useParams<{ token: string }>();
  const [data, setData] = useState<State | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    api.get(`/promotions/unsubscribe/${token}`)
      .then((res) => setData(res.data))
      .catch((err) => setError(apiError(err, 'Este enlace no es válido o ya no existe.')));
  }, [token]);

  const confirm = async () => {
    try {
      setBusy(true);
      const res = await api.post(`/promotions/unsubscribe/${token}`);
      setData(res.data);
    } catch (err) {
      setError(apiError(err, 'No se pudo completar. Inténtalo de nuevo.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="min-h-screen bg-[#F2F2F2] flex items-center justify-center px-4" data-unsubscribe>
      <div className="w-full max-w-md bg-white rounded-3xl border border-slate-200 shadow-sm p-8 text-center">
        {!data && !error && <p className="flex items-center justify-center gap-2 text-sm text-slate-500"><Loader2 size={16} className="animate-spin" /> Cargando...</p>}

        {error && !data && (
          <>
            <span className="mx-auto mb-5 w-14 h-14 rounded-2xl bg-slate-100 text-slate-500 flex items-center justify-center"><MailX size={26} /></span>
            <h1 className="text-xl font-extrabold text-[#022340] mb-2">Enlace no válido</h1>
            <p className="text-sm text-slate-500">{error}</p>
          </>
        )}

        {data && !data.unsubscribed && (
          <>
            <span className="mx-auto mb-5 w-14 h-14 rounded-2xl bg-[#84D0D9]/25 text-[#3F768C] flex items-center justify-center"><MailX size={26} /></span>
            <h1 className="text-xl font-extrabold text-[#022340] mb-2">¿Dejar de recibir promociones?</h1>
            <p className="text-sm text-slate-500 leading-relaxed mb-6">
              Hola {data.name}: si confirmas, {data.company} ya no te enviará promociones por correo. Seguirás recibiendo los avisos de tus citas y tus comprobantes.
            </p>
            {error && <p className="text-sm text-red-600 mb-4">{error}</p>}
            <Button type="button" variant="primary" data-confirm disabled={busy} onClick={confirm} className="w-full">
              {busy ? 'Guardando...' : 'Sí, ya no quiero recibir promociones'}
            </Button>
          </>
        )}

        {data && data.unsubscribed && (
          <>
            <span className="mx-auto mb-5 w-14 h-14 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center"><CheckCircle2 size={26} /></span>
            <h1 className="text-xl font-extrabold text-[#022340] mb-2" data-done>Listo, {data.name}</h1>
            <p className="text-sm text-slate-500 leading-relaxed">
              {data.company} ya no te enviará promociones por correo. Si cambias de opinión, puedes volver a aceptarlas desde tu portal o pidiéndolo en la sede.
            </p>
          </>
        )}
      </div>
    </div>
  );
}
