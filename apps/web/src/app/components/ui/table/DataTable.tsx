'use client';

import { useEffect, useMemo, useState, ReactNode } from 'react';
import {
  Search,
  Filter,
  ArrowUpDown,
  Plus,
  Lock,
  ChevronDown,
  MoreHorizontal,
  Download,
  SlidersHorizontal,
  LayoutGrid,
  Table as TableIcon,
  ChevronLeft,
  ChevronRight,
  Edit2,
  X,
  Mail,
  Clock,
  ExternalLink
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import styles from './DataTable.module.css';
import Select from '@/app/components/common/Select';
import DateFilter from './DateFilter';
import { PAGE_SIZE } from '@/app/components/common/Pager';
import { dateRangeOf, inDateRange, type DateFilterValue, type DateRange } from './dateFilters';

export type FilterType = 'select' | 'date' | 'daterange' | 'text';

const STAGES = [
  { id: 'suscriptor', name: 'Suscriptor', colorClass: styles.badgeSuscriptor },
  { id: 'lead', name: 'Lead', colorClass: styles.badgeLead },
  { id: 'mql', name: 'Lead calificado por marketing', colorClass: styles.badgeMql },
  { id: 'sql', name: 'Lead calificado por ventas', colorClass: styles.badgeSql },
  { id: 'oportunidad', name: 'Oportunidad', colorClass: styles.badgeOportunidad },
  { id: 'cliente', name: 'Cliente', colorClass: styles.badgeCliente },
];

export interface Column<T> {
  header: string;
  accessorKey?: keyof T;
  cell?: (item: T) => ReactNode;
  className?: string;
}

export interface FilterOption {
  label: string;
  value: string | number;
}

export interface FilterConfig {
  id: string;
  label: string;
  type: FilterType;
  options?: FilterOption[];
}

/** Filtro de fecha: la tabla lo aplica sola sobre las filas que recibe. */
export interface DateFilterConfig<T> {
  id: string;
  label: string;
  // Fecha de la fila; con `end`, la fila ocupa un tramo de días.
  value: (item: T) => string | null | undefined;
  end?: (item: T) => string | null | undefined;
  // La fecha puede ser futura (citas, controles): ofrece mañana, próxima semana, etc.
  future?: boolean;
  // Para pantallas que piden sus filas por periodo: se les avisa del rango elegido.
  onRange?: (range: DateRange | null) => void;
}

/** Un criterio de "Ordenar por". */
export interface SortOption<T> {
  id: string;
  label: string;
  type?: 'text' | 'number' | 'date';
  value: (item: T) => string | number | null | undefined;
}

// Cómo se llama cada sentido según lo que se ordena.
const SORT_DIRECTIONS: Record<'text' | 'number' | 'date', { first: 'asc' | 'desc'; asc: string; desc: string }> = {
  text: { first: 'asc', asc: 'A → Z', desc: 'Z → A' },
  number: { first: 'desc', asc: 'Menor primero', desc: 'Mayor primero' },
  date: { first: 'desc', asc: 'Más antiguo', desc: 'Más reciente' },
};

export interface TabOption {
  id: string;
  label: string;
  count?: number;
}

export interface MetricCardItem {
  title: string;
  value: string | number;
  icon?: ReactNode;
  onClick?: () => void;
  borderColorClass?: string;
  iconColorClass?: string;
}

interface DataTableProps<T> {
  title: string;
  primaryActionLabel?: string;
  onPrimaryAction?: () => void;
  /** Si viene, el botón principal queda bloqueado y muestra este motivo. */
  primaryActionBlocked?: string;
  tabs?: TabOption[];
  activeTab?: string;
  onTabChange?: (tabId: string) => void;
  searchTerm?: string;
  onSearchChange?: (term: string) => void;
  filterChips?: string[];

  filterConfigs?: FilterConfig[];
  filterValues?: Record<string, any>;
  onFilterChange?: (filterId: string, value: any) => void;
  dateFilters?: DateFilterConfig<T>[];
  sortOptions?: SortOption<T>[];

  columns: Column<T>[];
  data: T[];
  getItemKey: (item: T) => string | number;
  entityLabel?: string;
  
  metricCards?: MetricCardItem[];
  // Filas por página.
  pageSize?: number;
}

export function DataTable<T extends Record<string, any>>({
  title,
  primaryActionLabel = 'Nuevo',
  onPrimaryAction,
  primaryActionBlocked,
  tabs = [],
  activeTab,
  onTabChange,
  searchTerm = '',
  onSearchChange,
  filterChips = [],
  filterConfigs = [],
  filterValues = {},
  onFilterChange,
  dateFilters = [],
  sortOptions = [],
  columns,
  data: allData,
  getItemKey,
  entityLabel = 'registros',
  metricCards,
  pageSize = PAGE_SIZE,
}: DataTableProps<T>) {
  const [viewMode, setViewMode] = useState<'table' | 'panel'>('table');
  const [showFilters, setShowFilters] = useState(false);
  const [showSort, setShowSort] = useState(false);
  const [dateValues, setDateValues] = useState<Record<string, DateFilterValue | null>>({});
  const [sortId, setSortId] = useState('');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

  const activeSort = sortOptions.find((option) => option.id === sortId);
  const sortType = activeSort?.type ?? 'text';
  const hasFilters = filterConfigs.length > 0 || dateFilters.length > 0 || filterChips.length > 0;
  const activeFilterCount =
    filterConfigs.filter((config) => filterValues[config.id] !== undefined && filterValues[config.id] !== null && filterValues[config.id] !== '').length +
    dateFilters.filter((config) => dateValues[config.id]).length;

  const changeDate = (config: DateFilterConfig<T>, value: DateFilterValue | null) => {
    setDateValues((current) => ({ ...current, [config.id]: value }));
    config.onRange?.(dateRangeOf(value));
  };

  const clearFilters = () => {
    filterConfigs.forEach((config) => {
      if (filterValues[config.id]) onFilterChange?.(config.id, '');
    });
    dateFilters.forEach((config) => {
      if (dateValues[config.id]) changeDate(config, null);
    });
  };

  const changeSort = (id: string) => {
    setSortId(id);
    const option = sortOptions.find((item) => item.id === id);
    if (option) setSortDir(SORT_DIRECTIONS[option.type ?? 'text'].first);
  };

  // Las filas que se ven: lo que llega, menos lo que dejan fuera las fechas, en el orden elegido.
  const data = useMemo(() => {
    let rows = allData;

    dateFilters.forEach((config) => {
      const range = dateRangeOf(dateValues[config.id]);
      if (range) rows = rows.filter((item) => inDateRange(range, config.value(item), config.end?.(item)));
    });

    if (!activeSort) return rows;

    const type = activeSort.type ?? 'text';
    const factor = sortDir === 'asc' ? 1 : -1;
    const empty = (v: unknown) => v === null || v === undefined || v === '';

    return [...rows].sort((a, b) => {
      const va = activeSort.value(a);
      const vb = activeSort.value(b);

      // Lo que no tiene dato va siempre al final.
      if (empty(va) || empty(vb)) return empty(va) === empty(vb) ? 0 : empty(va) ? 1 : -1;

      if (type === 'number') return (Number(va) - Number(vb)) * factor;
      if (type === 'date') return (new Date(String(va)).getTime() - new Date(String(vb)).getTime()) * factor;
      return String(va).localeCompare(String(vb), 'es', { sensitivity: 'base', numeric: true }) * factor;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allData, dateValues, sortId, sortDir]);

  // Paginación: la tabla muestra una página; el tablero, todo.
  const [page, setPage] = useState(1);
  const pages = Math.max(1, Math.ceil(data.length / pageSize));
  const currentPage = Math.min(page, pages);
  const pageRows = data.slice((currentPage - 1) * pageSize, currentPage * pageSize);
  const filterKey = JSON.stringify(filterValues);

  // Al buscar, filtrar, ordenar o cambiar de pestaña se vuelve a la primera página.
  useEffect(() => {
    setPage(1);
  }, [searchTerm, activeTab, filterKey, dateValues, sortId, sortDir]);

  return (
    <div className={styles.container}>
      {/* Header Superior (Título y botón principal) */}
      <div className={styles.header}>
        <div className={styles.headerTop}>
          <div className={styles.titleGroup}>
            <h1 className={styles.title}>{title}</h1>
            <ChevronDown size={20} className="text-slate-400 hover:text-[#022340] cursor-pointer transition-colors" />
          </div>

          <div className={styles.actionsGroup}>
            <button className={styles.actionBtnSecondary}>
              <MoreHorizontal size={18} />
            </button>
            {onPrimaryAction && primaryActionBlocked && (
              <div className={styles.blockedAction} data-primary-blocked>
                <button type="button" className={`${styles.btnPrimary} ${styles.btnPrimaryBlocked}`} disabled aria-describedby="primary-blocked-reason">
                  <Lock size={16} className="text-[#84D0D9]" />
                  {primaryActionLabel}
                </button>
                <div id="primary-blocked-reason" role="tooltip" className={styles.blockedReason}>
                  {primaryActionBlocked}
                </div>
              </div>
            )}
            {onPrimaryAction && !primaryActionBlocked && (
              <button className={styles.btnPrimary} onClick={onPrimaryAction}>
                <Plus size={18} className="text-[#84D0D9] stroke-[2.5]" />
                {primaryActionLabel}
              </button>
            )}
          </div>
        </div>

        {/* Pestañas (Pill Control) */}
        {tabs.length > 0 && (
          <div className={styles.tabsContainer}>
            <div className={styles.tabsList}>
              {tabs.map((tab) => {
                const isActive = activeTab === tab.id;
                return (
                  <button
                    key={tab.id}
                    onClick={() => onTabChange?.(tab.id)}
                    className={styles.tabBtn}
                  >
                    {isActive && (
                      <motion.div
                        layoutId="activeTabBackground"
                        className={styles.activeIndicator}
                        transition={{ type: 'spring', stiffness: 400, damping: 30 }}
                      />
                    )}
                    <span className={isActive ? styles.tabTextActive : styles.tabText}>
                      {tab.label}
                    </span>
                    {tab.count !== undefined && (
                      <span className={`${styles.tabCount} ${isActive ? styles.tabCountActive : ''}`}>
                        {tab.count}
                      </span>
                    )}
                  </button>
                );
              })}
            </div>

            <button className={styles.addTabBtn} title="Añadir vista personalizada">
              <Plus size={15} />
            </button>
          </div>
        )}
      </div>

      {/* Contenedor Padre Exclusivo para las Tarjetas de Métricas (Espaciado equilibrado) */}
      {metricCards && metricCards.length > 0 && (
        <div className="mt-5 mb-5 px-4 relative z-10">
          <div className={`grid grid-cols-1 sm:grid-cols-3 ${metricCards.length > 3 ? "xl:grid-cols-6 gap-4" : "gap-5"}`}>
            {metricCards.map((card, idx) => (
              <div
                key={idx}
                onClick={card.onClick}
                className={`relative overflow-hidden bg-white px-4 py-3 rounded-2xl border border-slate-100 shadow-[0_4px_20px_-4px_rgba(0,0,0,0.05)] transition-all duration-300 ${
                  card.onClick ? 'cursor-pointer hover:-translate-y-1 hover:shadow-[0_10px_25px_-5px_rgba(0,0,0,0.08)] hover:border-slate-200' : ''
                } ${card.borderColorClass || ''}`}
              >
                <div className="flex items-center justify-between">
                  <div>
                    <span className="text-[10px] font-semibold tracking-wider text-slate-400 uppercase">
                      {card.title}
                    </span>
                    <div className="mt-0.5 text-2xl font-extrabold tracking-tight text-slate-900">
                      {card.value}
                    </div>
                  </div>
                  {card.icon && (
                    <div className={`p-2 rounded-xl bg-slate-50 border border-slate-100 ${card.iconColorClass || 'text-slate-500'}`}>
                      {card.icon}
                    </div>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Barra de Filtros */}
      <div className={styles.filterSection}>
        <div className={styles.filterTopRow}>
          <div className={styles.filterLeftGroup}>
            {onSearchChange && (
              <div className={styles.searchInputWrapper}>
                <Search size={16} className="text-[#3F768C]" />
                <input
                  type="text"
                  placeholder="Buscar ( / )..."
                  value={searchTerm}
                  onChange={(e) => onSearchChange(e.target.value)}
                  className={styles.searchInput}
                />
                <span className={styles.kbdHint}>⌘K</span>
              </div>
            )}

            {hasFilters && (
              <button
                data-toggle="filters"
                aria-expanded={showFilters}
                onClick={() => setShowFilters(!showFilters)}
                className={`${styles.filterActionBtn} ${showFilters ? styles.filterActionBtnActive : ''}`}
              >
                <SlidersHorizontal size={15} className={showFilters ? 'text-white' : 'text-[#3F768C]'} />
                <span className={styles.btnText}>Filtro</span>
                {activeFilterCount > 0
                  ? <span className={`${styles.countPill} ${showFilters ? styles.countPillOnDark : ''}`} data-filter-count>{activeFilterCount}</span>
                  : showFilters && <span className={styles.activeDot} />}
              </button>
            )}

            {sortOptions.length > 0 && (
              <button
                data-toggle="sort"
                aria-expanded={showSort}
                onClick={() => setShowSort(!showSort)}
                className={`${styles.filterActionBtn} ${showSort ? styles.filterActionBtnActive : ''}`}
              >
                <ArrowUpDown size={15} className={showSort ? 'text-white' : 'text-[#3F768C]'} />
                <span className={`${styles.btnText} ${styles.btnTextCollapse}`}>
                  {activeSort ? `Orden: ${activeSort.label}` : 'Ordenar por'}
                </span>
                {showSort && !activeSort && <span className={styles.activeDot} />}
              </button>
            )}
          </div>

          <div className={styles.filterRightGroup}>
            <button className={styles.exportBtn}>
              <Download size={14} />
              <span className={`${styles.btnText} ${styles.btnTextCollapse}`}>Exportar</span>
            </button>

            <div className={styles.viewToggleGroup}>
              <button
                onClick={() => setViewMode('panel')}
                className={`${styles.viewToggleBtn} ${viewMode === 'panel' ? styles.viewToggleActive : ''}`}
                title="Vista Panel"
              >
                <LayoutGrid size={15} />
              </button>
              <button
                onClick={() => setViewMode('table')}
                className={`${styles.viewToggleBtn} ${viewMode === 'table' ? styles.viewToggleActive : ''}`}
                title="Vista Tabla"
              >
                <TableIcon size={15} />
              </button>
            </div>
          </div>
        </div>

        {/* Drawer de Filtros */}
        <AnimatePresence>
          {showFilters && (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: 'auto', opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              transition={{ duration: 0.25, ease: 'easeInOut' }}
              className="overflow-hidden"
            >
              <div className={styles.filterDrawer}>
                <div className={styles.pillsList}>
                  <span className={styles.drawerLabel}>Filtrar por:</span>
                  
                  {filterConfigs.map((config) => {
                    const currentValue = filterValues[config.id];
                    
                    return (
                      <div key={config.id} className="relative inline-flex items-center">
                        <div className={styles.filterChip}>
                          <span className="text-xs leading-5 text-slate-500 mr-1">{config.label}:</span>
                          <Select
                            variant="chip"
                            ariaLabel={config.label}
                            placeholder="Todos"
                            value={currentValue || ''}
                            onChange={(e) => { if (onFilterChange) onFilterChange(config.id, String(e.target.value)); }}
                            options={config.options ?? []}
                          />
                        </div>
                      </div>
                    );
                  })}

                  {dateFilters.map((config) => (
                    <div key={config.id} className="relative inline-flex items-center">
                      <div className={styles.filterChip}>
                        <span className="text-xs leading-5 text-slate-500 mr-1">{config.label}:</span>
                        <DateFilter
                          label={config.label}
                          value={dateValues[config.id] ?? null}
                          onChange={(value) => changeDate(config, value)}
                          future={config.future}
                        />
                      </div>
                    </div>
                  ))}

                  {filterChips.map((chip, idx) => (
                    <button key={`chip-${idx}`} className={styles.filterChip}>
                      <span>{chip}</span>
                      <ChevronDown size={13} className="text-slate-400" />
                    </button>
                  ))}
                  
                  <div className={styles.chipDivider} />
                  <button className={styles.iconCircleBtn} title="Agregar filtro">
                    <Plus size={14} />
                  </button>
                </div>

                <div className={styles.drawerActions}>
                  {activeFilterCount > 0 && (
                    <button type="button" data-clear-filters onClick={clearFilters} className={styles.advancedFiltersBtn}>
                      <X size={13} />
                      Limpiar filtros
                    </button>
                  )}
                  <button className={styles.advancedFiltersBtn}>
                    <Filter size={13} />
                    Filtros avanzados
                  </button>
                  <button
                    onClick={() => setShowFilters(false)}
                    className={styles.closeDrawerBtn}
                    title="Cerrar barra de filtros"
                  >
                    <X size={14} />
                  </button>
                </div>
              </div>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Barra de orden: mismo diseño que la de filtros; con ambas abiertas queda debajo. */}
        <AnimatePresence>
          {showSort && sortOptions.length > 0 && (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: 'auto', opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              transition={{ duration: 0.25, ease: 'easeInOut' }}
              className="overflow-hidden"
            >
              <div className={styles.filterDrawer} data-sort-drawer>
                <div className={styles.pillsList}>
                  <span className={styles.drawerLabel}>Ordenar por:</span>

                  <div className="relative inline-flex items-center">
                    <div className={styles.filterChip}>
                      <span className="text-xs leading-5 text-slate-500 mr-1">Campo:</span>
                      <Select
                        variant="chip"
                        ariaLabel="Ordenar por"
                        placeholder="Sin ordenar"
                        value={sortId}
                        onChange={(e) => changeSort(String(e.target.value))}
                        options={sortOptions.map((option) => ({ value: option.id, label: option.label }))}
                      />
                    </div>
                  </div>

                  {activeSort && (
                    <div className={styles.segmented} role="group" aria-label="Sentido del orden">
                      {([SORT_DIRECTIONS[sortType].first, SORT_DIRECTIONS[sortType].first === 'asc' ? 'desc' : 'asc'] as const).map((dir) => (
                        <button
                          key={dir}
                          type="button"
                          data-sort-dir={dir}
                          aria-pressed={sortDir === dir}
                          onClick={() => setSortDir(dir)}
                          className={`${styles.segmentedBtn} ${sortDir === dir ? styles.segmentedBtnActive : ''}`}
                        >
                          {SORT_DIRECTIONS[sortType][dir]}
                        </button>
                      ))}
                    </div>
                  )}
                </div>

                <div className={styles.drawerActions}>
                  {activeSort && (
                    <button type="button" data-clear-sort onClick={() => setSortId('')} className={styles.advancedFiltersBtn}>
                      <X size={13} />
                      Quitar orden
                    </button>
                  )}
                  <button onClick={() => setShowSort(false)} className={styles.closeDrawerBtn} title="Cerrar barra de orden">
                    <X size={14} />
                  </button>
                </div>
              </div>
            </motion.div>
          )}
        </AnimatePresence>
      </div>

      {viewMode === 'table' ? (
        <div className={styles.tableContainer}>
          <table className={styles.table}>
            <thead className={styles.thead}>
              <tr>
                <th className={styles.thCheckbox}>
                  <input type="checkbox" className="rounded border-slate-300 accent-[#022340] cursor-pointer" />
                </th>
                {columns.map((col, idx) => (
                  <th key={idx} className={styles.th}>
                    {col.header}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {pageRows.map((item) => (
                <tr key={getItemKey(item)} className={styles.tr}>
                  <td className={styles.thCheckbox} onClick={(e) => e.stopPropagation()}>
                    <input type="checkbox" className="rounded border-slate-300 accent-[#022340] cursor-pointer" />
                  </td>
                  {columns.map((col, idx) => (
                    <td key={idx} className={col.className || styles.td}>
                      {col.cell
                        ? col.cell(item)
                        : col.accessorKey
                        ? String(item[col.accessorKey] ?? '')
                        : null}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className={styles.boardContainer}>
          <div className={styles.boardTrack}>
            {STAGES.map((stage) => {
              const stageItems = data.filter(
                (item) => item.stage === stage.id || (!item.stage && stage.id === 'lead')
              );

              return (
                <div key={stage.id} className={styles.boardColumn}>
                  <div className={styles.columnHeader}>
                    <span className={`${styles.stageBadge} ${stage.colorClass}`}>
                      {stage.name}
                    </span>
                    <span className={styles.stageCount}>{stageItems.length}</span>
                  </div>

                  <div className={styles.cardsContainer}>
                    {stageItems.map((item) => {
                      const name = item.fullName || item.name || item.contactName || 'Sin Nombre';
                      const email = item.email || '';
                      const activity = item.lastActivity || 'hace 2 meses';

                      return (
                        <div key={getItemKey(item)} className={styles.boardCard}>
                          <div className={styles.cardHeader}>
                            <h4 className={styles.cardTitle}>{name}</h4>
                          </div>

                          {email && (
                            <div className={styles.cardEmail}>
                              <Mail size={12} className="shrink-0 text-slate-400" />
                              <span className="truncate">{email}</span>
                              <ExternalLink size={10} className="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" />
                            </div>
                          )}

                          <div className={styles.cardFooter}>
                            <div className={styles.activityBadge}>
                              <Clock size={11} />
                              <span>{activity}</span>
                            </div>
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      <div className={styles.footer}>
        <span className={styles.counterBadge}>
          {data.length} {entityLabel}
        </span>

        <div className={styles.paginationGroup}>
          <span className={styles.pageInfo} data-page-info>
            {viewMode === 'table' && data.length > 0
              ? `${(currentPage - 1) * pageSize + 1}–${Math.min(currentPage * pageSize, data.length)} · Página ${currentPage} de ${pages}`
              : `Página ${currentPage} de ${pages}`}
          </span>
          <div className={styles.paginationBtns}>
            <button type="button" data-page="prev" aria-label="Página anterior" className={styles.pageBtn} disabled={currentPage <= 1} onClick={() => setPage(currentPage - 1)}>
              <ChevronLeft size={16} />
            </button>
            <button type="button" data-page="next" aria-label="Página siguiente" className={styles.pageBtn} disabled={currentPage >= pages} onClick={() => setPage(currentPage + 1)}>
              <ChevronRight size={16} />
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}