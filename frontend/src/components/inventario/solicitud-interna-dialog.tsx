import React, { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import {
  Plus,
  Trash2,
  Send,
  Save,
  Package,
  AlertCircle,
  CheckCircle2,
  Building2,
  FileText,
} from 'lucide-react';
import {
  solicitudesInternasService,
  type PrioridadSolicitud,
  type CreateSolicitudPayload,
} from '@/services/solicitudesInternas';
import { inventarioService } from '@/services/inventario';

interface SolicitudInternaDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

interface DraftItem {
  producto_id: number;
  codigo: string;
  nombre: string;
  unidad_medida: string;
  permite_decimales: boolean;
  cantidad_solicitada: number;
  observaciones: string;
  disponible_para_solicitar: boolean;
  stock_disponible?: number;
}

export const SolicitudInternaDialog: React.FC<SolicitudInternaDialogProps> = ({ open, onOpenChange, onSuccess }) => {
  const queryClient = useQueryClient();

  const [departamentoId, setDepartamentoId] = useState<string>('');
  const [prioridad, setPrioridad] = useState<PrioridadSolicitud>('media');
  const [justificacion, setJustificacion] = useState('');
  const [items, setItems] = useState<DraftItem[]>([]);

  const [selectedProdId, setSelectedProdId] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const [isDropdownOpen, setIsDropdownOpen] = useState(false);
  const [inputCantidad, setInputCantidad] = useState<string>('1');
  const [inputObs, setInputObs] = useState<string>('');

  const dropdownRef = React.useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsDropdownOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const { data: departamentos = [] } = useQuery({
    queryKey: ['departamentos-options'],
    queryFn: () => inventarioService.getDepartamentos(),
    enabled: open,
    staleTime: 1000 * 60 * 5,
  });

  const { data: catalogo = [], isLoading: loadingInit } = useQuery({
    queryKey: ['solicitudes-catalogo'],
    queryFn: () => solicitudesInternasService.getCatalogo(),
    enabled: open,
  });

  const filteredCatalogo = catalogo.filter((p) => {
    const query = searchQuery.toLowerCase().trim();
    if (!query) return true;
    return (
      p.nombre.toLowerCase().includes(query) ||
      p.codigo.toLowerCase().includes(query)
    );
  });

  useEffect(() => {
    if (open && departamentos.length > 0 && !departamentoId) {
      setDepartamentoId(departamentos[0].id.toString());
    }
  }, [open, departamentos, departamentoId]);

  const createMutation = useMutation({
    mutationFn: (payload: CreateSolicitudPayload) => solicitudesInternasService.create(payload),
    onSuccess: (res, variables) => {
      toast.success(
        variables.accion === 'enviar'
          ? `Solicitud ${res.numero_solicitud} enviada exitosamente para aprobación.`
          : `Solicitud ${res.numero_solicitud} guardada en borrador.`
      );
      resetForm();
      onOpenChange(false);
      queryClient.invalidateQueries({ queryKey: ['solicitudes-internas'] });
      onSuccess();
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al procesar la solicitud.');
    },
  });

  const resetForm = () => {
    setJustificacion('');
    setPrioridad('media');
    setItems([]);
    setSelectedProdId('');
    setSearchQuery('');
    setIsDropdownOpen(false);
    setInputCantidad('1');
    setInputObs('');
  };

  const handleAddItem = () => {
    if (!selectedProdId) {
      toast.warning('Seleccione un insumo o producto del catálogo.');
      return;
    }

    const prod = catalogo.find((p) => p.id.toString() === selectedProdId);
    if (!prod) return;

    const cant = parseFloat(inputCantidad);
    if (isNaN(cant) || cant <= 0) {
      toast.warning('Ingrese una cantidad válida mayor a 0.');
      return;
    }

    if (!prod.permite_decimales && Math.abs(cant - Math.round(cant)) > 0.0001) {
      toast.warning(`El producto "${prod.nombre}" no admite fracciones ni decimales.`);
      return;
    }

    const existingIndex = items.findIndex((it) => it.producto_id === prod.id);
    if (existingIndex >= 0) {
      const updated = [...items];
      const newCant = updated[existingIndex].cantidad_solicitada + cant;
      if (!prod.permite_decimales && Math.abs(newCant - Math.round(newCant)) > 0.0001) {
        toast.warning(`El producto "${prod.nombre}" no admite cantidades fraccionadas.`);
        return;
      }
      updated[existingIndex].cantidad_solicitada = newCant;
      if (inputObs.trim()) {
        updated[existingIndex].observaciones = inputObs.trim();
      }
      setItems(updated);
    } else {
      setItems([
        ...items,
        {
          producto_id: prod.id,
          codigo: prod.codigo,
          nombre: prod.nombre,
          unidad_medida: prod.unidad_medida,
          permite_decimales: prod.permite_decimales,
          cantidad_solicitada: cant,
          observaciones: inputObs.trim(),
          disponible_para_solicitar: prod.disponible_para_solicitar,
          stock_disponible: prod.stock_disponible,
        },
      ]);
    }

    setSelectedProdId('');
    setSearchQuery('');
    setIsDropdownOpen(false);
    setInputCantidad('1');
    setInputObs('');
    toast.success(`Insumo "${prod.nombre}" agregado.`);
  };

  const handleRemoveItem = (index: number) => {
    setItems(items.filter((_, i) => i !== index));
  };

  const handleSubmit = (accion: 'guardar' | 'enviar') => {
    if (!departamentoId) {
      toast.error('Seleccione el departamento receptor.');
      return;
    }
    if (!justificacion.trim()) {
      toast.error('El motivo o justificación de la solicitud es obligatorio.');
      return;
    }
    if (items.length === 0) {
      toast.error('Debe agregar al menos un insumo a la lista.');
      return;
    }

    const payload: CreateSolicitudPayload = {
      departamento_id: parseInt(departamentoId, 10),
      justificacion: justificacion.trim(),
      prioridad,
      accion,
      items: items.map((it) => ({
        producto_id: it.producto_id,
        cantidad_solicitada: it.cantidad_solicitada,
        observaciones: it.observaciones,
      })),
    };

    createMutation.mutate(payload);
  };

  const selectedProdObj = catalogo.find((p) => p.id.toString() === selectedProdId);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="text-xl font-bold flex items-center gap-2 text-foreground">
            <FileText className="size-6 text-foreground" />
            Nueva Solicitud Interna / Requisición de Insumos
          </DialogTitle>
          <DialogDescription>
            Complete los datos de la solicitud y agregue los ítems requeridos del catálogo del almacén.
          </DialogDescription>
        </DialogHeader>

        {loadingInit ? (
          <div className="py-12 text-center text-muted-foreground">Cargando catálogo y departamentos...</div>
        ) : (
          <div className="space-y-6 py-2">
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label className="flex items-center gap-1.5 font-semibold text-foreground">
                  <Building2 className="size-4 text-muted-foreground" />
                  Departamento Destinatario / Solicitante
                </Label>
                <select
                  value={departamentoId}
                  onChange={(e) => setDepartamentoId(e.target.value)}
                  className="w-full h-10 px-3 py-2 rounded-md border border-input bg-background text-sm font-medium text-foreground focus:outline-none focus:ring-2 focus:ring-primary cursor-pointer"
                >
                  <option value="" disabled>Seleccione Departamento</option>
                  {departamentos.map((d) => (
                    <option key={d.id} value={String(d.id)}>
                      {d.nombre}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-2">
                <Label className="font-semibold text-foreground">Prioridad</Label>
                <Select value={prioridad} onValueChange={(val: any) => setPrioridad(val)}>
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="baja">Baja</SelectItem>
                    <SelectItem value="media">Media (Estándar)</SelectItem>
                    <SelectItem value="alta">Alta</SelectItem>
                    <SelectItem value="urgente">Urgente</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            <div className="space-y-2">
              <Label className="font-semibold text-foreground">
                Motivo / Justificación Institucional <span className="text-destructive">*</span>
              </Label>
              <Textarea
                placeholder="Describa brevemente la necesidad o justificación del pedido para la dependencia..."
                value={justificacion}
                onChange={(e) => setJustificacion(e.target.value)}
                rows={2}
                className="bg-background"
              />
            </div>

            <div className="border border-border/60 rounded-lg p-4 bg-muted/30 space-y-4">
              <h4 className="text-sm font-semibold text-foreground flex items-center gap-2">
                <Package className="size-4 text-muted-foreground" />
                Agregar Insumos del Catálogo
              </h4>

              <div className="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                <div className="relative md:col-span-5 space-y-1.5" ref={dropdownRef}>
                  <Label className="text-xs text-muted-foreground">Producto / Insumo</Label>
                  <div className="relative">
                    <Input
                      type="text"
                      placeholder="Buscar insumo por código o nombre..."
                      value={selectedProdObj ? `[${selectedProdObj.codigo}] ${selectedProdObj.nombre}` : searchQuery}
                      onChange={(e) => {
                        setSelectedProdId('');
                        setSearchQuery(e.target.value);
                        setIsDropdownOpen(true);
                      }}
                      onFocus={() => setIsDropdownOpen(true)}
                      className="w-full pr-8 bg-background font-medium"
                    />
                    {selectedProdObj || searchQuery ? (
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedProdId('');
                          setSearchQuery('');
                          setIsDropdownOpen(true);
                        }}
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground text-xs p-1"
                        title="Limpiar selección"
                      >
                        ✕
                      </button>
                    ) : null}
                  </div>

                  {isDropdownOpen && (
                    <div className="absolute z-50 left-0 right-0 top-full mt-1 bg-popover text-popover-foreground border border-border rounded-md shadow-lg max-h-60 overflow-y-auto divide-y divide-border/40">
                      {filteredCatalogo.length === 0 ? (
                        <div className="p-3 text-xs text-muted-foreground text-center">
                          No se encontraron insumos coincidentes en el catálogo.
                        </div>
                      ) : (
                        filteredCatalogo.map((p) => {
                          const isSelected = selectedProdId === p.id.toString();
                          return (
                            <button
                              key={p.id}
                              type="button"
                              onClick={() => {
                                setSelectedProdId(p.id.toString());
                                setSearchQuery(`[${p.codigo}] ${p.nombre}`);
                                setIsDropdownOpen(false);
                              }}
                              className={`w-full text-left p-2.5 hover:bg-accent hover:text-accent-foreground text-xs transition-colors flex items-center justify-between gap-2 ${
                                isSelected ? 'bg-primary/15 font-semibold text-primary' : ''
                              }`}
                            >
                              <div className="flex items-center gap-2 truncate">
                                <span className="font-mono text-[11px] text-muted-foreground px-1.5 py-0.5 bg-muted rounded border border-border shrink-0">
                                  {p.codigo}
                                </span>
                                <span className="truncate">{p.nombre}</span>
                              </div>
                              <Badge variant={p.disponible_para_solicitar ? 'outline' : 'secondary'} className="text-[10px] shrink-0">
                                Stock: {p.stock_disponible ?? p.existencias} {p.unidad_medida}
                              </Badge>
                            </button>
                          );
                        })
                      )}
                    </div>
                  )}
                </div>

                <div className="md:col-span-3 space-y-1.5">
                  <Label className="text-xs text-muted-foreground">
                    Cantidad Solicitada {selectedProdObj ? `(${selectedProdObj.unidad_medida})` : ''}
                  </Label>
                  <Input
                    type="number"
                    step={selectedProdObj?.permite_decimales ? '0.001' : '1'}
                    min="0.001"
                    className="bg-background"
                    value={inputCantidad}
                    onChange={(e) => setInputCantidad(e.target.value)}
                  />
                </div>

                <div className="md:col-span-3 space-y-1.5">
                  <Label className="text-xs text-muted-foreground">Observaciones (Opcional)</Label>
                  <Input
                    type="text"
                    placeholder="Ej. Tinta Negra"
                    className="bg-background"
                    value={inputObs}
                    onChange={(e) => setInputObs(e.target.value)}
                  />
                </div>

                <div className="md:col-span-1">
                  <Button
                    type="button"
                    onClick={handleAddItem}
                    className="w-full bg-primary text-primary-foreground hover:bg-primary/90"
                  >
                    <Plus className="size-4" />
                  </Button>
                </div>
              </div>
            </div>

            <div className="border border-border/60 rounded-lg overflow-hidden">
              <Table>
                <TableHeader className="bg-muted/50 dark:bg-muted/30">
                  <TableRow>
                    <TableHead className="w-12 text-xs font-semibold text-muted-foreground uppercase tracking-wider">#</TableHead>
                    <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Código & Insumo</TableHead>
                    <TableHead className="text-center w-28 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Disponibilidad</TableHead>
                    <TableHead className="text-right w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Cant. Solicitada</TableHead>
                    <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Observaciones</TableHead>
                    <TableHead className="w-12 text-center"></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {items.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={6} className="text-center py-8 text-muted-foreground text-sm">
                        No hay insumos agregados a esta solicitud.
                      </TableCell>
                    </TableRow>
                  ) : (
                    items.map((it, idx) => (
                      <TableRow key={it.producto_id}>
                        <TableCell className="font-mono text-xs text-muted-foreground">{idx + 1}</TableCell>
                        <TableCell>
                          <div className="font-medium text-foreground">{it.nombre}</div>
                          <div className="text-xs font-mono text-muted-foreground">{it.codigo}</div>
                        </TableCell>
                        <TableCell className="text-center">
                          {it.disponible_para_solicitar ? (
                            <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 text-[10px]">
                              <CheckCircle2 className="size-3 mr-1 text-emerald-600" />
                              Disponible
                            </Badge>
                          ) : (
                            <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 text-[10px]">
                              <AlertCircle className="size-3 mr-1 text-amber-600" />
                              Bajo Pedido
                            </Badge>
                          )}
                        </TableCell>
                        <TableCell className="text-right font-mono font-semibold text-foreground">
                          {it.cantidad_solicitada.toLocaleString('es-VE', { minimumFractionDigits: it.permite_decimales ? 2 : 0 })}{' '}
                          <span className="text-xs text-muted-foreground font-normal">{it.unidad_medida}</span>
                        </TableCell>
                        <TableCell className="text-xs text-muted-foreground">{it.observaciones || '-'}</TableCell>
                        <TableCell className="text-center">
                          <Button
                            variant="ghost"
                            size="icon"
                            className="size-7 text-rose-500 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/30"
                            onClick={() => handleRemoveItem(idx)}
                          >
                            <Trash2 className="size-4" />
                          </Button>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>
            </div>
          </div>
        )}

        <DialogFooter className="gap-2 sm:gap-0 pt-2 border-t border-border/60">
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={createMutation.isPending}>
            Cancelar
          </Button>
          <Button
            variant="secondary"
            onClick={() => handleSubmit('guardar')}
            disabled={createMutation.isPending || items.length === 0}
            className="gap-1.5"
          >
            <Save className="size-4" />
            Guardar Borrador
          </Button>
          <Button
            onClick={() => handleSubmit('enviar')}
            disabled={createMutation.isPending || items.length === 0}
            className="bg-primary text-primary-foreground hover:bg-primary/90 gap-1.5 font-bold"
          >
            <Send className="size-4" />
            Enviar a Aprobación
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
