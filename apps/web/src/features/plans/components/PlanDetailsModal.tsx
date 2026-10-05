'use client';

import React, { useState } from 'react';
import { 
  X, Clock, Users, Store, Briefcase, Infinity, 
  ShieldCheck, Cpu, HardDrive, Headphones, Layers, 
  CheckCircle2, DollarSign, Tag, FileText 
} from 'lucide-react';

interface SaaSPlan {
  id: string;
  name: string;
  slug: string;
  code_internal?: string;
  description?: string;
  price_monthly: number;
  price_yearly: number;
  currency?: string;
  limits?: {
    users?: number;
    usuarios?: number;
    branches?: number;
    sucursales?: number;
    sedes?: number;
    clients?: number;
    services?: number;
    [key: string]: any;
  };
  trial_config?: {
    has_trial: boolean;
    trial_days: number;
    require_payment_at_start?: boolean;
  };
  consumption?: {
    storage_gb?: number;
    api_requests_monthly?: number;
    [key: string]: any;
  };
  modules?: Record<string, any>;
  support?: {
    level?: string;
    tickets_monthly?: number;
    [key: string]: any;
  };
  features?: Record<string, any>;
  status: string;
  presentation_order?: number;
}

interface PlanDetailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  plan: SaaSPlan | null;
}

export default function PlanDetailsModal({ isOpen, onClose, plan }: PlanDetailsModalProps) {
  const [activeTab, setActiveTab] = useState<'general' | 'limits' | 'modules' | 'advanced'>('general');

  if (!isOpen || !plan) return null;

  const currency = plan.currency || 'PEN';

  const renderLimitValue = (value: number | undefined) => {
    if (value === undefined || value === null) return <span className="text-slate-400">-</span>;
    if (value === -1) {
      return (
        <span className="inline-flex items-center gap-1 text-[#022340] font-semibold text-xs">
          <Infinity size={14} /> Ilimitado
        </span>
      );
    }
    return <span className="font-semibold text-slate-800">{value}</span>;
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-fadeIn">
      {/* Contenedor con tamaño fijo para evitar saltos de diseño */}
      <div className="bg-white rounded-2xl shadow-2xl w-[680px] h-[620px] overflow-hidden border border-slate-100 flex flex-col">
        
        {/* Cabecera */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/75 shrink-0">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-[#022340]/10 text-[#022340] rounded-xl border border-[#022340]/20">
              <Tag size={20} />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h3 className="text-lg font-bold text-slate-900">{plan.name}</h3>
                {plan.code_internal && (
                  <span className="px-2 py-0.5 text-[10px] font-mono font-semibold bg-slate-200 text-slate-700 rounded">
                    {plan.code_internal}
                  </span>
                )}
              </div>
              <p className="text-xs text-slate-500 font-mono">slug: {plan.slug}</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <X size={18} />
          </button>
        </div>

        {/* Pestañas de navegación con color #022340 */}
        <div className="flex border-b border-slate-100 bg-slate-50/40 px-6 gap-6 text-sm font-medium shrink-0">
          <button
            onClick={() => setActiveTab('general')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'general' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <DollarSign size={16} /> General y Precios
          </button>
          <button
            onClick={() => setActiveTab('limits')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'limits' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <Users size={16} /> Límites y Consumo
          </button>
          <button
            onClick={() => setActiveTab('modules')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'modules' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <Layers size={16} /> Módulos y Soporte
          </button>
          <button
            onClick={() => setActiveTab('advanced')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'advanced' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <ShieldCheck size={16} /> Características
          </button>
        </div>

        {/* Contenido con Scroll interno fijo */}
        <div className="p-6 space-y-5 text-sm text-slate-700 overflow-y-auto flex-1">
          
          {/* TAB 1: GENERAL */}
          {activeTab === 'general' && (
            <div className="space-y-4 animate-fadeIn">
              {plan.description && (
                <div className="p-3.5 bg-slate-50 border border-slate-100 rounded-xl">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Descripción</span>
                  <p className="text-xs text-slate-600">{plan.description}</p>
                </div>
              )}

              <div className="grid grid-cols-2 gap-4">
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white shadow-xs">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Precio Mensual</span>
                  <span className="text-xl font-bold text-slate-900 mt-1 block">
                    {currency} {Number(plan.price_monthly).toFixed(2)}
                  </span>
                </div>
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white shadow-xs">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Precio Anual</span>
                  <span className="text-xl font-bold text-slate-900 mt-1 block">
                    {currency} {Number(plan.price_yearly).toFixed(2)}
                  </span>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Estado</span>
                  <span className={`inline-block px-2.5 py-0.5 text-xs font-medium rounded-full ${
                    plan.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-200 text-slate-600'
                  }`}>
                    {plan.status === 'active' ? 'Activo' : 'Inactivo'}
                  </span>
                </div>
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Orden de presentación</span>
                  <span className="text-xs font-semibold text-slate-800">#{plan.presentation_order ?? 'N/A'}</span>
                </div>
              </div>

              <div className="p-3.5 border border-slate-100 rounded-xl space-y-2 bg-white">
                <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Periodo de Prueba (Trial)</span>
                {plan.trial_config?.has_trial ? (
                  <div className="flex items-center gap-2 text-[#022340] bg-[#022340]/10 px-3 py-2 rounded-lg text-xs font-medium border border-[#022340]/20">
                    <Clock size={16} />
                    <span>{plan.trial_config.trial_days} días de prueba gratuita</span>
                  </div>
                ) : (
                  <span className="text-xs text-slate-500 font-medium block py-1">No cuenta con periodo de prueba</span>
                )}
              </div>
            </div>
          )}

          {/* TAB 2: LÍMITES Y CONSUMO */}
          {activeTab === 'limits' && (
            <div className="space-y-4 animate-fadeIn">
              <div className="space-y-2">
                <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Límites Cuantitativos</span>
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                  <div className="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center flex flex-col items-center justify-center gap-1">
                    <Users size={16} className="text-slate-400" />
                    <span className="text-slate-400 text-[11px]">Usuarios</span>
                    {renderLimitValue(plan.limits?.users ?? plan.limits?.usuarios)}
                  </div>
                  <div className="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center flex flex-col items-center justify-center gap-1">
                    <Store size={16} className="text-slate-400" />
                    <span className="text-slate-400 text-[11px]">Sucursales</span>
                    {renderLimitValue(plan.limits?.branches ?? plan.limits?.sucursales ?? plan.limits?.sedes)}
                  </div>
                  <div className="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center flex flex-col items-center justify-center gap-1">
                    <FileText size={16} className="text-slate-400" />
                    <span className="text-slate-400 text-[11px]">Clientes</span>
                    {renderLimitValue(plan.limits?.clients)}
                  </div>
                  <div className="bg-slate-50 p-3 rounded-xl border border-slate-100 text-center flex flex-col items-center justify-center gap-1">
                    <Briefcase size={16} className="text-slate-400" />
                    <span className="text-slate-400 text-[11px]">Servicios</span>
                    {renderLimitValue(plan.limits?.services)}
                  </div>
                </div>
              </div>

              <div className="space-y-2 pt-2">
                <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Consumo y Recursos</span>
                <div className="grid grid-cols-2 gap-3 text-xs">
                  <div className="p-3 border border-slate-100 rounded-xl bg-white flex items-center gap-3">
                    <div className="p-2 bg-[#022340]/10 text-[#022340] rounded-lg">
                      <HardDrive size={16} />
                    </div>
                    <div>
                      <span className="block text-slate-400 text-[11px]">Almacenamiento</span>
                      <span className="font-semibold text-slate-800">{plan.consumption?.storage_gb ?? 0} GB</span>
                    </div>
                  </div>
                  <div className="p-3 border border-slate-100 rounded-xl bg-white flex items-center gap-3">
                    <div className="p-2 bg-[#022340]/10 text-[#022340] rounded-lg">
                      <Cpu size={16} />
                    </div>
                    <div>
                      <span className="block text-slate-400 text-[11px]">Peticiones API / mes</span>
                      <span className="font-semibold text-slate-800">
                        {plan.consumption?.api_requests_monthly?.toLocaleString() ?? 0}
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 3: MÓDULOS Y SOPORTE */}
          {activeTab === 'modules' && (
            <div className="space-y-4 animate-fadeIn">
              <div className="space-y-2">
                <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Módulos Habilitados</span>
                <div className="grid grid-cols-1 gap-2">
                  {plan.modules && Object.keys(plan.modules).length > 0 ? (
                    Object.entries(plan.modules).map(([category, items]) => (
                      <div key={category} className="p-3 bg-slate-50 border border-slate-100 rounded-xl">
                        <span className="text-xs font-bold uppercase text-[#022340] block mb-1.5">{category}</span>
                        <div className="flex flex-wrap gap-1.5">
                          {Array.isArray(items) && items.map((mod: string) => (
                            <span key={mod} className="px-2 py-0.5 bg-white text-slate-700 border border-slate-200 text-xs rounded-md font-medium">
                              {mod}
                            </span>
                          ))}
                        </div>
                      </div>
                    ))
                  ) : (
                    <p className="text-xs text-slate-400">No hay módulos específicos registrados.</p>
                  )}
                </div>
              </div>

              <div className="space-y-2 pt-2">
                <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Nivel de Soporte</span>
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="p-2 bg-emerald-50 text-emerald-600 rounded-lg">
                      <Headphones size={16} />
                    </div>
                    <div>
                      <span className="block text-slate-400 text-[11px]">Atención</span>
                      <span className="font-semibold text-slate-800">{plan.support?.level || 'Estándar'}</span>
                    </div>
                  </div>
                  <div className="text-right">
                    <span className="block text-slate-400 text-[11px]">Tickets mensuales</span>
                    <span className="font-semibold text-slate-800">
                      {plan.support?.tickets_monthly === -1 ? 'Ilimitados' : (plan.support?.tickets_monthly ?? 0)}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* TAB 4: CARACTERÍSTICAS (FEATURES) */}
          {activeTab === 'advanced' && (
            <div className="space-y-2 animate-fadeIn">
              <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Parámetros y Permisos (Features)</span>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                {plan.features && Object.keys(plan.features).length > 0 ? (
                  Object.entries(plan.features).map(([key, value]) => (
                    <div key={key} className="flex items-center justify-between p-3 bg-slate-50 border border-slate-100 rounded-xl text-xs">
                      <span className="font-medium text-slate-700 capitalize">{key.replace(/_/g, ' ')}</span>
                      {typeof value === 'boolean' ? (
                        value ? (
                          <span className="flex items-center gap-1 text-emerald-600 font-semibold">
                            <CheckCircle2 size={14} /> Sí
                          </span>
                        ) : (
                          <span className="text-slate-400">No</span>
                        )
                      ) : (
                        <span className="font-semibold text-slate-900">{String(value)}</span>
                      )}
                    </div>
                  ))
                ) : (
                  <p className="text-xs text-slate-400">No hay características avanzadas configuradas.</p>
                )}
              </div>
            </div>
          )}

        </div>

        {/* Pie */}
        <div className="flex justify-end px-6 py-3.5 border-t border-slate-100 bg-slate-50/75 shrink-0">
          <button
            onClick={onClose}
            className="px-5 py-2 bg-[#022340] text-white text-xs font-semibold rounded-xl hover:bg-[#022340]/90 transition-colors shadow-sm"
          >
            Cerrar
          </button>
        </div>

      </div>
    </div>
  );
}