import React, { useState, useMemo } from 'react';
import { useQuery, useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { toast } from 'sonner';
import { 
  ArrowLeftRight, Plus, Search, RefreshCw, Edit, Ban, CheckCircle2, 
  AlertTriangle, ListFilter, ArrowRight, Upload, Download, Trash2, ChevronDown, Wrench 
} from 'lucide-react';
import { 
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { 
  matrizConversionService, 
  type MatrizConversion, 
  type TipoOperacion 
} from '@/services/matrizConversion';
import { MatrizDialog } from '@/components/contabilidad/matriz-dialog';
import { MatrizImportDialog } from '@/components/contabilidad/matriz-import-dialog';
import { MatrizDangerModal } from '@/components/contabilidad/matriz-danger-modal';
import { ConfirmActionModal } from '@/components/common/ConfirmActionModal';

export const MatrizListPage: React.FC = () => {
  const queryClient = useQueryClient();

  // Estados
  const [dialogOpen, setDialogOpen] = useState(false);
  const [importDialogOpen, setImportDialogOpen] = useState(false);
  const [vaciarModalOpen, setVaciarModalOpen] = useState(false);
  const [matrizAEditar, setMatrizAEditar] = useState<MatrizConversion | undefined>(undefined);
  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [matrizAToggle, setMatrizAToggle] = useState<MatrizConversion | null>(null);

  const [tipoFilter, setTipoFilter] = useState<string>('');
  const [estadoFilter, setEstadoFilter] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const [activeSearch, setActiveSearch] = useState('');
  const [page, setPage] = useState(1);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setActiveSearch(searchQuery);
    setPage(1);
  };

  // Fetching con TanStack Query Infinite (Estándar Paginación Infinito Cargar Más)
  const {
    data: infiniteData,
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isLoading,
    isFetching,
    refetch,
  } = useInfiniteQuery({
    queryKey: ['matriz-conversion-infinite', tipoFilter, estadoFilter, activeSearch],
    queryFn: ({ pageParam = 1 }) =>
      matrizConversionService.getAll({
        page: pageParam as number,
        limit: 50,
        tipo_operacion: tipoFilter,
        estado: estadoFilter,
        q: activeSearch.trim(),
      }),
    getNextPageParam: (lastPage) => {
      const p = lastPage?.paginacion;
      if (!p) return undefined;
      return p.page < p.pages ? p.page + 1 : undefined;
    },
    initialPageParam: 1,
  });

  const matrizItems = useMemo(() => {
    return infiniteData?.pages.flatMap((page: any) => page?.items || []) ?? [];
  }, [infiniteData]);

  const paginacion = infiniteData?.pages[0]?.paginacion || { total: 0, page: 1, limit: 50, pages: 1 };
  const stats = infiniteData?.pages[0]?.estadisticas;

  // Mutación para Cambiar Estado (Inactivar/Activar)
  const toggleEstadoMutation = useMutation({
    mutationFn: ({ id, estado }: { id: number; estado: 'activa' | 'inactiva' }) =>
      matrizConversionService.toggleEstado(id, estado),
    onSuccess: (_, variables) => {
      toast.success(`Regla de matriz ${variables.estado === 'activa' ? 'activada' : 'inactivada'} exitosamente.`);
      queryClient.invalidateQueries({ queryKey: ['matriz-conversion'] });
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const handleExportarMatriz = () => {
    const url = matrizConversionService.obtenerUrlExportacion();
    window.open(url, '_blank');
  };

  const openNewModal = () => {
    setMatrizAEditar(undefined);
    setDialogOpen(true);
  };

  const openEditModal = (item: MatrizConversion) => {
    setMatrizAEditar(item);
    setDialogOpen(true);
  };

  const renderBadgeTipo = (tipo: string) => {
    const t = (tipo || '').toLowerCase();
    switch (t) {
      case 'gasto':
      case 'pago':
      case 'patrimonial':
      case 'causacion':
        return (
          <Badge className="bg-sky-500 hover:bg-sky-600 text-white border-none text-[10px] font-bold px-2 py-0.5 rounded-md shadow-xs">
            Pago
          </Badge>
        );
      case 'ingreso':
      case 'recaudacion':
        return (
          <Badge className="bg-blue-600 hover:bg-blue-700 text-white border-none text-[10px] font-bold px-2 py-0.5 rounded-md shadow-xs">
            Ingreso
          </Badge>
        );
      default:
        return (
          <Badge className="bg-sky-500 hover:bg-sky-600 text-white border-none text-[10px] font-bold px-2 py-0.5 rounded-md shadow-xs">
            Pago
          </Badge>
        );
    }
  };

  return (
    <div className="space-y-6">
      {/* HEADER PAGE */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <ArrowLeftRight className="size-6 text-foreground" />
            Matriz de Conversión Contable - Presupuestaria
          </h1>
          <p className="text-xs text-muted-foreground">
            Definición de reglas de imputación automática entre clasificadores ONAPRE y asientos del libro diario.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button 
            onClick={openNewModal} 
            className="bg-primary hover:bg-primary/90 text-primary-foreground font-bold shadow-xs text-xs gap-1.5"
          >
            <Plus className="size-4" />
            Nueva Regla de Matriz
          </Button>

          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button 
                variant="outline" 
                size="sm"
                className="gap-1.5 font-semibold text-xs border-border/80 shadow-xs hover:bg-accent hover:text-foreground"
              >
                <Wrench className="size-3.5 text-muted-foreground" />
                Acciones
                <ChevronDown className="size-3.5 text-muted-foreground" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56 border-border shadow-md">
              <DropdownMenuLabel className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                Operaciones Masivas
              </DropdownMenuLabel>
              
              <DropdownMenuItem 
                onClick={() => setImportDialogOpen(true)}
                className="cursor-pointer text-xs gap-2 py-2 font-medium"
              >
                <Upload className="size-4 text-emerald-600 dark:text-emerald-400" />
                <span>Importar desde Excel</span>
              </DropdownMenuItem>

              <DropdownMenuItem 
                onClick={handleExportarMatriz}
                className="cursor-pointer text-xs gap-2 py-2 font-medium"
              >
                <Download className="size-4 text-blue-600 dark:text-blue-400" />
                <span>Exportar Matriz completa</span>
              </DropdownMenuItem>

              <DropdownMenuSeparator />

              <DropdownMenuLabel className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                Mantenimiento de Matriz
              </DropdownMenuLabel>

              <DropdownMenuItem 
                onClick={() => setVaciarModalOpen(true)}
                className="cursor-pointer text-xs gap-2 py-2 text-rose-600 focus:text-rose-600 focus:bg-rose-500/10 font-semibold"
              >
                <Trash2 className="size-4 text-rose-600" />
                <span>Vaciar / Rollback Matriz</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* TARJETAS DE ESTADÍSTICAS */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
          <div className="size-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold">
            <ArrowLeftRight className="size-5" />
          </div>
          <div>
            <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Total Reglas</p>
            <h3 className="text-xl font-bold text-foreground">
              {isLoading ? <Skeleton className="h-6 w-12" /> : (stats?.total_general ?? paginacion.total ?? 0)}
            </h3>
          </div>
        </Card>

        <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
          <div className="size-10 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center font-bold">
            <CheckCircle2 className="size-5" />
          </div>
          <div>
            <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Reglas Activas</p>
            <h3 className="text-xl font-bold text-emerald-600">
              {isLoading ? <Skeleton className="h-6 w-12" /> : (stats?.activas ?? 0)}
            </h3>
          </div>
        </Card>

        <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
          <div className="size-10 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center font-bold">
            <AlertTriangle className="size-5" />
          </div>
          <div>
            <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Reglas de Gasto</p>
            <h3 className="text-xl font-bold text-rose-600">
              {isLoading ? <Skeleton className="h-6 w-12" /> : (stats?.gastos ?? 0)}
            </h3>
          </div>
        </Card>

        <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
          <div className="size-10 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center font-bold">
            <RefreshCw className="size-5" />
          </div>
          <div>
            <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Reglas de Ingreso</p>
            <h3 className="text-xl font-bold text-blue-600">
              {isLoading ? <Skeleton className="h-6 w-12" /> : (stats?.ingresos ?? 0)}
            </h3>
          </div>
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
                  placeholder="Buscar por código ONAPRE, cuenta DEBE o HABER..."
                  className="pl-9 h-9 text-xs bg-background border-input"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
              </div>
              <Button type="submit" variant="secondary" size="sm" className="h-9 text-xs font-semibold">Buscar</Button>
            </form>

            <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Operación:</span>
                <Select value={tipoFilter || 'todos'} onValueChange={(val) => { setTipoFilter(val === 'todos' ? '' : val); setPage(1); }}>
                  <SelectTrigger className="w-[170px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todas las Operaciones</SelectItem>
                    <SelectItem value="pago">Pago</SelectItem>
                    <SelectItem value="compromiso">Compromiso</SelectItem>
                    <SelectItem value="causacion">Causación</SelectItem>
                    <SelectItem value="devengado">Devengado</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Estado:</span>
                <Select value={estadoFilter || 'todos'} onValueChange={(val) => { setEstadoFilter(val === 'todos' ? '' : val); setPage(1); }}>
                  <SelectTrigger className="w-[150px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="todos">Todos los Estados</SelectItem>
                    <SelectItem value="activa">Activas</SelectItem>
                    <SelectItem value="inactiva">Inactivas</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <Button variant="outline" size="icon" className="h-9 w-9" onClick={() => refetch()} title="Recargar">
                <RefreshCw className={`size-4 ${isFetching ? 'animate-spin' : ''}`} />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* TABLA PRINCIPAL DE MATRIZ */}
      <Card className="border-border/60 shadow-xs overflow-hidden">
        <Table className="w-full table-fixed">
          <TableHeader className="bg-muted/50">
            <TableRow className="hover:bg-transparent border-b">
              <TableHead className="w-[12%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Operación</TableHead>
              <TableHead className="w-[32%] text-xs font-semibold text-muted-foreground px-3 py-3">Partida Presupuestaria ONAPRE</TableHead>
              <TableHead className="w-[37%] text-xs font-semibold text-muted-foreground px-3 py-3">Asiento Automático (DEBE ➔ HABER)</TableHead>
              <TableHead className="w-[11%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Estado</TableHead>
              <TableHead className="w-[8%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: 6 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell className="text-center px-2 py-3"><Skeleton className="h-5 w-16 mx-auto rounded" /></TableCell>
                  <TableCell className="px-3 py-3"><Skeleton className="h-5 w-48 rounded" /></TableCell>
                  <TableCell className="px-3 py-3"><Skeleton className="h-5 w-64 rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-3"><Skeleton className="h-5 w-14 mx-auto rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-3"><Skeleton className="h-5 w-10 mx-auto rounded" /></TableCell>
                </TableRow>
              ))
            ) : matrizItems.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center py-12 text-muted-foreground">
                  <div className="flex flex-col items-center justify-center gap-2">
                    <ListFilter className="size-8 text-muted-foreground/50" />
                    <p className="text-sm font-medium">No se encontraron reglas de matriz de conversión.</p>
                    <p className="text-xs text-muted-foreground">Haga clic en "Nueva Regla de Matriz" para registrar una equivalencia.</p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              matrizItems.map((item) => {
                const activa = item.estado === 'activa';
                const debeCod = item.debe_codigo || (item as any).cuenta_debe_codigo || '---';
                const debeNom = item.debe_nombre || (item as any).cuenta_debe_nombre || '';
                const haberCod = item.haber_codigo || (item as any).cuenta_haber_codigo;
                const haberNom = item.haber_nombre || (item as any).cuenta_haber_nombre;
                return (
                  <TableRow key={item.id} className="hover:bg-muted/30 transition-colors border-b border-border/40">
                    <TableCell className="text-center px-2 py-3">
                      {renderBadgeTipo(item.tipo_operacion)}
                    </TableCell>
                    <TableCell className="px-3 py-3">
                      <div className="font-semibold text-xs text-foreground truncate" title={item.partida_nombre}>
                        {item.partida_nombre || 'Partida Desconocida'}
                      </div>
                      <div className="font-mono text-[11px] text-muted-foreground tracking-tight">
                        {item.partida_codigo_completo || item.partida_codigo}
                      </div>
                    </TableCell>
                    <TableCell className="px-3 py-3">
                      <div className="flex items-center gap-2 font-mono text-xs">
                        <span className="font-semibold text-emerald-600 bg-emerald-500/10 px-2 py-0.5 rounded border border-emerald-500/20 truncate" title={`${debeCod} - ${debeNom}`}>
                          [DEBE] {debeCod}
                        </span>
                        <ArrowRight className="size-3 text-muted-foreground flex-shrink-0" />
                        {haberCod ? (
                          <span className="font-semibold text-blue-600 bg-blue-500/10 px-2 py-0.5 rounded border border-blue-500/20 truncate" title={`${haberCod} - ${haberNom}`}>
                            [HABER] {haberCod}
                          </span>
                        ) : (
                          <span className="text-muted-foreground italic text-[11px]">
                            (Haber según Banco/Caja)
                          </span>
                        )}
                      </div>
                    </TableCell>
                    <TableCell className="text-center px-2 py-3">
                      {activa ? (
                        <Badge className="bg-emerald-500/10 text-emerald-600 border-emerald-500/20 text-[10px] py-0 h-5">
                          <CheckCircle2 className="size-3 mr-1" /> Activa
                        </Badge>
                      ) : (
                        <Badge variant="outline" className="bg-rose-500/10 text-rose-600 border-rose-500/20 text-[10px] py-0 h-5">
                          <AlertTriangle className="size-3 mr-1" /> Inactiva
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-center px-2 py-3">
                      <div className="flex items-center justify-center gap-0.5">
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          className="size-6 text-muted-foreground hover:text-primary hover:bg-primary/10" 
                          onClick={() => openEditModal(item)}
                          title="Editar regla"
                        >
                          <Edit className="size-3.5" />
                        </Button>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          className={`size-6 ${activa ? 'text-rose-500 hover:bg-rose-500/10' : 'text-emerald-500 hover:bg-emerald-500/10'}`} 
                          onClick={() => { 
                            setMatrizAToggle(item);
                            setConfirmModalOpen(true);
                          }} 
                          disabled={toggleEstadoMutation.isPending}
                          title={activa ? 'Inactivar regla' : 'Activar regla'}
                        >
                          {activa ? <Ban className="size-3.5" /> : <CheckCircle2 className="size-3.5" />}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>

        {/* PAGINACIÓN INFINITA UNIFICADA (Estándar Cargar Más ERP) */}
        {hasNextPage && (
          <div className="flex justify-center p-4 border-t border-border bg-muted/10">
            <Button
              variant="outline"
              onClick={() => fetchNextPage()}
              disabled={isFetchingNextPage}
              className="flex items-center gap-2 px-6 shadow-2xs border-primary/20 hover:border-primary text-xs font-semibold"
            >
              {isFetchingNextPage ? 'Cargando más reglas...' : 'Cargar más reglas de conversión'}
              <ChevronDown className="h-4 w-4" />
            </Button>
          </div>
        )}
      </Card>

      {/* DIÁLOGO FORMULARIO */}
      <MatrizDialog 
        open={dialogOpen} 
        onOpenChange={setDialogOpen} 
        matrizEdit={matrizAEditar} 
      />

      {/* MODAL DE IMPORTACIÓN */}
      <MatrizImportDialog
        open={importDialogOpen}
        onOpenChange={setImportDialogOpen}
      />

      {/* MODAL DE PELIGRO ENTERPRISE (BATCH ROLLBACK & SAFE DELETE) */}
      <MatrizDangerModal
        open={vaciarModalOpen}
        onOpenChange={setVaciarModalOpen}
      />

      <ConfirmActionModal
        isOpen={confirmModalOpen}
        onClose={() => {
          setConfirmModalOpen(false);
          setMatrizAToggle(null);
        }}
        onConfirm={() => {
          if (matrizAToggle) {
            const activa = matrizAToggle.estado === 'activa';
            toggleEstadoMutation.mutate(
              { id: matrizAToggle.id, estado: activa ? 'inactiva' : 'activa' },
              {
                onSuccess: () => {
                  setConfirmModalOpen(false);
                  setMatrizAToggle(null);
                }
              }
            );
          }
        }}
        title={matrizAToggle ? (matrizAToggle.estado === 'activa' ? 'Inactivar Regla de Conversión' : 'Activar Regla de Conversión') : 'Cambiar Estado'}
        description={matrizAToggle ? `¿Está seguro de que desea ${matrizAToggle.estado === 'activa' ? 'inactivar' : 'activar'} la regla de conversión para la partida "${matrizAToggle.partida_nombre}"?` : ''}
        variant={matrizAToggle && matrizAToggle.estado === 'activa' ? 'anular' : 'despachar'}
        confirmText={matrizAToggle && matrizAToggle.estado === 'activa' ? 'Sí, Inactivar' : 'Sí, Activar'}
        isProcessing={toggleEstadoMutation.isPending}
      />
    </div>
  );
};
