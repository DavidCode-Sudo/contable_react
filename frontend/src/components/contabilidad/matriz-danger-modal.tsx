import React, { useState, useEffect } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { 
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter 
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import { 
  AlertTriangle, Undo2, Trash2, ShieldAlert, Loader2, AlertOctagon 
} from 'lucide-react';
import { matrizConversionService } from '@/services/matrizConversion';

interface MatrizDangerModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export const MatrizDangerModal: React.FC<MatrizDangerModalProps> = ({
  open,
  onOpenChange,
}) => {
  const queryClient = useQueryClient();
  const [confirmWord, setConfirmWord] = useState('');

  useEffect(() => {
    if (!open) {
      setConfirmWord('');
    }
  }, [open]);

  // Mutación 1: Deshacer Último Lote (Batch Rollback)
  const deshacerLoteMutation = useMutation({
    mutationFn: () => matrizConversionService.deshacerUltimoLote(),
    onSuccess: (data) => {
      toast.success(data.mensaje);
      queryClient.invalidateQueries({ queryKey: ['matriz-conversion'] });
      onOpenChange(false);
    },
    onError: (err: Error) => {
      toast.error(err.message);
    },
  });

  // Mutación 2: Vaciar Matriz Completa (Safe Delete + Fail-Closed)
  const vaciarMatrizMutation = useMutation({
    mutationFn: () => matrizConversionService.vaciarMatriz(),
    onSuccess: (data) => {
      toast.success(data.mensaje);
      queryClient.invalidateQueries({ queryKey: ['matriz-conversion'] });
      onOpenChange(false);
    },
    onError: (err: Error) => {
      toast.error(err.message);
    },
  });

  const isProcessing = deshacerLoteMutation.isPending || vaciarMatrizMutation.isPending;

  const handleClose = (newOpen: boolean) => {
    if (isProcessing) return;
    onOpenChange(newOpen);
  };

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent 
        className="max-w-xl border-border shadow-xl bg-background"
        onPointerDownOutside={(e) => { if (isProcessing) e.preventDefault(); }}
        onEscapeKeyDown={(e) => { if (isProcessing) e.preventDefault(); }}
      >
        {/* REGLA 1: TÍTULO MONOCROMÁTICO CON ÍCONO DE ACENTO SOBRIO */}
        <DialogHeader className="pb-3 border-b border-border/60">
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            <div className="size-8 rounded-md bg-muted text-amber-500 flex items-center justify-center border border-border">
              <AlertTriangle className="size-4 shrink-0" />
            </div>
            Gestión de Limpieza y Rollback de Matriz
          </DialogTitle>
          <DialogDescription className="text-xs text-muted-foreground">
            Elija la estrategia de eliminación según el nivel de impacto deseado (Blast Radius Control).
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3.5 py-2">
          {/* OPCIÓN A: DESHACER ÚLTIMA IMPORTACIÓN (CONTENEDOR NEUTRO CON ACENTO EN ÍCONO Y BADGE) */}
          <div className="p-3.5 rounded-lg border border-border bg-card hover:border-border/80 transition-all space-y-2.5 shadow-xs">
            <div className="flex items-start justify-between gap-2">
              <div className="flex items-center gap-2.5">
                <div className="size-7 rounded bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center shrink-0 border border-amber-500/20">
                  <Undo2 className="size-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-foreground">
                    Opción A: Deshacer Última Importación
                  </h4>
                  <p className="text-[11px] text-muted-foreground font-mono">
                    Batch Rollback (Solo último Excel/CSV)
                  </p>
                </div>
              </div>
              <Badge variant="outline" className="bg-amber-500/10 text-amber-700 dark:text-amber-300 border-amber-500/30 text-[10px] uppercase font-bold shrink-0">
                Radio de Impacto: BAJO
              </Badge>
            </div>

            {/* REGLA 2: TIPOGRAFÍA LIMPIA SIN TEXTO DE COLORES EXCESIVOS */}
            <p className="text-[11.5px] leading-relaxed text-muted-foreground">
              Revierte de forma segura las reglas procesadas durante la última carga masiva. 
              <span className="block mt-1 text-foreground font-medium">
                Nota: Si este lote actualizó reglas que ya existían previamente en el sistema, dichas reglas serán eliminadas de la base de datos (no restauradas a su valor anterior).
              </span>
            </p>

            <div className="flex justify-end pt-1">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={isProcessing}
                onClick={() => deshacerLoteMutation.mutate()}
                className="h-8 text-xs font-semibold border-border hover:bg-muted text-foreground gap-1.5"
              >
                {deshacerLoteMutation.isPending ? (
                  <Loader2 className="size-3.5 animate-spin text-amber-500" />
                ) : (
                  <Undo2 className="size-3.5 text-amber-500" />
                )}
                Deshacer Último Lote
              </Button>
            </div>
          </div>

          {/* OPCIÓN B: VACIAR MATRIZ COMPLETA (CONTENEDOR NEUTRO CON VACIAR COMO DETONADOR ÚNICO ROJO) */}
          <div className="p-3.5 rounded-lg border border-border bg-card hover:border-border/80 transition-all space-y-2.5 shadow-xs">
            <div className="flex items-start justify-between gap-2">
              <div className="flex items-center gap-2.5">
                <div className="size-7 rounded bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center shrink-0 border border-rose-500/20">
                  <AlertOctagon className="size-4" />
                </div>
                <div>
                  <h4 className="text-xs font-bold text-foreground">
                    Opción B: Vaciar Matriz Completa
                  </h4>
                  <p className="text-[11px] text-muted-foreground font-mono">
                    Reset Total de Configuración
                  </p>
                </div>
              </div>
              <Badge variant="destructive" className="text-[10px] uppercase font-bold shrink-0">
                Radio de Impacto: TOTAL
              </Badge>
            </div>

            <p className="text-[11.5px] leading-relaxed text-muted-foreground">
              Elimina <strong className="text-foreground">TODAS</strong> las reglas de conversión registradas en la base de datos para iniciar el catálogo desde cero.
            </p>

            <div className="p-2.5 bg-muted/50 border border-border rounded text-[11px] text-muted-foreground flex items-start gap-2">
              <ShieldAlert className="size-4 shrink-0 text-foreground mt-0.5" />
              <span>
                <strong className="text-foreground">Protección ERP de Trazabilidad (Fail-Closed):</strong> Esta acción será denegada automáticamente si existen asientos contables generados en el Libro Diario.
              </span>
            </div>

            {/* CONFIRMACIÓN CON FRICCIÓN INTENCIONAL */}
            <div className="space-y-1.5 pt-1.5 border-t border-border">
              <label className="text-[11px] font-semibold text-foreground block">
                Para habilitar el vaciado total, escriba la palabra <span className="font-mono font-bold uppercase underline text-rose-600 dark:text-rose-400">VACIAR</span>:
              </label>
              <Input
                type="text"
                placeholder="Escriba VACIAR para habilitar el botón"
                value={confirmWord}
                onChange={(e) => setConfirmWord(e.target.value.toUpperCase())}
                disabled={isProcessing}
                className="h-8 text-xs bg-background border-border focus-visible:ring-rose-500 font-mono tracking-wider font-bold text-foreground"
              />
            </div>

            <div className="flex justify-end pt-1">
              <Button
                type="button"
                size="sm"
                variant="destructive"
                disabled={isProcessing || confirmWord !== 'VACIAR'}
                onClick={() => vaciarMatrizMutation.mutate()}
                className="h-8 text-xs font-semibold gap-1.5 bg-rose-600 hover:bg-rose-700 text-white disabled:opacity-40 disabled:cursor-not-allowed shadow-xs"
              >
                {vaciarMatrizMutation.isPending ? (
                  <Loader2 className="size-3.5 animate-spin" />
                ) : (
                  <Trash2 className="size-3.5" />
                )}
                Vaciar Matriz Completa
              </Button>
            </div>
          </div>
        </div>

        <DialogFooter className="border-t border-border/60 pt-3 mt-2">
          <Button 
            variant="outline" 
            type="button" 
            size="sm" 
            disabled={isProcessing}
            onClick={() => onOpenChange(false)} 
            className="h-8 text-xs"
          >
            Cancelar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
