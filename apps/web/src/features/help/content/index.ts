import type { HelpTopics } from '../types';
import { agendaTopics } from './agenda';
import { atencionTopics } from './atencion';
import { catalogoTopics } from './catalogo';
import { equipoTopics } from './equipo';
import { ventasTopics } from './ventas';

export const helpTopics: HelpTopics = (vocab) => ({
  ...agendaTopics(vocab),
  ...atencionTopics(vocab),
  ...equipoTopics(vocab),
  ...ventasTopics(vocab),
  ...catalogoTopics(vocab),
});
