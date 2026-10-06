'use client';

interface SwitchProps {
  checked: boolean;
  onChange: (checked: boolean) => void;
  // Texto para lectores de pantalla: qué enciende o apaga.
  label: string;
  disabled?: boolean;
}

// Interruptor de encendido/apagado. Es un botón, así responde al clic por sí
// solo y también cuando está dentro de una etiqueta (label) clicable.
export default function Switch({ checked, onChange, label, disabled = false }: SwitchProps) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      disabled={disabled}
      onClick={() => onChange(!checked)}
      className={`relative inline-flex items-center w-10 h-6 rounded-full shrink-0 transition-colors cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:ring-[#3F768C] disabled:opacity-50 disabled:cursor-not-allowed ${
        checked ? 'bg-[var(--accent)]' : 'bg-slate-300'
      }`}
    >
      <span className={`absolute left-1 w-4 h-4 rounded-full bg-[#fff] shadow transition-transform ${checked ? 'translate-x-4' : ''}`} />
    </button>
  );
}
