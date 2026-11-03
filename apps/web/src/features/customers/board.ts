export interface BoardStage {
  id: string;
  name: string;
  description?: string | null;
  color: string;
  system_key?: string | null;
  customers: number;
}

export interface BoardInfo {
  ready: boolean;
  message?: string;
  stages: BoardStage[];
  default_stage_id: string | null;
  colors: string[];
  max_columns: number;
  can_move: boolean;
  can_edit_columns: boolean;
}

export interface StageChange {
  id: string;
  from: string | null;
  to: string | null;
  source: 'board' | 'form' | 'detail' | 'column_deleted' | 'auto';
  created_at: string;
  by: { id: string; name: string } | null;
}

export const STAGE_SOURCE: Record<StageChange['source'], string> = {
  board: 'desde el tablero',
  form: 'desde el formulario',
  detail: 'desde su página',
  column_deleted: 'al borrar su columna',
  auto: 'solo, según sus citas',
};

export const AUTO_STAGE = 'auto';

export const stageOf = (info: BoardInfo | null, stageId?: string | null): BoardStage | undefined => {
  if (!info) return undefined;
  return info.stages.find((stage) => stage.id === stageId) ?? info.stages.find((stage) => stage.id === info.default_stage_id);
};
