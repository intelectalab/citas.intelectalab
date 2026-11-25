'use client';

import { useEffect, useState } from 'react';
import { Store } from 'lucide-react';
import Select from '@/app/components/common/Select';
import { useAuth } from '@/hooks/useAuth';
import { readBranchFocus, saveBranchFocus } from '@/lib/axios';

export default function BranchFocus({ inline = false }: { inline?: boolean }) {
  const { user } = useAuth();
  const choices = user?.branch_choices ?? [];
  const [focus, setFocus] = useState('');
  const ids = choices.map((branch) => branch.id).join(',');

  useEffect(() => {
    const saved = readBranchFocus();
    if (saved && !ids.split(',').includes(saved)) {
      if (user) saveBranchFocus('');
      setFocus('');
      return;
    }
    setFocus(saved);
  }, [ids, user]);

  if (choices.length < 2) return null;

  const change = (value: string) => {
    if (value === focus) return;
    saveBranchFocus(value);
    setFocus(value);
    window.location.reload();
  };

  return (
    <div className={inline ? 'w-full' : 'hidden sm:block w-44 lg:w-52'} data-branch-focus title="Elige qué sede ver en las listas, la agenda y los reportes">
      <Select
        variant={inline ? 'field' : 'chip'}
        icon={<Store size={14} />}
        ariaLabel="Sede que estás viendo"
        clearable={false}
        value={focus}
        onChange={(e) => change(String(e.target.value))}
        options={[{ value: '', label: 'Todas las sedes' }, ...choices.map((branch) => ({ value: branch.id, label: branch.name }))]}
      />
    </div>
  );
}
