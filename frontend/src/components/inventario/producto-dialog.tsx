import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Package, Save, ChevronDown, ChevronUp, Pencil, Lightbulb, ClipboardList, Tag, FileText, AlertCircle } from 'lucide-react'
import { toast } from 'sonner'

import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import {
  fetchCategorias,
  inventarioKeys,
  saveProducto,
  type GuardarProductoPayload,
  type InventarioListResponse,
  type Producto,
} from '@/services/inventario'

interface ProductoDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  productoEditar?: Producto | null
}

export function ProductoDialog({ open, onOpenChange, productoEditar }: ProductoDialogProps) {
  const queryClient = useQueryClient()

  const [codigo, setCodigo] = useState('')
  const [nombre, setNombre] = useState('')
  const [descripcion, setDescripcion] = useState('')
  const [categoriaId, setCategoriaId] = useState<number | null>(null)
  const [unidadMedida, setUnidadMedida] = useState('UNID')

  // Campos de Ingreso Inicial de Existencias
  const [cantidadInicial, setCantidadInicial] = useState<number | ''>(0)
  const [tipoIngreso, setTipoIngreso] = useState('Donación')
  const [documentoReferencia, setDocumentoReferencia] = useState('')
  const [observacionesIngreso, setObservacionesIngreso] = useState('')

  // Campos avanzadas opcionales ERP (Costo, Precio, Ubicación, Mínimo/Máximo)
  const [mostrarAvanzados, setMostrarAvanzados] = useState(false)
  const [costo, setCosto] = useState<number | ''>('')
  const [precio, setPrecio] = useState<number | ''>('')
  const [stockMinimo, setStockMinimo] = useState<number | ''>(5)
  const [stockMaximo, setStockMaximo] = useState<number | ''>(100)
  const [ubicacion, setUbicacion] = useState('')
  const [estado, setEstado] = useState<'activo' | 'inactivo'>('activo')

  const [errorLocal, setErrorLocal] = useState<string | null>(null)

  const categoriasQuery = useQuery({
    queryKey: inventarioKeys.categorias(),
    queryFn: fetchCategorias,
    enabled: open,
  })

  // Obtener el próximo correlativo sugerido desde la caché de inventario (con 6 ceros)
  useEffect(() => {
    if (open) {
      if (productoEditar) {
        setCodigo(productoEditar.codigo || '')
        setNombre(productoEditar.nombre || '')
        setDescripcion(productoEditar.descripcion || '')
        setCategoriaId(productoEditar.categoria?.id || null)
        setUnidadMedida(productoEditar.unidad_medida || 'UNID')
        setCosto(productoEditar.costo)
        setPrecio(productoEditar.precio)
        setStockMinimo(productoEditar.stock_minimo)
        setStockMaximo(productoEditar.stock_maximo)
        setUbicacion(productoEditar.ubicacion || '')
        setEstado(productoEditar.estado || 'activo')
        setCantidadInicial(0)
        setTipoIngreso('Donación')
        setDocumentoReferencia('')
        setObservacionesIngreso('')
      } else {
        const cachedData = queryClient.getQueryData<InventarioListResponse>(inventarioKeys.productosList())
        const proximoSugerido = cachedData?.proximo_codigo || 'PRD-000001'

        setCodigo(proximoSugerido)
        setNombre('')
        setDescripcion('')
        setCategoriaId(null)
        setUnidadMedida('UNID')
        setCantidadInicial(0)
        setTipoIngreso('Donación')
        setDocumentoReferencia('')
        setObservacionesIngreso('')
        setCosto(0)
        setPrecio(0)
        setStockMinimo(5)
        setStockMaximo(100)
        setUbicacion('')
        setEstado('activo')
      }
      setErrorLocal(null)
    }
  }, [productoEditar, open, queryClient])

  const mutation = useMutation({
    mutationFn: (payload: GuardarProductoPayload) => saveProducto(payload),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: inventarioKeys.all })
      toast.success(data.message || 'Producto guardado exitosamente.')
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

    const numCosto = Number(costo) || 0
    const numPrecio = Number(precio) || 0
    const numStockMin = Number(stockMinimo) || 0
    const numStockMax = Number(stockMaximo) || 0
    const numCantInicial = Number(cantidadInicial) || 0

    if (!nombre.trim()) {
      setErrorLocal('El nombre del producto es obligatorio.')
      return
    }
    if (numCantInicial < 0) {
      setErrorLocal('La cantidad inicial de ingreso no puede ser negativa.')
      return
    }
    if (!productoEditar && numCantInicial > 0) {
      if (numCosto <= 0) {
        setErrorLocal('El Costo Unitario / Valor Tasado de Mercado en Bolívares es estrictamente obligatorio para registrar existencias al patrimonio.')
        return
      }
      if (!documentoReferencia.trim()) {
        setErrorLocal('Debe ingresar el Documento / Acta de Referencia respaldante (Factura, Nota de Entrega o Acta) para el ingreso inicial.')
        return
      }
    }

    mutation.mutate({
      id: productoEditar?.id ?? null,
      codigo: codigo.trim() || undefined,
      nombre: nombre.trim(),
      descripcion: descripcion.trim(),
      costo: numCosto,
      precio: numPrecio,
      stock_minimo: numStockMin,
      stock_maximo: numStockMax,
      unidad_medida: unidadMedida,
      ubicacion: ubicacion.trim(),
      categoria_id: categoriaId,
      estado,
      cantidad_inicial: numCantInicial,
      tipo_ingreso: tipoIngreso,
      documento_referencia: documentoReferencia.trim(),
      observaciones_ingreso: observacionesIngreso.trim(),
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl max-h-[85vh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-lg font-bold">
              <Package className="size-5 text-primary" />
              {productoEditar ? `Editar Producto: ${productoEditar.codigo}` : 'Nuevo Producto'}
            </DialogTitle>
          </DialogHeader>

          {errorLocal && (
            <div className="my-3 p-3.5 rounded-lg bg-rose-50 border border-rose-200 text-xs font-semibold text-rose-800 leading-relaxed flex items-start gap-2.5 shadow-2xs">
              <AlertCircle className="size-4 text-rose-600 mt-0.5 shrink-0" />
              <div className="flex-1">{errorLocal}</div>
            </div>
          )}

          <div className="space-y-4 py-2">
            {/* Fila 1: Código de Producto (correlativo autogenerado 6 ceros, readOnly) + Nombre del Producto */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-foreground">Código de Producto</label>
                <Input
                  value={codigo}
                  disabled
                  readOnly
                  className="bg-muted/50 text-muted-foreground font-mono text-xs cursor-not-allowed select-none h-9"
                />
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-foreground">Nombre del Producto *</label>
                <Input
                  required
                  placeholder="Nombre descriptivo"
                  value={nombre}
                  disabled={mutation.isPending}
                  onChange={(e) => setNombre(e.target.value)}
                  className="text-xs font-medium h-9"
                />
              </div>
            </div>

            {/* Fila 2: Descripción */}
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-foreground">Descripción</label>
              <textarea
                rows={3}
                placeholder="Ingrese una descripción específica del producto (características, especificaciones, etc.)"
                value={descripcion}
                disabled={mutation.isPending}
                onChange={(e) => setDescripcion(e.target.value)}
                className="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:ring-2 focus:ring-primary focus:outline-none placeholder:text-muted-foreground/60 transition-colors"
              />
            </div>

            {/* Fila 3: Categoría + Unidad de Medida */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-foreground">Categoría</label>
                <select
                  value={categoriaId ?? ''}
                  disabled={mutation.isPending}
                  onChange={(e) => setCategoriaId(e.target.value ? Number(e.target.value) : null)}
                  className="w-full h-9 rounded-md border border-input bg-background px-3 text-xs focus:ring-2 focus:ring-primary focus:outline-none transition-colors"
                >
                  <option value="">Seleccionar Categoría</option>
                  {categoriasQuery.data?.map((cat) => (
                    <option key={cat.id} value={cat.id}>
                      {cat.nombre}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1.5">
                <label className="text-xs font-semibold text-foreground">Unidad de Medida</label>
                <select
                  value={unidadMedida}
                  disabled={mutation.isPending}
                  onChange={(e) => setUnidadMedida(e.target.value)}
                  className="w-full h-9 rounded-md border border-input bg-background px-3 text-xs focus:ring-2 focus:ring-primary focus:outline-none transition-colors"
                >
                  <option value="UNID">Unidad/Pieza</option>
                  <option value="CAJA">Caja</option>
                  <option value="KG">Kilogramo</option>
                  <option value="LT">Litro</option>
                  <option value="MTR">Metro</option>
                  <option value="PAQ">Paquete</option>
                  <option value="SERVIC">Otro / Servicio</option>
                </select>
                <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1.5">
                  <Pencil className="size-3.5 text-primary/70 shrink-0" />
                  <span>Seleccione la unidad de medida del producto</span>
                </p>
              </div>
            </div>

            {/* Fila 4: Bloque Transaccional de Ingreso Inicial (Alta Nueva) */}
            {!productoEditar && (
              <div className="space-y-4 p-4 rounded-xl bg-muted/20 border border-border/70 shadow-2xs">
                <div className="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-2 border-b border-border/40 pb-2.5 mb-1">
                  <Package className="size-4 text-primary shrink-0" />
                  <span>Ingreso Inicial de Existencias (Almacén)</span>
                </div>

                {/* 1. Tipo de Ingreso / Origen (Primera Posición - Sin Bypass Presupuestario) */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-foreground">Tipo de Ingreso / Origen *</label>
                  <select
                    value={tipoIngreso}
                    disabled={mutation.isPending}
                    onChange={(e) => setTipoIngreso(e.target.value)}
                    className="w-full h-9 rounded-md border border-input bg-background px-3 text-xs focus:ring-2 focus:ring-primary focus:outline-none font-medium transition-colors"
                  >
                    <option value="Apertura">Apertura de Sistema / Carga Inicial (Migración de Saldos)</option>
                    <option value="Donación">Donación / Aporte Institucional</option>
                    <option value="Sobrante">Ajuste Positivo / Sobrante de Inventario (Auditoría Física)</option>
                  </select>
                  <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1.5">
                    <ClipboardList className="size-3.5 text-primary/70 shrink-0" />
                    <span>Origen patrimonial no presupuestario del saldo inicial</span>
                  </p>
                </div>

                {/* 2. Cuadrícula de 2 columnas: Cantidad Inicial (Izq) y Costo / Tasación Obligatoria (Der) */}
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                  {/* Columna Izquierda: Cantidad Inicial */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-semibold text-foreground">Cantidad Inicial</label>
                    <Input
                      type="number"
                      min="0"
                      step="1"
                      placeholder="0"
                      value={cantidadInicial}
                      disabled={mutation.isPending}
                      onChange={(e) => setCantidadInicial(e.target.value ? Number(e.target.value) : '')}
                      className="text-xs font-mono font-bold h-9"
                    />
                    <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1.5">
                      <Lightbulb className="size-3.5 text-amber-500/80 shrink-0" />
                      <span>Cantidad física recibida</span>
                    </p>
                  </div>

                  {/* Columna Derecha: Costo Unitario / Valor Tasado Obligatorio */}
                  <div className="space-y-1.5">
                    <label className="text-xs font-semibold text-foreground flex items-center justify-between">
                      <span>Costo Unitario / Valor Tasado {Number(cantidadInicial) > 0 ? '*' : ''}</span>
                      {Number(cantidadInicial) > 0 && Number(costo) <= 0 && (
                        <span className="text-[10px] text-rose-500 font-bold">Obligatorio</span>
                      )}
                    </label>
                    <Input
                      type="number"
                      step="0.01"
                      min="0.01"
                      placeholder="Bs. 0.00"
                      value={costo}
                      disabled={mutation.isPending}
                      onChange={(e) => setCosto(e.target.value ? Number(e.target.value) : '')}
                      className={`text-xs font-mono font-bold h-9 ${
                        Number(cantidadInicial) > 0 && Number(costo) <= 0
                          ? 'border-rose-400 focus:ring-rose-500 bg-rose-50/10'
                          : ''
                      }`}
                    />
                    <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1.5">
                      <Tag className="size-3.5 text-primary/70 shrink-0" />
                      <span>Valor financiero obligatorio para asiento contable patrimonial</span>
                    </p>
                  </div>
                </div>

                {/* 3. Documento / Factura / Acta de Referencia Fiscal SENIAT */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-foreground flex items-center justify-between">
                    <span>Documento / Factura / Acta de Referencia {Number(cantidadInicial) > 0 ? '*' : ''}</span>
                    {Number(cantidadInicial) > 0 && !documentoReferencia.trim() && (
                      <span className="text-[10px] text-rose-500 font-bold">Obligatorio (Saldo &gt; 0)</span>
                    )}
                  </label>
                  <Input
                    placeholder={
                      tipoIngreso === 'Donación'
                        ? 'Ej: Acta de Donación N° 012-2026'
                        : 'Ej: Factura N° 00012456, Nota de Entrega #88...'
                    }
                    value={documentoReferencia}
                    disabled={mutation.isPending}
                    onChange={(e) => setDocumentoReferencia(e.target.value)}
                    className={`text-xs font-mono h-9 ${
                      Number(cantidadInicial) > 0 && !documentoReferencia.trim()
                        ? 'border-rose-400 focus:ring-rose-500 bg-rose-50/10'
                        : ''
                    }`}
                  />
                  <p className="text-[11px] text-muted-foreground flex items-center gap-1.5 font-normal mt-1.5">
                    <FileText className="size-3.5 text-primary/70 shrink-0" />
                    <span>Respaldante legal fiscal del saldo inicial (Factura, Nota de Entrega o Acta)</span>
                  </p>
                </div>

                {/* 4. Observaciones del Ingreso */}
                <div className="space-y-1.5">
                  <label className="text-xs font-semibold text-foreground">Observaciones del Ingreso</label>
                  <textarea
                    rows={2}
                    placeholder="Detalles adicionales sobre el ingreso del producto (opcional)..."
                    value={observacionesIngreso}
                    disabled={mutation.isPending}
                    onChange={(e) => setObservacionesIngreso(e.target.value)}
                    className="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:ring-2 focus:ring-primary focus:outline-none placeholder:text-muted-foreground/60 transition-colors"
                  />
                </div>
              </div>
            )}

            {/* Desplegable Opciones Avanzadas ERP (Precio, Ubicación, Stock Min/Max) */}
            <div className="border-t border-border/60 pt-3 mt-3">
              <button
                type="button"
                onClick={() => setMostrarAvanzados(!mostrarAvanzados)}
                className="flex items-center gap-1.5 text-xs font-semibold text-muted-foreground hover:text-foreground transition-colors"
              >
                {mostrarAvanzados ? <ChevronUp className="size-3.5" /> : <ChevronDown className="size-3.5" />}
                {mostrarAvanzados ? 'Ocultar Opciones Avanzadas ERP' : 'Configuración Avanzada (Valor Referencial, Ubicación, Límites)'}
              </button>

              {mostrarAvanzados && (
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-3 bg-muted/20 p-4 rounded-xl border border-border/70 shadow-2xs">
                  {productoEditar ? (
                    <>
                      {/* En Edición: Fila 1 - Costo Unitario y Valor Referencial */}
                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Costo Unitario (Bs.)
                        </label>
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          value={costo}
                          disabled={mutation.isPending}
                          onChange={(e) => setCosto(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Valor Estimado Referencial (Bs.)
                        </label>
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          value={precio}
                          disabled={mutation.isPending}
                          onChange={(e) => setPrecio(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      {/* Fila 2 - Límites de Stock */}
                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Stock Mínimo (Alerta)
                        </label>
                        <Input
                          type="number"
                          step="1"
                          min="0"
                          value={stockMinimo}
                          disabled={mutation.isPending}
                          onChange={(e) => setStockMinimo(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Stock Máximo
                        </label>
                        <Input
                          type="number"
                          step="1"
                          min="0"
                          value={stockMaximo}
                          disabled={mutation.isPending}
                          onChange={(e) => setStockMaximo(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      {/* Fila 3 - Ubicación */}
                      <div className="sm:col-span-2 space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Ubicación / Depósito
                        </label>
                        <Input
                          placeholder="Ej: Estante A-2, Almacén Central"
                          value={ubicacion}
                          disabled={mutation.isPending}
                          onChange={(e) => setUbicacion(e.target.value)}
                          className="text-xs h-9"
                        />
                      </div>
                    </>
                  ) : (
                    <>
                      {/* Alta Nueva: Fila 1 - Umbrales de Stock (Mínimo y Máximo) */}
                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Stock Mínimo (Alerta)
                        </label>
                        <Input
                          type="number"
                          step="1"
                          min="0"
                          placeholder="0"
                          value={stockMinimo}
                          disabled={mutation.isPending}
                          onChange={(e) => setStockMinimo(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Stock Máximo
                        </label>
                        <Input
                          type="number"
                          step="1"
                          min="0"
                          placeholder="0"
                          value={stockMaximo}
                          disabled={mutation.isPending}
                          onChange={(e) => setStockMaximo(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      {/* Alta Nueva: Fila 2 - Valor Referencial y Ubicación */}
                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Valor Estimado Referencial (Bs.)
                        </label>
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0.00"
                          value={precio}
                          disabled={mutation.isPending}
                          onChange={(e) => setPrecio(e.target.value ? Number(e.target.value) : '')}
                          className="text-xs font-mono h-9"
                        />
                      </div>

                      <div className="space-y-1.5">
                        <label className="text-xs font-semibold text-foreground flex items-center h-5">
                          Ubicación / Depósito
                        </label>
                        <Input
                          placeholder="Ej: Estante A-2, Almacén Central"
                          value={ubicacion}
                          disabled={mutation.isPending}
                          onChange={(e) => setUbicacion(e.target.value)}
                          className="text-xs h-9"
                        />
                      </div>
                    </>
                  )}
                </div>
              )}
            </div>
          </div>

          {(() => {
            const esInvalidoPorDocRef = !productoEditar && Number(cantidadInicial) > 0 && !documentoReferencia.trim()
            return (
              <DialogFooter className="gap-2 pt-2 border-t border-border">
                <Button variant="outline" size="sm" type="button" onClick={() => onOpenChange(false)}>
                  Cancelar
                </Button>
                <Button
                  size="sm"
                  type="submit"
                  disabled={mutation.isPending || esInvalidoPorDocRef}
                  className="gap-2 font-bold px-6"
                >
                  <Save className="size-4" />
                  {mutation.isPending ? 'Guardando...' : 'Guardar'}
                </Button>
              </DialogFooter>
            )
          })()}
        </form>
      </DialogContent>
    </Dialog>
  )
}
