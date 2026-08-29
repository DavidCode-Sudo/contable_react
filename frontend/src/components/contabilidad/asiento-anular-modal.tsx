import React, { useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { AlertCircle, Ban, Loader2, Calendar, FileText, AlertTriangle } from 'lucide-react';
import type { Asiento } from '@/types/asientos';

interface AsientoAnularModalProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: (fechaReversion: string, motivo?: string) => Promise<void>;
  asiento: Asiento | null;
  isSubmitting?: boolean;
}

export const AsientoAnularModal: React.FC<AsientoAnularModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  asiento,
  isSubmitting = false,
}) => {
  const [fechaReversion, setFechaReversion] = useState<string>('');
  const [motivo, setMotivo] = useState<string>('');
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  useEffect(() => {
    if (isOpen) {
      setFechaReversion(new Date().toISOString().split('T')[0]);
      setMotivo('');
      setErrorMsg(null);
    }
  }, [isOpen]);

  if (!asiento) return null;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);

    if (!motivo.trim()) {
      setErrorMsg('El motivo de anulación es obligatorio por normas de auditoría.');
      return;
    }

    try {
      await onConfirm(fechaReversion, motivo.trim());
      onClose();
    } catch (err: any) {
      setErrorMsg(err?.message || 'Error al anular el comprobante contable.');
    }
  };

  const fechaLimpia = asiento.fecha
    ? asiento.fecha.split(' ')[0].split('-').reverse().join('/')
    : 'N/A';

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="sm:max-w-lg w-full p-6 space-y-4 max-h-[92vh] overflow-y-auto shadow-xl border-border/80 rounded-2xl font-sans">
        <DialogHeader className="space-y-1.5 text-left border-b border-border/60 pb-3">
          <DialogTitle className="flex items-center gap-2.5 text-lg font-extrabold text-foreground">
            <Ban className="size-5 text-rose-500 shrink-0" />
            <span>Anular Comprobante Contable</span>
          </DialogTitle>
          <DialogDescription className="text-xs text-muted-foreground">
            Reversión de asiento procesado en conformidad con las normas de inmutabilidad financiera.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 pt-1">
          {errorMsg && (
            <div className="p-3 bg-rose-500/15 border border-rose-500/30 text-rose-700 dark:text-rose-300 rounded-xl text-xs font-medium flex items-center gap-2">
              <AlertCircle className="size-4 shrink-0" />
              <span>{errorMsg}</span>
            </div>
          )}

          {/* Alerta de Inmutabilidad ONCOP / SUDEBAN (Tonos Suaves de Advertencia) */}
          <div className="p-3.5 bg-amber-500/10 border border-amber-500/20 text-amber-900 dark:text-amber-200 rounded-xl text-xs space-y-1.5">
            <p className="font-bold flex items-center gap-1.5 text-xs text-amber-800 dark:text-amber-300">
              <AlertTriangle className="size-4 text-amber-600 shrink-0" />
              <span>Norma de Auditoría e Inmutabilidad Financiera:</span>
            </p>
            <p className="text-[11px] leading-relaxed text-amber-900/80 dark:text-amber-200/90">
              No se realiza borrado físico en base de datos. El asiento <strong className="font-mono">{asiento.numero || `AS-${asiento.id}`}</strong> cambiará su estado a <strong className="uppercase font-bold text-rose-600 dark:text-rose-400">ANULADO</strong> y se autogenerará un contra-asiento de reverso invertido.
            </p>
          </div>

          {/* Ficha Resumen del Comprobante */}
          <div className="p-3.5 bg-muted/40 rounded-xl border border-border/60 text-xs space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-muted-foreground font-semibold flex items-center gap-1.5">
                <FileText className="size-3.5 text-muted-foreground" /> Comprobante a anular:
              </span>
              <span className="font-mono font-bold text-primary text-xs">{asiento.numero || `AS-${asiento.id}`}</span>
            </div>

            <div className="flex items-center justify-between text-muted-foreground">
              <span className="flex items-center gap-1.5"><Calendar className="size-3.5 text-muted-foreground" /> Fecha Registro:</span>
              <span className="font-medium text-foreground">{fechaLimpia}</span>
            </div>

            <div className="border-t border-border/40 pt-1.5 text-muted-foreground">
              <span className="font-semibold block text-[11px] uppercase tracking-wider mb-0.5 text-foreground">Concepto:</span>
              <p className="text-foreground font-medium line-clamp-2">{asiento.concepto}</p>
            </div>
          </div>

          {/* Entrada Fecha Reversión */}
          <div className="space-y-1.5">
            <Label htmlFor="fechaReversion" className="text-xs font-semibold text-foreground">
              Fecha del Contra-Asiento de Reversión <span className="text-rose-500">*</span>
            </Label>
            <Input
              id="fechaReversion"
              type="date"
              value={fechaReversion}
              onChange={(e) => setFechaReversion(e.target.value)}
              required
              className="h-9 text-xs bg-background"
            />
          </div>

          {/* Entrada Motivo de Anulación */}
          <div className="space-y-1.5">
            <Label htmlFor="motivo" className="text-xs font-semibold text-foreground">
              Motivo de Anulación <span className="text-rose-500">*</span>
            </Label>
            <Input
              id="motivo"
              placeholder="Ej: Error en imputación de cuenta, duplicidad de comprobante"
              value={motivo}
              onChange={(e) => setMotivo(e.target.value)}
              required
              className="h-9 text-xs bg-background"
            />
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
              className="text-xs font-bold gap-1.5 h-9 bg-rose-600 hover:bg-rose-700 text-white shadow-2xs w-full sm:w-auto"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="size-4 animate-spin" />
                  Anulando comprobante...
                </>
              ) : (
                <>
                  <Ban className="size-4" />
                  Confirmar Anulación y Reverso
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};
