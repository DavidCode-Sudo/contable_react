import React, { useState, useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  PackageCheck,
  TrendingUp,
  Clock,
  Plus,
  Search,
  Filter,
  Eye,
  Building2,
  RefreshCw,
} from 'lucide-react'
import { toast } from 'sonner'
import {
  fetchOrdenesEntregaList,
  fetchDepartamentosList,
  getEstadoOrdenMeta,
  type OrdenEntregaListItem,
  type OrdenEntregaKPIs,
  type DepartamentoOption,
} from '@/services/ordenesEntrega'
import { OrdenEntregaDialog } from '@/components/inventario/orden-entrega-dialog'

export function OrdenesEntregaListPage() {
  const navigate = useNavigate()
  const [loading, setLoading] = useState(true)
  const [ordenes, setOrdenes] = useState<OrdenEntregaListItem[]>([])
  const [kpis, setKpis] = useState<OrdenEntregaKPIs>({
    total_despachos_mes: 0,
    total_unidades_entregadas: 0,
    ordenes_pendientes: 0,
  })

  // Filtros
  const [searchQuery, setSearchQuery] = useState('')
  const [estadoFilter, setEstadoFilter] = useState('')
  const [deptFilter, setDeptFilter] = useState<number>(0)
  const [departamentos, setDepartamentos] = useState<DepartamentoOption[]>([])

  // Modal
  const [isDialogOpen, setIsDialogOpen] = useState(false)

  const loadData = async () => {
    setLoading(true)
    try {
      const data = await fetchOrdenesEntregaList({
        q: searchQuery,
        estado: estadoFilter,
        departamento_id: deptFilter > 0 ? deptFilter : undefined,
        limit: 50,
      })
      setOrdenes(data.ordenes || [])
      setKpis(
        data.kpis || {
          total_despachos_mes: 0,
          total_unidades_entregadas: 0,
          ordenes_pendientes: 0,
        }
      )
    } catch (err: any) {
      toast.error(err.message || 'Error al cargar las órdenes de entrega.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadData()
    fetchDepartamentosList().then(setDepartamentos)
  }, [estadoFilter, deptFilter])

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    loadData()
  }

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <PackageCheck className="size-6 text-foreground" />
            Órdenes de Entrega y Despacho
          </h1>
          <p className="text-xs text-muted-foreground">
            Control e historial de salidas de almacén institucionales hacia departamentos y coordinaciones.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={loadData} className="h-9 gap-1.5 text-xs shadow-2xs">
            <RefreshCw className="size-3.5" /> Actualizar
          </Button>
          <Button
            onClick={() => setIsDialogOpen(true)}
            className="h-9 bg-primary text-primary-foreground hover:bg-primary/90 gap-1.5 text-xs font-bold shadow-2xs"
          >
            <Plus className="size-4" /> Nueva Orden de Entrega
          </Button>
        </div>
      </div>

      {/* TARJETAS DE RESUMEN (KPIs) */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        <Card className="border-border/60 shadow-2xs">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Despachos este Mes
            </CardTitle>
            <PackageCheck className="size-4 text-emerald-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-20" /> : kpis.total_despachos_mes}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Órdenes despachadas en el mes actual</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Unidades Entregadas
            </CardTitle>
            <TrendingUp className="size-4 text-blue-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? (
                <Skeleton className="h-7 w-24" />
              ) : (
                kpis.total_unidades_entregadas.toLocaleString('es-VE', { minimumFractionDigits: 0 })
              )}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Total de ítems entregados en el mes</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Órdenes Pendientes
            </CardTitle>
            <Clock className="size-4 text-amber-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-16" /> : kpis.ordenes_pendientes}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">En borrador o pendientes por despachar</p>
          </CardContent>
        </Card>
      </div>

      {/* BARRA DE FILTROS (Identica a Asientos Contables) */}
      <Card className="border-border/60 bg-card shadow-2xs">
        <CardContent className="p-3 sm:p-4">
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
            <form onSubmit={handleSearchSubmit} className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
              <div className="relative w-full">
                <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar por correlativo (ODE-2026-XXXXX), justificación o departamento..."
                  className="pl-9 h-9 text-xs bg-background border-input"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
              </div>
              <Button type="submit" variant="secondary" size="sm" className="h-9 text-xs font-semibold">Buscar</Button>
            </form>

            <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Estado:</span>
                <Select value={estadoFilter || 'all'} onValueChange={(val) => setEstadoFilter(val === 'all' ? '' : val)}>
                  <SelectTrigger className="w-[170px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">Todos los Estados</SelectItem>
                    <SelectItem value="despachada">Despachada</SelectItem>
                    <SelectItem value="aprobada">Aprobada</SelectItem>
                    <SelectItem value="borrador">Borrador</SelectItem>
                    <SelectItem value="anulada">Anulada</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Departamento:</span>
                <Select value={deptFilter === 0 ? 'all' : String(deptFilter)} onValueChange={(val) => setDeptFilter(val === 'all' ? 0 : Number(val))}>
                  <SelectTrigger className="w-[180px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">Todos los Departamentos</SelectItem>
                    {departamentos.map((d) => (
                      <SelectItem key={d.id} value={String(d.id)}>
                        {d.nombre}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* TABLA PRINCIPAL DE ÓRDENES */}
      <Card className="border-border/60 shadow-2xs">
        <CardContent className="p-0">
          {loading ? (
            <div className="p-6 space-y-4">
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
              <Skeleton className="h-10 w-full" />
            </div>
          ) : ordenes.length === 0 ? (
            <div className="p-12 text-center text-muted-foreground text-xs space-y-3">
              <PackageCheck className="size-10 mx-auto text-muted-foreground/50" />
              <p className="font-semibold text-foreground text-sm">No se encontraron órdenes de entrega.</p>
              <p>Haga clic en "Nueva Orden de Entrega" para registrar el primer despacho de almacén.</p>
            </div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="bg-muted/50 text-muted-foreground font-semibold uppercase tracking-wider text-[10px] border-b border-border/60">
                  <tr>
                    <th className="px-4 py-3 text-left">Correlativo</th>
                    <th className="px-4 py-3 text-left">Fecha Orden</th>
                    <th className="px-4 py-3 text-left">Departamento Receptor</th>
                    <th className="px-4 py-3 text-center">Estado</th>
                    <th className="px-4 py-3 text-center">Unidades</th>
                    <th className="px-4 py-3 text-right">Costo Total Despacho</th>
                    <th className="px-4 py-3 text-center">Acciones</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/40">
                  {ordenes.map((ord) => {
                    const meta = getEstadoOrdenMeta(ord.estado)
                    return (
                      <tr
                        key={ord.id}
                        onClick={() => navigate(`/inventario/ordenes-entrega/${ord.id}`)}
                        className="hover:bg-muted/30 transition-colors cursor-pointer"
                      >
                        <td className="px-4 py-3 font-bold text-foreground">
                          <Link
                            to={`/inventario/ordenes-entrega/${ord.id}`}
                            className="hover:underline text-emerald-700 dark:text-emerald-400"
                          >
                            {ord.numero_orden}
                          </Link>
                        </td>
                        <td className="px-4 py-3 text-muted-foreground font-medium">
                          {new Date(ord.fecha_orden).toLocaleDateString('es-VE')}
                        </td>
                        <td className="px-4 py-3 font-semibold text-foreground flex items-center gap-1.5">
                          <Building2 className="size-3.5 text-muted-foreground" />
                          {ord.departamento_nombre}
                        </td>
                        <td className="px-4 py-3 text-center">
                          <Badge variant="outline" className={`text-[10px] ${meta.colorClass}`}>
                            {meta.label}
                          </Badge>
                        </td>
                        <td className="px-4 py-3 text-center font-mono font-bold">
                          {ord.total_articulos.toLocaleString('es-VE')}
                        </td>
                        <td className="px-4 py-3 text-right font-mono font-bold text-foreground">
                          Bs. {ord.costo_total_despacho.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </td>
                        <td className="px-4 py-3 text-center" onClick={(e) => e.stopPropagation()}>
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => navigate(`/inventario/ordenes-entrega/${ord.id}`)}
                            className="h-8 gap-1 text-xs"
                          >
                            <Eye className="size-3.5" /> Ver Detalle
                          </Button>
                        </td>
                      </tr>
                    )
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* MODAL DE NUEVA ORDEN */}
      <OrdenEntregaDialog
        open={isDialogOpen}
        onOpenChange={setIsDialogOpen}
        onSuccess={loadData}
      />
    </div>
  )
}
