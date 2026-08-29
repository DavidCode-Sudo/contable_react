import React, { useState, useEffect, useRef } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Plus, Trash2, Package, Search, ChevronDown, Check, Edit3 } from 'lucide-react'
import { toast } from 'sonner'
import {
  crearOrdenEntrega,
  actualizarOrdenEntrega,
  fetchDepartamentosList,
  type TipoDestinoOrdenEntrega,
  type DepartamentoOption,
  type ProductoSearchOption,
  type OrdenEntregaDetail,
  type OrdenEntregaItem,
} from '@/services/ordenesEntrega'
import { fetchProductosList } from '@/services/inventario'

interface OrdenEntregaDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onSuccess: () => void
  editData?: OrdenEntregaDetail | null
  initialItems?: OrdenEntregaItem[]
}

interface FormItem {
  producto_id: number
  producto_codigo: string
  producto_nombre: string
  producto_unidad: string
  stock_disponible: number
  costo_actual: number
  cantidad_solicitada: number
  observaciones: string
}

export function OrdenEntregaDialog({
  open,
  onOpenChange,
  onSuccess,
  editData,
  initialItems,
}: OrdenEntregaDialogProps) {
  const [loading, setLoading] = useState(false)
  const [departamentos, setDepartamentos] = useState<DepartamentoOption[]>([])
  const [productosCat, setProductosCat] = useState<ProductoSearchOption[]>([])

  const [tipoDestino, setTipoDestino] = useState<TipoDestinoOrdenEntrega>('departamento')
  const [departamentoId, setDepartamentoId] = useState<string>('')
  const [centroCostoId, setCentroCostoId] = useState<string>('')
  const [justificacion, setJustificacion] = useState('')
  const [observaciones, setObservaciones] = useState('')
  const [estadoCreacion, setEstadoCreacion] = useState<'borrador' | 'aprobada'>('borrador')
  const [items, setItems] = useState<FormItem[]>([])

  // Estado Select2 (Combobox con buscador integrado)
  const [selectedProdId, setSelectedProdId] = useState<number | null>(null)
  const [cantInput, setCantInput] = useState<string>('1')
  const [select2Open, setSelect2Open] = useState(false)
  const [select2Query, setSelect2Query] = useState('')
  const select2Ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (open) {
      loadCatalogos()
      if (editData) {
        setTipoDestino(editData.tipo_destino || 'departamento')
        setDepartamentoId(editData.departamento_id ? String(editData.departamento_id) : '')
        setCentroCostoId(editData.centro_costo_id ? String(editData.centro_costo_id) : '')
        setJustificacion(editData.justificacion || '')
        setObservaciones(editData.observaciones || '')
        setEstadoCreacion(
          editData.estado === 'aprobada' || (editData.numero_orden && editData.numero_orden.startsWith('ODE-'))
            ? 'aprobada'
            : 'borrador'
        )
        if (initialItems && initialItems.length > 0) {
          setItems(
            initialItems.map((it) => ({
              producto_id: it.producto_id,
              producto_codigo: it.producto_codigo,
              producto_nombre: it.producto_nombre,
              producto_unidad: it.producto_unidad,
              stock_disponible: it.producto_stock_disponible,
              costo_actual: it.producto_costo_actual,
              cantidad_solicitada: it.cantidad_solicitada,
              observaciones: it.observaciones || '',
            }))
          )
        }
      } else {
        setTipoDestino('departamento')
        setDepartamentoId('')
        setCentroCostoId('')
        setJustificacion('')
        setObservaciones('')
        setEstadoCreacion('borrador')
        setItems([])
      }
    }
  }, [open, editData, initialItems])

  // Cerrar dropdown Select2 si se hace clic fuera
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (select2Ref.current && !select2Ref.current.contains(event.target as Node)) {
        setSelect2Open(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const loadCatalogos = async () => {
    try {
      const depts = await fetchDepartamentosList()
      setDepartamentos(depts)

      const prodsRes: any = await fetchProductosList({ limit: 500 })
      const rawList = prodsRes.items || prodsRes.productos || (Array.isArray(prodsRes) ? prodsRes : [])
      
      const mappedProds: ProductoSearchOption[] = rawList.map((p: any) => ({
        id: p.id,
        codigo: p.codigo || `PRD-${p.id}`,
        nombre: p.nombre,
        unidad_medida: p.unidad_medida || 'UNID',
        existencias: Number(p.existencias ?? 0),
        stock_reservado: Number(p.stock_reservado ?? 0),
        costo: Number(p.costo ?? 0),
      }))

      setProductosCat(mappedProds)
    } catch (err) {
      console.error('Error al cargar catálogos:', err)
      toast.error('Error al cargar el inventario de almacén.')
    }
  }

  // Filtrado dinámico Select2 por código o nombre
  const filteredProducts = productosCat.filter((p) => {
    const q = select2Query.toLowerCase().trim()
    if (!q) return true
    return (
      p.codigo.toLowerCase().includes(q) ||
      p.nombre.toLowerCase().includes(q)
    )
  })

  const selectedProduct = productosCat.find((p) => p.id === selectedProdId)

  const handleAddItem = () => {
    if (!selectedProdId || !selectedProduct) {
      toast.error('Seleccione un insumo del catálogo.')
      return
    }

    const cant = parseFloat(cantInput)
    if (isNaN(cant) || cant <= 0) {
      toast.error('La cantidad a solicitar debe ser mayor a cero.')
      return
    }

    const stockDisponible = Math.max(0, selectedProduct.existencias - (selectedProduct.stock_reservado || 0))

    if (cant > stockDisponible) {
      toast.error(
        `Stock disponible insuficiente para '${selectedProduct.nombre}'. Disponible: ${stockDisponible} ${selectedProduct.unidad_medida}.`
      )
      return
    }

    const existingIdx = items.findIndex((i) => i.producto_id === selectedProduct.id)
    if (existingIdx >= 0) {
      const updated = [...items]
      const totalQty = updated[existingIdx].cantidad_solicitada + cant
      if (totalQty > stockDisponible) {
        toast.error(`La cantidad acumulada (${totalQty}) supera el stock disponible (${stockDisponible}).`)
        return
      }
      updated[existingIdx].cantidad_solicitada = totalQty
      setItems(updated)
    } else {
      setItems([
        ...items,
        {
          producto_id: selectedProduct.id,
          producto_codigo: selectedProduct.codigo,
          producto_nombre: selectedProduct.nombre,
          producto_unidad: selectedProduct.unidad_medida,
          stock_disponible: stockDisponible,
          costo_actual: selectedProduct.costo,
          cantidad_solicitada: cant,
          observaciones: '',
        },
      ])
    }

    setSelectedProdId(null)
    setSelect2Query('')
    setCantInput('1')
  }

  const handleRemoveItem = (index: number) => {
    setItems(items.filter((_, idx) => idx !== index))
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    if (!justificacion.trim() || justificacion.trim().length < 10) {
      toast.error('La justificación o motivo operativo debe contener al menos 10 caracteres.')
      return
    }

    if (items.length === 0) {
      toast.error('Debe incluir al menos un producto a despachar en la orden.')
      return
    }

    setLoading(true)
    try {
      const payload = {
        tipo_destino: tipoDestino,
        departamento_id: departamentoId ? Number(departamentoId) : null,
        centro_costo_id: centroCostoId ? Number(centroCostoId) : null,
        justificacion,
        observaciones,
        estado: estadoCreacion,
        items: items.map((i) => ({
          producto_id: i.producto_id,
          cantidad_solicitada: i.cantidad_solicitada,
          observaciones: i.observaciones,
        })),
      }

      if (editData) {
        const res = await actualizarOrdenEntrega(editData.id, payload)
        toast.success(`Orden de Entrega ${res.numero_orden} actualizada con éxito.`)
      } else {
        const res = await crearOrdenEntrega(payload)
        toast.success(`Orden de Entrega ${res.numero_orden} registrada con éxito.`)
      }

      onSuccess()
      onOpenChange(false)

      // Reset Form
      setJustificacion('')
      setObservaciones('')
      setDepartamentoId('')
      setCentroCostoId('')
      setItems([])
      setSelectedProdId(null)
    } catch (err: any) {
      toast.error(err.message || 'Error al guardar la orden de entrega.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
            {editData ? (
              <>
                <Edit3 className="size-5 text-amber-600" />
                Editar Orden de Entrega {editData.numero_orden}
              </>
            ) : (
              <>
                <Package className="size-5 text-foreground" />
                Nueva Orden de Entrega / Despacho de Almacén
              </>
            )}
          </DialogTitle>
          <DialogDescription className="text-xs">
            {editData
              ? 'Modifique los insumos, justificación o departamento receptor de la orden en borrador.'
              : 'Formule un acta formal de despacho para materiales y suministros hacia los departamentos de la institución.'}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 py-2">
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Tipo de Destino *</label>
              <Select
                value={tipoDestino}
                onValueChange={(val) => setTipoDestino(val as TipoDestinoOrdenEntrega)}
              >
                <SelectTrigger className="w-full h-9 text-xs bg-background">
                  <SelectValue placeholder="Seleccione destino" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="departamento">Departamento / Unidad</SelectItem>
                  <SelectItem value="empleado">Empleado / Personal Directo</SelectItem>
                  <SelectItem value="evento">Evento Institucional</SelectItem>
                  <SelectItem value="merma_baja">Baja o Merma Operativa</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1 sm:col-span-2">
              <label className="text-xs font-semibold text-foreground">Departamento Receptor *</label>
              <Select
                value={departamentoId}
                onValueChange={(val) => setDepartamentoId(val)}
              >
                <SelectTrigger className="w-full h-9 text-xs bg-background">
                  <SelectValue placeholder="-- Seleccione el departamento destino --" />
                </SelectTrigger>
                <SelectContent>
                  {departamentos.map((d) => (
                    <SelectItem key={d.id} value={String(d.id)}>
                      {d.nombre}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          <div className="space-y-1">
            <label className="text-xs font-semibold text-foreground">Justificación / Motivo Operativo *</label>
            <Textarea
              rows={2}
              placeholder="Describa detalladamente la necesidad operativa del despacho de materiales..."
              value={justificacion}
              onChange={(e) => setJustificacion(e.target.value)}
              className="text-xs"
            />
          </div>

          {/* COMPONENTE SELECT2 INTERACTIVO PARA AGREGAR PRODUCTOS */}
          <div className="border border-border/60 p-3 rounded-lg space-y-3 bg-muted/20">
            <span className="text-xs font-bold text-foreground uppercase tracking-wider block">
              Agregar Insumo del Catálogo (Buscador Select2)
            </span>

            <div className="grid grid-cols-1 sm:grid-cols-12 gap-2">
              {/* CAMPO SELECT2 DE PRODUCTO */}
              <div className="sm:col-span-7 relative" ref={select2Ref}>
                <div
                  onClick={() => setSelect2Open(!select2Open)}
                  className="w-full h-8 px-2.5 rounded-md border border-input bg-background text-xs font-medium flex items-center justify-between cursor-pointer hover:border-ring transition-colors"
                >
                  {selectedProduct ? (
                    <span className="truncate font-semibold text-foreground">
                      <span className="text-emerald-600 font-bold mr-1.5">{selectedProduct.codigo}</span>
                      {selectedProduct.nombre}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">-- Buscar por código o nombre de producto --</span>
                  )}
                  <ChevronDown className="size-3.5 text-muted-foreground shrink-0 ml-1" />
                </div>

                {/* DROPDOWN FLOTANTE BÚSQUEDA SELECT2 */}
                {select2Open && (
                  <div className="absolute z-50 left-0 right-0 top-9 bg-popover border border-border shadow-md rounded-md overflow-hidden">
                    <div className="p-2 border-b border-border bg-muted/40 flex items-center gap-1.5">
                      <Search className="size-3.5 text-muted-foreground shrink-0" />
                      <input
                        type="text"
                        autoFocus
                        placeholder="Escriba el nombre o código del insumo..."
                        value={select2Query}
                        onChange={(e) => setSelect2Query(e.target.value)}
                        className="w-full bg-transparent text-xs outline-none text-foreground placeholder:text-muted-foreground"
                      />
                    </div>

                    <div className="max-h-48 overflow-y-auto divide-y divide-border/40 text-xs">
                      {filteredProducts.length === 0 ? (
                        <div className="p-3 text-center text-muted-foreground">
                          No se encontraron insumos coincidentes en el almacén.
                        </div>
                      ) : (
                        filteredProducts.map((p) => {
                          const disp = Math.max(0, p.existencias - (p.stock_reservado || 0))
                          const isSelected = selectedProdId === p.id

                          return (
                            <div
                              key={p.id}
                              onClick={() => {
                                setSelectedProdId(p.id)
                                setSelect2Open(false)
                              }}
                              className={`px-3 py-2 cursor-pointer flex items-center justify-between transition-colors ${
                                isSelected ? 'bg-emerald-50 dark:bg-emerald-950/30' : 'hover:bg-muted/50'
                              }`}
                            >
                              <div className="flex flex-col min-w-0 pr-2">
                                <span className="font-semibold text-foreground truncate">
                                  <span className="text-emerald-600 font-bold mr-1">{p.codigo}</span> - {p.nombre}
                                </span>
                                <span className="text-[11px] text-muted-foreground">
                                  Disp: <strong className="text-emerald-600">{disp}</strong> {p.unidad_medida} | Físico: {p.existencias}
                                </span>
                              </div>

                              {isSelected && <Check className="size-4 text-emerald-600 shrink-0" />}
                            </div>
                          )
                        })
                      )}
                    </div>
                  </div>
                )}
              </div>

              <div className="sm:col-span-3">
                <Input
                  type="number"
                  min="0.001"
                  step="0.001"
                  placeholder="Cantidad"
                  value={cantInput}
                  onChange={(e) => setCantInput(e.target.value)}
                  className="h-8 text-xs font-semibold"
                />
              </div>

              <div className="sm:col-span-2">
                <Button
                  type="button"
                  size="sm"
                  onClick={handleAddItem}
                  className="h-8 w-full gap-1 text-xs font-bold bg-primary text-primary-foreground hover:bg-primary/90"
                >
                  <Plus className="size-3.5" /> Añadir
                </Button>
              </div>
            </div>
          </div>

          {/* TABLA DE PRODUCTOS AGREGADOS */}
          <div className="border border-border/60 rounded-md overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-muted/60 text-muted-foreground font-semibold uppercase text-[10px] border-b">
                <tr>
                  <th className="px-3 py-2 text-left">Insumo</th>
                  <th className="px-3 py-2 text-center">Unidad</th>
                  <th className="px-3 py-2 text-center">Disponible</th>
                  <th className="px-3 py-2 text-center">Solicitado</th>
                  <th className="px-3 py-2 text-right">Acción</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/40">
                {items.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-3 py-6 text-center text-muted-foreground text-xs">
                      No ha agregado insumos a la orden de entrega.
                    </td>
                  </tr>
                ) : (
                  items.map((it, idx) => (
                    <tr key={idx} className="hover:bg-muted/20">
                      <td className="px-3 py-2 font-medium">
                        <span className="font-bold text-foreground">{it.producto_codigo}</span> - {it.producto_nombre}
                      </td>
                      <td className="px-3 py-2 text-center font-mono">{it.producto_unidad}</td>
                      <td className="px-3 py-2 text-center font-mono text-muted-foreground">{it.stock_disponible}</td>
                      <td className="px-3 py-2 text-center font-mono font-bold text-foreground">{it.cantidad_solicitada}</td>
                      <td className="px-3 py-2 text-right">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          onClick={() => handleRemoveItem(idx)}
                          className="h-7 w-7 text-rose-600 hover:bg-rose-50"
                        >
                          <Trash2 className="size-3.5" />
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Estado de la Orden</label>
              <Select
                value={estadoCreacion}
                onValueChange={(val) => setEstadoCreacion(val as 'borrador' | 'aprobada')}
                disabled={Boolean(editData && (['aprobada', 'reserva_vencida'].includes(editData.estado) || editData.numero_orden?.startsWith('ODE-')))}
              >
                <SelectTrigger className="w-full h-8 text-xs bg-background">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {!(editData && (['aprobada', 'reserva_vencida'].includes(editData.estado) || editData.numero_orden?.startsWith('ODE-'))) && (
                    <SelectItem value="borrador">Borrador (Sin Reserva)</SelectItem>
                  )}
                  <SelectItem value="aprobada">Aprobada (Reservar Stock Físico)</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Observaciones Adicionales</label>
              <Input
                placeholder="Observaciones de entrega o empaque..."
                value={observaciones}
                onChange={(e) => setObservaciones(e.target.value)}
                className="h-8 text-xs"
              />
            </div>
          </div>

          <DialogFooter className="pt-3 border-t">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={loading}
              className="h-8 text-xs"
            >
              Cancelar
            </Button>
            <Button
              type="submit"
              disabled={loading}
              className="h-8 text-xs font-bold bg-primary text-primary-foreground hover:bg-primary/90"
            >
              {loading ? 'Guardando...' : editData ? 'Guardar Cambios' : 'Crear Orden de Entrega'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
