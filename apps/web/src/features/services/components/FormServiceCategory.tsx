'use client';

import { useState, useEffect } from 'react';
import { X, CheckCircle2, AlertCircle } from 'lucide-react';
import { api } from '@/lib/axios';

interface FormServiceCategoryProps {
    isOpen: boolean;
    onClose: () => void;
    onCategoryCreated: (newCategory: { id: string; name: string }) => void;
    existingCategories?: any[];
}

export default function FormServiceCategory({
    isOpen,
    onClose,
    onCategoryCreated,
    existingCategories = [],
}: FormServiceCategoryProps) {
    const [name, setName] = useState('');
    const [loading, setLoading] = useState(false);
    const [errorMessage, setErrorMessage] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [isDuplicate, setIsDuplicate] = useState(false);

    useEffect(() => {
        const trimmed = name.trim().toLowerCase();
        if (!trimmed) {
            setIsDuplicate(false);
            setErrorMessage(null);
            return;
        }

        const found = existingCategories.some((cat) => {
            const catName = typeof cat === 'string' ? cat : (cat?.name || '');
            return catName.trim().toLowerCase() === trimmed;
        });

        if (found) {
            setIsDuplicate(true);
            setErrorMessage('Ya existe una categoría con este nombre.');
        } else {
            setIsDuplicate(false);
            setErrorMessage(null);
        }
    }, [name, existingCategories]);

    if (!isOpen) return null;

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        const trimmedName = name.trim();
        if (!trimmedName || isDuplicate) return;

        try {
            setLoading(true);
            setErrorMessage(null);
            setSuccessMessage(null);

            const res = await api.post('/service-categories', { name: trimmedName });

            setSuccessMessage('¡Categoría creada con éxito!');

            onCategoryCreated({
                id: String(res.data.id),
                name: res.data.name,
            });

            setTimeout(() => {
                setName('');
                setSuccessMessage(null);
                onClose();
            }, 700);

        } catch (error: any) {
            if (error.response && error.response.status === 422) {
                setErrorMessage(error.response.data?.message || 'Ya existe una categoría con este nombre.');
                setIsDuplicate(true);
            } else {
                setErrorMessage('Ocurrió un error al procesar la categoría.');
            }
        } finally {
            setLoading(false);
        }
    };

    const handleClose = () => {
        setName('');
        setErrorMessage(null);
        setSuccessMessage(null);
        setIsDuplicate(false);
        onClose();
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/55 backdrop-blur-sm p-4">
            <div className="bg-white rounded-2xl shadow-xl w-full max-w-sm overflow-hidden animate-in fade-in zoom-in duration-200">
                <div className="flex items-center justify-between px-5 py-3.5 border-b border-slate-100">
                    <h3 className="text-base font-semibold text-slate-800">Nueva Categoría</h3>
                    <button
                        type="button"
                        onClick={handleClose}
                        className="p-1 text-slate-400 hover:text-slate-600 rounded-lg transition-colors"
                    >
                        <X size={18} />
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="p-5 space-y-4">
                    <div>
                        <label className="block text-xs font-semibold text-slate-600 mb-1">
                            Nombre de la Categoría *
                        </label>
                        <input
                            type="text"
                            required
                            autoFocus
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className={`w-full px-3 py-2 border rounded-xl text-sm focus:outline-none focus:ring-2 ${
                                isDuplicate
                                    ? 'border-red-300 focus:ring-red-500/20 focus:border-red-500 bg-red-50/30'
                                    : 'border-slate-200 focus:ring-blue-500/20 focus:border-blue-500'
                            }`}
                            placeholder="Ej. Cortes, Barbería, Masajes"
                        />
                    </div>

                    {errorMessage && (
                        <div className="flex items-center gap-2 p-3 text-xs text-red-600 bg-red-50 border border-red-100 rounded-xl">
                            <AlertCircle size={16} className="shrink-0 text-red-500" />
                            <span>{errorMessage}</span>
                        </div>
                    )}

                    {successMessage && (
                        <div className="flex items-center gap-2 p-3 text-xs text-emerald-700 bg-emerald-50 border border-emerald-100 rounded-xl">
                            <CheckCircle2 size={16} className="text-emerald-600 shrink-0" />
                            <span>{successMessage}</span>
                        </div>
                    )}

                    <div className="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            onClick={handleClose}
                            className="px-3.5 py-2 text-xs font-medium text-slate-600 hover:bg-slate-100 rounded-xl transition-colors"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            disabled={loading || isDuplicate || !name.trim() || !!successMessage}
                            className="px-4 py-2 text-xs font-medium text-white bg-brand hover:bg-brand/90 rounded-xl transition-colors shadow-sm disabled:opacity-50"
                        >
                            {loading ? 'Guardando...' : 'Crear Categoría'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}