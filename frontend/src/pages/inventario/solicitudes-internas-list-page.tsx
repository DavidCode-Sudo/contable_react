import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { toast } from 'sonner';
import { FileText, Plus, Search, RefreshCw, Eye, Send, RotateCcw, CheckCircle2, Clock, AlertCircle, MoreVertical, ShoppingCart, Ban } from 'lucide-react';
import { solicitudesInternasService, type EstadoSolicitudInterna } from '@/services/solicitudesInternas';
import { inventarioService } from '@/services/inventario';
import { SolicitudInternaDialog } from '@/components/inventario/solicitud-interna-dialog';

export const SolicitudesInternasListPage: React.FC = () => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [dialogOpen, setDialogOpen] = useState(false);

  // Filters & Pagination State
  const [estadoFilter, setEstadoFilter] = useState<string>('todos');
  const [deptoFilter, setDeptoFilter] = useState<string>('todos');
  const [searchQuery, setSearchQuery] = useState('');
  const [activeSearch, setActiveSearch] = useState('');
  const [page, setPage] = useState(1);

  // TanStack Query: Fetch Departamentos
  const { data: departamentos = [] } = useQuery({
    queryKey: ['departamentos-options'],
    queryFn: () => inventarioService.getDepartamentos(),
    staleTime: 1000 * 60 * 5,
  });

  // TanStack Query: Fetch Solicitudes Internas List & Stats
  const { data: dataResponse, isLoading: loading, refetch } = useQuery({
    queryKey: ['solicitudes-internas', page, estadoFilter, deptoFilter, activeSearch],
    queryFn: () => solicitudesInternasService.getAll({
      page,
      limit: 25,
      estado: estadoFilter === 'todos' ? undefined : estadoFilter,
      departamento_id: deptoFilter === 'todos' ? undefined : parseInt(deptoFilter, 10),
      q: activeSearch.trim() || undefined,
    }),
    retry: false,
    staleTime: 1000 * 15,
  });

  const solicitudes = dataResponse?.solicitudes || [];
  const stats = dataResponse?.estadisticas || { borrador: 0, enviada: 0, convertida: 0, procesada_parcial: 0, derivada_compras: 0, rechazada: 0, anulada: 0 };
  const totalPages = dataResponse?.paginacion.pages || 1;

  // TanStack Mutations
  const enviarMutation = useMutation({
    mutationFn: (id: number) => solicitudesInternasService.enviar(id),
    onSuccess: () => {
      toast.success('Solicitud enviada a revisión exitosamente.');
      queryClient.invalidateQueries({ queryKey: ['solicitudes-internas'] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al enviar la solicitud.');
    },
  });

  const retractarMutation = useMutation({
    mutationFn: (id: number) => solicitudesInternasService.retractar(id),
    onSuccess: () => {
      toast.success('Solicitud retractada a borrador correctamente.');
      queryClient.invalidateQueries({ queryKey: ['solicitudes-internas'] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al retractar la solicitud.');
    },
  });

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setPage(1);
    setActiveSearch(searchQuery);
  };

  const getStatusBadge = (estado: EstadoSolicitudInterna, ordenEntregaNumero?: string | null) => {
    const estadoClean = (estado || '').trim().toLowerCase();
    switch (estadoClean) {
      case 'borrador':
        return <Badge variant="outline" className="bg-muted/60 text-muted-foreground border-border"><Clock className="size-3 mr-1" /> Borrador</Badge>;
      case 'enviada':
        return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800"><Send className="size-3 mr-1 text-blue-500" /> Enviada</Badge>;
      case 'convertida':
      case 'aprobada':
        return <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800"><CheckCircle2 className="size-3 mr-1 text-emerald-600" /> Convertida (ODE)</Badge>;
      case 'procesada_parcial':
        return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800"><CheckCircle2 className="size-3 mr-1 text-blue-600" /> Procesada Parcial</Badge>;
      case 'derivada_compras':
        return <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800"><ShoppingCart className="size-3 mr-1 text-amber-600" /> Derivada Compras</Badge>;
      case 'rechazada':
        return <Badge variant="outline" className="bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800"><AlertCircle className="size-3 mr-1 text-rose-600" /> Rechazada</Badge>;
      case 'anulada':
        return <Badge variant="outline" className="bg-muted text-muted-foreground border-border"><Ban className="size-3 mr-1" /> Anulada</Badge>;
      default:
        if (ordenEntregaNumero) {
          return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800"><CheckCircle2 className="size-3 mr-1 text-blue-600" /> Procesada Parcial</Badge>;
        }
        return <Badge variant="outline">{estado || 'Enviada'}</Badge>;
    }
  };

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <FileText className="size-6 text-foreground" />
            Mis Solicitudes / Solicitudes Internas
          </h1>
          <p className="text-xs text-muted-foreground">
            Gestión e historial institucional de requerimientos de insumos de almacén.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => navigate('/inventario/necesidades-compras')}
            className="h-9 gap-1.5 text-xs shadow-2xs font-semibold"
          >
            <ShoppingCart className="size-4 text-muted-foreground" />
            Cola de Procura
          </Button>
          <Button
            size="sm"
            onClick={() => setDialogOpen(true)}
            className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-primary text-primary-foreground hover:bg-primary/90"
          >
            <Plus className="size-4" />
            Nueva Solicitud
          </Button>
        </div>
      </div>

      {/* TARJETAS DE RESUMEN (KPIs) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Borradores
            </CardTitle>
            <Clock className="size-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : stats.borrador}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">En preparación por solicitantes</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Enviadas
            </CardTitle>
            <Send className="size-4 text-blue-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : stats.enviada}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">En revisión por administración</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Convertidas (ODE)
            </CardTitle>
            <CheckCircle2 className="size-4 text-emerald-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : (stats.convertida + stats.procesada_parcial)}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Orden de entrega generada</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Derivadas Compras
            </CardTitle>
            <ShoppingCart className="size-4 text-amber-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : stats.derivada_compras}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">En cola de adquisición</p>
          </CardContent>
        </Card>
      </div>

      {/* BARRA DE FILTROS */}
      <Card className="border-border/60 bg-card shadow-2xs">
        <CardContent className="p-3 sm:p-4">
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
            <form onSubmit={handleSearchSubmit} className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
              <div className="relative w-full">
                <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar por correlativo SI-..., motivo o solicitante..."
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
                <Select value={estadoFilter} onValueChange={(v) => { setEstadoFilter(v); setPage(1); }}>
                  <SelectTrigger className="w-[160px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos los Estados</SelectItem>
                    <SelectItem value="borrador">Borrador</SelectItem>
                    <SelectItem value="enviada">Enviada</SelectItem>
                    <SelectItem value="convertida">Convertida (ODE)</SelectItem>
                    <SelectItem value="procesada_parcial">Procesada Parcial</SelectItem>
                    <SelectItem value="derivada_compras">Derivada a Compras</SelectItem>
                    <SelectItem value="rechazada">Rechazada</SelectItem>
                    <SelectItem value="anulada">Anulada</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Depto:</span>
                <Select value={deptoFilter} onValueChange={(v) => { setDeptoFilter(v); setPage(1); }}>
                  <SelectTrigger className="w-[180px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos los Deptos</SelectItem>
                    {departamentos.map((d) => (
                      <SelectItem key={d.id} value={d.id.toString()}>{d.nombre}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <Button variant="outline" size="icon" className="h-9 w-9" onClick={() => refetch()} title="Recargar">
                <RefreshCw className="size-3.5" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* TABLA PRINCIPAL DE SOLICITUDES */}
      <Card className="border-border/60 shadow-2xs overflow-hidden">
        <Table>
          <TableHeader className="bg-muted/50 dark:bg-muted/30">
            <TableRow>
              <TableHead className="w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Correlativo</TableHead>
              <TableHead className="w-48 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Departamento</TableHead>
              <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Solicitante & Justificación</TableHead>
              <TableHead className="text-center w-24 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Ítems</TableHead>
              <TableHead className="w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Estado</TableHead>
              <TableHead className="w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Orden Entrega</TableHead>
              <TableHead className="w-32 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Fecha</TableHead>
              <TableHead className="w-12 text-center"></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell><Skeleton className="h-4 w-24" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-48" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-12 mx-auto" /></TableCell>
                  <TableCell><Skeleton className="h-5 w-24" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-24" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-20" /></TableCell>
                  <TableCell><Skeleton className="h-6 w-6 mx-auto" /></TableCell>
                </TableRow>
              ))
            ) : solicitudes.length === 0 ? (
              <TableRow>
                <TableCell colSpan={8} className="py-16 text-center text-muted-foreground">
                  <div className="flex flex-col items-center gap-2">
                    <FileText className="size-8 text-muted-foreground/40" />
                    <p className="text-sm font-semibold">No se encontraron solicitudes internas</p>
                    <p className="text-xs text-muted-foreground">Pruebe ajustando los filtros de búsqueda o cree una nueva solicitud.</p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              solicitudes.map((s) => (
                <TableRow key={s.id} onClick={() => navigate(`/inventario/solicitudes-internas/${s.id}`)} className="cursor-pointer hover:bg-muted/40 transition-colors">
                  <TableCell className="font-mono font-bold text-foreground text-xs">{s.numero_solicitud}</TableCell>
                  <TableCell className="text-xs font-semibold text-foreground">{s.departamento_nombre}</TableCell>
                  <TableCell>
                    <div className="font-medium text-foreground text-sm">{s.solicitante_nombre}</div>
                    <div className="text-xs text-muted-foreground line-clamp-1">{s.justificacion}</div>
                  </TableCell>
                  <TableCell className="text-center font-mono text-xs font-semibold">{s.total_items_distintos}</TableCell>
                  <TableCell>{getStatusBadge(s.estado, s.orden_entrega_numero)}</TableCell>
                  <TableCell>{s.orden_entrega_numero ? <span className="font-mono text-xs font-bold text-emerald-600">{s.orden_entrega_numero}</span> : <span className="text-xs text-muted-foreground">-</span>}</TableCell>
                  <TableCell className="text-xs font-mono text-muted-foreground">{(() => {
                    if (!s.fecha_solicitud) return 'N/A';
                    const match = s.fecha_solicitud.match(/^(\d{4})-(\d{2})-(\d{2})/);
                    if (match) {
                      const [_, y, m, d] = match;
                      return `${parseInt(d, 10)}/${parseInt(m, 10)}/${y}`;
                    }
                    return s.fecha_solicitud;
                  })()}</TableCell>
                  <TableCell className="text-center" onClick={(e) => e.stopPropagation()}>
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground"><MoreVertical className="size-4" /></Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => navigate(`/inventario/solicitudes-internas/${s.id}`)}>
                          <Eye className="size-4 mr-2 text-foreground" /> Ver Detalle
                        </DropdownMenuItem>
                        {s.estado === 'borrador' && (
                          <DropdownMenuItem onClick={() => enviarMutation.mutate(s.id)}>
                            <Send className="size-4 mr-2 text-blue-600" /> Enviar a Revisión
                          </DropdownMenuItem>
                        )}
                        {s.estado === 'enviada' && (
                          <DropdownMenuItem onClick={() => retractarMutation.mutate(s.id)}>
                            <RotateCcw className="size-4 mr-2 text-amber-600" /> Retractar a Borrador
                          </DropdownMenuItem>
                        )}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>

        {/* PAGINACIÓN */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between p-4 border-t border-border/60 text-xs">
            <span className="text-muted-foreground font-medium">Página {page} de {totalPages}</span>
            <div className="flex gap-2">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)} className="h-8 text-xs">Anterior</Button>
              <Button variant="outline" size="sm" disabled={page >= totalPages} onClick={() => setPage(page + 1)} className="h-8 text-xs">Siguiente</Button>
            </div>
          </div>
        )}
      </Card>

      {/* Modal para Crear Solicitud */}
      <SolicitudInternaDialog open={dialogOpen} onOpenChange={setDialogOpen} onSuccess={() => refetch()} />
    </div>
  );
};
