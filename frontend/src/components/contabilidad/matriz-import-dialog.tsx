import React, { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { 
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter 
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from 'sonner';
import { Loader2, FileSpreadsheet, Download, Upload, AlertTriangle, CheckCircle2, Info, BookOpen, ShieldAlert } from 'lucide-react';
import { matrizConversionService } from '@/services/matrizConversion';

interface MatrizImportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export const MatrizImportDialog: React.FC<MatrizImportDialogProps> = ({
  open,
  onOpenChange,
}) => {
  const queryClient = useQueryClient();
  const [selectedFile, setSelectedFile] = useState<File | null>(null);
  const [resultado, setResultado] = useState<{
    mensaje: string;
    procesados: number;
    insertados: number;
    actualizados?: number;
    omitidos?: number;
    errores: number;
    detalles: string[];
  } | null>(null);

  const inputRef = React.useRef<HTMLInputElement>(null);

  React.useEffect(() => {
    if (!open) {
      setSelectedFile(null);
      setResultado(null);
      importMutation.reset();
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    }
  }, [open]);

  const importMutation = useMutation({
    mutationFn: (formData: FormData) => matrizConversionService.importarMasivo(formData),
    onSuccess: (data) => {
      setResultado(data);
      queryClient.invalidateQueries({ queryKey: ['matriz-conversion'] });
    },
  });

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      setSelectedFile(e.target.files[0]);
      setResultado(null);
    }
  };

  const handleUpload = (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedFile) {
      toast.error('Por favor seleccione un archivo (.xls / .csv) para importar.');
      return;
    }

    const formData = new FormData();
    formData.append('archivo', selectedFile);

    // VECTOR 3: TOAST ASÍNCRONO PURAMENTE VISUAL
    toast.promise(importMutation.mutateAsync(formData), {
      loading: 'Subiendo y analizando matriz de conversión...',
      success: (data) => data.mensaje || 'Importación procesada exitosamente.',
      error: (err: Error) => err.message || 'Error al procesar la importación masiva.',
    });
  };

  const handleDownloadTemplate = () => {
    const url = matrizConversionService.obtenerUrlPlantilla();
    window.open(url, '_blank');
  };

  // VECTOR 4: MANEJO CONDICIONAL DEL DIÁLOGO (PREVENT UNMOUNT)
  const handleOpenChange = (newOpen: boolean) => {
    if (importMutation.isPending) return; // Bloquear cierre mientras procesa
    onOpenChange(newOpen);
  };

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent 
        className="max-w-lg border-border shadow-xl"
        onPointerDownOutside={(e) => {
          if (importMutation.isPending) e.preventDefault();
        }}
        onEscapeKeyDown={(e) => {
          if (importMutation.isPending) e.preventDefault();
        }}
      >
        <DialogHeader className="pb-3 border-b border-border/60">
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            <div className="size-8 rounded-md bg-primary/10 text-primary flex items-center justify-center">
              <FileSpreadsheet className="size-4" />
            </div>
            Importar Matriz de Conversión (Archivos Migrados)
          </DialogTitle>
          <DialogDescription className="text-xs text-muted-foreground">
            Cargue equivalencias masivas entre partidas presupuestarias ONAPRE y cuentas patrimoniales SIGCOF.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleUpload} className="space-y-4 py-2">
          {/* GUÍA CRÍTICA DE FUNCIONAMIENTO E IMPORTANCIA */}
          <div className="p-3.5 bg-blue-500/5 border border-blue-500/20 rounded-lg space-y-2.5 text-xs text-foreground">
            <div className="flex items-center gap-2 font-bold text-blue-600 dark:text-blue-400">
              <Info className="size-4 shrink-0" />
              <span>Funcionamiento e Importancia de la Matriz al Importar</span>
            </div>

            <p className="text-[11.5px] leading-relaxed text-muted-foreground">
              La <strong>Matriz de Conversión</strong> es el puente técnico indispensable entre el 
              <strong> Presupuesto de Gastos/Ingresos</strong> y la <strong>Contabilidad Patrimonial</strong>. 
              Al ejecutar compromisos o pagos, el sistema consulta esta matriz para generar automáticamente 
              los asientos contables en el Libro Diario.
            </p>

            <div className="space-y-2 pt-1 border-t border-blue-500/10 text-[11px]">
              <div className="flex items-start gap-1.5">
                <ShieldAlert className="size-3.5 text-amber-500 shrink-0 mt-0.5" />
                <span>
                  <strong>Archivos Migrados vs. Viejos:</strong> Utilice exclusivamente los códigos vigentes 
                  del nuevo Plan Único de Cuentas (ej: Partida <code>4.01.01.01.01</code> vinculada a Cuenta <code>6.1.1.01.01.00</code>). 
                  Los códigos de catálogos antiguos no son compatibles y serán rebotados por el validador.
                </span>
              </div>

              <div className="flex items-start gap-1.5">
                <BookOpen className="size-3.5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                <span>
                  <strong>Ejemplos Omitibles (# EJEMPLO):</strong> La plantilla descargable incluye filas de demostración 
                  etiquetadas con <code># EJEMPLO</code>. El motor de importación las detecta y las 
                  <strong> omite automáticamente</strong>, por lo que no es necesario borrarlas para cargar sus datos.
                </span>
              </div>
            </div>
          </div>

          {/* DESCARGA DE PLANTILLA */}
          <div className="p-3 bg-muted/30 border border-border/60 rounded-lg space-y-2">
            <div className="flex items-center justify-between">
              <span className="text-xs font-semibold text-foreground flex items-center gap-1.5">
                <Download className="size-3.5 text-primary" />
                Plantilla limpia con ejemplos reales
              </span>
              <Button 
                type="button" 
                variant="outline" 
                size="sm" 
                disabled={importMutation.isPending}
                onClick={handleDownloadTemplate}
                className="h-7 text-[11px] gap-1 border-primary/30 text-primary hover:bg-primary/10 font-semibold"
              >
                Descargar Plantilla Excel (.xls)
              </Button>
            </div>
            <p className="text-[11px] text-muted-foreground">
              Formato estandarizado sin catálogos de referencia pesados al final. Incluye estructura lista para ser completada.
            </p>
          </div>

          {/* VECTOR 1: SELECCIÓN DE ARCHIVO DESHABILITADA MIENTRAS PROCESA */}
          <div className="space-y-1.5">
            <Label className="text-xs font-semibold text-foreground">
              Seleccionar archivo (.xls o .csv) <span className="text-destructive">*</span>
            </Label>
            <Input
              ref={inputRef}
              type="file"
              accept=".xls,.xlsx,.csv,.txt"
              disabled={importMutation.isPending}
              onChange={handleFileChange}
              className="h-9 text-xs cursor-pointer bg-background disabled:opacity-50 disabled:cursor-not-allowed"
            />
          </div>

          {/* RESULTADO DE IMPORTACIÓN */}
          {resultado && (
            <div className="p-3 bg-muted/30 border border-border rounded-lg space-y-2 text-xs">
              <div className="flex items-center gap-2 font-semibold text-emerald-600 dark:text-emerald-400">
                <CheckCircle2 className="size-4" />
                <span>{resultado.mensaje}</span>
              </div>
              <div className="grid grid-cols-4 gap-1.5 text-center text-[10px]">
                <div className="bg-background p-1.5 rounded border border-border">
                  <span className="text-muted-foreground block font-medium">Nuevos</span>
                  <span className="font-bold text-emerald-600 dark:text-emerald-400">{resultado.insertados}</span>
                </div>
                <div className="bg-background p-1.5 rounded border border-border">
                  <span className="text-muted-foreground block font-medium">Actualizados</span>
                  <span className="font-bold text-blue-600 dark:text-blue-400">{resultado.actualizados ?? 0}</span>
                </div>
                <div className="bg-background p-1.5 rounded border border-border">
                  <span className="text-muted-foreground block font-medium">Omitidos</span>
                  <span className="font-bold text-amber-600 dark:text-amber-400">{resultado.omitidos ?? 0}</span>
                </div>
                <div className="bg-background p-1.5 rounded border border-border">
                  <span className="text-muted-foreground block font-medium">Errores</span>
                  <span className="font-bold text-rose-600 dark:text-rose-400">{resultado.errores}</span>
                </div>
              </div>

              {resultado.detalles && resultado.detalles.length > 0 && (
                <div className="mt-2 space-y-1">
                  <p className="text-[11px] font-bold text-rose-600 dark:text-rose-400 flex items-center gap-1">
                    <AlertTriangle className="size-3" /> Detalle de Errores:
                  </p>
                  <ul className="max-h-28 overflow-y-auto space-y-0.5 text-[10px] font-mono text-muted-foreground bg-background p-1.5 rounded border border-border">
                    {resultado.detalles.map((d, i) => (
                      <li key={i}>• {d}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}

          {/* VECTOR 1 & 2: BLOQUEO DE BOTONES Y MICRO-INTERACCIONES EN SUBMIT */}
          <DialogFooter className="border-t border-border/60 pt-3 mt-4">
            <Button 
              variant="outline" 
              type="button" 
              size="sm" 
              disabled={importMutation.isPending}
              onClick={() => onOpenChange(false)} 
              className="h-9 text-xs"
            >
              Cerrar
            </Button>
            <Button 
              type="submit" 
              size="sm" 
              disabled={!selectedFile || importMutation.isPending} 
              className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold h-9 text-xs px-4 gap-1.5 transition-all"
            >
              {importMutation.isPending ? (
                <>
                  <Loader2 className="size-3.5 animate-spin" />
                  <span>Procesando archivo, por favor espere...</span>
                </>
              ) : (
                <>
                  <Upload className="size-3.5" />
                  <span>Procesar Importación</span>
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};

