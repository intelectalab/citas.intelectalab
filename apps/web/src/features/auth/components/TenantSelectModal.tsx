'use client';

import { motion, AnimatePresence } from 'framer-motion';
import { Building2, ChevronRight, X } from 'lucide-react';

export interface LoginAccount {
  account_id: string;
  account_type: 'staff' | 'customer';
  tenant_id: string | null;
  tenant_name: string;
  business_type: string | null;
  color: string | null;
}

interface TenantSelectModalProps {
  isOpen: boolean;
  accounts: LoginAccount[];
  loadingAccountId: string | null;
  errorMessage: string | null;
  onSelect: (account: LoginAccount) => void;
  onClose: () => void;
}

export function TenantSelectModal({
  isOpen,
  accounts,
  loadingAccountId,
  errorMessage,
  onSelect,
  onClose,
}: TenantSelectModalProps) {
  const isBusy = loadingAccountId !== null;

  return (
    <AnimatePresence>
      {isOpen && (
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          exit={{ opacity: 0 }}
          className="fixed inset-0 z-50 bg-brand/60 backdrop-blur-xs flex items-center justify-center p-4"
        >
          <motion.div
            role="dialog"
            aria-modal="true"
            aria-labelledby="tenant-select-title"
            initial={{ opacity: 0, y: 16, scale: 0.98 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 16, scale: 0.98 }}
            transition={{ duration: 0.2 }}
            className="w-full max-w-md bg-white rounded-3xl border border-slate-200/80 shadow-xl overflow-hidden"
          >
            <div className="flex items-start justify-between gap-4 p-6 pb-4">
              <div className="space-y-1">
                <h2 id="tenant-select-title" className="text-xl font-black text-brand-ink tracking-tight">
                  ¿A qué empresa deseas ingresar?
                </h2>
                <p className="text-xs text-slate-500 font-medium">
                  Tu correo está registrado en más de una empresa.
                </p>
              </div>
              <button
                type="button"
                onClick={onClose}
                disabled={isBusy}
                aria-label="Cerrar"
                className="p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors disabled:opacity-50 cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {errorMessage && (
              <div className="mx-6 mb-3 p-3 rounded-xl bg-red-50 border border-red-200 text-red-600 text-xs font-medium">
                {errorMessage}
              </div>
            )}

            <ul className="px-6 pb-6 space-y-2 max-h-[50vh] overflow-y-auto">
              {accounts.map((account) => {
                const isLoading = loadingAccountId === account.account_id;
                return (
                  <li key={account.account_id}>
                    <button
                      type="button"
                      onClick={() => onSelect(account)}
                      disabled={isBusy}
                      className="w-full flex items-center gap-3 p-3 rounded-2xl border border-slate-200 bg-slate-50/60 hover:bg-white hover:border-brand-accent text-left transition-all disabled:opacity-60 cursor-pointer"
                    >
                      <span
                        className="w-10 h-10 rounded-xl flex items-center justify-center text-white shrink-0"
                        style={{ backgroundColor: account.color ?? '#022340' }}
                      >
                        <Building2 className="w-5 h-5" />
                      </span>
                      <span className="flex-1 min-w-0">
                        <span className="block text-sm font-bold text-brand-ink truncate">
                          {account.tenant_name}
                        </span>
                        <span className="block text-[11px] text-slate-500 font-medium truncate">
                          {[account.business_type, account.account_type === 'customer' ? 'Portal de cliente' : 'Panel de la empresa']
                            .filter(Boolean)
                            .join(' · ')}
                        </span>
                      </span>
                      {isLoading ? (
                        <span className="w-5 h-5 border-2 border-brand-accent border-t-transparent rounded-full animate-spin shrink-0" />
                      ) : (
                        <ChevronRight className="w-4 h-4 text-slate-400 shrink-0" />
                      )}
                    </button>
                  </li>
                );
              })}
            </ul>
          </motion.div>
        </motion.div>
      )}
    </AnimatePresence>
  );
}
