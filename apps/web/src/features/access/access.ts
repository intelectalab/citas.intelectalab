// Tipos y textos de Usuarios y roles.

export type Scope = 'tenant' | 'branch' | 'own';

export interface RoleOption {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  is_system: boolean;
  // Qué tanto ve quien tiene el rol.
  scope: Scope;
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
  // view, create, edit, delete o uno especial (forzar horario, acceso de emergencia...).
  action: string;
  // Permiso especial: va en su propia columna de la rejilla.
  special?: boolean;
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

export const ACTION_ORDER = ['view', 'create', 'edit', 'delete'];
export const ACTION_LABELS: Record<string, string> = { view: 'Ver', create: 'Crear', edit: 'Editar', delete: 'Eliminar' };

// Qué tanto ve quien tiene un rol, con palabras.
export const SCOPE_OPTIONS: { id: Scope; title: string; hint: string }[] = [
  { id: 'tenant', title: 'Toda la empresa', hint: 'Ve y trabaja con todas las sedes.' },
  { id: 'branch', title: 'Solo su sede', hint: 'La agenda, las citas, la caja, las ventas, las salas y el equipo de la sede de cada persona. Clientes, servicios y cupones siguen siendo de toda la empresa.' },
  { id: 'own', title: 'Solo lo suyo', hint: 'Sus propias citas, fichas y controles. Es para quien atiende.' },
];

export const scopeTitle = (scope?: Scope) => SCOPE_OPTIONS.find((o) => o.id === scope)?.title ?? 'Toda la empresa';

// Para quien trabaja solo con su sede y aún no tiene una asignada.
export const NO_BRANCH_NOTE = 'Trabaja solo con su sede, pero no tiene una asignada: no verá agenda, caja ni ventas hasta que se la asignes.';

export const initialsOf = (name: string) =>
  name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase();

export const countOn = (grid: GridModule[]) => grid.reduce((sum, m) => sum + m.actions.filter((a) => a.on).length, 0);
export const countAll = (grid: GridModule[]) => grid.reduce((sum, m) => sum + m.actions.length, 0);
export const countOverride = (grid: GridModule[], value: boolean) =>
  grid.reduce((sum, m) => sum + m.actions.filter((a) => a.override === value).length, 0);
