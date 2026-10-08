'use client';

import Link from 'next/link';
import { useParams } from 'next/navigation';
import { Construction, Loader2, Lock } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import { MODULE_LABELS, PENDING_SECTIONS } from '@/lib/modules';

// Página provisional de los módulos que ya se activan por plan y rubro pero
// todavía no tienen pantallas. Se reemplaza por la ruta real de cada módulo.
export default function ModulePage() {
  const params = useParams<{ key: string }>();
  const { hasModule, loading } = useAuth();
  const moduleKey = params.key;
  const section = PENDING_SECTIONS[moduleKey];
  const label = section?.label ?? MODULE_LABELS[moduleKey];

  if (loading) {
    return (
      <div className="min-h-[60vh] flex items-center justify-center">
        <Loader2 className="w-8 h-8 text-[#3F768C] animate-spin" />
      </div>
    );
  }

  const isAvailable = Boolean(label) && hasModule(section?.module ?? moduleKey);

  return (
    <div className="max-w-2xl mx-auto pt-10 font-sans text-[#1F2937]">
      <div className="bg-white rounded-3xl border border-slate-200/80 shadow-xs p-8 flex items-start gap-4">
        <span className="p-3 bg-[#84D0D9]/20 text-[#022340] rounded-2xl shrink-0">
          {isAvailable ? <Construction className="w-6 h-6" /> : <Lock className="w-6 h-6" />}
        </span>
        <div className="space-y-2">
          <h1 className="text-xl font-black text-[#022340] tracking-tight">
            {label ?? 'Módulo no encontrado'}
          </h1>
          <p className="text-sm text-slate-500 font-medium leading-relaxed">
            {isAvailable
              ? 'Está activo para tu empresa. Sus pantallas aún están en construcción.'
              : 'No está disponible para tu empresa. Depende de tu plan y del rubro de tu negocio.'}
          </p>
          <Link href="/home" className="inline-block text-xs font-bold text-[#3F768C] hover:text-[#022340] transition-colors">
            Volver al inicio
          </Link>
        </div>
      </div>
    </div>
  );
}
