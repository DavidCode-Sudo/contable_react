import { useMemo, useState } from 'react'
import { useQuery, useInfiniteQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import {
  Calendar,
  CheckCircle2,
  ChevronDown,
  ClipboardList,
  Clock,
  Edit3,
  Eye,
  Filter,
  Plus,
  RefreshCw,
  Search,
  XCircle,
} from 'lucide-react'

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  fetchRequisicionesList,
  getRequisicionEstadoMeta,
  type RequisicionListItem,
  type RequisicionesListFilters,
  type RequisicionesSummary,
  type RequisicionesListResponse,
} from '@/services/requisiciones'

type FilterState = {
  q: string
  estado: string
  prioridad: string
  fechaDesde: string
  fechaHasta: string
}

const currencyBs = new Intl.NumberFormat('es-VE', {
  style: 'currency',
  currency: 'VES',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const initialFilters: FilterState = {
  q: '',
  estado: '',
  prioridad: '',
  fechaDesde: '',
  fechaHasta: '',
}

export function RequisicionesListPage() {
  const [formFilters, setFormFilters] = useState<FilterState>(initialFilters)
  const [filters, setFilters] = useState<RequisicionesListFilters>({
    limit: 100,
  })

  const {
    data: infiniteData,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading: isLoadingQuery,
    refetch,
  } = useInfiniteQuery({
    queryKey: ['requisiciones-infinite', filters],
    queryFn: ({ pageParam = 0 }) =>
      fetchRequisicionesList({
        ...filters,
        offset: pageParam as number,
        limit: 50,
      }),
    getNextPageParam: (lastPage) => {
      const p = lastPage?.pagination;
      if (!p) return undefined;
      const nextOffset = (p.offset || 0) + (p.limit || 50);
      return nextOffset < p.total ? nextOffset : undefined;
    },
    initialPageParam: 0,
  });

  const items = useMemo(() => {
    return infiniteData?.pages.flatMap((page: any) => page?.items || []) ?? [];
  }, [infiniteData]);

  const summary: RequisicionesSummary | undefined = infiniteData?.pages[0]?.summary;

  const handleInputChange = (name: keyof FilterState, value: string) => {
    setFormFilters((prev) => ({ ...prev, [name]: value }))
  }

  const applyFilters = (e: React.FormEvent) => {
    e.preventDefault()
    setFilters((prev) => ({
      ...prev,
      q: formFilters.q || undefined,
      estado: formFilters.estado || undefined,
      prioridad: formFilters.prioridad || undefined,
      fechaDesde: formFilters.fechaDesde || undefined,
      fechaHasta: formFilters.fechaHasta || undefined,
      offset: 0,
    }))
  }

  const handleSelectEstadoFilter = (estado: string) => {
    setFormFilters((prev) => ({ ...prev, estado }))
    setFilters((prev) => ({ ...prev, estado: estado || undefined, offset: 0 }))
  }

  const resetFilters = () => {
    setFormFilters(initialFilters)
    setFilters({ limit: 100, offset: 0 })
  }

  const hasActiveFilters = useMemo(() => {
    return Object.values(formFilters).some((value) => value && value.length > 0)
  }, [formFilters])

  return (
    <div className="space-y-6">
      {/* CABECERA CON BOTÓN DE ACCIÓN */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <ClipboardList className="size-6 text-foreground" />
            Requisiciones
          </h1>
          <p className="text-xs text-muted-foreground">
            Consulta y gestiona las requisiciones internas de bienes y servicios.
          </p>
        </div>
        <Button asChild size="sm" className="gap-2 shadow-xs shrink-0 w-full sm:w-auto font-bold">
          <Link to="/inventario/requisiciones/nueva">
            <Plus className="size-4" />
            Nueva Requisición
          </Link>
        </Button>
      </div>

      {/* METRICAS TARJETAS INTERACTIVAS */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <SummaryStat
          icon={<ClipboardList className="size-4 text-primary" />}
          label="Total requisiciones"
          value={summary?.total ?? 0}
          tone="default"
          onClick={() => handleSelectEstadoFilter('')}
        />
        <SummaryStat
          icon={<Clock className="size-4 text-amber-500" />}
          label="Pendientes"
          value={summary?.pendientes ?? 0}
          tone="amber"
          onClick={() => handleSelectEstadoFilter('pendiente')}
        />
        <SummaryStat
          icon={<CheckCircle2 className="size-4 text-emerald-600" />}
          label="Aprobadas"
          value={summary?.aprobadas ?? 0}
          tone="emerald"
          onClick={() => handleSelectEstadoFilter('aprobada')}
        />
      </div>

      {/* BARRA DE FILTROS (Identica a Asientos Contables) */}
      <Card className="border-border/60 bg-card shadow-2xs">
        <CardContent className="p-3 sm:p-4">
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
            <form onSubmit={applyFilters} className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
              <div className="relative w-full">
                <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar por número, solicitante o concepto..."
                  className="pl-9 h-9 text-xs bg-background border-input"
                  value={formFilters.q}
                  onChange={(e) => handleInputChange('q', e.target.value)}
                />
              </div>
              <Button type="submit" variant="secondary" size="sm" className="h-9 text-xs font-semibold">Buscar</Button>
            </form>

            <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Estado:</span>
                <Select value={formFilters.estado || 'todos'} onValueChange={(val) => handleInputChange('estado', val === 'todos' ? '' : val)}>
                  <SelectTrigger className="w-[160px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos los Estados</SelectItem>
                    <SelectItem value="borrador">Borrador</SelectItem>
                    <SelectItem value="enviada">Enviada</SelectItem>
                    <SelectItem value="pendiente">Pendiente</SelectItem>
                    <SelectItem value="aprobada">Aprobada</SelectItem>
                    <SelectItem value="rechazada">Rechazada</SelectItem>
                    <SelectItem value="anulada">Anulada</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Prioridad:</span>
                <Select value={formFilters.prioridad || 'todos'} onValueChange={(val) => handleInputChange('prioridad', val === 'todos' ? '' : val)}>
                  <SelectTrigger className="w-[150px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todas las Prioridades</SelectItem>
                    <SelectItem value="baja">Baja</SelectItem>
                    <SelectItem value="media">Media</SelectItem>
                    <SelectItem value="alta">Alta</SelectItem>
                    <SelectItem value="urgente">Urgente</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <Button variant="outline" size="icon" className="h-9 w-9" onClick={() => refetch()} title="Recargar">
                <RefreshCw className={`size-4 ${isFetchingNextPage ? 'animate-spin' : ''}`} />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* REGISTROS CON COLUMNA DE ACCIONES */}
      <Card className="border-border/60 shadow-xs">
        <CardHeader className="flex flex-row items-center justify-between p-4">
          <CardTitle className="text-sm font-bold flex items-center gap-2">
            <span>Listado de Registros</span>
            {isFetchingNextPage && (
              <span className="flex items-center gap-1 text-[11px] text-muted-foreground font-normal">
                <RefreshCw className="size-3 animate-spin" /> Actualizando...
              </span>
            )}
          </CardTitle>
        </CardHeader>
        <CardContent className="p-0">
          {isLoadingQuery ? (
            <LoadingTable />
          ) : (
            <>
              <RequisicionesResponsiveList items={items} />

              {/* PAGINACIÓN INFINITA UNIFICADA (Estándar Cargar Más ERP) */}
              {hasNextPage && (
                <div className="flex justify-center p-4 border-t border-border bg-muted/10">
                  <Button
                    variant="outline"
                    onClick={() => fetchNextPage()}
                    disabled={isFetchingNextPage}
                    className="flex items-center gap-2 px-6 shadow-2xs border-primary/20 hover:border-primary text-xs font-semibold"
                  >
                    {isFetchingNextPage ? 'Cargando más requisiciones...' : 'Cargar más requisiciones'}
                    <ChevronDown className="h-4 w-4" />
                  </Button>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  )
}

function SummaryStat({
  icon,
  label,
  value,
  tone,
  onClick,
}: {
  icon: React.ReactNode
  label: string
  value: number
  tone: 'default' | 'amber' | 'emerald'
  onClick?: () => void
}) {
  const toneClasses =
    tone === 'amber'
      ? 'text-amber-500 bg-amber-50 dark:bg-amber-500/10'
      : tone === 'emerald'
        ? 'text-emerald-600 bg-emerald-50 dark:bg-emerald-500/10'
        : 'text-primary bg-primary/10'

  return (
    <Card onClick={onClick} className="border-border/60 shadow-xs cursor-pointer hover:border-primary/50 transition-colors">
      <CardContent className="flex items-center gap-3.5 p-4">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${toneClasses}`}>
          {icon}
        </span>
        <div className="truncate">
          <p className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground">
            {label}
          </p>
          <p className="text-xl font-extrabold text-foreground">{value}</p>
        </div>
      </CardContent>
    </Card>
  )
}

function LoadingTable() {
  return (
    <div className="space-y-3 p-4">
      <Skeleton className="h-12 w-full rounded-lg" />
      <Skeleton className="h-12 w-full rounded-lg" />
      <Skeleton className="h-12 w-full rounded-lg" />
    </div>
  )
}

function RequisicionesResponsiveList({
  items,
}: {
  items: RequisicionListItem[]
}) {
  if (items.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center gap-2 p-12 text-center text-xs text-muted-foreground">
        <ClipboardList className="size-10 text-muted-foreground/50" />
        <p className="font-semibold">No se encontraron requisiciones registradas.</p>
      </div>
    )
  }

  return (
    <div>
      {/* VISTA MÓVIL (block md:hidden) */}
      <div className="block md:hidden divide-y divide-border/40">
        {items.map((item) => (
          <div key={item.id} className="p-4 space-y-3">
            <div className="flex items-center justify-between">
              <Link
                to={`/inventario/requisiciones/${item.id}`}
                className="font-bold text-xs text-primary underline-offset-2 hover:underline"
              >
                {item.numero ?? `#${item.id}`}
              </Link>
              <EstadoBadges item={item} />
            </div>

            <div className="grid grid-cols-2 gap-2 text-xs">
              <div>
                <span className="text-[10px] text-muted-foreground font-semibold uppercase block">Solicitante</span>
                <span className="font-medium text-foreground">{item.solicitante ?? 'Sin asignar'}</span>
              </div>
              <div className="text-right">
                <span className="text-[10px] text-muted-foreground font-semibold uppercase block">Monto Total</span>
                <span className="font-bold text-primary">{currencyBs.format(item.monto_total_bs)}</span>
              </div>
            </div>

            <div className="flex items-center justify-between text-[11px] text-muted-foreground pt-2 border-t border-border/40">
              <span className="flex items-center gap-1">
                <Calendar className="size-3" /> {formatDateSafe(item.fecha_solicitud) ?? 'N/A'}
              </span>
              <div className="flex items-center gap-2">
                {item.estado === 'borrador' && (
                  <Button asChild size="sm" variant="outline" className="h-7 text-[10px] gap-1">
                    <Link to={`/inventario/requisiciones/${item.id}/editar`}>
                      <Edit3 className="size-3" /> Editar
                    </Link>
                  </Button>
                )}
                <Button asChild size="sm" variant="secondary" className="h-7 text-[10px] gap-1">
                  <Link to={`/inventario/requisiciones/${item.id}`}>
                    <Eye className="size-3" /> Ver
                  </Link>
                </Button>
              </div>
            </div>
          </div>
        ))}
      </div>

      {/* VISTA ESCRITORIO CON COLUMNA DE ACCIONES (hidden md:block) */}
      <div className="hidden md:block overflow-x-auto">
        <table className="min-w-full divide-y divide-border/60 text-xs">
          <thead className="bg-muted/50 text-[11px] uppercase font-bold text-muted-foreground">
            <tr>
              <th className="px-4 py-3 text-left">Número</th>
              <th className="px-4 py-3 text-left">Fechas</th>
              <th className="px-4 py-3 text-left">Tipo / Prioridad</th>
              <th className="px-4 py-3 text-left">Solicitante</th>
              <th className="px-4 py-3 text-left">Proveedor</th>
              <th className="px-4 py-3 text-right">Monto (Bs)</th>
              <th className="px-4 py-3 text-left">Estado</th>
              <th className="px-4 py-3 text-right">Acciones</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-border/40">
            {items.map((item) => (
              <tr key={item.id} className="hover:bg-muted/30 transition-colors">
                <td className="px-4 py-3">
                  <Link
                    to={`/inventario/requisiciones/${item.id}`}
                    className="font-bold text-primary hover:underline"
                  >
                    {item.numero ?? `#${item.id}`}
                  </Link>
                </td>
                <td className="px-4 py-3 text-muted-foreground">
                  <div>Sol: {formatDateSafe(item.fecha_solicitud) ?? 'N/A'}</div>
                  <div className="text-[10px]">Req: {formatDateSafe(item.fecha_requerida) ?? 'N/A'}</div>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5">
                    <Badge variant="outline" className="capitalize text-[10px]">{item.tipo_requisicion}</Badge>
                    <Badge variant="secondary" className="capitalize text-[10px]">{item.prioridad}</Badge>
                  </div>
                </td>
                <td className="px-4 py-3 font-medium">{item.solicitante ?? 'Sin asignar'}</td>
                <td className="px-4 py-3 font-medium">{item.proveedor.nombre ?? 'Sin proveedor'}</td>
                <td className="px-4 py-3 text-right font-bold text-foreground">
                  {currencyBs.format(item.monto_total_bs)}
                </td>
                <td className="px-4 py-3">
                  <EstadoBadges item={item} />
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex items-center justify-end gap-1.5">
                    {item.estado === 'borrador' && (
                      <Button asChild size="sm" variant="outline" className="h-7 px-2 text-[11px] gap-1 font-semibold">
                        <Link to={`/inventario/requisiciones/${item.id}/editar`}>
                          <Edit3 className="size-3.5 text-amber-600" /> Editar
                        </Link>
                      </Button>
                    )}
                    <Button asChild size="sm" variant="secondary" className="h-7 px-2 text-[11px] gap-1 font-semibold">
                      <Link to={`/inventario/requisiciones/${item.id}`}>
                        <Eye className="size-3.5 text-primary" /> Ver
                      </Link>
                    </Button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

function EstadoBadges({ item }: { item: RequisicionListItem }) {
  const meta = getRequisicionEstadoMeta(item.estado)

  return (
    <Badge variant="outline" className={`font-bold text-[10px] px-2.5 py-0.5 uppercase tracking-wider rounded-md ${meta.colorClass}`}>
      {meta.label}
    </Badge>
  )
}

function formatDateSafe(dateStr?: string | null) {
  if (!dateStr) return null
  try {
    const d = new Date(dateStr)
    return d.toLocaleDateString('es-VE')
  } catch {
    return dateStr
  }
}
