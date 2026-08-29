import React, { useState, useEffect } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { 
  ShieldAlert, RotateCcw, Trash2, AlertTriangle, 
  Loader2, Info, CheckCircle2 
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { catalogoCuentasService } from '@/services/catalogoCuentas';

interface CatalogoDangerModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

type TipoSubdominio = 'presupuestario' | 'patrimonial';

export const CatalogoDangerModal: React.FC<CatalogoDangerModalProps> = ({
  open,
  onOpenChange,
}) => {
  const queryClient = useQueryClient();
  const [subdominioTarget, setSubdominioTarget] = useState<TipoSubdominio>('presupuestario');
  const [confirmInput, setConfirmInput] = useState('');
  const [bloqueoMensaje, setBloqueoMensaje] = useState<string | null>(null);

  const wordRequired = subdominioTarget === 'presupuestario' ? 'VACIAR ONAPRE' : 'VACIAR ONCOP';
  const isConfirmValid = confirmInput.trim().toUpperCase() === wordRequired;

  useEffect(() => {
    if (!open) {
      setConfirmInput('');
      setBloqueoMensaje(null);
      setSubdominioTarget('presupuestario');
    }
  }, [open]);

  // MUTACIÓN 1: ROLLBACK DEL ÚLTIMO LOTE
  const rollbackMutation = useMutation({
    mutationFn: () => catalogoCuentasService.deshacerUltimoLote(),
    onSuccess: (data) => {
      toast.success(data.mensaje || 'Último lote deshecho exitosamente.');
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
      onOpenChange(false);
    },
    onError: (err: Error) => {
      setBloqueoMensaje(err.message);
      toast.error(err.message || 'No se pudo deshacer el último lote.');
    },
  });

  // MUTACIÓN 2: VACIADO SEGURO POR SUBDOMINIO
  const vaciarMutation = useMutation({
    mutationFn: (tipo: TipoSubdominio) => catalogoCuentasService.vaciarCatalogo(tipo),
    onSuccess: (data) => {
      toast.success(data.mensaje || 'Subdominio vaciado exitosamente.');
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
      onOpenChange(false);
    },
    onError: (err: Error) => {
      setBloqueoMensaje(err.message);
      toast.error(err.message || 'No se pudo vaciar el catálogo.');
    },
  });

  const isPending = rollbackMutation.isPending || vaciarMutation.isPending;

  return (
    <Dialog open={open} onOpenChange={(val) => !isPending && onOpenChange(val)}>
      <DialogContent className="sm:max-w-xl border-border shadow-xl max-h-[92vh] overflow-y-auto p-6">
        <DialogHeader className="pb-3 border-b border-border/60">
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            <div className="size-8 rounded-md bg-rose-500/10 text-rose-600 flex items-center justify-center">
              <ShieldAlert className="size-4" />
            </div>
            Gestión de Limpieza y Rollback de Catálogo
          </DialogTitle>
        </DialogHeader>

        {/* ALERTA DE BLOQUEO POR DEPENDENCIAS (FAIL-CLOSED) */}
        {bloqueoMensaje && (
          <div className="bg-rose-500/10 border border-rose-500/30 rounded-lg p-3 text-xs text-rose-600 space-y-1">
            <p className="font-bold flex items-center gap-1.5">
              <AlertTriangle className="size-4 shrink-0" /> Operación Bloqueada por Seguridad:
            </p>
            <p className="pl-5 leading-relaxed">{bloqueoMensaje}</p>
          </div>
        )}

        <div className="space-y-4 py-2 text-xs">
          {/* OPCIÓN A: ROLLBACK ÚLTIMO LOTE */}
          <div className="bg-card border border-border/70 rounded-lg p-4 space-y-3 shadow-xs">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="size-7 rounded bg-amber-500/10 text-amber-600 flex items-center justify-center">
                  <RotateCcw className="size-3.5" />
                </div>
                <h3 className="font-bold text-foreground text-xs">Opción A: Deshacer Última Importación (Batch Rollback)</h3>
              </div>
              <Badge variant="outline" className="bg-amber-500/10 text-amber-600 border-amber-500/20 text-[10px] font-mono">
                BAJO - ROLLBACK
              </Badge>
            </div>

            <p className="text-muted-foreground leading-relaxed">
              Identifica las cuentas creadas durante la última importación masiva y las elimina quirúrgicamente sin tocar el resto del catálogo.
            </p>

            <div className="pt-1 flex justify-end">
              <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={isPending}
                onClick={() => rollbackMutation.mutate()}
                className="border-amber-500/30 text-amber-600 hover:bg-amber-500/10 font-semibold text-xs h-8 gap-1.5"
              >
                {rollbackMutation.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <RotateCcw className="size-3.5" />}
                Deshacer Último Lote
              </Button>
            </div>
          </div>

          {/* OPCIÓN B: VACIADO SEGURO POR SUBDOMINIO */}
          <div className="bg-card border border-border/70 rounded-lg p-4 space-y-3 shadow-xs">
            <div className="flex items-center justify-between">
              <div className="flex items-center gap-2">
                <div className="size-7 rounded bg-rose-500/10 text-rose-600 flex items-center justify-center">
                  <Trash2 className="size-3.5" />
                </div>
                <h3 className="font-bold text-foreground text-xs">Opción B: Vaciar Subdominio Específico</h3>
              </div>
              <Badge variant="outline" className="bg-rose-500/10 text-rose-600 border-rose-500/20 text-[10px] font-mono">
                TOTAL POR DOMINIO
              </Badge>
            </div>

            <p className="text-muted-foreground leading-relaxed">
              Elimina de forma masiva y quirúrgica únicamente la mitad seleccionada del catálogo, protegiendo las partidas presupuestarias o cuentas patrimoniales del subdominio contrario.
            </p>

            {/* SELECCIÓN DE SUBDOMINIO */}
            <div className="space-y-1.5 pt-1">
              <Label className="text-xs font-semibold text-foreground">Subdominio a Vaciar:</Label>
              <Select
                value={subdominioTarget}
                onValueChange={(val) => {
                  setSubdominioTarget(val as TipoSubdominio);
                  setConfirmInput('');
                  setBloqueoMensaje(null);
                }}
                disabled={isPending}
              >
                <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="presupuestario" className="text-xs">Solo Partidas Presupuestarias (ONAPRE)</SelectItem>
                  <SelectItem value="patrimonial" className="text-xs">Solo Cuentas Patrimoniales (ONCOP)</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* CONFIRMACIÓN DE FRICCIÓN INTENCIONAL */}
            <div className="space-y-1.5 border-t border-border/40 pt-3">
              <Label className="text-xs font-semibold text-foreground">
                Para confirmar la eliminación del subdominio, escriba <span className="font-mono text-rose-600 select-all font-bold">{wordRequired}</span>:
              </Label>
              <Input
                value={confirmInput}
                onChange={(e) => setConfirmInput(e.target.value)}
                placeholder={`Escriba ${wordRequired} aquí...`}
                disabled={isPending}
                className="h-9 text-xs font-mono border-rose-500/30 focus-visible:ring-rose-500"
              />
            </div>

            <div className="pt-2 flex justify-end">
              <Button
                type="button"
                size="sm"
                disabled={!isConfirmValid || isPending}
                onClick={() => vaciarMutation.mutate(subdominioTarget)}
                className="bg-rose-600 hover:bg-rose-700 text-white font-semibold text-xs h-8 px-4 gap-1.5 shadow-xs"
              >
                {vaciarMutation.isPending ? <Loader2 className="size-3.5 animate-spin" /> : <Trash2 className="size-3.5" />}
                Vaciar Catálogo ({subdominioTarget === 'presupuestario' ? 'ONAPRE' : 'ONCOP'})
              </Button>
            </div>
          </div>
        </div>

        <DialogFooter className="border-t border-border/60 pt-3 flex justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={isPending}
            onClick={() => onOpenChange(false)}
            className="text-xs h-9"
          >
            Cerrar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
