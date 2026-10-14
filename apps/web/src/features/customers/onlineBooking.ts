// Inasistencias de un cliente a citas que reservó en línea, y si por eso ya no
// puede reservar por el portal. Lo calcula la API según lo que eligió la empresa.

export interface OnlineBookingState {
  misses: number;
  last_miss_at: string;
  blocked: boolean;
  // Bloqueado y sin fecha: hasta que la empresa lo vuelva a permitir.
  blocked_until: string | null;
  // A cuántas inasistencias se le cierra la reserva en línea; null si la empresa solo deja la nota.
  limit: number | null;
}

const day = (iso: string) => new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }).replace(/\./g, '');

export const missesLabel = (state: OnlineBookingState): string =>
  `${state.misses} ${state.misses === 1 ? 'inasistencia' : 'inasistencias'} a citas reservadas en línea`;

// "No puede reservar en línea hasta el 9 nov 2026", o lo que le falta para llegar a eso.
export const blockLabel = (state: OnlineBookingState): string | null => {
  if (state.blocked) {
    return state.blocked_until ? `No puede reservar en línea hasta el ${day(state.blocked_until)}` : 'No puede reservar en línea hasta que se le permita';
  }

  if (state.limit && state.misses < state.limit) {
    const left = state.limit - state.misses;
    return `Con ${left === 1 ? 'una más' : `${left} más`} ya no podrá reservar en línea`;
  }

  return null;
};
