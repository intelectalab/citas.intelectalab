import React from 'react';
import { AlertTriangle, Info } from 'lucide-react';

interface AppModalProps {
  isOpen: boolean;
  type?: 'confirm' | 'alert';
  variant?: 'default' | 'danger';
  title: string;
  message: string | React.ReactNode;
  confirmText?: string;
  cancelText?: string;
  onConfirm: () => void;
  onClose: () => void;
  isLoading?: boolean;
  maxWidth?: string;
}

export const AppModal: React.FC<AppModalProps> = ({
  isOpen,
  type = 'confirm',
  variant = 'default',
  title,
  message,
  confirmText = 'Aceptar',
  cancelText = 'Cancelar',
  onConfirm,
  onClose,
  isLoading = false,
  maxWidth = 'max-w-md'
}) => {
  if (!isOpen) return null;

  const isDanger = variant === 'danger';

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm animate-fadeIn">
      <div className={`bg-white rounded-2xl p-6 ${maxWidth} w-full shadow-2xl border border-slate-100 transform transition-all`}>
        
        <div className="flex items-start gap-4">
          <div className={`p-2.5 rounded-xl shrink-0 ${
            isDanger ? 'bg-red-50 text-red-600' : 'bg-slate-100 text-slate-700'
          }`}>
            {isDanger ? <AlertTriangle size={20} /> : <Info size={20} />}
          </div>

          <div className="flex-1">
            <h3 className="text-lg font-semibold text-slate-900">{title}</h3>
            {typeof message === 'string' ? (
              <p className="text-sm text-slate-500 mt-1.5 leading-relaxed whitespace-pre-line">
                {message}
              </p>
            ) : (
              <div className="text-sm text-slate-500 mt-1.5 leading-relaxed">
                {message}
              </div>
            )}
          </div>
        </div>
        
        <div className="mt-6 flex items-center justify-end gap-3 pt-4 border-t border-slate-100">
          {type === 'confirm' && (
            <button
              type="button"
              onClick={onClose}
              disabled={isLoading}
              className="px-4 py-2 border border-slate-200 rounded-xl text-slate-700 hover:bg-slate-50 font-medium text-sm transition-colors disabled:opacity-50"
            >
              {cancelText}
            </button>
          )}
          
          <button
            type="button"
            onClick={onConfirm}
            disabled={isLoading}
            className={`px-4 py-2 rounded-xl font-medium text-sm transition-colors shadow-sm disabled:opacity-50 flex items-center justify-center min-w-[90px] ${
              isDanger 
                ? 'bg-red-600 text-white hover:bg-red-700 shadow-red-100' 
                : 'bg-brand text-white hover:bg-brand-hover shadow-slate-100'
            }`}
          >
            {isLoading ? (
              <span className="flex items-center gap-2">
                <svg className="animate-spin h-4 w-4 text-white" viewBox="0 0 24 24" fill="none">
                  <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                  <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                Procesando...
              </span>
            ) : (
              confirmText
            )}
          </button>
        </div>
      </div>
    </div>
  );
};