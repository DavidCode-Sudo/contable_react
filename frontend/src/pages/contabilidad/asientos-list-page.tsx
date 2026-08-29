import React, { useState, useEffect, useMemo } from 'react';
import { useAsientos, useAsientosInfinite } from '@/hooks/useAsientos';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Table, TableHeader, TableBody, TableCell, TableHead, TableRow } from '@/components/ui/table';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { AsientoModal } from '@/components/contabilidad/asiento-modal';
import { AsientoDetalleModal } from '@/components/contabilidad/asiento-detalle-modal';
import { AsientoAnularModal } from '@/components/contabilidad/asiento-anular-modal';
import { AsientoConfirmarModal } from '@/components/contabilidad/asiento-confirmar-modal';
import {
  Plus,
  Search,
  CheckCircle2,
  Ban,
  Eye,
  FileText,
  ChevronRight,
  RefreshCw,
  Clock,
  MoreVertical,
} from 'lucide-react';
import type { EstadoAsiento, Asiento } from '@/types/asientos';
import { apiClient } from '@/lib/apiClient';

export const AsientosListPage: React.FC = () => {
  const [estadoFilter, setEstadoFilter] = useState<string>('todos');
  const [tipoFilter, setTipoFilter] = useState<string>('todos');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [activeSearch, setActiveSearch] = useState<string>('');
  const [isModalOpen, setIsModalOpen] = useState<boolean>(false);
  const [cuentas, setCuentas] = useState<Array<{ id: number; codigo: string; nombre: string; naturaleza: string }>>([]);

  const [selectedAsiento, setSelectedAsiento] = useState<Asiento | null>(null);
  const [isDetalleOpen, setIsDetalleOpen] = useState<boolean>(false);
  const [fechaReversion, setFechaReversion] = useState<string>(new Date().toISOString().split('T')[0]);
  const [isAnularOpen, setIsAnularOpen] = useState<boolean>(false);
  const [asientoToAnular, setAsientoToAnular] = useState<Asiento | null>(null);
  const [isConfirmarOpen, setIsConfirmarOpen] = useState<boolean>(false);
  const [asientoToConfirmar, setAsientoToConfirmar] = useState<Asiento | null>(null);

  const estadoParam = estadoFilter !== 'todos' ? (estadoFilter as EstadoAsiento) : undefined;

  const {
    data: infiniteData,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
    refetch,
  } = useAsientosInfinite({
    estado: estadoParam,
    q: activeSearch,
    limit: 25,
  });

  const {
    crearAsiento,
    confirmarAsiento,
    anularAsiento,
    isCreating,
    isConfirming,
    isAnulando,
  } = useAsientos();

  const itemsAcumulados = useMemo(() => {
    return infiniteData?.pages.flatMap((page: any) => {
      const list = Array.isArray(page)
        ? page
        : Array.isArray(page?.items)
        ? page.items
        : Array.isArray(page?.data)
        ? page.data
        : [];
      return list;
    }) ?? [];
  }, [infiniteData]);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setActiveSearch(searchQuery.trim());
  };

  const handleCargarMas = () => {
    if (hasNextPage && !isFetchingNextPage) {
      fetchNextPage();
    }
  };

  useEffect(() => {
    const fetchCuentas = async () => {
      try {
        const res = await apiClient<{ data?: Array<{ id: number; codigo: string; nombre: string; naturaleza: string }>; cuentas?: Array<{ id: number; codigo: string; nombre: string; naturaleza: string }> }>('api/catalogo/cuentas?imputable=1&limit=500');
        const list = res.data || res.cuentas || [];
        setCuentas(list);
      } catch (e) {
        console.error('Error al cargar catálogo de cuentas:', e);
      }
    };
    fetchCuentas();
  }, []);

  const handleOpenConfirmar = (asiento: Asiento) => {
    setAsientoToConfirmar(asiento);
    setIsConfirmarOpen(true);
  };

  const handleOpenAnular = (asiento: Asiento) => {
    setAsientoToAnular(asiento);
    setIsAnularOpen(true);
  };

  const handleAnularSubmit = async () => {
    if (!asientoToAnular) return;
    try {
      await anularAsiento({ id: asientoToAnular.id, fechaReversion });
      alert('Asiento anulado y reverso generado exitosamente.');
      setIsAnularOpen(false);
    } catch (e: any) {
      alert(e?.message || 'Error al anular comprobante');
    }
  };

  const handleVerDetalle = async (id: number) => {
    // 1. Mostrar datos de cabecera de inmediato como fallback
    const found = listaParaMostrar.find((item) => item.id === id);
    if (found) {
      setSelectedAsiento(found);
      setIsDetalleOpen(true);
    }

    // 2. Consultar API para obtener el comprobante completo con sus renglones
    try {
      const res = await apiClient<any>(`api/contabilidad/asientos/${id}`);
      const asientoData = res?.data?.id ? res.data : (res?.id ? res : null);
      if (asientoData) {
        setSelectedAsiento(asientoData);
        setIsDetalleOpen(true);
      }
    } catch (e: any) {
      console.error('Error al obtener detalle del comprobante:', e);
    }
  };

  const listaParaMostrar = useMemo(() => {
    let list = itemsAcumulados;
    if (tipoFilter !== 'todos') {
      list = list.filter((a) => (a.tipo || 'manual').toLowerCase() === tipoFilter.toLowerCase());
    }
    return list;
  }, [itemsAcumulados, tipoFilter]);

  const stats = useMemo(() => {
    const list = itemsAcumulados;
    return {
      total: list.length,
      borrador: list.filter((a) => a.estado === 'borrador').length,
      confirmado: list.filter((a) => a.estado === 'confirmado').length,
      anulado: list.filter((a) => a.estado === 'anulado').length,
    };
  }, [itemsAcumulados]);

  const renderBadgeEstado = (estado: string) => {
    switch ((estado || '').toLowerCase()) {
      case 'confirmado':
        return (
          <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800">
            <CheckCircle2 className="size-3 mr-1 text-emerald-600" /> Confirmado
          </Badge>
        );
      case 'borrador':
        return (
          <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800">
            <Clock className="size-3 mr-1 text-amber-500" /> Borrador
          </Badge>
        );
      case 'anulado':
        return (
          <Badge variant="outline" className="bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800">
            <Ban className="size-3 mr-1 text-rose-500" /> Anulado
          </Badge>
        );
      default:
        return <Badge variant="outline">{estado}</Badge>;
    }
  };

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <FileText className="size-6 text-foreground" />
            Asientos Contables / Libro Diario
          </h1>
          <p className="text-xs text-muted-foreground">
            Gestión e historial institucional de comprobantes contables con control de partida doble.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <Button
            size="sm"
            onClick={() => setIsModalOpen(true)}
            className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-primary text-primary-foreground hover:bg-primary/90"
          >
            <Plus className="size-4" />
            Nuevo Asiento
          </Button>
        </div>
      </div>

      {/* TARJETAS DE RESUMEN (KPIs Identicos a Mis Solicitudes) */}
      <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Borradores
            </CardTitle>
            <Clock className="size-4 text-amber-500" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {isLoading ? <Skeleton className="h-7 w-12" /> : stats.borrador}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">En preparación por contadores</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Confirmados
            </CardTitle>
            <CheckCircle2 className="size-4 text-emerald-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {isLoading ? <Skeleton className="h-7 w-12" /> : stats.confirmado}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Comprobantes oficiales estampados</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Anulados
            </CardTitle>
            <Ban className="size-4 text-rose-500" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {isLoading ? <Skeleton className="h-7 w-12" /> : stats.anulado}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Comprobantes reversados</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Total Registros
            </CardTitle>
            <FileText className="size-4 text-blue-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {isLoading ? <Skeleton className="h-7 w-12" /> : stats.total}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Asientos en el Libro Diario</p>
          </CardContent>
        </Card>
      </div>

      {/* BARRA DE FILTROS (Identica a Mis Solicitudes) */}
      <Card className="border-border/60 bg-card shadow-2xs">
        <CardContent className="p-3 sm:p-4">
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
            <form onSubmit={handleSearchSubmit} className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
              <div className="relative w-full">
                <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar por correlativo AS-..., motivo o concepto..."
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
                <Select value={estadoFilter} onValueChange={(v) => { setEstadoFilter(v); }}>
                  <SelectTrigger className="w-[170px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos los Estados</SelectItem>
                    <SelectItem value="borrador">Borrador</SelectItem>
                    <SelectItem value="confirmado">Confirmado</SelectItem>
                    <SelectItem value="anulado">Anulado</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Tipo:</span>
                <Select value={tipoFilter} onValueChange={(v) => { setTipoFilter(v); }}>
                  <SelectTrigger className="w-[150px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos los Tipos</SelectItem>
                    <SelectItem value="manual">Manual</SelectItem>
                    <SelectItem value="automatico">Automático</SelectItem>
                    <SelectItem value="cierre">Cierre</SelectItem>
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

      {/* TABLA PRINCIPAL DE ASIENTOS (Identica a Mis Solicitudes) */}
      <Card className="border-border/60 shadow-2xs overflow-hidden">
        <Table>
          <TableHeader className="bg-muted/50 dark:bg-muted/30">
            <TableRow>
              <TableHead className="w-40 text-xs font-semibold text-muted-foreground uppercase tracking-wider">CORRELATIVO</TableHead>
              <TableHead className="w-32 text-xs font-semibold text-muted-foreground uppercase tracking-wider">FECHA</TableHead>
              <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">CONCEPTO / GLOSA</TableHead>
              <TableHead className="w-24 text-xs font-semibold text-muted-foreground uppercase tracking-wider">TIPO</TableHead>
              <TableHead className="w-36 text-right text-xs font-semibold text-muted-foreground uppercase tracking-wider">DEBE (VES)</TableHead>
              <TableHead className="w-36 text-right text-xs font-semibold text-muted-foreground uppercase tracking-wider">HABER (VES)</TableHead>
              <TableHead className="w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">ESTADO</TableHead>
              <TableHead className="w-12 text-center"></TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && listaParaMostrar.length === 0 ? (
              Array.from({ length: 5 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell><Skeleton className="h-4 w-28" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-20" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-48" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-24 ml-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-24 ml-auto" /></TableCell>
                  <TableCell><Skeleton className="h-5 w-24" /></TableCell>
                  <TableCell><Skeleton className="h-6 w-6 mx-auto" /></TableCell>
                </TableRow>
              ))
            ) : listaParaMostrar.length === 0 ? (
              <TableRow>
                <TableCell colSpan={8} className="py-16 text-center text-muted-foreground">
                  <div className="flex flex-col items-center gap-2">
                    <FileText className="size-8 text-muted-foreground/40" />
                    <p className="text-sm font-semibold">No se encontraron comprobantes contables</p>
                    <p className="text-xs text-muted-foreground">Pruebe ajustando los filtros de búsqueda o cree un nuevo asiento manual.</p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              listaParaMostrar.map((a) => {
                const fechaLimpia = a.fecha
                  ? a.fecha.split(' ')[0].split('-').reverse().join('/')
                  : 'N/A';

                return (
                  <TableRow key={a.id} className="hover:bg-muted/30 transition-colors">
                    <TableCell className="font-mono font-bold text-xs text-foreground">
                      {a.numero || `AS-${a.id}`}
                    </TableCell>
                    <TableCell className="text-xs font-medium text-muted-foreground whitespace-nowrap">
                      {fechaLimpia}
                    </TableCell>
                    <TableCell className="text-xs font-semibold text-foreground max-w-xs truncate" title={a.concepto}>
                      {a.concepto}
                    </TableCell>
                    <TableCell className="text-xs font-medium text-muted-foreground capitalize">
                      {a.tipo || 'manual'}
                    </TableCell>
                    <TableCell className="text-right font-mono font-bold text-xs text-emerald-600 dark:text-emerald-400">
                      {Number(a.total_debe || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                    </TableCell>
                    <TableCell className="text-right font-mono font-bold text-xs text-blue-600 dark:text-blue-400">
                      {Number(a.total_haber || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                    </TableCell>
                    <TableCell>
                      {renderBadgeEstado(a.estado)}
                    </TableCell>
                    <TableCell className="text-center">
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="h-8 w-8 text-muted-foreground hover:text-foreground">
                            <MoreVertical className="size-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                          <DropdownMenuItem onClick={() => handleVerDetalle(a.id)} className="cursor-pointer text-xs gap-2 py-2 font-medium">
                            <Eye className="size-4 text-blue-600" />
                            <span>Ver Detalle</span>
                          </DropdownMenuItem>

                          {a.estado === 'borrador' && (
                            <DropdownMenuItem onClick={() => handleOpenConfirmar(a)} className="cursor-pointer text-xs gap-2 py-2 font-semibold text-emerald-600 focus:text-emerald-600">
                              <CheckCircle2 className="size-4 text-emerald-600" />
                              <span>Confirmar Asiento</span>
                            </DropdownMenuItem>
                          )}

                          {a.estado === 'confirmado' && (
                            <DropdownMenuItem onClick={() => handleOpenAnular(a)} className="cursor-pointer text-xs gap-2 py-2 font-semibold text-rose-600 focus:text-rose-600">
                              <Ban className="size-4 text-rose-600" />
                              <span>Anular Asiento</span>
                            </DropdownMenuItem>
                          )}
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </Card>

      {/* Botón de Cargar Más (Paginación Keyset O(1) TanStack Query Infinite) */}
      {hasNextPage && (
        <div className="flex justify-center pt-2 pb-4">
          <Button
            variant="outline"
            onClick={handleCargarMas}
            disabled={isFetchingNextPage}
            className="flex items-center gap-2 px-6 shadow-2xs border-primary/20 hover:border-primary text-xs font-semibold"
          >
            {isFetchingNextPage ? 'Cargando más comprobantes...' : 'Cargar más comprobantes'}
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      )}

      {/* MODAL DE NUEVO ASIENTO */}
      <AsientoModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        onSave={crearAsiento}
        cuentas={cuentas}
        isSubmitting={isCreating}
      />

      {/* MODAL DE CONFIRMACIÓN Y ESTAMPADO DE SECUENCIA LEGAL */}
      <AsientoConfirmarModal
        isOpen={isConfirmarOpen}
        onClose={() => {
          setIsConfirmarOpen(false);
          setAsientoToConfirmar(null);
        }}
        onConfirm={async () => {
          if (!asientoToConfirmar) return;
          await confirmarAsiento(asientoToConfirmar.id);
          setAsientoToConfirmar(null);
        }}
        asiento={asientoToConfirmar}
        isSubmitting={isConfirming}
      />

      {/* MODAL DE INSPECCIÓN DE DETALLE */}
      <AsientoDetalleModal
        isOpen={isDetalleOpen}
        onClose={() => {
          setIsDetalleOpen(false);
          setSelectedAsiento(null);
        }}
        asiento={selectedAsiento}
      />

      {/* MODAL DE ANULACIÓN Y CONTRA-ASIENTO */}
      <AsientoAnularModal
        isOpen={isAnularOpen}
        onClose={() => {
          setIsAnularOpen(false);
          setAsientoToAnular(null);
        }}
        onConfirm={async (fechaRev, motivo) => {
          if (!asientoToAnular) return;
          await anularAsiento({ id: asientoToAnular.id, fechaReversion: fechaRev, motivo: motivo });
          setAsientoToAnular(null);
        }}
        asiento={asientoToAnular}
        isSubmitting={isAnulando}
      />
    </div>
  );
};
