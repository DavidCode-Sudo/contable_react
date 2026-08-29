import React, { useState, useEffect, useCallback, useMemo, useRef } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { 
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter 
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { toast } from 'sonner';
import { Loader2, ArrowLeftRight, Check, ChevronsUpDown, Search, X, AlertCircle } from 'lucide-react';
import { 
  matrizConversionService, 
  type MatrizConversion, 
  type MatrizPayload, 
  type TipoOperacion,
  type SearchCuentaItem 
} from '@/services/matrizConversion';

interface MatrizDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  matrizEdit?: MatrizConversion;
}

// COMPONENTE BUSCADOR ESTILO SELECT2 PARA REACT CON VALIDACIÓN
const Select2Combobox: React.FC<{
  value?: number | null;
  onChange: (val: number | null) => void;
  placeholder: string;
  isPartida?: boolean;
  initialLabel?: string;
  allowEmptyHaber?: boolean;
  hasError?: boolean;
}> = ({ value, onChange, placeholder, isPartida = false, initialLabel, allowEmptyHaber = false, hasError = false }) => {
  const [open, setOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [items, setItems] = useState<SearchCuentaItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [selectedLabel, setSelectedLabel] = useState(initialLabel || '');
  const containerRef = useRef<HTMLDivElement>(null);
  const inputRef = useRef<HTMLInputElement>(null);

  const loadItems = useCallback(async (q: string) => {
    setLoading(true);
    try {
      const results = isPartida 
        ? await matrizConversionService.searchPartidas(q)
        : await matrizConversionService.searchContables(q);
      setItems(results);
    } catch {
      setItems([]);
    } finally {
      setLoading(false);
    }
  }, [isPartida]);

  useEffect(() => {
    if (open) {
      loadItems(searchQuery);
      setTimeout(() => inputRef.current?.focus(), 50);
    }
  }, [open, searchQuery, loadItems]);

  useEffect(() => {
    if (initialLabel) setSelectedLabel(initialLabel);
  }, [initialLabel]);

  // Cierra el menú desplegable si se hace clic fuera del contenedor
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div ref={containerRef} className="relative w-full">
      {/* BOTÓN CAJA SELECT2 */}
      <button
        type="button"
        onClick={() => {
          setOpen(!open);
          setSearchQuery('');
        }}
        className={`w-full h-9 px-3 py-1.5 text-xs font-mono bg-background border rounded-md shadow-xs flex items-center justify-between transition-colors focus:outline-none focus:ring-1 focus:ring-primary ${
          hasError
            ? 'border-destructive focus:ring-destructive text-destructive'
            : open 
            ? 'border-primary ring-1 ring-primary' 
            : 'border-input hover:bg-accent/40'
        }`}
      >
        <span className={`truncate mr-2 ${selectedLabel ? 'text-foreground font-semibold' : 'text-muted-foreground font-sans'}`}>
          {selectedLabel || placeholder}
        </span>
        <div className="flex items-center gap-1 shrink-0 text-muted-foreground">
          {selectedLabel && (
            <X 
              className="size-3.5 hover:text-destructive cursor-pointer mr-0.5" 
              onClick={(e) => {
                e.stopPropagation();
                onChange(null);
                setSelectedLabel('');
              }}
            />
          )}
          <ChevronsUpDown className="size-3.5 opacity-60" />
        </div>
      </button>

      {/* MENÚ DESPLEGABLE CON BUSCADOR INTERNO SELECT2 */}
      {open && (
        <div className="absolute z-50 mt-1 w-full max-h-72 rounded-md border border-border bg-background shadow-xl p-1.5 flex flex-col space-y-1.5">
          {/* BARRA DE BÚSQUEDA SELECT2 */}
          <div className="relative flex items-center px-1 pt-1">
            <Search className="absolute left-3 size-3.5 text-muted-foreground" />
            <Input
              ref={inputRef}
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Filtrar por código o nombre..."
              className="h-8 pl-8 text-xs font-mono bg-muted/30 focus-visible:ring-primary"
            />
            {searchQuery && (
              <X 
                className="absolute right-3 size-3.5 text-muted-foreground hover:text-foreground cursor-pointer" 
                onClick={() => setSearchQuery('')}
              />
            )}
          </div>

          {/* RESULTADOS SCROLLABLES */}
          <div className="overflow-y-auto max-h-56 divide-y divide-border/30">
            {allowEmptyHaber && (
              <div
                onClick={() => {
                  onChange(null);
                  setSelectedLabel('Ninguna (usar cuenta bancaria)');
                  setOpen(false);
                }}
                className="text-xs font-mono cursor-pointer flex items-center justify-between py-2 px-2.5 rounded-sm hover:bg-accent text-amber-600 dark:text-amber-400 font-semibold mb-1"
              >
                <span>Ninguna (usar cuenta bancaria)</span>
                {!value && <Check className="size-3.5 text-amber-600" />}
              </div>
            )}

            {loading ? (
              <div className="flex items-center justify-center p-4 text-xs text-muted-foreground">
                <Loader2 className="size-4 animate-spin mr-2 text-primary" /> Cargando catálogo de cuentas...
              </div>
            ) : items.length === 0 ? (
              <div className="p-4 text-center text-xs text-muted-foreground">
                No se encontraron cuentas que coincidan con &quot;{searchQuery}&quot;.
              </div>
            ) : (
              items.map((item) => {
                const labelText = `${item.codigo_completo || item.codigo} - ${item.nombre}`;
                const isSelected = value === item.id;
                return (
                  <div
                    key={item.id}
                    onClick={() => {
                      onChange(item.id);
                      setSelectedLabel(labelText);
                      setOpen(false);
                    }}
                    className={`text-xs font-mono cursor-pointer flex items-center justify-between py-2 px-2.5 rounded-sm transition-colors hover:bg-primary/10 ${
                      isSelected ? 'bg-primary/15 font-bold text-primary' : 'text-foreground'
                    }`}
                  >
                    <span className="truncate mr-2" title={labelText}>{labelText}</span>
                    {isSelected && <Check className="size-3.5 text-primary shrink-0" />}
                  </div>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export const MatrizDialog: React.FC<MatrizDialogProps> = ({
  open,
  onOpenChange,
  matrizEdit,
}) => {
  const queryClient = useQueryClient();
  const isEditing = !!matrizEdit;

  const [partidaId, setPartidaId] = useState<number>(0);
  const [tipoOperacion, setTipoOperacion] = useState<TipoOperacion>('gasto');
  const [cuentaDebeId, setCuentaDebeId] = useState<number>(0);
  const [cuentaHaberId, setCuentaHaberId] = useState<number | null>(null);
  
  const [ecoCodigo, setEcoCodigo] = useState<string>('');
  const [ecoNombre, setEcoNombre] = useState<string>('');
  const [accionProyecto, setAccionProyecto] = useState<string>('');
  const [descripcion, setDescripcion] = useState<string>('');
  const [estado, setEstado] = useState<'activa' | 'inactiva'>('activa');

  // Estado de interactividad para campos tocados
  const [touched, setTouched] = useState<Record<string, boolean>>({});

  useEffect(() => {
    if (matrizEdit) {
      setPartidaId(matrizEdit.partida_presupuestaria_id);
      const rawTipo = (matrizEdit.tipo_operacion || 'pago').toLowerCase();
      const tipoNormalizado: TipoOperacion = 
        (rawTipo === 'gasto' || rawTipo === 'patrimonial' || rawTipo === 'pago') ? 'pago' 
        : (rawTipo === 'causacion') ? 'causacion' 
        : (rawTipo === 'devengado') ? 'devengado' 
        : (rawTipo === 'compromiso') ? 'compromiso' 
        : 'pago';
      setTipoOperacion(tipoNormalizado);
      setCuentaDebeId(matrizEdit.cuenta_contable_debe_id);
      setCuentaHaberId(matrizEdit.cuenta_contable_haber_id || null);
      setEcoCodigo(matrizEdit.clasificador_economico_codigo || '');
      setEcoNombre(matrizEdit.clasificador_economico_nombre || '');
      setAccionProyecto(matrizEdit.accion_centralizada_proyecto || '');
      setDescripcion(matrizEdit.descripcion || '');
      setEstado(matrizEdit.estado);
    } else {
      setPartidaId(0);
      setTipoOperacion('pago');
      setCuentaDebeId(0);
      setCuentaHaberId(null);
      setEcoCodigo('');
      setEcoNombre('');
      setAccionProyecto('');
      setDescripcion('');
      setEstado('activa');
    }
    setTouched({});
  }, [matrizEdit, open]);

  // Validaciones en tiempo real por campo
  const errors = useMemo(() => {
    const errs: Record<string, string> = {};

    // 1. Partida Presupuestaria obligatoria
    if (!partidaId || partidaId <= 0) {
      errs.partida = 'Debe seleccionar una partida presupuestaria obligatoria.';
    }

    // 2. Cuenta Contable (DEBE) obligatoria
    if (!cuentaDebeId || cuentaDebeId <= 0) {
      errs.debe = 'La cuenta contable (DEBE) es obligatoria.';
    }

    // 3. Cuenta Contable (HABER) no idéntica a DEBE
    if (cuentaDebeId && cuentaHaberId && cuentaDebeId === cuentaHaberId) {
      errs.haber = 'La cuenta DEBE y HABER no pueden ser idénticas.';
    }

    // 4. Clasificador Económico (Código) - Formato numérico con puntos
    if (ecoCodigo.trim()) {
      if (!/^[\d\.\s-]+$/.test(ecoCodigo.trim())) {
        errs.ecoCodigo = 'El código solo debe contener números y puntos (Ej: 2.1.1.01.00).';
      } else if (ecoCodigo.trim().length > 30) {
        errs.ecoCodigo = 'El código excede la longitud máxima (máx. 30 caracteres).';
      }
    }

    // 5. Clasificador Económico (Nombre)
    if (ecoNombre.trim()) {
      if (/^[^\w\s\.\,\-\(\)\/]/i.test(ecoNombre.trim()) || /[<>\{\}\$#%\*\!\=\+\?\^]/.test(ecoNombre)) {
        errs.ecoNombre = 'Símbolos especiales no permitidos en el nombre del clasificador.';
      }
    }

    // 6. Acción Centralizada / Proyecto
    if (accionProyecto.trim()) {
      if (!/^[a-zA-Z0-9_\-\s]+$/.test(accionProyecto.trim())) {
        errs.accionProyecto = 'Solo se permiten caracteres alfanuméricos y guiones (Ej: ACCCENTR, PROY-01).';
      } else if (accionProyecto.trim().length > 50) {
        errs.accionProyecto = 'Código demasiado largo (máx. 50 caracteres).';
      }
    }

    // 7. Descripción
    if (descripcion.trim()) {
      if (/[<>\{\}\$#%\*\!\=\+\?\^]/.test(descripcion)) {
        errs.descripcion = 'Símbolos de riesgo (<, >, $, #, %, *) no están permitidos.';
      } else if (descripcion.trim().length > 250) {
        errs.descripcion = 'La descripción no puede exceder 250 caracteres.';
      }
    }

    return errs;
  }, [partidaId, cuentaDebeId, cuentaHaberId, ecoCodigo, ecoNombre, accionProyecto, descripcion]);

  const isFormValid = useMemo(() => {
    return Object.keys(errors).length === 0;
  }, [errors]);

  const mutation = useMutation({
    mutationFn: (payload: MatrizPayload) => {
      return isEditing
        ? matrizConversionService.update(matrizEdit.id, payload)
        : matrizConversionService.create(payload);
    },
    onSuccess: () => {
      toast.success(isEditing ? 'Regla de matriz actualizada.' : 'Regla de matriz creada exitosamente.');
      queryClient.invalidateQueries({ queryKey: ['matriz-conversion'] });
      onOpenChange(false);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setTouched({
      partida: true,
      debe: true,
      haber: true,
      ecoCodigo: true,
      ecoNombre: true,
      accionProyecto: true,
      descripcion: true,
    });

    if (!isFormValid) {
      toast.error('Corrija los campos con errores en rojo antes de guardar.');
      return;
    }

    mutation.mutate({
      partida_presupuestaria_id: partidaId,
      tipo_operacion: tipoOperacion,
      cuenta_contable_debe_id: cuentaDebeId,
      cuenta_contable_haber_id: cuentaHaberId || null,
      clasificador_economico_codigo: ecoCodigo.trim() || undefined,
      clasificador_economico_nombre: ecoNombre.trim() || undefined,
      accion_centralizada_proyecto: accionProyecto.trim() || undefined,
      descripcion: descripcion.trim(),
      estado,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl md:max-w-3xl border-border shadow-xl max-h-[92vh] overflow-y-auto p-6">
        <DialogHeader className="pb-3 border-b border-border/60">
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            <div className="size-8 rounded-md bg-primary/10 text-primary flex items-center justify-center">
              <ArrowLeftRight className="size-4" />
            </div>
            {isEditing ? 'Editar Conversión' : 'Nueva Conversión'}
          </DialogTitle>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 py-2 text-xs" noValidate>
          {/* Campo 1: Partida Presupuestaria con Select2 */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">
              Partida Presupuestaria <span className="text-destructive">*</span>
            </Label>
            <Select2Combobox
              value={partidaId}
              onChange={(val) => {
                setPartidaId(val || 0);
                setTouched((p) => ({ ...p, partida: true }));
              }}
              placeholder="Buscar partida..."
              isPartida={true}
              hasError={Boolean(touched.partida && errors.partida)}
              initialLabel={matrizEdit?.partida_codigo ? `${matrizEdit.partida_codigo_completo || matrizEdit.partida_codigo} - ${matrizEdit.partida_nombre}` : ''}
            />
            <p className="text-[11px] text-muted-foreground">Escriba para buscar por código o nombre</p>
            {touched.partida && errors.partida && (
              <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                <AlertCircle className="size-3 shrink-0" />
                {errors.partida}
              </p>
            )}
          </div>

          {/* Campo 2: Cuenta Contable (DEBE) con Select2 */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">
              Cuenta Contable (DEBE) <span className="text-destructive">*</span>
            </Label>
            <Select2Combobox
              value={cuentaDebeId}
              onChange={(val) => {
                setCuentaDebeId(val || 0);
                setTouched((p) => ({ ...p, debe: true }));
              }}
              placeholder="Buscar cuenta..."
              isPartida={false}
              hasError={Boolean(touched.debe && errors.debe)}
              initialLabel={matrizEdit?.debe_codigo ? `${matrizEdit.debe_codigo} - ${matrizEdit.debe_nombre}` : ''}
            />
            <p className="text-[11px] text-muted-foreground">Escriba para buscar por código o nombre</p>
            {touched.debe && errors.debe && (
              <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                <AlertCircle className="size-3 shrink-0" />
                {errors.debe}
              </p>
            )}
          </div>

          {/* Campo 3: Cuenta Contable (HABER) con Select2 */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">
              Cuenta Contable (HABER) <span className="text-muted-foreground font-normal">(Opcional - si no se especifica, se usa cuenta bancaria)</span>
            </Label>
            <Select2Combobox
              value={cuentaHaberId}
              onChange={(val) => {
                setCuentaHaberId(val || null);
                setTouched((p) => ({ ...p, haber: true }));
              }}
              placeholder="Ninguna (usar cuenta bancaria)"
              isPartida={false}
              allowEmptyHaber={true}
              hasError={Boolean(touched.haber && errors.haber)}
              initialLabel={matrizEdit?.haber_codigo ? `${matrizEdit.haber_codigo} - ${matrizEdit.haber_nombre}` : 'Ninguna (usar cuenta bancaria)'}
            />
            <p className="text-[11px] text-muted-foreground">Escriba para buscar por código o nombre</p>
            {touched.haber && errors.haber && (
              <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                <AlertCircle className="size-3 shrink-0" />
                {errors.haber}
              </p>
            )}
          </div>

          {/* Grid de 2 columnas: Tipo de Operación y Estado */}
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1">
              <Label className="text-xs font-semibold text-foreground">Tipo de Operación</Label>
              <Select value={tipoOperacion} onValueChange={(val) => setTipoOperacion(val as TipoOperacion)}>
                <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="pago" className="text-xs">Pago</SelectItem>
                  <SelectItem value="compromiso" className="text-xs">Compromiso</SelectItem>
                  <SelectItem value="causacion" className="text-xs">Causación</SelectItem>
                  <SelectItem value="devengado" className="text-xs">Devengado</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <Label className="text-xs font-semibold text-foreground">Estado de la Regla</Label>
              <Select value={estado} onValueChange={(val) => setEstado(val as 'activa' | 'inactiva')}>
                <SelectTrigger className="h-9 text-xs"><SelectValue /></SelectTrigger>
                <SelectContent>
                  <SelectItem value="activa" className="text-xs">Activa</SelectItem>
                  <SelectItem value="inactiva" className="text-xs">Inactiva</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* Clasificador Económico en ancho completo con grid interno de 3 columnas */}
          <div className="space-y-1 border-t border-border/40 pt-3">
            <Label className="text-xs font-semibold text-foreground">
              Clasificador Económico <span className="text-muted-foreground font-normal">(Opcional - se infiere automáticamente si está vacío)</span>
            </Label>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div className="sm:col-span-1">
                <Input
                  value={ecoCodigo}
                  onChange={(e) => {
                    setEcoCodigo(e.target.value);
                    setTouched((p) => ({ ...p, ecoCodigo: true }));
                  }}
                  placeholder="Ej: 2.1.1.01.00"
                  className={`h-9 text-xs font-mono ${touched.ecoCodigo && errors.ecoCodigo ? 'border-destructive focus-visible:ring-destructive' : ''}`}
                />
                {touched.ecoCodigo && errors.ecoCodigo && (
                  <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                    <AlertCircle className="size-3 shrink-0" />
                    {errors.ecoCodigo}
                  </p>
                )}
              </div>
              <div className="sm:col-span-2">
                <Input
                  value={ecoNombre}
                  onChange={(e) => {
                    setEcoNombre(e.target.value);
                    setTouched((p) => ({ ...p, ecoNombre: true }));
                  }}
                  placeholder="Ej: Beneficios socioeconómicos y primas..."
                  className={`h-9 text-xs ${touched.ecoNombre && errors.ecoNombre ? 'border-destructive focus-visible:ring-destructive' : ''}`}
                />
                {touched.ecoNombre && errors.ecoNombre && (
                  <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                    <AlertCircle className="size-3 shrink-0" />
                    {errors.ecoNombre}
                  </p>
                )}
              </div>
            </div>
            <p className="text-[10px] text-muted-foreground leading-tight mt-0.5">
              Si está vacío, se calcula automáticamente del código presupuestario.
            </p>
          </div>

          {/* Campo: Acción Centralizada / Proyecto */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">
              Acción Centralizada/Proyecto <span className="text-muted-foreground font-normal">(Opcional)</span>
            </Label>
            <Input
              value={accionProyecto}
              onChange={(e) => {
                setAccionProyecto(e.target.value);
                setTouched((p) => ({ ...p, accionProyecto: true }));
              }}
              placeholder="Ej: ACCCENTR, PROY-01"
              className={`h-9 text-xs font-mono ${touched.accionProyecto && errors.accionProyecto ? 'border-destructive focus-visible:ring-destructive' : ''}`}
            />
            {touched.accionProyecto && errors.accionProyecto ? (
              <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                <AlertCircle className="size-3 shrink-0" />
                {errors.accionProyecto}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground">Código de acción centralizada o proyecto según normativa</p>
            )}
          </div>

          {/* Campo: Descripción */}
          <div className="space-y-1">
            <Label className="text-xs font-semibold text-foreground">Descripción u Observaciones</Label>
            <Textarea
              value={descripcion}
              onChange={(e) => {
                setDescripcion(e.target.value);
                setTouched((p) => ({ ...p, descripcion: true }));
              }}
              rows={2}
              placeholder="Escriba observaciones o notas de la regla de conversión..."
              className={`text-xs resize-none ${touched.descripcion && errors.descripcion ? 'border-destructive focus-visible:ring-destructive' : ''}`}
            />
            {touched.descripcion && errors.descripcion && (
              <p className="text-[11px] text-destructive font-medium flex items-center gap-1 mt-0.5">
                <AlertCircle className="size-3 shrink-0" />
                {errors.descripcion}
              </p>
            )}
          </div>

          <DialogFooter className="border-t border-border/60 pt-3 mt-4 flex items-center justify-end gap-2">
            <Button variant="outline" type="button" size="sm" onClick={() => onOpenChange(false)} className="h-9 text-xs px-4">
              Cancelar
            </Button>
            <Button 
              type="submit" 
              size="sm" 
              disabled={!isFormValid || mutation.isPending} 
              className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold h-9 text-xs px-5"
            >
              {mutation.isPending ? <Loader2 className="size-3.5 animate-spin mr-1.5" /> : null}
              Guardar
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};
