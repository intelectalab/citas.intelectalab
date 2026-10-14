interface CredentialsNoteProps {
  email: string;
  password: string;
}

// Correo y contraseña temporal de una cuenta: se muestran una sola vez.
export default function CredentialsNote({ email, password }: CredentialsNoteProps) {
  return (
    <div className="space-y-4 pt-1" data-credentials>
      <p className="text-sm text-slate-600">Entrégale estos datos para que entre al sistema:</p>

      <div className="space-y-2.5 p-4 bg-slate-50 border border-slate-200/80 rounded-2xl">
        <div className="flex items-center justify-between gap-3">
          <span className="text-sm font-medium text-slate-500">Correo</span>
          <span className="text-xs font-mono font-semibold text-slate-800 bg-white px-3 py-1.5 rounded-lg border border-slate-200 select-all">{email}</span>
        </div>
        <div className="flex items-center justify-between gap-3">
          <span className="text-sm font-medium text-slate-500">Contraseña</span>
          <span data-temporary-password className="text-xs font-mono font-bold text-slate-900 bg-white px-3 py-1.5 rounded-lg border border-slate-200 select-all">{password}</span>
        </div>
      </div>

      <p className="text-xs text-slate-500 bg-slate-100/80 border border-slate-200/60 p-3 rounded-xl font-medium">
        Cópiala antes de cerrar: no se vuelve a mostrar. Si se pierde, puedes darle otra con el botón de la llave.
      </p>
    </div>
  );
}
