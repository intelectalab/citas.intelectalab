import WalkinBoard from '@/features/walkin/components/WalkinBoard';

export const metadata = {
  title: 'Cola sin cita | Agenda 360',
};

export default function WalkinPage() {
  return (
    <div className="space-y-4">
      <WalkinBoard />
    </div>
  );
}
