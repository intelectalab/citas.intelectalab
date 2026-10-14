// Tipos y textos de Usuarios y roles.

export interface RoleOption {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  is_system: boolean;
}

export interface RoleRow extends RoleOption {
  // El administrador puede todo y no se recorta.
  locked: boolean;
  users: number;
  permissions: number;
  total: number;
  created_at?: string;
}

export interface BranchOption {
  id: string;
  name: string;
}

export interface StaffUser {
  id: string;
  name: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  status: string;
  is_owner: boolean;
  is_self: boolean;
  role: { id: string; slug: string; name: string } | null;
  branch: BranchOption | null;
  // Si además atiende citas, su página de profesional.
  professional_id: string | null;
  // Cuántos permisos se le agregaron y cuántos se le quitaron respecto de su rol.
  granted?: number;
  revoked?: number;
  created_at?: string;
}

export interface GridAction {
  action: 'view' | 'create' | 'edit' | 'delete';
  name: string;
  label: string;
  hint: string;
  // Lo trae su rol.
  role: boolean;
  // true: se le agregó a la persona; false: se le quitó; null: sigue a su rol.
  override: boolean | null;
  on: boolean;
}

export interface GridModule {
  key: string;
  label: string;
  actions: GridAction[];
}

export interface PlanUsage {
  used: number;
  limit: number | null;
}

export const ACTION_ORDER: GridAction['action'][] = ['view', 'create', 'edit', 'delete'];
export const ACTION_LABELS: Record<GridAction['action'], string> = { view: 'Ver', create: 'Crear', edit: 'Editar', delete: 'Eliminar' };

// El gerente de sede existe ya, pero el límite a su sede llega en una próxima entrega.
export const MANAGER_NOTE = 'Por ahora quien tiene este rol ve todas las sedes de la empresa. Que vea solo la suya llegará en una próxima entrega.';

export const initialsOf = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

export const countOn = (grid: GridModule[]) => grid.reduce((sum, m) => sum + m.actions.filter((a) => a.on).length, 0);
export const countAll = (grid: GridModule[]) => grid.reduce((sum, m) => sum + m.actions.length, 0);
export const countOverride = (grid: GridModule[], value: boolean) =>
  grid.reduce((sum, m) => sum + m.actions.filter((a) => a.override === value).length, 0);
