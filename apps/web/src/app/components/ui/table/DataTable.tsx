'use client';

import { useEffect, useMemo, useRef, useState, ReactNode } from 'react';
import {
  Search,
  ArrowUpDown,
  Plus,
  Lock,
  ChevronDown,
  MoreHorizontal,
  Columns3,
  Table2,
  SlidersHorizontal,
  ChevronLeft,
  ChevronRight,
  X,
} from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';
import styles from './DataTable.module.css';
import Select from '@/app/components/common/Select';
import DateFilter from './DateFilter';
import { PAGE_SIZE } from '@/app/components/common/Pager';
import { dateRangeOf, describeDateFilter, inDateRange, type DateFilterValue, type DateRange } from './dateFilters';
import ExportMenu from './ExportMenu';
import ScreenJump from './ScreenJump';
import type { ExportColumn } from './exportTable';
import { useAuth } from '@/hooks/useAuth';

export type { ExportColumn } from './exportTable';

export type FilterType = 'select' | 'date' | 'daterange' | 'text';

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

export interface DateFilterConfig<T> {
  id: string;
  label: string;
  value: (item: T) => string | null | undefined;
  end?: (item: T) => string | null | undefined;
  future?: boolean;
  onRange?: (range: DateRange | null) => void;
  clearOnTab?: boolean;
}

export interface SortOption<T> {
  id: string;
  label: string;
  type?: 'text' | 'number' | 'date';
  value: (item: T) => string | number | null | undefined;
}

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
  extraFilterCount?: number;
  onClearFilters?: () => void;
  dateFilters?: DateFilterConfig<T>[];
  sortOptions?: SortOption<T>[];

  columns: Column<T>[];
  data: T[];
  getItemKey: (item: T) => string | number;
  entityLabel?: string;
  
  metricCards?: MetricCardItem[];
  pageSize?: number;

  exportColumns?: ExportColumn<T>[];
  exportTitle?: string;
  exportScreen?: string;
  exportFilters?: string[];
  exportNotice?: string;
  boardView?: (rows: T[]) => ReactNode;
  view?: 'table' | 'board';
  onViewChange?: (view: 'table' | 'board') => void;
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
  extraFilterCount = 0,
  onClearFilters,
  dateFilters = [],
  sortOptions = [],
  columns,
  data: allData,
  getItemKey,
  entityLabel = 'registros',
  metricCards,
  pageSize = PAGE_SIZE,
  exportColumns,
  exportTitle,
  exportScreen,
  exportFilters = [],
  exportNotice,
  boardView,
  view = 'table',
  onViewChange,
}: DataTableProps<T>) {
  const showBoard = Boolean(boardView) && view === 'board';
  const { can } = useAuth();
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [showFilters, setShowFilters] = useState(false);
  const [showSort, setShowSort] = useState(false);
  const [dateValues, setDateValues] = useState<Record<string, DateFilterValue | null>>({});
  const [sortId, setSortId] = useState('');
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
  const [searchText, setSearchText] = useState(searchTerm);
  const searchRef = useRef<HTMLInputElement>(null);
  const titleRef = useRef<HTMLDivElement>(null);
  const sentSearch = useRef(searchTerm);
  const searchHandler = useRef(onSearchChange);

  useEffect(() => {
    searchHandler.current = onSearchChange;
  }, [onSearchChange]);

  useEffect(() => {
    if (searchTerm !== sentSearch.current) {
      sentSearch.current = searchTerm;
      setSearchText(searchTerm);
    }
  }, [searchTerm]);

  useEffect(() => {
    if (searchText === sentSearch.current) return;
    const timer = window.setTimeout(() => {
      sentSearch.current = searchText;
      searchHandler.current?.(searchText);
    }, 300);
    return () => window.clearTimeout(timer);
  }, [searchText]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement | null;
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
      if (!searchRef.current) return;
      e.preventDefault();
      searchRef.current.focus();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const activeSort = sortOptions.find((option) => option.id === sortId);
  const sortType = activeSort?.type ?? 'text';
  const hasFilters = filterConfigs.length > 0 || dateFilters.length > 0 || filterChips.length > 0;
  const activeFilterCount =
    filterConfigs.filter((config) => filterValues[config.id] !== undefined && filterValues[config.id] !== null && filterValues[config.id] !== '').length +
    dateFilters.filter((config) => dateValues[config.id]).length +
    extraFilterCount;

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
    onClearFilters?.();
  };

  const chooseTab = (tabId: string) => {
    dateFilters.forEach((config) => {
      if (config.clearOnTab && dateValues[config.id]) changeDate(config, null);
    });
    onTabChange?.(tabId);
  };

  const changeSort = (id: string) => {
    setSortId(id);
    const option = sortOptions.find((item) => item.id === id);
    if (option) setSortDir(SORT_DIRECTIONS[option.type ?? 'text'].first);
  };

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

      if (empty(va) || empty(vb)) return empty(va) === empty(vb) ? 0 : empty(va) ? 1 : -1;

      if (type === 'number') return (Number(va) - Number(vb)) * factor;
      if (type === 'date') return (new Date(String(va)).getTime() - new Date(String(vb)).getTime()) * factor;
      return String(va).localeCompare(String(vb), 'es', { sensitivity: 'base', numeric: true }) * factor;
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [allData, dateValues, sortId, sortDir]);

  const [page, setPage] = useState(1);
  const pages = Math.max(1, Math.ceil(data.length / pageSize));
  const currentPage = Math.min(page, pages);
  const pageRows = data.slice((currentPage - 1) * pageSize, currentPage * pageSize);
  const filterKey = JSON.stringify(filterValues);

  const selectedRows = data.filter((item) => selected.has(getItemKey(item)));
  const allSelected = data.length > 0 && selectedRows.length === data.length;
  const toggleRow = (key: string | number) => setSelected((current) => {
    const next = new Set(current);
    if (next.has(key)) next.delete(key); else next.add(key);
    return next;
  });
  const toggleAll = () => setSelected(allSelected ? new Set() : new Set(data.map((item) => getItemKey(item))));

  const describeFilters = (): string[] => {
    const out: string[] = [];
    const tab = tabs.length > 1 ? tabs.find((t) => t.id === activeTab) : null;
    if (tab) out.push(`Pestaña: ${tab.label}`);
    if (searchText.trim()) out.push(`Búsqueda: "${searchText.trim()}"`);
    filterConfigs.forEach((config) => {
      const value = filterValues[config.id];
      if (value === undefined || value === null || value === '') return;
      const option = config.options?.find((o) => String(o.value) === String(value));
      out.push(`${config.label}: ${option?.label ?? value}`);
    });
    dateFilters.forEach((config) => {
      if (dateValues[config.id]) out.push(`${config.label}: ${describeDateFilter(dateValues[config.id])}`);
    });
    out.push(...exportFilters);
    if (activeSort) out.push(`Orden: ${activeSort.label} (${SORT_DIRECTIONS[sortType][sortDir]})`);
    return out;
  };

  useEffect(() => {
    setPage(1);
  }, [searchTerm, activeTab, filterKey, dateValues, sortId, sortDir]);

  return (
    <div className={styles.container}>
      <div className={styles.header}>
        <div className={styles.headerTop}>
          <div className={styles.titleGroup} ref={titleRef}>
            <h1 className={styles.title}>{title}</h1>
            <ScreenJump anchorRef={titleRef} />
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

        {tabs.length > 0 && (
          <div className={styles.tabsContainer}>
            <div className={styles.tabsList}>
              {tabs.map((tab) => {
                const isActive = activeTab === tab.id;
                return (
                  <button
                    key={tab.id}
                    onClick={() => chooseTab(tab.id)}
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
          </div>
        )}
      </div>

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

      <div className={styles.filterSection}>
        <div className={styles.filterTopRow}>
          <div className={styles.filterLeftGroup}>
            {onSearchChange && (
              <div className={styles.searchInputWrapper}>
                <Search size={16} className="text-[#3F768C]" />
                <input
                  ref={searchRef}
                  type="text"
                  placeholder="Buscar"
                  value={searchText}
                  onChange={(e) => setSearchText(e.target.value)}
                  className={styles.searchInput}
                  data-table-search
                />
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
            {boardView && onViewChange && (
              <div className={styles.segmented} role="group" aria-label="Vista">
                {([['table', 'Tabla', Table2], ['board', 'Tablero', Columns3]] as const).map(([id, label, Icon]) => (
                  <button
                    key={id}
                    type="button"
                    data-view={id}
                    aria-pressed={view === id}
                    onClick={() => onViewChange(id)}
                    className={`${styles.segmentedBtn} ${view === id ? styles.segmentedBtnActive : ''} inline-flex items-center gap-1.5`}
                  >
                    <Icon size={14} />
                    <span className={styles.btnText}>{label}</span>
                  </button>
                ))}
              </div>
            )}
            {exportColumns && exportColumns.length > 0 && can('data.export') && (
              <ExportMenu
                title={exportTitle ?? title}
                screen={exportScreen ?? (exportTitle ?? title)}
                columns={exportColumns}
                filtered={data}
                selected={selectedRows}
                filters={describeFilters()}
                notice={exportNotice}
              />
            )}
          </div>
        </div>

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
                </div>

                <div className={styles.drawerActions}>
                  {activeFilterCount > 0 && (
                    <button type="button" data-clear-filters onClick={clearFilters} className={styles.advancedFiltersBtn}>
                      <X size={13} />
                      Limpiar filtros
                    </button>
                  )}
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

      {showBoard && boardView?.(data)}

      {!showBoard && (
      <div className={styles.tableContainer}>
        <table className={styles.table}>
          <thead className={styles.thead}>
            <tr>
              <th className={styles.thCheckbox}>
                <input type="checkbox" aria-label="Seleccionar todas las filas filtradas" data-select-all checked={allSelected} ref={(el) => { if (el) el.indeterminate = selectedRows.length > 0 && !allSelected; }} onChange={toggleAll} className="rounded border-slate-300 accent-[#022340] cursor-pointer" />
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
                  <input type="checkbox" aria-label="Seleccionar fila" data-select-row checked={selected.has(getItemKey(item))} onChange={() => toggleRow(getItemKey(item))} className="rounded border-slate-300 accent-[#022340] cursor-pointer" />
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
      )}

      <div className={styles.footer}>
        <span className="flex items-center gap-2">
          <span className={styles.counterBadge}>
            {data.length} {entityLabel}
          </span>
          {selectedRows.length > 0 && (
            <span className="text-xs text-slate-500" data-selected-count>
              {selectedRows.length} {selectedRows.length === 1 ? 'seleccionada' : 'seleccionadas'} ·{' '}
              <button type="button" onClick={() => setSelected(new Set())} className="font-semibold text-[#3F768C] hover:text-[#022340]">Quitar selección</button>
            </span>
          )}
        </span>

        {!showBoard && (
        <div className={styles.paginationGroup}>
          <span className={styles.pageInfo} data-page-info>
            {data.length > 0
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
        )}
      </div>
    </div>
  );
}