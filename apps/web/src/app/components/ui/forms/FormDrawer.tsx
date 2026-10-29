'use client';

import { X } from 'lucide-react';
import React, { useEffect } from 'react';

interface FormDrawerProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  icon?: React.ReactNode;
  children: React.ReactNode;
  maxWidth?: string;
}

export default function FormDrawer({ isOpen, onClose, title, subtitle, icon, children, maxWidth = 'max-w-xl' }: FormDrawerProps) {
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    if (isOpen) window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden">
      <div 
        className="absolute inset-0 bg-slate-900/15 transition-opacity" 
        onClick={onClose}
      />

      <div className="absolute inset-y-0 right-0 max-w-full flex">
        <div className={`w-screen ${maxWidth} bg-white shadow-2xl border-l border-slate-200 flex flex-col transform transition-transform duration-300 ease-in-out`}>
          
          <div className="flex items-center justify-between gap-3 sm:gap-0 px-4 sm:px-6 py-4 sm:py-5 border-b border-slate-100 bg-slate-50/85">
            <div className="flex items-center gap-3">
              {icon && (
                <div className="p-2 bg-blue-50 text-brand-ink rounded-lg">
                  {icon}
                </div>
              )}
              <div>
                <h3 className="text-base font-bold text-slate-900">{title}</h3>
                {subtitle && <p className="text-xs text-slate-500">{subtitle}</p>}
              </div>
            </div>
            <button 
              onClick={onClose}
              className="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 transition-colors"
            >
              <X size={18} />
            </button>
          </div>

          <div className="flex-1 overflow-y-auto p-4 sm:p-6">
            {children}
          </div>
        </div>
      </div>
    </div>
  );
}