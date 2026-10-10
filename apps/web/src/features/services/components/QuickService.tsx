'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/axios';
import FormService from './FormService';

export interface CreatedService {
  id: string;
  name: string;
  duration_minutes: number;
  price: string;
  professional_ids: string[];
}

interface QuickServiceProps {
  isOpen: boolean;
  onClose: () => void;
  // El servicio recién creado, para dejarlo elegido en el formulario que lo pidió.
  onCreated: (service: CreatedService) => void;
}

// Servicio nuevo sin salir del formulario en que se estaba (cita, lista de
// espera, control): abre el mismo panel de Servicios y devuelve lo creado.
export default function QuickService({ isOpen, onClose, onCreated }: QuickServiceProps) {
  const [categories, setCategories] = useState<{ label: string; value: string }[]>([]);

  useEffect(() => {
    if (!isOpen) return;

    api.get('/service-categories')
      .then((res) => {
        const list = Array.isArray(res.data) ? res.data : res.data?.data ?? [];
        setCategories(list.map((category: any) => ({ value: category.id, label: category.name })));
      })
      .catch(() => setCategories([]));
  }, [isOpen]);

  return (
    <FormService
      isOpen={isOpen}
      onClose={onClose}
      categories={categories}
      onSubmitSuccess={(service) => {
        if (service?.id) {
          onCreated({
            id: service.id,
            name: service.name,
            duration_minutes: Number(service.duration_minutes),
            price: String(service.price ?? '0'),
            professional_ids: [],
          });
        }
      }}
    />
  );
}
