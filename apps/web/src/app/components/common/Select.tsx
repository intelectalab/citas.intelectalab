'use client';

import React, { useState, useRef } from 'react';
import { ChevronDown, Plus } from 'lucide-react';
import Floating from './Floating';

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
  // Sin la opción vacía: para listas donde siempre hay algo elegido.
  clearable?: boolean;
  disabled?: boolean;
  // 'chip': versión pequeña, sin caja, para ir dentro de una fila o un filtro.
  variant?: 'field' | 'chip';
  // Botón "+" junto a la lista, para crear lo que falta sin salir del formulario.
  onAdd?: () => void;
  addLabel?: string;
  ariaLabel?: string;
  // Hacia dónde se alinea el menú de la versión chip.
  align?: 'left' | 'right';
}

export default function Select({
  label,
  options,
  placeholder = 'Seleccione una opción...',
  icon,
  value,
  onChange,
  error,
  clearable = true,
  disabled = false,
  variant = 'field',
  onAdd,
  addLabel,
  ariaLabel,
  align = 'left',
}: SelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const triggerRef = useRef<HTMLDivElement>(null);

  // Encontrar la opción seleccionada actualmente para mostrar su etiqueta
  const selectedOption = options.find((opt) => opt.value === value);

  const handleSelect = (optionValue: string | number) => {
    onChange({ target: { value: optionValue } });
    setIsOpen(false);
  };

  const toggle = () => { if (!disabled) setIsOpen(!isOpen); };
  const isChip = variant === 'chip';

  // El menú flota sobre la página: no lo recorta el panel o la barra donde está la lista.
  const menu = (
    <Floating
      anchorRef={triggerRef}
      open={isOpen}
      onClose={() => setIsOpen(false)}
      align={align}
      matchWidth={!isChip}
      role="listbox"
      className={`bg-white border border-slate-200 rounded-lg shadow-xl overflow-hidden flex flex-col animate-in fade-in duration-150 ${
        isChip ? 'min-w-44 w-max max-w-72' : ''
      }`}
    >
      <div className="max-h-60 min-h-0 overflow-y-auto select-scroll py-1">
      {clearable && (
        <div
          role="option"
          aria-selected={!selectedOption}
          onClick={() => handleSelect('')}
          className="px-3.5 py-2 text-sm text-slate-400 hover:bg-slate-50 cursor-pointer transition-colors"
        >
          {placeholder}
        </div>
      )}
      {options.map((opt) => {
        const isSelected = opt.value === value;
        return (
          <div
            key={opt.value}
            role="option"
            aria-selected={isSelected}
            data-option={opt.value}
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
    </Floating>
  );

  if (isChip) {
    return (
      <div className="relative inline-flex items-center">
        <div
          ref={triggerRef}
          role="combobox"
          aria-expanded={isOpen}
          aria-label={ariaLabel ?? label}
          aria-disabled={disabled}
          onClick={toggle}
          className={`flex items-center gap-1 text-xs leading-5 font-medium rounded-md pl-2 pr-1 select-none transition-colors ${
            disabled ? 'text-slate-400 cursor-not-allowed' : 'text-slate-700 cursor-pointer hover:bg-slate-100'
          } ${isOpen ? 'bg-slate-100' : ''}`}
        >
          <span className={`truncate max-w-48 ${!selectedOption ? 'text-slate-500' : ''}`}>{selectedOption ? selectedOption.label : placeholder}</span>
          <ChevronDown size={13} className={`text-slate-400 transition-transform duration-200 ${isOpen ? 'transform rotate-180' : ''}`} />
        </div>
        {menu}
      </div>
    );
  }

  return (
    <div className="w-full relative">
      {label && (
        <label className="block text-xs font-semibold uppercase tracking-wider text-slate-600 mb-1.5">
          {label}
        </label>
      )}

      <div className={onAdd ? 'flex items-center gap-2' : undefined}>
        {/* Botón principal que simula el input */}
        <div
          ref={triggerRef}
          role="combobox"
          aria-expanded={isOpen}
          aria-label={ariaLabel ?? label}
          aria-disabled={disabled}
          onClick={toggle}
          className={`text-sm border rounded-lg py-2.5 flex items-center justify-between transition-all select-none ${
            onAdd ? 'flex-1 min-w-0' : 'w-full'
          } ${
            disabled ? 'bg-slate-50 text-slate-400 cursor-not-allowed' : 'bg-white text-slate-800 cursor-pointer'
          } ${
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

          <span className={`truncate ${!selectedOption ? 'text-slate-400' : disabled ? 'text-slate-500' : 'text-slate-800'}`}>
            {selectedOption ? selectedOption.label : placeholder}
          </span>

          <ChevronDown
            size={16}
            className={`text-slate-400 shrink-0 transition-transform duration-200 ${isOpen ? 'transform rotate-180' : ''}`}
          />
        </div>

        {onAdd && (
          <button
            type="button"
            data-select-add
            title={addLabel ?? 'Agregar'}
            aria-label={addLabel ?? 'Agregar'}
            onClick={() => { setIsOpen(false); onAdd(); }}
            className="shrink-0 p-2.5 bg-[#022340] text-white rounded-lg hover:bg-[#03315a] shadow-sm transition-colors"
          >
            <Plus size={18} className="stroke-[2.5]" />
          </button>
        )}
      </div>

      {/* Menú desplegable flotante personalizado */}
      {menu}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}
