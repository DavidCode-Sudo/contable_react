import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ArrowDownLeft, ArrowUpRight, Building2, FileText, Layers, Tag } from 'lucide-react'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import {
  ajustarStock,
  inventarioKeys,
  type AjustarStockPayload,
  type Producto,
} from '@/services/inventario'

interface AjusteStockDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  producto: Producto | null
}

const OPCIONES_ENTRADA = [
  { value: 'donacion', label: 'Donación / Aporte Institucional' },
  { value: 'ingreso_interno', label: 'Ingreso Interno / Traspaso de Almacén' },
  { value: 'compra_directa', label: 'Compra Directa / Fondo en Anticipo' },
  { value: 'ajuste_auditoria', label: 'Ajuste por Inventario Físico / Sobrante' },
]

const OPCIONES_SALIDA = [
  { value: 'despacho_interno', label: 'Despacho a Departamento / Consumo Interno' },
  { value: 'merma_averia', label: 'Merma / Pérdida / Deterioro' },
  { value: 'ajuste_auditoria', label: 'Ajuste por Conteo Físico / Faltante' },
]

export function AjusteStockDialog({ open, onOpenChange, producto }: AjusteStockDialogProps) {
  const queryClient = useQueryClient()

  const [tipo, setTipo] = useState<'entrada' | 'salida'>('entrada')
  const [motivo, setMotivo] = useState('donacion')
  const [cantidad, setCantidad] = useState<number | ''>('')
  const [costoUnitario, setCostoUnitario] = useState<number | ''>('')
  const [documentoRef, setDocumentoRef] = useState('')
  const [departamentoDestino, setDepartamentoDestino] = useState('')
  const [observaciones, setObservaciones] = useState('')
  const [errorLocal, setErrorLocal] = useState<string | null>(null)

  useEffect(() => {
    if (producto) {
      setTipo('entrada')
      setMotivo('donacion')
      setCantidad('')
      setCostoUnitario(producto.costo || 0)
      setDocumentoRef('')
      setDepartamentoDestino('')
      setObservaciones('')
    }
    setErrorLocal(null)
  }, [producto, open])

  const handleTipoChange = (nuevoTipo: 'entrada' | 'salida') => {
    setTipo(nuevoTipo)
    setMotivo(nuevoTipo === 'entrada' ? 'donacion' : 'despacho_interno')
  }

  const mutation = useMutation({
    mutationFn: (payload: AjustarStockPayload) => ajustarStock(payload),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: inventarioKeys.all })
      toast.success(data.message || 'Ajuste de stock registrado con éxito.')
      onOpenChange(false)
    },
    onError: (err: Error) => {
      setErrorLocal(err.message)
      toast.error(err.message)
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setErrorLocal(null)

    if (!producto) return

    const numCantidad = Number(cantidad) || 0
    const numCostoUnitario = costoUnitario !== '' ? Number(costoUnitario) : undefined

    if (numCantidad <= 0) {
      setErrorLocal('La cantidad debe ser un número mayor a cero.')
      return
    }

    if (tipo === 'salida' && producto.existencias < numCantidad) {
      setErrorLocal(`Stock insuficiente. El disponible actual es ${producto.existencias} ${producto.unidad_medida}.`)
      return
    }

    if (tipo === 'entrada' && !documentoRef.trim()) {
      setErrorLocal('Debe ingresar el Documento / Acta de Referencia respaldante (Factura, Nota de Entrega o Acta) para la entrada de almacén.')
      return
    }

    const opciones = tipo === 'entrada' ? OPCIONES_ENTRADA : OPCIONES_SALIDA
    const opcionSeleccionada = opciones.find((o) => o.value === motivo)
    const motivoLabel = opcionSeleccionada ? opcionSeleccionada.label : motivo

    let finalObs = observaciones.trim()
    if (tipo === 'salida' && departamentoDestino.trim()) {
      finalObs = `[Destino: ${departamentoDestino.trim()}] ${finalObs}`.trim()
    }

    mutation.mutate({
      producto_id: producto.id,
      tipo,
      cantidad: numCantidad,
      costo_unitario: tipo === 'entrada' ? numCostoUnitario : undefined,
      motivo,
      motivo_label: motivoLabel,
      documento_referencia: documentoRef.trim(),
      observaciones: finalObs,
    })
  }

  if (!producto) return null

  const opcionesMotivo = tipo === 'entrada' ? OPCIONES_ENTRADA : OPCIONES_SALIDA

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md max-h-[85vh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-lg font-bold">
              {tipo === 'entrada' ? (
                <ArrowDownLeft className="size-5 text-emerald-600" />
              ) : (
                <ArrowUpRight className="size-5 text-rose-600" />
              )}
              Ajuste de Stock: {producto.nombre}
            </DialogTitle>
            <DialogDescription className="text-xs">
              Registrar movimiento de almacén con motivo estructurado para auditoría contable.
            </DialogDescription>
          </DialogHeader>

          {errorLocal && (
            <div className="my-3 p-3 rounded-lg bg-rose-50 border border-rose-200 text-xs font-semibold text-rose-700">
              {errorLocal}
            </div>
          )}

          <div className="space-y-4 py-3">
            {/* Tarjeta Informativa de Stock */}
            <div className="p-3 rounded-xl bg-muted/40 border border-border/60 flex items-center justify-between text-xs">
              <div>
                <span className="text-muted-foreground block text-[10px] uppercase font-bold">Stock Actual</span>
                <span className="font-bold text-sm text-foreground">
                  {producto.existencias} {producto.unidad_medida}
                </span>
              </div>
              <div>
                <span className="text-muted-foreground block text-[10px] uppercase font-bold">Costo Promedio (CPP)</span>
                <span className="font-mono font-bold text-sm text-foreground">
                  Bs. {producto.costo.toFixed(2)}
                </span>
              </div>
            </div>

            {/* Selector de Tipo de Movimiento */}
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Tipo de Movimiento *</label>
              <div className="grid grid-cols-2 gap-2">
                <Button
                  type="button"
                  variant={tipo === 'entrada' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => handleTipoChange('entrada')}
                  className={tipo === 'entrada' ? 'bg-emerald-600 hover:bg-emerald-700 font-bold' : ''}
                >
                  <ArrowDownLeft className="size-4 mr-1" /> Entrada (+ Stock)
                </Button>
                <Button
                  type="button"
                  variant={tipo === 'salida' ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => handleTipoChange('salida')}
                  className={tipo === 'salida' ? 'bg-rose-600 hover:bg-rose-700 font-bold' : ''}
                >
                  <ArrowUpRight className="size-4 mr-1" /> Salida (- Stock)
                </Button>
              </div>
            </div>

            {/* Selector Cerrado de Motivo / Tipo de Origen */}
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">
                {tipo === 'entrada' ? 'Motivo / Origen del Ingreso *' : 'Motivo / Destino de la Salida *'}
              </label>
              <select
                value={motivo}
                disabled={mutation.isPending}
                onChange={(e) => setMotivo(e.target.value)}
                className="w-full rounded-md border border-input bg-background p-2 text-xs focus:ring-2 focus:ring-primary focus:outline-none font-medium"
              >
                {opcionesMotivo.map((op) => (
                  <option key={op.value} value={op.value}>
                    {op.label}
                  </option>
                ))}
              </select>
              <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1">
                <Tag className="size-3.5 text-primary/70 shrink-0" />
                <span>Seleccione la justificación contable para el movimiento</span>
              </p>
            </div>

            {/* Cantidad y Costo Unitario */}
            <div className="grid grid-cols-2 gap-3">
              <div className="space-y-1">
                <label className="text-xs font-semibold text-foreground">Cantidad *</label>
                <Input
                  required
                  type="number"
                  step="0.01"
                  min="0.01"
                  placeholder="0.00"
                  value={cantidad}
                  disabled={mutation.isPending}
                  onChange={(e) => setCantidad(e.target.value ? Number(e.target.value) : '')}
                  className="text-xs font-mono font-bold"
                />
                <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1">
                  <Layers className="size-3.5 text-primary/70 shrink-0" />
                  <span>Unidades a mutar</span>
                </p>
              </div>

              {tipo === 'entrada' && (
                <div className="space-y-1">
                  <label className="text-xs font-semibold text-foreground">
                    Costo Unitario / Valor Tasado de Mercado (Bs.) *
                  </label>
                  <Input
                    type="number"
                    step="0.01"
                    min="0.01"
                    placeholder="Bs. 0.00"
                    value={costoUnitario}
                    disabled={mutation.isPending}
                    onChange={(e) => setCostoUnitario(e.target.value ? Number(e.target.value) : '')}
                    className="text-xs font-mono"
                  />
                  <p className="text-[10px] text-muted-foreground flex items-center gap-1 font-normal mt-1">
                    {motivo === 'donacion'
                      ? '💡 Si se omite, se hereda el costo histórico para proteger el CPP.'
                      : 'Impacta el Costo Promedio Ponderado.'}
                  </p>
                </div>
              )}
            </div>

            {/* Departamento / Centro de Costo Receptor (Solo en salidas) */}
            {tipo === 'salida' && (
              <div className="space-y-1">
                <label className="text-xs font-semibold text-foreground">
                  Departamento / Centro de Costo Receptor
                </label>
                <Input
                  placeholder="Ej: Dirección de Administración, Gerencia de Salud, Informática..."
                  value={departamentoDestino}
                  disabled={mutation.isPending}
                  onChange={(e) => setDepartamentoDestino(e.target.value)}
                  className="text-xs font-medium"
                />
                <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1">
                  <Building2 className="size-3.5 text-primary/70 shrink-0" />
                  <span>Gerencia o Unidad receptora del consumo interno</span>
                </p>
              </div>
            )}

            {/* Documento / Acta de Referencia */}
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground flex items-center justify-between">
                <span>Documento / Acta de Referencia {tipo === 'entrada' ? '*' : ''}</span>
                {tipo === 'entrada' && !documentoRef.trim() && (
                  <span className="text-[10px] text-rose-500 font-bold">Obligatorio en entradas</span>
                )}
              </label>
              <Input
                placeholder="Ej: Factura N° 00012456, Acta de Donación #124, Memorando #45..."
                value={documentoRef}
                disabled={mutation.isPending}
                onChange={(e) => setDocumentoRef(e.target.value)}
                className={`text-xs font-mono ${
                  tipo === 'entrada' && !documentoRef.trim()
                    ? 'border-rose-400 focus:ring-rose-500 bg-rose-50/10'
                    : ''
                }`}
              />
              <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1">
                <FileText className="size-3.5 text-primary/70 shrink-0" />
                <span>Nro. de Factura, Acta, Oficio o Documento físico respaldante</span>
              </p>
            </div>

            {/* Observaciones Adicionales */}
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Observaciones / Detalle Adicional</label>
              <textarea
                rows={2}
                placeholder="Detalles adicionales sobre el movimiento de inventario (opcional)..."
                value={observaciones}
                disabled={mutation.isPending}
                onChange={(e) => setObservaciones(e.target.value)}
                className="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:ring-2 focus:ring-primary focus:outline-none placeholder:text-muted-foreground/60"
              />
            </div>
          </div>

          {(() => {
            const esInvalidoPorDocRef = tipo === 'entrada' && !documentoRef.trim()
            return (
              <DialogFooter className="gap-2 pt-2 border-t border-border">
                <Button variant="outline" size="sm" type="button" onClick={() => onOpenChange(false)}>
                  Cancelar
                </Button>
                <Button
                  size="sm"
                  type="submit"
                  disabled={mutation.isPending || esInvalidoPorDocRef}
                  className={
                    tipo === 'entrada'
                      ? 'bg-emerald-600 hover:bg-emerald-700 font-bold px-6'
                      : 'bg-rose-600 hover:bg-rose-700 font-bold px-6'
                  }
                >
                  {mutation.isPending ? 'Procesando...' : 'Confirmar Ajuste'}
                </Button>
              </DialogFooter>
            )
          })()}
        </form>
      </DialogContent>
    </Dialog>
  )
}
