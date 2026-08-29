import React, { useState } from 'react';
import { AlertTriangle, Copy, Check, ExternalLink, X } from 'lucide-react';

export interface ErrorPayload {
  diagnostico: string;
  detalle: string;
  accion: string;
  ruta_sugerida?: string | null;
  permiso_requerido?: string | null;
}

interface ErrorCalloutModalProps {
  isOpen: boolean;
  onClose: () => void;
  payload: ErrorPayload;
  onNavigate?: (ruta: string) => void;
  userPermissions?: string[];
}

export const ErrorCalloutModal: React.FC<ErrorCalloutModalProps> = ({
  isOpen,
  onClose,
  payload,
  onNavigate,
  userPermissions = [],
}) => {
  const [copied, setCopied] = useState(false);

  if (!isOpen) return null;

  const userCan = (permiso?: string | null) => {
    if (!permiso) return true;
    return userPermissions.includes(permiso) || userPermissions.includes('admin');
  };

  const handleCopyReport = () => {
    const text = `[REPORTE TÉCNICO ALMACÉN/CONTABILIDAD]\nDiagnóstico: ${payload.diagnostico}\nDetalle: ${payload.detalle}\nAcción Sugerida: ${payload.accion}`;
    navigator.clipboard.writeText(text);
    setCopied(true);
    setTimeout(() => setCopied(false), 2500);
  };

  return (
    <div 
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-4"
      style={{ animation: 'fadeIn 0.2s ease-out' }}
    >
      <style>{`
        @keyframes modalEnter {
          from { opacity: 0; transform: scale(0.95); }
          to { opacity: 1; transform: scale(1); }
        }
        @keyframes fadeIn {
          from { opacity: 0; }
          to { opacity: 1; }
        }
      `}</style>

      <div 
        className="bg-white rounded-xl shadow-2xl max-w-lg w-full overflow-hidden border border-red-100"
        style={{ animation: 'modalEnter 0.25s cubic-bezier(0.16, 1, 0.3, 1)' }}
      >
        
        {/* Header */}
        <div className="bg-red-600 px-6 py-4 flex items-center justify-between text-white">
          <div className="flex items-center gap-3">
            <AlertTriangle className="w-6 h-6 text-yellow-300" />
            <h3 className="font-bold text-lg">Validación de Control Interno</h3>
          </div>
          <button onClick={onClose} className="text-white/80 hover:text-white transition">
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-4">
          
          {/* Diagnóstico */}
          <div className="bg-red-50 border-l-4 border-red-600 p-4 rounded-r-lg">
            <h4 className="font-bold text-red-900 text-base mb-1">📌 Diagnóstico</h4>
            <p className="text-red-800 text-sm">{payload.diagnostico}</p>
          </div>

          {/* Detalle Contextual */}
          {payload.detalle && (
            <div className="bg-gray-50 border border-gray-200 p-4 rounded-lg">
              <h5 className="font-semibold text-gray-700 text-xs uppercase tracking-wider mb-1">💡 Detalle Contextual</h5>
              <p className="text-gray-600 text-sm font-mono whitespace-pre-line">{payload.detalle}</p>
            </div>
          )}

          {/* Acción Requerida */}
          {payload.accion && (
            <div className="bg-blue-50 border border-blue-200 p-4 rounded-lg">
              <h5 className="font-semibold text-blue-900 text-xs uppercase tracking-wider mb-1">🔧 Acción Requerida</h5>
              <p className="text-blue-800 text-sm">{payload.accion}</p>
            </div>
          )}
        </div>

        {/* Footer Actions */}
        <div className="bg-gray-100 px-6 py-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200">
          <button
            onClick={handleCopyReport}
            className="flex items-center gap-1.5 text-xs text-gray-600 hover:text-gray-900 font-medium transition"
          >
            {copied ? <Check className="w-4 h-4 text-green-600" /> : <Copy className="w-4 h-4" />}
            {copied ? '¡Reporte Copiado!' : 'Copiar reporte técnico'}
          </button>

          <div className="flex items-center gap-2">
            {payload.ruta_sugerida && onNavigate && userCan(payload.permiso_requerido) && (
              <button
                onClick={() => onNavigate(payload.ruta_sugerida!)}
                className="flex items-center gap-1.5 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold rounded-lg shadow transition"
              >
                Resolver Configuración
                <ExternalLink className="w-4 h-4" />
              </button>
            )}
            <button
              onClick={onClose}
              className="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 text-sm font-medium rounded-lg transition"
            >
              Entendido
            </button>
          </div>
        </div>

      </div>
    </div>
  );
};

export default ErrorCalloutModal;
