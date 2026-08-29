import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
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
  Folder, FolderOpen, FileText, File, Circle, 
  Calculator, FileSpreadsheet, Plus, Search, RefreshCw, 
  Edit, Ban, CheckCircle2, AlertTriangle, Filter, Layers, ListFilter, Settings
} from 'lucide-react';
import { catalogoCuentasService, type VistaCatalogo, type CuentaContable } from '@/services/catalogoCuentas';
import { CatalogoDialog } from '@/components/inventario/catalogo-dialog';
import { CatalogoImportDialog } from '@/components/contabilidad/catalogo-import-dialog';
import { CatalogoDangerModal } from '@/components/contabilidad/catalogo-danger-modal';
import { ConfirmActionModal } from '@/components/common/ConfirmActionModal';
import { ConfiguracionCuentasPage } from '@/pages/contabilidad/configuracion-cuentas-page';
import { 
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Trash2, Upload, Download, ChevronDown, Wrench } from 'lucide-react';

export const CatalogoListPage: React.FC = () => {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  // Estados
  const [dialogOpen, setDialogOpen] = useState(false);
  const [importDialogOpen, setImportDialogOpen] = useState(false);
  const [dangerModalOpen, setDangerModalOpen] = useState(false);
  const [cuentaAEditar, setCuentaAEditar] = useState<CuentaContable | undefined>(undefined);
  const [confirmModalOpen, setConfirmModalOpen] = useState(false);
  const [cuentaAToggle, setCuentaAToggle] = useState<CuentaContable | null>(null);
  
  const [vistaActiva, setVistaActiva] = useState<VistaCatalogo>('presupuestarias');
  const [estadoFilter, setEstadoFilter] = useState<string>('');
  const [tipoFilter, setTipoFilter] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState('');
  const [activeSearch, setActiveSearch] = useState('');
  const [page, setPage] = useState(1);

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setActiveSearch(searchQuery);
    setPage(1);
  };

  // Fetch API
  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['catalogo-cuentas', vistaActiva, page, estadoFilter, tipoFilter, activeSearch],
    queryFn: () => catalogoCuentasService.getAll({
      vista: vistaActiva,
      page,
      limit: 100, // Cargar en bloques optimizados de 100 registros para velocidad ultra alta
      estado: estadoFilter,
      tipo: tipoFilter,
      q: activeSearch.trim()
    }),
  });

  const cuentas = data?.cuentas || [];
  const stats = data?.estadisticas;
  const paginacion = data?.paginacion || { total: 0, page: 1, limit: 100, pages: 1 };

  const toggleEstadoMutation = useMutation({
    mutationFn: ({ id, estado }: { id: number; estado: 'activa' | 'inactiva' }) => 
      catalogoCuentasService.toggleEstado(id, estado),
    onSuccess: (_, variables) => {
      toast.success(`Cuenta ${variables.estado === 'activa' ? 'activada' : 'desactivada'} exitosamente.`);
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
    },
    onError: (err: Error) => toast.error(err.message)
  });

  const handleCreateInventario = useMutation({
    mutationFn: () => catalogoCuentasService.crearInventario(),
    onSuccess: () => {
      toast.success('Cuenta 1102 de Inventario creada exitosamente.');
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
    },
    onError: (err: Error) => toast.error(err.message)
  });

  const openNewModal = () => {
    setCuentaAEditar(undefined);
    setDialogOpen(true);
  };

  const openEditModal = (cuenta: CuentaContable) => {
    setCuentaAEditar(cuenta);
    setDialogOpen(true);
  };

  const resetFilters = () => {
    setSearchQuery('');
    setActiveSearch('');
    setEstadoFilter('');
    setTipoFilter('');
    setPage(1);
  };

  // Iconos jerárquicos
  const renderIconoJerarquia = (c: CuentaContable) => {
    if (c.es_partida_presupuestaria) {
      if (c.nivel_indentacion === 0) return <Folder className="size-4 text-amber-500" />;
      if (c.nivel_indentacion === 1) return <FolderOpen className="size-4 text-blue-500" />;
      if (c.nivel_indentacion === 2) return <FileText className="size-4 text-slate-400" />;
      return <File className="size-3.5 text-slate-400" />;
    }
    if (c.nivel_indentacion === 0) return <Folder className="size-4 text-primary" />;
    if (c.nivel_indentacion === 1) return <FolderOpen className="size-4 text-indigo-400" />;
    return <Circle className="size-2.5 text-slate-400" />;
  };

  const renderBadgeNivel = (c: CuentaContable) => {
    if (c.es_partida_presupuestaria) {
      const label = c.nivel_partida_calculado?.toUpperCase() || 'ESP';
      const badgeStyle = label === 'PARTIDA' 
        ? 'bg-amber-500/10 text-amber-600 border-amber-500/20' 
        : label === 'GENERICA' 
        ? 'bg-blue-500/10 text-blue-600 border-blue-500/20' 
        : 'bg-muted text-muted-foreground border-border/60';
      return (
        <Badge variant="outline" className={`text-[10px] font-mono font-semibold min-w-[54px] justify-center ${badgeStyle}`}>
          {label === 'SUBESPECIFICA' ? 'SUB' : label.substring(0, 4)}
        </Badge>
      );
    }
    const label = c.tipo_clasificacion?.toUpperCase() || 'CUENTA';
    const badgeStyle = label === 'GRUPO' 
      ? 'bg-primary/10 text-primary border-primary/20' 
      : label === 'SUBGRUPO' 
      ? 'bg-blue-500/10 text-blue-600 border-blue-500/20' 
      : 'bg-muted text-muted-foreground border-border/60';
    return (
      <Badge variant="outline" className={`text-[10px] font-mono font-semibold min-w-[65px] justify-center ${badgeStyle}`}>
        {label}
      </Badge>
    );
  };

  return (
    <div className="space-y-6">
      {/* CABECERA PRINCIPAL CON ACCIONES */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <Calculator className="size-6 text-foreground" />
            Catálogo de Cuentas y Partidas
          </h1>
          <p className="text-xs text-muted-foreground">
            Gestión del clasificador presupuestario ONAPRE y plan de cuentas patrimonial del sistema.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button 
            size="sm"
            className="gap-2 font-bold shadow-xs text-xs bg-primary hover:bg-primary/90 text-primary-foreground" 
            onClick={openNewModal}
          >
            <Plus className="size-4" /> 
            Nueva Cuenta / Partida
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
                onClick={() => window.open(catalogoCuentasService.getExportUrl(vistaActiva), '_blank')}
                className="cursor-pointer text-xs gap-2 py-2 font-medium"
              >
                <Download className="size-4 text-blue-600 dark:text-blue-400" />
                <span>
                  {vistaActiva === 'presupuestarias' 
                    ? 'Exportar Partidas ONAPRE' 
                    : vistaActiva === 'contables' 
                    ? 'Exportar Cuentas ONCOP' 
                    : 'Exportar Catálogo'}
                </span>
              </DropdownMenuItem>

              <DropdownMenuSeparator />

              <DropdownMenuLabel className="text-[10px] font-bold tracking-wider text-muted-foreground uppercase">
                Mantenimiento de Catálogo
              </DropdownMenuLabel>

              <DropdownMenuItem 
                onClick={() => setDangerModalOpen(true)}
                className="cursor-pointer text-xs gap-2 py-2 text-rose-600 focus:text-rose-600 focus:bg-rose-500/10 font-semibold"
              >
                <Trash2 className="size-4 text-rose-600" />
                <span>Vaciar / Rollback Catálogo</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* METRICAS Y METRIC CARDS */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card 
          className={`border-border/60 shadow-xs cursor-pointer transition-all ${vistaActiva === 'presupuestarias' ? 'ring-2 ring-blue-500/40 border-blue-500' : 'hover:border-blue-500/50'}`}
          onClick={() => { setVistaActiva('presupuestarias'); setPage(1); }}
        >
          <CardContent className="p-4 flex items-center justify-between">
            <div className="space-y-1">
              <p className="text-xs font-semibold text-blue-600 dark:text-blue-400">Partidas ONAPRE</p>
              <p className="text-2xl font-bold tracking-tight text-foreground">{stats?.total_presupuestarias || 0}</p>
              <p className="text-[11px] text-muted-foreground">{stats?.activas_presupuestarias || 0} activas</p>
            </div>
            <div className="size-10 rounded-lg bg-blue-500/10 flex items-center justify-center text-blue-500">
              <FileSpreadsheet className="size-5" />
            </div>
          </CardContent>
        </Card>

        <Card 
          className={`border-border/60 shadow-xs cursor-pointer transition-all ${vistaActiva === 'contables' ? 'ring-2 ring-primary/40 border-primary' : 'hover:border-primary/50'}`}
          onClick={() => { setVistaActiva('contables'); setPage(1); }}
        >
          <CardContent className="p-4 flex items-center justify-between">
            <div className="space-y-1">
              <p className="text-xs font-semibold text-primary">Cuentas Patrimoniales</p>
              <p className="text-2xl font-bold tracking-tight text-foreground">{stats?.total_contables || 0}</p>
              <p className="text-[11px] text-muted-foreground">{stats?.activas_contables || 0} activas</p>
            </div>
            <div className="size-10 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
              <Calculator className="size-5" />
            </div>
          </CardContent>
        </Card>

        <Card 
          className={`border-border/60 shadow-xs cursor-pointer transition-all ${vistaActiva === 'configuracion' ? 'ring-2 ring-primary/40 border-primary' : 'hover:border-primary/50'}`}
          onClick={() => setVistaActiva('configuracion')}
        >
          <CardContent className="p-4 flex items-center justify-between">
            <div className="space-y-1">
              <p className="text-xs font-semibold text-primary">Configuración del Sistema</p>
              <p className="text-2xl font-bold tracking-tight text-foreground">14</p>
              <p className="text-[11px] text-muted-foreground">Cuentas automáticas ONCOP</p>
            </div>
            <div className="size-10 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
              <Settings className="size-5" />
            </div>
          </CardContent>
        </Card>
      </div>

      {/* PESTAÑAS NAVEGADORAS DE MÓDULO */}
      <Card className="border-border/60 shadow-xs">
        <CardContent className="p-3">
          <div className="flex border-b border-border/60 gap-2 pb-2">
            <button
              className={`flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg transition-colors ${
                vistaActiva === 'presupuestarias' 
                  ? 'bg-primary text-primary-foreground shadow-xs' 
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
              }`}
              onClick={() => { setVistaActiva('presupuestarias'); setPage(1); }}
            >
              <FileSpreadsheet className="size-4" /> 
              Partidas Presupuestarias
            </button>
            <button
              className={`flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg transition-colors ${
                vistaActiva === 'contables' 
                  ? 'bg-primary text-primary-foreground shadow-xs' 
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
              }`}
              onClick={() => { setVistaActiva('contables'); setPage(1); }}
            >
              <Calculator className="size-4" /> 
              Cuentas Contables
            </button>
            <button
              className={`flex items-center gap-2 px-4 py-2 text-xs font-semibold rounded-lg transition-colors ${
                vistaActiva === 'configuracion' 
                  ? 'bg-primary text-primary-foreground shadow-xs' 
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground'
              }`}
              onClick={() => setVistaActiva('configuracion')}
            >
              <Settings className="size-4" /> 
              Configuración de Cuentas (ONCOP)
            </button>
          </div>
        </CardContent>
      </Card>

      {vistaActiva === 'configuracion' ? (
        <ConfiguracionCuentasPage embedded={true} />
      ) : (
        <>
          {/* BARRA DE FILTROS (Identica a Asientos Contables) */}
          <Card className="border-border/60 bg-card shadow-2xs">
            <CardContent className="p-3 sm:p-4">
              <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
                <form onSubmit={handleSearchSubmit} className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
                  <div className="relative w-full">
                    <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                    <Input
                      placeholder="Ej: 4.01, 1.1.01, Banco, Materiales..."
                      className="pl-9 h-9 text-xs bg-background border-input font-mono"
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                    />
                  </div>
                  <Button type="submit" variant="secondary" size="sm" className="h-9 text-xs font-semibold">Buscar</Button>
                </form>

                <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
                  {vistaActiva === 'contables' && (
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-semibold text-muted-foreground">Tipo:</span>
                      <Select
                        value={tipoFilter === '' ? 'all' : tipoFilter}
                        onValueChange={(val) => { setTipoFilter(val === 'all' ? '' : val); setPage(1); }}
                      >
                        <SelectTrigger className="w-[160px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                        <SelectContent>
                          <SelectItem value="all">Todos los Tipos</SelectItem>
                          <SelectItem value="activo">Activo</SelectItem>
                          <SelectItem value="pasivo">Pasivo</SelectItem>
                          <SelectItem value="patrimonio">Patrimonio</SelectItem>
                          <SelectItem value="ingreso">Ingreso</SelectItem>
                          <SelectItem value="gasto">Gasto</SelectItem>
                          <SelectItem value="orden">Orden</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>
                  )}

                  <div className="flex items-center gap-2">
                    <span className="text-xs font-semibold text-muted-foreground">Estado:</span>
                    <Select
                      value={estadoFilter === '' ? 'all' : estadoFilter}
                      onValueChange={(val) => { setEstadoFilter(val === 'all' ? '' : val); setPage(1); }}
                    >
                      <SelectTrigger className="w-[150px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                      <SelectContent>
                        <SelectItem value="all">Todos los Estados</SelectItem>
                        <SelectItem value="activa">Activas</SelectItem>
                        <SelectItem value="inactiva">Inactivas</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <Button variant="outline" size="icon" className="h-9 w-9" onClick={resetFilters} title="Recargar">
                    <RefreshCw className={`size-4 ${isFetching ? 'animate-spin' : ''}`} />
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>

      {/* TABLA PRINCIPAL DE CUENTAS */}
      <Card className="border-border/60 shadow-xs overflow-hidden">
        <Table className="w-full table-fixed">
          <TableHeader className="bg-muted/50">
            <TableRow className="hover:bg-transparent border-b">
              <TableHead className="w-[9%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Nivel</TableHead>
              <TableHead className="w-[39%] text-xs font-semibold text-muted-foreground px-3 py-3">Código y Denominación</TableHead>
              <TableHead className="w-[12%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Clasificación</TableHead>
              <TableHead className="w-[11%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Naturaleza</TableHead>
              <TableHead className="w-[14%] text-right text-xs font-semibold text-muted-foreground px-3 py-3">Saldo Actual</TableHead>
              <TableHead className="w-[9%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Estado</TableHead>
              <TableHead className="w-[6%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: 8 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell className="text-center px-2 py-2.5"><Skeleton className="h-5 w-10 mx-auto rounded" /></TableCell>
                  <TableCell className="px-3 py-2.5"><Skeleton className="h-5 w-48 rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-2.5"><Skeleton className="h-5 w-16 mx-auto rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-2.5"><Skeleton className="h-5 w-14 mx-auto rounded" /></TableCell>
                  <TableCell className="text-right px-3 py-2.5"><Skeleton className="h-5 w-20 ml-auto rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-2.5"><Skeleton className="h-5 w-14 mx-auto rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-2.5"><Skeleton className="h-5 w-10 mx-auto rounded" /></TableCell>
                </TableRow>
              ))
            ) : cuentas.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="text-center py-12 text-muted-foreground">
                  <div className="flex flex-col items-center justify-center gap-2">
                    <ListFilter className="size-8 text-muted-foreground/50" />
                    <p className="text-sm font-medium">No se encontraron partidas o cuentas contables.</p>
                    <p className="text-xs text-muted-foreground">Intente ajustar los filtros de búsqueda o intente recargar la vista.</p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              cuentas.map((c) => {
                const activa = String(c.estado).toLowerCase().trim() === 'activa' || String(c.estado).toLowerCase().trim() === 'activo' || c.estado === '1' || (c.estado as any) === 1 || !c.estado;
                return (
                  <TableRow key={c.id} className="hover:bg-muted/30 transition-colors border-b border-border/40">
                    <TableCell className="text-center px-2 py-2.5">{renderBadgeNivel(c)}</TableCell>
                    <TableCell className="px-3 py-2.5">
                      <div className="flex items-center gap-2" style={{ paddingLeft: `${Math.min(c.nivel_indentacion, 4) * 12}px` }}>
                        <div className={`flex-shrink-0 size-6 rounded border flex items-center justify-center ${
                          c.es_partida_presupuestaria ? 'bg-muted/60 border-border/80' : 'bg-primary/5 border-primary/20'
                        }`}>
                          {renderIconoJerarquia(c)}
                        </div>
                        <div className="min-w-0 flex-1">
                          <div className="font-semibold text-xs text-foreground truncate" title={c.nombre}>{c.nombre}</div>
                          <div className="font-mono text-[11px] text-muted-foreground tracking-tight">
                            {c.codigo_display || c.codigo_completo || c.codigo}
                          </div>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="text-center px-2 py-2.5">
                      <Badge variant="outline" className="text-[10px] uppercase font-bold py-0 h-4 shadow-none border border-primary/20 bg-primary/5 text-primary">
                        {c.tipo || 'General'}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-center px-2 py-2.5">
                      <Badge variant="outline" className="text-[10px] capitalize font-medium py-0 h-4 border-border/80">
                        {c.naturaleza}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs font-semibold text-foreground px-3 py-2.5 whitespace-nowrap">
                      {c.es_partida_presupuestaria && c.nivel_indentacion === 0 ? (
                        <Badge variant="secondary" className="text-[10px]">Partida Principal</Badge>
                      ) : (
                        `Bs. ${(c.saldo_actual || 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}`
                      )}
                    </TableCell>
                    <TableCell className="text-center px-2 py-2.5 whitespace-nowrap">
                      {activa ? (
                        <Badge className="bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20 shadow-none hover:bg-emerald-500/20 text-[10px] py-0 h-5">
                          <CheckCircle2 className="size-3 mr-1" /> Activa
                        </Badge>
                      ) : (
                        <Badge variant="outline" className="bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20 text-[10px] py-0 h-5">
                          <AlertTriangle className="size-3 mr-1" /> Inactiva
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-center px-2 py-2.5">
                      <div className="flex items-center justify-center gap-0.5">
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          className="size-6 text-muted-foreground hover:text-primary hover:bg-primary/10" 
                          onClick={() => openEditModal(c)}
                          title="Editar cuenta / partida"
                        >
                          <Edit className="size-3.5" />
                        </Button>
                        <Button 
                          variant="ghost" 
                          size="icon" 
                          className={`size-6 ${activa ? 'text-rose-500 hover:bg-rose-500/10' : 'text-emerald-500 hover:bg-emerald-500/10'}`} 
                          onClick={() => { 
                            setCuentaAToggle(c);
                            setConfirmModalOpen(true);
                          }} 
                          disabled={toggleEstadoMutation.isPending}
                          title={activa ? 'Desactivar cuenta' : 'Activar cuenta'}
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

        {/* PAGINACIÓN RESPONSIVA */}
        {paginacion.pages > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-border bg-muted/20 text-xs">
            <p className="text-muted-foreground">
              Página <span className="font-semibold text-foreground">{paginacion.page}</span> de <span className="font-semibold text-foreground">{paginacion.pages}</span> (Total {paginacion.total} registros)
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="sm"
                className="h-8 text-xs gap-1"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1 || isFetching}
              >
                Anterior
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="h-8 text-xs gap-1"
                onClick={() => setPage((p) => Math.min(paginacion.pages, p + 1))}
                disabled={page >= paginacion.pages || isFetching}
              >
                Siguiente
              </Button>
            </div>
          </div>
        )}
      </Card>
      </>
      )}

      <CatalogoDialog 
        open={dialogOpen} 
        onOpenChange={setDialogOpen} 
        cuentaEdit={cuentaAEditar} 
        cuentasPadre={cuentas.filter(c => c.estado === 'activa')} 
      />

      <CatalogoImportDialog
        open={importDialogOpen}
        onOpenChange={setImportDialogOpen}
        defaultDominio={vistaActiva === 'presupuestarias' ? 'presupuestario' : vistaActiva === 'contables' ? 'patrimonial' : 'auto'}
      />

      <CatalogoDangerModal
        open={dangerModalOpen}
        onOpenChange={setDangerModalOpen}
      />

      <ConfirmActionModal
        isOpen={confirmModalOpen}
        onClose={() => {
          setConfirmModalOpen(false);
          setCuentaAToggle(null);
        }}
        onConfirm={() => {
          if (cuentaAToggle) {
            const activa = String(cuentaAToggle.estado).toLowerCase().trim() === 'activa' || String(cuentaAToggle.estado).toLowerCase().trim() === 'activo' || cuentaAToggle.estado === '1' || (cuentaAToggle.estado as any) === 1 || !cuentaAToggle.estado;
            toggleEstadoMutation.mutate(
              { id: cuentaAToggle.id, estado: activa ? 'inactiva' : 'activa' },
              {
                onSuccess: () => {
                  setConfirmModalOpen(false);
                  setCuentaAToggle(null);
                }
              }
            );
          }
        }}
        title={cuentaAToggle ? (String(cuentaAToggle.estado).toLowerCase().trim() === 'activa' || String(cuentaAToggle.estado).toLowerCase().trim() === 'activo' || cuentaAToggle.estado === '1' || (cuentaAToggle.estado as any) === 1 || !cuentaAToggle.estado ? 'Desactivar Cuenta / Partida' : 'Activar Cuenta / Partida') : 'Cambiar Estado'}
        description={cuentaAToggle ? `¿Está seguro de que desea ${String(cuentaAToggle.estado).toLowerCase().trim() === 'activa' || String(cuentaAToggle.estado).toLowerCase().trim() === 'activo' || cuentaAToggle.estado === '1' || (cuentaAToggle.estado as any) === 1 || !cuentaAToggle.estado ? 'desactivar' : 'activar'} la cuenta "${cuentaAToggle.nombre}" (${cuentaAToggle.codigo_display || cuentaAToggle.codigo_completo || cuentaAToggle.codigo})?` : ''}
        variant={cuentaAToggle && (String(cuentaAToggle.estado).toLowerCase().trim() === 'activa' || String(cuentaAToggle.estado).toLowerCase().trim() === 'activo' || cuentaAToggle.estado === '1' || (cuentaAToggle.estado as any) === 1 || !cuentaAToggle.estado) ? 'anular' : 'despachar'}
        confirmText={cuentaAToggle && (String(cuentaAToggle.estado).toLowerCase().trim() === 'activa' || String(cuentaAToggle.estado).toLowerCase().trim() === 'activo' || cuentaAToggle.estado === '1' || (cuentaAToggle.estado as any) === 1 || !cuentaAToggle.estado) ? 'Sí, Desactivar' : 'Sí, Activar'}
        isProcessing={toggleEstadoMutation.isPending}
      />
    </div>
  );
};
