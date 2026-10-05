'use client';

import React, { useState, useRef, useEffect } from 'react';
import { ChevronDown } from 'lucide-react';

export interface Option {
  label: string;
  value: string | number;
}

interface SelectProps {
  label?: string;
  options: Option[];
  placeholder?: string;
  icon?: React.ReactNode;
  value: string | number;
  onChange: (e: { target: { value: string | number } }) => void;
  required?: boolean;
  error?: string;
}

export default function Select({
  label,
  options,
  placeholder = 'Seleccione una opción...',
  icon,
  value,
  onChange,
  error,
}: SelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Encontrar la opción seleccionada actualmente para mostrar su etiqueta
  const selectedOption = options.find((opt) => opt.value === value);

  // Cerrar el menú si se hace clic fuera del componente
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleSelect = (optionValue: string | number) => {
    onChange({ target: { value: optionValue } });
    setIsOpen(false);
  };

  return (
    <div className="w-full relative" ref={containerRef}>
      {label && (
        <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
          {label}
        </label>
      )}

      {/* Botón principal que simula el input */}
      <div
        onClick={() => setIsOpen(!isOpen)}
        className={`w-full text-sm border rounded-lg py-2.5 bg-white text-slate-800 flex items-center justify-between cursor-pointer transition-all select-none ${
          icon ? 'pl-9 pr-3' : 'px-3.5'
        } ${
          isOpen
            ? 'border-[#022340] ring-2 ring-[#022340]/20'
            : 'border-slate-200 hover:border-slate-300'
        } ${error ? 'border-red-500 ring-red-200' : ''}`}
      >
        {/* Ícono izquierdo */}
        {icon && (
          <span className="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-slate-400 mt-6">
            {icon}
          </span>
        )}

        <span className={`truncate ${!selectedOption ? 'text-slate-400' : 'text-slate-800'}`}>
          {selectedOption ? selectedOption.label : placeholder}
        </span>

        <ChevronDown
          size={16}
          className={`text-slate-400 transition-transform duration-200 ${isOpen ? 'transform rotate-180' : ''}`}
        />
      </div>

      {/* Menú desplegable flotante personalizado */}
      {isOpen && (
        <div className="absolute z-50 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-xl max-h-60 overflow-y-auto py-1 animate-in fade-in zoom-in-95 duration-150">
          <div
            onClick={() => handleSelect('')}
            className="px-3.5 py-2 text-sm text-slate-400 hover:bg-slate-50 cursor-pointer transition-colors"
          >
            {placeholder}
          </div>
          {options.map((opt) => {
            const isSelected = opt.value === value;
            return (
              <div
                key={opt.value}
                onClick={() => handleSelect(opt.value)}
                className={`px-3.5 py-2 text-sm cursor-pointer transition-colors truncate ${
                  isSelected
                    ? 'bg-blue-50 text-[#022340] font-medium'
                    : 'text-slate-700 hover:bg-slate-50'
                }`}
              >
                {opt.label}
              </div>
            );
          })}
        </div>
      )}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}