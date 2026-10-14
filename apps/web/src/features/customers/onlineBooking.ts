export interface OnlineBookingState {
  misses: number;
  last_miss_at: string;
  blocked: boolean;
  blocked_until: string | null;
  limit: number | null;
}

const day = (iso: string) => new Date(iso).toLocaleDateString('es-PE', { day: 'numeric', month: 'short', year: 'numeric' }).replace(/\./g, '');

export const missesLabel = (state: OnlineBookingState): string =>
  `${state.misses} ${state.misses === 1 ? 'inasistencia' : 'inasistencias'} a citas reservadas en línea`;

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
