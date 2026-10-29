'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/axios';
import { apiError } from '@/features/appointments/appointments';
import type { InventoryBoardData } from './inventory';

export function useInventoryBoard(enabled = true) {
  const [board, setBoard] = useState<InventoryBoardData | null>(null);
  const [warehouseFilter, setWarehouseFilter] = useState('');
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = useCallback(() => {
    if (!enabled) return;
    api
      .get('/inventory')
      .then((res) => {
        setBoard(res.data);
        setError('');
      })
      .catch((err) => setError(apiError(err, 'No se pudo cargar el inventario.')));
  }, [enabled]);

  useEffect(() => {
    load();
  }, [load]);

  const warehouses = board?.warehouses ?? [];
  const ownWarehouse = board?.branch_limit
    ? board.warehouses.find((w) => w.branch_id === board.branch_limit)?.id ?? ''
    : '';

  useEffect(() => {
    if (ownWarehouse && !warehouseFilter) setWarehouseFilter(ownWarehouse);
  }, [ownWarehouse, warehouseFilter]);

  const saved = (next: InventoryBoardData, message: string) => {
    setBoard(next);
    setError('');
    setNotice(message);
  };

  const warehouseFilterConfig = warehouses.length > 1
    ? [{
        id: 'warehouse',
        label: 'Sede',
        type: 'select' as const,
        options: warehouses.map((w) => ({ value: w.id, label: w.branch_name })),
      }]
    : [];

  return {
    board,
    load,
    saved,
    warehouses,
    warehouseFilter,
    setWarehouseFilter,
    warehouseFilterConfig,
    currency: board?.currency ?? 'PEN',
    can: board?.can ?? { create: false, edit: false, delete: false, products: false, export: false },
    error,
    setError,
    notice,
    setNotice,
  };
}
