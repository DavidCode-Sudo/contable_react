import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  AlertTriangle,
  ArrowDownLeft,
  ArrowUpRight,
  Boxes,
  Edit3,
  FolderPlus,
  History,
  PackageCheck,
  PackageMinus,
  PackageSearch,
  Plus,
  RefreshCw,
  Search,
  Warehouse,
} from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

import { AjusteStockDialog } from '@/components/inventario/ajuste-stock-dialog'
import { CategoriaDialog } from '@/components/inventario/categoria-dialog'
import { ProductoDialog } from '@/components/inventario/producto-dialog'
import {
  fetchCategorias,
  fetchMovimientos,
  fetchProductosList,
  inventarioKeys,
  type CategoriaProducto,
  type Producto,
} from '@/services/inventario'

const MOTIVO_BADGES: Record<string, { label: string; color: string }> = {
  donacion: { label: 'Donación', color: 'bg-purple-100 text-purple-800 border-purple-300' },
  ingreso_interno: { label: 'Traspaso Interno', color: 'bg-blue-100 text-blue-800 border-blue-300' },
  compra_directa: { label: 'Compra Directa', color: 'bg-amber-100 text-amber-800 border-amber-300' },
  ajuste_auditoria: { label: 'Auditoría Físico', color: 'bg-slate-100 text-slate-800 border-slate-300' },
  despacho_interno: { label: 'Despacho Interno', color: 'bg-sky-100 text-sky-800 border-sky-300' },
  merma_averia: { label: 'Merma / Avería', color: 'bg-orange-100 text-orange-800 border-orange-300' },
  recepcion_requisicion: { label: 'Requisición', color: 'bg-emerald-100 text-emerald-800 border-emerald-300' },
}

function formatBs(amount: number): string {
  return (amount || 0).toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

export function InventarioListPage() {
  const [activeTab, setActiveTab] = useState<'productos' | 'categorias' | 'movimientos'>('productos')

  // Filtros de Productos
  const [searchQuery, setSearchQuery] = useState('')
  const [selectedCategoria, setSelectedCategoria] = useState<number | ''>('')
  const [alertaFilter, setAlertaFilter] = useState<string>('')

  // Filtros de Movimientos
  const [movQuery, setMovQuery] = useState('')
  const [movTipo, setMovTipo] = useState('')

  // Modales
  const [modalProductoOpen, setModalProductoOpen] = useState(false)
  const [productoEditar, setProductoEditar] = useState<Producto | null>(null)

  const [modalAjusteOpen, setModalAjusteOpen] = useState(false)
  const [productoAjustar, setProductoAjustar] = useState<Producto | null>(null)

  const [modalCategoriaOpen, setModalCategoriaOpen] = useState(false)
  const [categoriaEditar, setCategoriaEditar] = useState<CategoriaProducto | null>(null)

  // 1. QUERY DE PRODUCTOS CON TANSTACK QUERY
  const productosQuery = useQuery({
    queryKey: inventarioKeys.productosList({
      q: searchQuery,
      categoria_id: selectedCategoria ? Number(selectedCategoria) : null,
      alerta_stock: alertaFilter,
    }),
    queryFn: () =>
      fetchProductosList({
        q: searchQuery,
        categoria_id: selectedCategoria ? Number(selectedCategoria) : null,
        alerta_stock: alertaFilter,
        limit: 100,
      }),
  })

  // 2. QUERY DE CATEGORÍAS
  const categoriasQuery = useQuery({
    queryKey: inventarioKeys.categorias(),
    queryFn: fetchCategorias,
  })

  // 3. QUERY DE MOVIMIENTOS
  const movimientosQuery = useQuery({
    queryKey: inventarioKeys.movimientos({ q: movQuery, tipo: movTipo }),
    queryFn: () =>
      fetchMovimientos({
        q: movQuery,
        tipo: movTipo,
      }),
    enabled: activeTab === 'movimientos',
  })

  const summary = productosQuery.data?.summary ?? {
    total_productos: 0,
    sin_stock: 0,
    bajo_stock: 0,
    stock_normal: 0,
    valor_total_inventario: 0,
  }

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <Warehouse className="size-6 text-foreground" />
            Gestión de Inventario y Almacén
          </h1>
          <p className="text-xs text-muted-foreground">
            Administre productos, control de existencias en tiempo real, categorías y trazabilidad de movimientos.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            size="sm"
            onClick={() => {
              setProductoEditar(null)
              setModalProductoOpen(true)
            }}
            className="gap-2 font-bold text-xs bg-primary text-primary-foreground shadow-xs"
          >
            <Plus className="size-4" /> Nuevo Producto
          </Button>

          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setCategoriaEditar(null)
              setModalCategoriaOpen(true)
            }}
            className="gap-2 text-xs font-semibold"
          >
            <FolderPlus className="size-4" /> Nueva Categoría
          </Button>
        </div>
      </div>

      {/* METRICAS KPI */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
        <Card className="border-border/60 shadow-2xs">
          <CardHeader className="p-4 pb-2">
            <CardTitle className="text-xs font-bold text-muted-foreground uppercase flex items-center justify-between">
              Total Catálogo
              <Boxes className="size-4 text-blue-600" />
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4 pt-0">
            <div className="text-2xl font-black text-foreground">{summary.total_productos}</div>
            <p className="text-[11px] text-muted-foreground mt-0.5">
              Valor: Bs. {formatBs(summary.valor_total_inventario)}
            </p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs bg-emerald-50/20 dark:bg-emerald-950/10">
          <CardHeader className="p-4 pb-2">
            <CardTitle className="text-xs font-bold text-emerald-700 dark:text-emerald-400 uppercase flex items-center justify-between">
              Stock Normal
              <PackageCheck className="size-4 text-emerald-600" />
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4 pt-0">
            <div className="text-2xl font-black text-emerald-700 dark:text-emerald-400">
              {summary.stock_normal}
            </div>
            <p className="text-[11px] text-muted-foreground mt-0.5">Existencias óptimas</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs bg-amber-50/20 dark:bg-amber-950/10">
          <CardHeader className="p-4 pb-2">
            <CardTitle className="text-xs font-bold text-amber-700 dark:text-amber-400 uppercase flex items-center justify-between">
              Bajo Stock
              <AlertTriangle className="size-4 text-amber-600" />
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4 pt-0">
            <div className="text-2xl font-black text-amber-700 dark:text-amber-400">
              {summary.bajo_stock}
            </div>
            <p className="text-[11px] text-muted-foreground mt-0.5">Requieren reposición</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs bg-rose-50/20 dark:bg-rose-950/10">
          <CardHeader className="p-4 pb-2">
            <CardTitle className="text-xs font-bold text-rose-700 dark:text-rose-400 uppercase flex items-center justify-between">
              Sin Stock
              <PackageMinus className="size-4 text-rose-600" />
            </CardTitle>
          </CardHeader>
          <CardContent className="p-4 pt-0">
            <div className="text-2xl font-black text-rose-700 dark:text-rose-400">
              {summary.sin_stock}
            </div>
            <p className="text-[11px] text-muted-foreground mt-0.5">Agotados en depósito</p>
          </CardContent>
        </Card>
      </div>

      {/* PESTAÑAS DE NAVEGACIÓN (TABS) */}
      <div className="flex border-b border-border/60 gap-4">
        <button
          onClick={() => setActiveTab('productos')}
          className={`pb-3 text-xs font-bold transition-colors border-b-2 flex items-center gap-2 ${
            activeTab === 'productos'
              ? 'border-primary text-primary'
              : 'border-transparent text-muted-foreground hover:text-foreground'
          }`}
        >
          <PackageSearch className="size-4" /> Catálogo de Productos ({summary.total_productos})
        </button>

        <button
          onClick={() => setActiveTab('categorias')}
          className={`pb-3 text-xs font-bold transition-colors border-b-2 flex items-center gap-2 ${
            activeTab === 'categorias'
              ? 'border-primary text-primary'
              : 'border-transparent text-muted-foreground hover:text-foreground'
          }`}
        >
          <Boxes className="size-4" /> Categorías ({categoriasQuery.data?.length ?? 0})
        </button>

        <button
          onClick={() => setActiveTab('movimientos')}
          className={`pb-3 text-xs font-bold transition-colors border-b-2 flex items-center gap-2 ${
            activeTab === 'movimientos'
              ? 'border-primary text-primary'
              : 'border-transparent text-muted-foreground hover:text-foreground'
          }`}
        >
          <History className="size-4" /> Trazabilidad de Movimientos
        </button>
      </div>

      {/* TAB 1: PRODUCTOS */}
      {activeTab === 'productos' && (
        <div className="space-y-4">
          {/* BARRA DE FILTROS (Identica a Asientos Contables) */}
          <Card className="border-border/60 bg-card shadow-2xs">
            <CardContent className="p-3 sm:p-4">
              <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
                <div className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
                  <div className="relative w-full">
                    <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                    <Input
                      placeholder="Buscar por código, nombre o ubicación..."
                      className="pl-9 h-9 text-xs bg-background border-input"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                    />
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted-foreground">Categoría:</span>
                    <Select
                      value={selectedCategoria === '' ? 'all' : String(selectedCategoria)}
                      onValueChange={(val) => setSelectedCategoria(val === 'all' ? '' : Number(val))}
                    >
                      <SelectTrigger className="w-[180px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">Todas las Categorías</SelectItem>
                        {categoriasQuery.data?.map((c) => (
                          <SelectItem key={c.id} value={String(c.id)}>
                            {c.nombre}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted-foreground">Stock:</span>
                    <Select
                      value={alertaFilter === '' ? 'all' : alertaFilter}
                      onValueChange={(val) => setAlertaFilter(val === 'all' ? '' : val)}
                    >
                      <SelectTrigger className="w-[160px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">Todos los Estados</SelectItem>
                        <SelectItem value="normal">Stock Normal</SelectItem>
                        <SelectItem value="bajo">Bajo Stock</SelectItem>
                        <SelectItem value="sin">Sin Stock</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <Button
                    variant="outline"
                    size="icon"
                    className="h-9 w-9"
                    onClick={() => {
                      setSearchQuery('');
                      setSelectedCategoria('');
                      setAlertaFilter('');
                      productosQuery.refetch();
                    }}
                    title="Recargar"
                  >
                    <RefreshCw className={`size-4 ${productosQuery.isFetching ? 'animate-spin' : ''}`} />
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* TABLA DE PRODUCTOS */}
          <Card className="border-border/60 shadow-xs overflow-hidden rounded-xl">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead className="bg-slate-100/80 dark:bg-slate-900/80 border-b border-border/80 text-slate-600 dark:text-slate-400 uppercase text-[10.5px] font-bold tracking-wider">
                  <tr>
                    <th className="px-4 py-3.5 align-middle w-32">Código</th>
                    <th className="px-4 py-3.5 align-middle">Producto / Descripción</th>
                    <th className="px-4 py-3.5 align-middle w-40">Categoría</th>
                    <th className="px-4 py-3.5 align-middle text-right w-44">Existencia Física</th>
                    <th className="px-4 py-3.5 align-middle text-right w-36">Costo Unit. (Bs.)</th>
                    <th className="px-4 py-3.5 align-middle text-right w-36">Precio Ref. (Bs.)</th>
                    <th className="px-4 py-3.5 align-middle w-32">Ubicación</th>
                    <th className="px-4 py-3.5 align-middle text-center w-28">Estado</th>
                    <th className="px-4 py-3.5 align-middle text-right w-48">Acciones</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/50 font-medium bg-card">
                  {productosQuery.isLoading ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                        <RefreshCw className="size-6 animate-spin mx-auto mb-2 text-primary" />
                        <span className="text-xs font-semibold">Cargando existencias de inventario...</span>
                      </td>
                    </tr>
                  ) : productosQuery.data?.items.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                        <p className="text-sm font-semibold">No se encontraron productos en el inventario.</p>
                        <p className="text-xs text-muted-foreground mt-1">Pruebe ajustando los filtros o agregue un nuevo producto.</p>
                      </td>
                    </tr>
                  ) : (
                    productosQuery.data?.items.map((prod) => (
                      <tr key={prod.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-900/40 transition-colors">
                        
                        {/* Código */}
                        <td className="px-4 py-3.5 align-middle">
                          <span className="font-mono text-xs font-bold text-slate-800 dark:text-slate-200 bg-slate-100 dark:bg-slate-800/80 px-2.5 py-1 rounded-md border border-slate-200/80 dark:border-slate-700/60 inline-block shadow-2xs">
                            {prod.codigo}
                          </span>
                        </td>

                        {/* Producto & Descripción */}
                        <td className="px-4 py-3.5 align-middle">
                          <div className="space-y-0.5">
                            <p className="font-semibold text-slate-900 dark:text-slate-100 text-xs sm:text-sm leading-snug">
                              {prod.nombre}
                            </p>
                            {prod.descripcion && (
                              <p className="text-[11px] text-slate-500 dark:text-slate-400 font-normal line-clamp-1 max-w-sm">
                                {prod.descripcion}
                              </p>
                            )}
                          </div>
                        </td>

                        {/* Categoría */}
                        <td className="px-4 py-3.5 align-middle">
                          <Badge variant="outline" className="text-[11px] font-semibold bg-slate-100/80 text-slate-700 border-slate-200/80 dark:bg-slate-800 dark:text-slate-300 dark:border-slate-700">
                            {prod.categoria.nombre}
                          </Badge>
                        </td>

                        {/* Existencia & Alerta de Stock (Limpia en columna derecha) */}
                        <td className="px-4 py-3.5 align-middle text-right">
                          <div className="flex flex-col items-end gap-1">
                            <span className="font-mono font-bold text-sm text-slate-900 dark:text-slate-100">
                              {prod.existencias} <span className="text-[11px] font-medium text-slate-500">{prod.unidad_medida}</span>
                            </span>
                            {Boolean(prod.stock_reservado && prod.stock_reservado > 0) && (
                              <span className="text-[10px] font-medium text-amber-700 dark:text-amber-400 bg-amber-50 dark:bg-amber-950/40 px-1.5 py-0.5 rounded border border-amber-200/60">
                                Res: <strong>{prod.stock_reservado}</strong> | Disp: <strong>{prod.stock_disponible}</strong>
                              </span>
                            )}
                            {prod.alerta_stock === 'sin_stock' ? (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/60 dark:text-rose-300 dark:border-rose-900">
                                <span className="size-1.5 rounded-full bg-rose-500" /> Sin Stock
                              </span>
                            ) : prod.alerta_stock === 'bajo_stock' ? (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200 dark:bg-amber-950/60 dark:text-amber-300 dark:border-amber-900">
                                <span className="size-1.5 rounded-full bg-amber-500 animate-pulse" /> Bajo Stock ({prod.stock_minimo})
                              </span>
                            ) : (
                              <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-900">
                                <span className="size-1.5 rounded-full bg-emerald-500" /> Normal
                              </span>
                            )}
                          </div>
                        </td>

                        {/* Costo Unitario */}
                        <td className="px-4 py-3.5 align-middle text-right font-mono text-xs font-semibold text-slate-900 dark:text-slate-100">
                          Bs. {formatBs(prod.costo)}
                        </td>

                        {/* Precio Referencial */}
                        <td className="px-4 py-3.5 align-middle text-right font-mono text-xs text-slate-600 dark:text-slate-400">
                          Bs. {formatBs(prod.precio)}
                        </td>

                        {/* Ubicación */}
                        <td className="px-4 py-3.5 align-middle text-slate-600 dark:text-slate-400 text-xs">
                          {prod.ubicacion ? (
                            <span className="font-medium text-slate-800 dark:text-slate-200">{prod.ubicacion}</span>
                          ) : (
                            <span className="text-slate-400 dark:text-slate-500 italic text-[11px]">Sin ubicar</span>
                          )}
                        </td>

                        {/* Estado */}
                        <td className="px-4 py-3.5 align-middle text-center">
                          <span
                            className={
                              prod.estado === 'activo'
                                ? 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-900'
                                : 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700'
                            }
                          >
                            <span className={`size-1.5 rounded-full ${prod.estado === 'activo' ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                            {prod.estado}
                          </span>
                        </td>

                        {/* Acciones */}
                        <td className="px-4 py-3.5 align-middle text-right">
                          <div className="flex items-center justify-end gap-1.5">
                            <Button
                              size="sm"
                              variant="outline"
                              onClick={() => {
                                setProductoAjustar(prod)
                                setModalAjusteOpen(true)
                              }}
                              className="h-8 px-2.5 text-xs gap-1.5 font-semibold text-emerald-700 hover:bg-emerald-50 border-emerald-200 hover:border-emerald-300 dark:border-emerald-900 dark:text-emerald-400 dark:hover:bg-emerald-950/50 shadow-2xs"
                              title="Registrar entrada o salida de inventario"
                            >
                              <RefreshCw className="size-3.5" /> Ajustar Stock
                            </Button>

                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() => {
                                setProductoEditar(prod)
                                setModalProductoOpen(true)
                              }}
                              className="h-8 px-2.5 text-xs gap-1 font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-100 dark:hover:bg-slate-800"
                              title="Editar producto"
                            >
                              <Edit3 className="size-3.5" /> Editar
                            </Button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {/* TAB 2: CATEGORÍAS */}
      {activeTab === 'categorias' && (
        <Card className="border-border/60 shadow-xs overflow-hidden rounded-xl">
          <div className="overflow-x-auto">
            <table className="w-full text-left text-xs border-collapse">
              <thead className="bg-slate-100/80 dark:bg-slate-900/80 border-b border-border/80 text-slate-600 dark:text-slate-400 uppercase text-[10.5px] font-bold tracking-wider">
                <tr>
                  <th className="px-4 py-3.5 align-middle">Categoría</th>
                  <th className="px-4 py-3.5 align-middle">Descripción</th>
                  <th className="px-4 py-3.5 align-middle">Cuenta Contable (Plan Único)</th>
                  <th className="px-4 py-3.5 align-middle text-center w-36">Productos Asignados</th>
                  <th className="px-4 py-3.5 align-middle text-center w-28">Estado</th>
                  <th className="px-4 py-3.5 align-middle text-right w-32">Acciones</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/50 font-medium bg-card">
                {categoriasQuery.isLoading ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                      <RefreshCw className="size-6 animate-spin mx-auto mb-2 text-primary" />
                      <span className="text-xs font-semibold">Cargando categorías...</span>
                    </td>
                  </tr>
                ) : categoriasQuery.data?.length === 0 ? (
                  <tr>
                    <td colSpan={6} className="px-4 py-12 text-center text-muted-foreground">
                      <p className="text-sm font-semibold">No hay categorías registradas.</p>
                    </td>
                  </tr>
                ) : (
                  categoriasQuery.data?.map((cat) => (
                    <tr key={cat.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-900/40 transition-colors">
                      <td className="px-4 py-3.5 align-middle font-bold text-slate-900 dark:text-slate-100 text-sm">
                        {cat.nombre}
                      </td>
                      <td className="px-4 py-3.5 align-middle text-slate-600 dark:text-slate-400 text-xs">
                        {cat.descripcion || <span className="italic text-slate-400">Sin descripción</span>}
                      </td>
                      <td className="px-4 py-3.5 align-middle text-xs">
                        {cat.cuenta_codigo ? (
                          <span className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-sky-50 text-sky-800 border border-sky-200 dark:bg-sky-950/60 dark:text-sky-300 dark:border-sky-800 font-mono font-bold">
                            {cat.cuenta_codigo} - {cat.cuenta_nombre}
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                            ⚠️ No Parametrizada
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3.5 align-middle text-center">
                        <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md text-xs font-mono font-bold bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-200 border border-slate-200 dark:border-slate-700">
                          {cat.total_productos} productos
                        </span>
                      </td>
                      <td className="px-4 py-3.5 align-middle text-center">
                        <span
                          className={
                            cat.estado === 'activo'
                              ? 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300 dark:border-emerald-900'
                              : 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200 dark:bg-slate-800 dark:text-slate-400 dark:border-slate-700'
                          }
                        >
                          <span className={`size-1.5 rounded-full ${cat.estado === 'activo' ? 'bg-emerald-500' : 'bg-slate-400'}`} />
                          {cat.estado}
                        </span>
                      </td>
                      <td className="px-4 py-3.5 align-middle text-right">
                        <Button
                          size="sm"
                          variant="ghost"
                          onClick={() => {
                            setCategoriaEditar(cat)
                            setModalCategoriaOpen(true)
                          }}
                          className="h-8 px-2.5 text-xs gap-1 font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 dark:text-slate-400 dark:hover:text-slate-100 dark:hover:bg-slate-800"
                        >
                          <Edit3 className="size-3.5" /> Editar
                        </Button>
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      {/* TAB 3: MOVIMIENTOS DE STOCK */}
      {activeTab === 'movimientos' && (
        <div className="space-y-4">
          {/* BARRA DE FILTROS (Identica a Asientos Contables) */}
          <Card className="border-border/60 bg-card shadow-2xs">
            <CardContent className="p-3 sm:p-4">
              <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
                <div className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
                  <div className="relative w-full">
                    <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                    <Input
                      placeholder="Buscar por producto, motivo o N° de requisición..."
                      className="pl-9 h-9 text-xs bg-background border-input"
                      value={movQuery}
                      onChange={(e) => setMovQuery(e.target.value)}
                    />
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
                  <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted-foreground">Tipo:</span>
                    <Select
                      value={movTipo === '' ? 'all' : movTipo}
                      onValueChange={(val) => setMovTipo(val === 'all' ? '' : val)}
                    >
                      <SelectTrigger className="w-[180px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">Todos los Movimientos</SelectItem>
                        <SelectItem value="entrada">Entradas (+)</SelectItem>
                        <SelectItem value="salida">Salidas (-)</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <Button
                    variant="outline"
                    size="icon"
                    className="h-9 w-9"
                    onClick={() => {
                      setMovQuery('');
                      setMovTipo('');
                      movimientosQuery.refetch();
                    }}
                    title="Recargar"
                  >
                    <RefreshCw className={`size-4 ${movimientosQuery.isFetching ? 'animate-spin' : ''}`} />
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className="border-border/60 shadow-xs overflow-hidden rounded-xl">
            <div className="overflow-x-auto">
              <table className="w-full text-left text-xs border-collapse">
                <thead className="bg-slate-100/80 dark:bg-slate-900/80 border-b border-border/80 text-slate-600 dark:text-slate-400 uppercase text-[10.5px] font-bold tracking-wider">
                  <tr>
                    <th className="px-4 py-3.5 align-middle w-36">Fecha / Hora</th>
                    <th className="px-4 py-3.5 align-middle w-28">Tipo</th>
                    <th className="px-4 py-3.5 align-middle">Producto</th>
                    <th className="px-4 py-3.5 align-middle text-right w-28">Cantidad</th>
                    <th className="px-4 py-3.5 align-middle text-right w-28">Stock Ant.</th>
                    <th className="px-4 py-3.5 align-middle text-right w-28">Stock Nuevo</th>
                    <th className="px-4 py-3.5 align-middle">Justificación / Motivo BI</th>
                    <th className="px-4 py-3.5 align-middle w-32">Usuario</th>
                    <th className="px-4 py-3.5 align-middle w-32">Referencia</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/50 font-medium bg-card">
                  {movimientosQuery.isLoading ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                        <RefreshCw className="size-6 animate-spin mx-auto mb-2 text-primary" />
                        <span className="text-xs font-semibold">Cargando historial de trazabilidad...</span>
                      </td>
                    </tr>
                  ) : movimientosQuery.data?.movimientos.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-muted-foreground">
                        <p className="text-sm font-semibold">No se han registrado movimientos de almacén.</p>
                      </td>
                    </tr>
                  ) : (
                    movimientosQuery.data?.movimientos.map((mov) => (
                      <tr key={mov.id} className="hover:bg-slate-50/80 dark:hover:bg-slate-900/40 transition-colors">
                        <td className="px-4 py-3.5 align-middle font-mono text-[11px] text-slate-600 dark:text-slate-400">
                          {new Date(mov.fecha).toLocaleString('es-VE')}
                        </td>
                        <td className="px-4 py-3.5 align-middle">
                          {mov.tipo === 'entrada' ? (
                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-800 border border-emerald-200 uppercase">
                              <ArrowDownLeft className="size-3 text-emerald-600" /> Entrada
                            </span>
                          ) : (
                            <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-800 border border-rose-200 uppercase">
                              <ArrowUpRight className="size-3 text-rose-600" /> Salida
                            </span>
                          )}
                        </td>
                        <td className="px-4 py-3.5 align-middle">
                          <div className="font-semibold text-slate-900 dark:text-slate-100 text-xs">{mov.producto_nombre}</div>
                          <div className="font-mono text-[10px] text-slate-500">{mov.producto_codigo}</div>
                        </td>
                        <td className="px-4 py-3.5 align-middle text-right font-mono font-bold text-sm text-slate-900 dark:text-slate-100">
                          {mov.cantidad} <span className="text-[10px] font-medium text-slate-500">{mov.unidad_medida}</span>
                        </td>
                        <td className="px-4 py-3.5 align-middle text-right font-mono text-slate-500 text-xs">{mov.stock_anterior}</td>
                        <td className="px-4 py-3.5 align-middle text-right font-mono font-bold text-slate-900 dark:text-slate-100 text-xs">{mov.stock_nuevo}</td>
                        <td className="px-4 py-3.5 align-middle text-xs text-slate-800 dark:text-slate-200 max-w-xs space-y-1">
                          {mov.motivo_codigo && MOTIVO_BADGES[mov.motivo_codigo] && (
                            <Badge
                              variant="outline"
                              className={`text-[9.5px] uppercase font-bold px-2 py-0.5 inline-block mb-1 ${MOTIVO_BADGES[mov.motivo_codigo].color}`}
                            >
                              {MOTIVO_BADGES[mov.motivo_codigo].label}
                            </Badge>
                          )}
                          <div className="leading-snug">{mov.razon}</div>
                        </td>
                        <td className="px-4 py-3.5 align-middle text-slate-600 dark:text-slate-400 text-xs font-medium">{mov.usuario}</td>
                        <td className="px-4 py-3.5 align-middle font-mono text-xs">
                          {mov.requisicion ? (
                            <Link
                              to={`/inventario/requisiciones/${mov.requisicion.id}`}
                              className="text-primary hover:underline font-bold inline-block"
                            >
                              {mov.requisicion.numero ?? `#${mov.requisicion.id}`}
                            </Link>
                          ) : mov.documento_referencia ? (
                            <span className="font-semibold text-slate-800 dark:text-slate-200">{mov.documento_referencia}</span>
                          ) : (
                            <span className="text-slate-400 italic text-[11px]">-</span>
                          )}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {/* MODALES */}
      <ProductoDialog
        open={modalProductoOpen}
        onOpenChange={setModalProductoOpen}
        productoEditar={productoEditar}
      />

      <AjusteStockDialog
        open={modalAjusteOpen}
        onOpenChange={setModalAjusteOpen}
        producto={productoAjustar}
      />

      <CategoriaDialog
        open={modalCategoriaOpen}
        onOpenChange={setModalCategoriaOpen}
        categoriaEditar={categoriaEditar}
      />
    </div>
  )
}
