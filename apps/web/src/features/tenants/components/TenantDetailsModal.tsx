'use client';

import React, { useState } from 'react';
import { 
  X, Building2, Clock, CheckCircle2, XCircle, 
  Users, Mail, CreditCard, Calendar, Shield, Globe 
} from 'lucide-react';

interface Tenant {
  id: string;
  name: string;
  slug: string;
  timezone: string;
  status: string;
  created_at: string;
  subscription?: {
    id?: string;
    status?: string;
    plan?: {
      name?: string;
      price_monthly?: number;
      currency?: string;
    };
    starts_at?: string;
    expires_at?: string;
    [key: string]: any;
  } | null;
  users?: Array<{
    id: string;
    first_name?: string;
    last_name?: string;
    name?: string;
    email: string;
    role?: string;
    [key: string]: any;
  }>;
  [key: string]: any;
}

interface TenantDetailsModalProps {
  isOpen: boolean;
  onClose: () => void;
  tenant: Tenant | null;
}

export default function TenantDetailsModal({ isOpen, onClose, tenant }: TenantDetailsModalProps) {
  const [activeTab, setActiveTab] = useState<'general' | 'subscription' | 'users'>('general');

  if (!isOpen || !tenant) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 animate-fadeIn">
      <div className="bg-white rounded-2xl shadow-2xl w-[680px] h-[620px] overflow-hidden border border-slate-100 flex flex-col">
        
        {/* Cabecera */}
        <div className="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50/75 shrink-0">
          <div className="flex items-center gap-3">
            <div className="p-2.5 bg-[#022340]/10 text-[#022340] rounded-xl border border-[#022340]/20">
              <Building2 size={20} />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h3 className="text-lg font-bold text-slate-900">{tenant.name}</h3>
                <span className={`px-2 py-0.5 text-[10px] font-semibold rounded-full ${
                  tenant.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-200 text-slate-600'
                }`}>
                  {tenant.status === 'active' ? 'Activo' : 'Inactivo'}
                </span>
              </div>
              <p className="text-xs text-slate-500 font-mono">slug: {tenant.slug}</p>
            </div>
          </div>
          <button
            onClick={onClose}
            className="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <X size={18} />
          </button>
        </div>

        {/* Pestañas de navegación */}
        <div className="flex border-b border-slate-100 bg-slate-50/40 px-6 gap-6 text-sm font-medium shrink-0">
          <button
            onClick={() => setActiveTab('general')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'general' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <Building2 size={16} /> Información General
          </button>
          <button
            onClick={() => setActiveTab('subscription')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'subscription' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <CreditCard size={16} /> Suscripción
          </button>
          <button
            onClick={() => setActiveTab('users')}
            className={`py-3 border-b-2 transition-colors flex items-center gap-2 ${
              activeTab === 'users' 
                ? 'border-[#022340] text-[#022340] font-semibold' 
                : 'border-transparent text-slate-600 hover:text-slate-900'
            }`}
          >
            <Users size={16} /> Usuarios ({tenant.users?.length || 0})
          </button>
        </div>

        {/* Contenido con Scroll interno */}
        <div className="p-6 space-y-5 text-sm text-slate-700 overflow-y-auto flex-1">
          
          {/* TAB 1: GENERAL */}
          {activeTab === 'general' && (
            <div className="space-y-4 animate-fadeIn">
              <div className="grid grid-cols-2 gap-4">
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white shadow-xs">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Zona Horaria</span>
                  <div className="flex items-center gap-2 text-slate-800 font-medium text-xs">
                    <Clock size={16} className="text-slate-400" />
                    <span>{tenant.timezone}</span>
                  </div>
                </div>
                <div className="p-3.5 border border-slate-100 rounded-xl bg-white shadow-xs">
                  <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold mb-1">Fecha de Registro</span>
                  <div className="flex items-center gap-2 text-slate-800 font-medium text-xs">
                    <Calendar size={16} className="text-slate-400" />
                    <span>{new Date(tenant.created_at).toLocaleDateString()}</span>
                  </div>
                </div>
              </div>

              <div className="p-4 bg-slate-50 border border-slate-100 rounded-xl space-y-2">
                <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Identificador de Sistema</span>
                <p className="text-xs text-slate-600 font-mono bg-white p-2.5 rounded-lg border border-slate-200">
                  ID: {tenant.id}
                </p>
              </div>
            </div>
          )}

          {/* TAB 2: SUSCRIPCIÓN */}
          {activeTab === 'subscription' && (
            <div className="space-y-4 animate-fadeIn">
              {tenant.subscription ? (
                <div className="space-y-3">
                  <div className="p-4 bg-[#022340]/5 border border-[#022340]/10 rounded-xl space-y-2">
                    <div className="flex items-center justify-between">
                      <span className="text-xs font-bold uppercase tracking-wider text-[#022340]">Plan Actual</span>
                      {/* Badge cambiado al azul institucional en lugar del morado */}
                      <span className="px-2.5 py-0.5 text-xs font-semibold bg-[#022340] text-white rounded-md uppercase">
                        {tenant.subscription.status || 'Activa'}
                      </span>
                    </div>
                    <h4 className="text-base font-bold text-slate-900">
                      {tenant.subscription.plan?.name || 'Plan Personalizado / Sin Nombre'}
                    </h4>
                    {tenant.subscription.plan?.price_monthly && (
                      <p className="text-xs text-slate-600 font-medium">
                        Costo mensual: <span className="font-bold text-slate-900">{tenant.subscription.plan.currency || 'PEN'} {tenant.subscription.plan.price_monthly}</span>
                      </p>
                    )}
                  </div>

                  <div className="grid grid-cols-2 gap-3 text-xs">
                    <div className="p-3 border border-slate-100 rounded-xl bg-white">
                      <span className="block text-slate-400 text-[11px]">Inicia</span>
                      <span className="font-semibold text-slate-800">
                        {tenant.subscription.starts_at ? new Date(tenant.subscription.starts_at).toLocaleDateString() : 'N/A'}
                      </span>
                    </div>
                    <div className="p-3 border border-slate-100 rounded-xl bg-white">
                      <span className="block text-slate-400 text-[11px]">Expira</span>
                      <span className="font-semibold text-slate-800">
                        {tenant.subscription.expires_at ? new Date(tenant.subscription.expires_at).toLocaleDateString() : 'N/A'}
                      </span>
                    </div>
                  </div>
                </div>
              ) : (
                <div className="text-center py-8 space-y-2">
                  <CreditCard size={32} className="mx-auto text-slate-300" />
                  <p className="text-xs text-slate-500 font-medium">Este tenant no cuenta con ninguna suscripción asociada.</p>
                </div>
              )}
            </div>
          )}

          {/* TAB 3: USUARIOS */}
          {activeTab === 'users' && (
            <div className="space-y-3 animate-fadeIn">
              <span className="block text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Contactos y Usuarios Registrados</span>
              {tenant.users && tenant.users.length > 0 ? (
                <div className="space-y-2">
                  {tenant.users.map((user) => {
                    const fullName = user.name || `${user.first_name || ''} ${user.last_name || ''}`.trim();
                    const initial = fullName ? fullName.charAt(0).toUpperCase() : 'U';

                    return (
                      <div key={user.id} className="flex items-center justify-between p-3 bg-slate-50 border border-slate-100 rounded-xl">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded-full bg-[#022340]/10 text-[#022340] font-bold text-xs flex items-center justify-center">
                            {initial}
                          </div>
                          <div>
                            <p className="text-xs font-semibold text-slate-900">{fullName || 'Sin nombre'}</p>
                            <p className="text-[11px] text-slate-500 flex items-center gap-1">
                              <Mail size={12} /> {user.email}
                            </p>
                          </div>
                        </div>
                        {user.role && (
                          <span className="px-2 py-0.5 bg-white border border-slate-200 text-slate-700 text-[11px] rounded-md font-medium">
                            {user.role}
                          </span>
                        )}
                      </div>
                    );
                  })}
                </div>
              ) : (
                <div className="text-center py-8 space-y-2">
                  <Users size={32} className="mx-auto text-slate-300" />
                  <p className="text-xs text-slate-500 font-medium">No hay usuarios vinculados a este tenant.</p>
                </div>
              )}
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