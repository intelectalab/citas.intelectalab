import ClassesBoard from '@/features/classes/components/ClassesBoard';

export const metadata = {
  title: 'Clases | Agenda 360',
};

export default function ClassesPage() {
  return (
    <div className="space-y-4">
      <ClassesBoard />
    </div>
  );
}
