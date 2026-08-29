import React, { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { AlertCircle, CheckCircle2, Loader2, Calendar, FileText, Lock } from 'lucide-react';
import type { Asiento } from '@/types/asientos';

interface AsientoConfirmarModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => Promise<void>;
  asiento: Asiento | null;
  isSubmitting?: boolean;
}

export const AsientoConfirmarModal: React.FC<AsientoConfirmarModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  asiento,
  isSubmitting = false,
}) => {
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  if (!asiento) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);

    try {
      await onConfirm();
      onClose();
    } catch (err: any) {
      setErrorMsg(err?.message || 'Error al confirmar el comprobante contable.');
    }
  };

  const fechaLimpia = asiento.fecha
    ? asiento.fecha.split(' ')[0].split('-').reverse().join('/')
    : 'N/A';

  const totalDebe = Number(asiento.total_debe || 0);

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-lg w-full p-6 space-y-4 max-h-[92vh] overflow-y-auto shadow-xl border-border/80 rounded-2xl">
        <DialogHeader className="space-y-1.5 text-left border-b border-border/60 pb-3">
          <DialogTitle className="flex items-center gap-2.5 text-lg font-bold text-emerald-600 dark:text-emerald-400">
            <CheckCircle2 className="size-5 text-emerald-600 dark:text-emerald-400 shrink-0" />
            Confirmar Asiento Contable
          </DialogTitle>
          <DialogDescription className="text-xs text-muted-foreground">
            Estampar secuencia legal inmutable y publicar el comprobante en el Libro Diario oficial.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 pt-1">
          {errorMsg && (
            <div className="p-3 bg-destructive/15 border border-destructive/30 text-destructive rounded-xl text-xs font-medium flex items-center gap-2">
              <AlertCircle className="size-4 shrink-0" />
              <span>{errorMsg}</span>
            </div>
          )}

          {/* Alerta Informativa de Estampado Correlativo */}
          <div className="p-3.5 bg-emerald-500/10 border border-emerald-500/25 text-emerald-800 dark:text-emerald-300 rounded-xl text-xs space-y-1.5">
            <p className="font-bold flex items-center gap-1.5 text-xs">
              <Lock className="size-3.5 text-emerald-600 dark:text-emerald-400" />
              <span>Consecuencia Financiera de la Confirmación:</span>
            </p>
            <p className="text-[11px] leading-relaxed text-emerald-900/80 dark:text-emerald-200/90">
              Se asignará el número correlativo inmutable (ej: <strong className="font-mono">AS-2026-000001</strong>). Una vez confirmado, el comprobante **no podrá ser editado ni eliminado físicamente**, resguardando la integridad del Libro Diario.
            </p>
          </div>

          {/* Ficha Resumen del Comprobante */}
          <div className="p-3.5 bg-muted/40 rounded-xl border border-border/60 text-xs space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground font-semibold flex items-center gap-1">
                <FileText className="size-3.5" /> Borrador Actual:
              </span>
              <span className="font-mono font-bold text-primary text-xs">{asiento.numero || `TMP-${asiento.id}`}</span>
            </div>

            <div className="flex items-center justify-between text-muted-foreground">
              <span className="flex items-center gap-1"><Calendar className="size-3.5" /> Fecha Registro:</span>
              <span className="font-medium text-foreground">{fechaLimpia}</span>
            </div>

            <div className="flex items-center justify-between text-muted-foreground">
              <span>Monto Total Asiento:</span>
              <span className="font-mono font-bold text-emerald-600 dark:text-emerald-400">
                Bs. {totalDebe.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
              </span>
            </div>

            <div className="border-t border-border/40 pt-1.5 text-muted-foreground">
              <span className="font-semibold block text-[11px] uppercase tracking-wider mb-0.5 text-foreground">Concepto / Glosa:</span>
              <p className="text-foreground font-medium line-clamp-2">{asiento.concepto}</p>
            </div>
          </div>

          <DialogFooter className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-3 border-t border-border/60">
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={isSubmitting}
              className="text-xs font-semibold h-9 w-full sm:w-auto"
            >
              Cancelar
            </Button>

            <Button
              type="submit"
              disabled={isSubmitting}
              className="text-xs font-bold gap-1.5 h-9 bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs w-full sm:w-auto"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="size-4 animate-spin" />
                  Estampando secuencia...
                </>
              ) : (
                <>
                  <CheckCircle2 className="size-4" />
                  Confirmar y Estampar Secuencia Legal
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};
