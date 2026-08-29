import React, { useState, useRef, useEffect } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { 
  Upload, FileSpreadsheet, AlertTriangle, CheckCircle2, 
  Loader2, Download, Info, ShieldAlert 
} from 'lucide-react';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { catalogoCuentasService } from '@/services/catalogoCuentas';

interface CatalogoImportDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  defaultDominio?: 'presupuestario' | 'patrimonial' | 'auto';
}

interface ResultadoImportacion {
  mensaje: string;
  lote_id: string;
  procesados: number;
  insertados: number;
  actualizados: number;
  omitidos: number;
  errores: number;
  detalles_errores?: string[];
}

export const CatalogoImportDialog: React.FC<CatalogoImportDialogProps> = ({
  open,
  onOpenChange,
  defaultDominio = 'auto',
}) => {
  const queryClient = useQueryClient();
  const [file, setFile] = useState<File | null>(null);
  const [resultado, setResultado] = useState<ResultadoImportacion | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const [dominioObjetivo, setDominioObjetivo] = useState<'auto' | 'presupuestario' | 'patrimonial'>(defaultDominio);

  // Limpieza física del input file al desmontar o cerrar
  useEffect(() => {
    if (!open) {
      setFile(null);
      setResultado(null);
      setDominioObjetivo(defaultDominio);
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    } else {
      setDominioObjetivo(defaultDominio);
    }
  }, [open, defaultDominio]);

  // TanStack Query useMutation (SoC: Separación estricta de Responsabilidades)
  const importMutation = useMutation({
    mutationFn: async (formData: FormData) => {
      return await catalogoCuentasService.importarMasivo(formData);
    },
    onSuccess: (data) => {
      setResultado(data);
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
    },
    onError: () => {
      // El manejo del error se realiza mediante toast.promise
    },
  });

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    if (e.target.files && e.target.files[0]) {
      const selectedFile = e.target.files[0];
      validateAndSetFile(selectedFile);
    }
  };

  const validateAndSetFile = (selectedFile: File) => {
    const ext = selectedFile.name.split('.').pop()?.toLowerCase();
    if (!['csv', 'xls', 'xlsx'].includes(ext || '')) {
      toast.error('Formato no válido. Solo se permiten archivos .csv, .xls o .xlsx');
      return;
    }
    
    // Circuit Breaker Frontend: 5MB
    if (['xls', 'xlsx'].includes(ext || '') && selectedFile.size > 5 * 1024 * 1024) {
      toast.error('El archivo Excel supera los 5MB. Para volúmenes masivos, por favor utilice formato CSV.');
      return;
    }

    setFile(selectedFile);
    setResultado(null);
  };

  const handleDrop = (e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
    if (importMutation.isPending) return;
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      validateAndSetFile(e.dataTransfer.files[0]);
    }
  };

  const handleDragOver = (e: React.DragEvent<HTMLDivElement>) => {
    e.preventDefault();
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!file || importMutation.isPending) return;

    const formData = new FormData();
    formData.append('archivo', file);
    formData.append('dominio_objetivo', dominioObjetivo);

    // SoC: toast.promise gestiona los mensajes de interfaz
    toast.promise(importMutation.mutateAsync(formData), {
      loading: 'Procesando catálogo masivo (ONAPRE y ONCOP)...',
      success: (data) => data.mensaje || 'Importación completada con éxito.',
      error: (err: Error) => err.message || 'Error al procesar el archivo.',
    });
  };

  return (
    <Dialog open={open} onOpenChange={(val) => !importMutation.isPending && onOpenChange(val)}>
      <DialogContent className="sm:max-w-xl border-border shadow-xl max-h-[90vh] overflow-y-auto p-6">
        <DialogHeader className="pb-3 border-b border-border/60">
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            <div className="size-8 rounded-md bg-emerald-500/10 text-emerald-600 flex items-center justify-center">
              <FileSpreadsheet className="size-4" />
            </div>
            Importar Catálogo de Cuentas y Partidas
          </DialogTitle>
        </DialogHeader>

        {/* INSTRUCCIONES Y REGLAS */}
        <div className="bg-muted/40 border border-border/60 rounded-lg p-3 space-y-2 text-xs text-muted-foreground">
          <div className="flex items-start gap-2">
            <Info className="size-4 text-primary shrink-0 mt-0.5" />
            <div>
              <p className="font-semibold text-foreground">Inferencia Polimórfica Estricta:</p>
              <p>El motor detecta automáticamente si la fila corresponde a una <strong>Partida Presupuestaria ONAPRE</strong> (códigos que inician con 4.01, 3.01...) o a una <strong>Cuenta Patrimonial ONCOP</strong> (activos, pasivos, patrimonio) infiriendo su tipo y naturaleza.</p>
            </div>
          </div>
          <div className="flex items-start gap-2 pt-1 border-t border-border/30">
            <ShieldAlert className="size-4 text-amber-500 shrink-0 mt-0.5" />
            <p><strong>Límite Táctico:</strong> Archivos Excel (.xls/.xlsx) hasta 5MB. Para volúmenes superiores, utilice formato <strong>.CSV</strong> para streaming sin colapsar el servidor.</p>
          </div>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4">
          {/* SELECTOR DE DOMINIO OBJETIVO (Enterprise Domain Context) */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">
              Dominio de Destino / Clasificación <span className="text-muted-foreground font-normal">(Opcional)</span>
            </Label>
            <select
              value={dominioObjetivo}
              onChange={(e) => setDominioObjetivo(e.target.value as 'auto' | 'presupuestario' | 'patrimonial')}
              disabled={importMutation.isPending}
              className="w-full text-xs font-medium bg-background border border-border rounded-md px-3 py-2 text-foreground focus:outline-none focus:ring-2 focus:ring-primary/40 cursor-pointer"
            >
              <option value="auto">✨ Detección Automática por Prefijo Oficial (ONAPRE / ONCOP)</option>
              <option value="presupuestario">📘 Forzar Partidas Presupuestarias (ONAPRE)</option>
              <option value="patrimonial">📗 Forzar Cuentas Patrimoniales (ONCOP)</option>
            </select>
          </div>

          {/* ZONA DRAG & DROP */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">
              Seleccionar archivo (.csv, .xls o .xlsx) <span className="text-destructive">*</span>
            </Label>
            <div
              onDrop={handleDrop}
              onDragOver={handleDragOver}
              className={`relative border-2 border-dashed rounded-lg p-6 text-center transition-all ${
                importMutation.isPending 
                  ? 'opacity-50 pointer-events-none bg-muted/20 border-border' 
                  : file 
                  ? 'border-emerald-500/50 bg-emerald-500/5' 
                  : 'border-border/80 hover:border-primary/50 hover:bg-muted/30'
              }`}
            >
              <input
                ref={inputRef}
                type="file"
                accept=".csv, .xls, .xlsx"
                onChange={handleFileChange}
                disabled={importMutation.isPending}
                className="hidden"
                id="catalogo-file-input"
              />
              <label htmlFor="catalogo-file-input" className="cursor-pointer flex flex-col items-center justify-center gap-2">
                <div className="size-10 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                  <Upload className="size-5" />
                </div>
                {file ? (
                  <div>
                    <p className="font-semibold text-foreground text-xs">{file.name}</p>
                    <p className="text-[11px] text-muted-foreground font-mono">{(file.size / 1024).toFixed(1)} KB</p>
                  </div>
                ) : (
                  <div>
                    <p className="font-semibold text-xs text-foreground">Arrastre el archivo aquí o haga clic para buscar</p>
                    <p className="text-[11px] text-muted-foreground">Formatos soportados: CSV, XLS (Excel 2003) o XLSX</p>
                  </div>
                )}
              </label>
            </div>
          </div>

          {/* CAJA DE RESULTADOS */}
          {resultado && (
            <div className="space-y-3 bg-card border border-border/80 rounded-lg p-4 text-xs">
              <div className="flex items-center gap-2 text-emerald-600 dark:text-emerald-400 font-semibold">
                <CheckCircle2 className="size-4 shrink-0" />
                <span>{resultado.mensaje}</span>
              </div>

              <div className="grid grid-cols-4 gap-2 text-center">
                <div className="bg-muted/30 p-2 rounded border border-border/40">
                  <p className="text-[10px] text-muted-foreground font-medium">Nuevos</p>
                  <p className="text-base font-bold text-emerald-600">{resultado.insertados}</p>
                </div>
                <div className="bg-muted/30 p-2 rounded border border-border/40">
                  <p className="text-[10px] text-muted-foreground font-medium">Actualizados</p>
                  <p className="text-base font-bold text-blue-600">{resultado.actualizados}</p>
                </div>
                <div className="bg-muted/30 p-2 rounded border border-border/40">
                  <p className="text-[10px] text-muted-foreground font-medium">Omitidos</p>
                  <p className="text-base font-bold text-amber-600">{resultado.omitidos}</p>
                </div>
                <div className="bg-muted/30 p-2 rounded border border-border/40">
                  <p className="text-[10px] text-muted-foreground font-medium">Errores</p>
                  <p className="text-base font-bold text-rose-600">{resultado.errores}</p>
                </div>
              </div>

              {resultado.detalles_errores && resultado.detalles_errores.length > 0 && (
                <div className="space-y-1 border-t border-border/40 pt-2">
                  <p className="font-semibold text-rose-600 flex items-center gap-1">
                    <AlertTriangle className="size-3.5" /> Detalle de Errores:
                  </p>
                  <div className="max-h-24 overflow-y-auto bg-muted/50 p-2 rounded text-[11px] font-mono text-rose-600 space-y-1">
                    {resultado.detalles_errores.map((err, i) => (
                      <p key={i}>• {err}</p>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          <DialogFooter className="border-t border-border/60 pt-3 flex items-center justify-between gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={importMutation.isPending}
              onClick={() => onOpenChange(false)}
              className="text-xs h-9"
            >
              {resultado ? 'Cerrar' : 'Cancelar'}
            </Button>

            <Button
              type="submit"
              size="sm"
              disabled={!file || importMutation.isPending}
              className="bg-emerald-600 hover:bg-emerald-700 text-white font-semibold text-xs h-9 px-5 gap-2"
            >
              {importMutation.isPending ? (
                <>
                  <Loader2 className="size-3.5 animate-spin" />
                  Procesando...
                </>
              ) : (
                <>
                  <Upload className="size-3.5" />
                  Procesar Importación
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};
