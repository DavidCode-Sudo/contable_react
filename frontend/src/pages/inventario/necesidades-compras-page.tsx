import React, { useState, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { toast } from 'sonner';
import {
  ShoppingCart,
  ArrowLeft,
  RefreshCw,
  Package,
  Building2,
  FileCheck,
  CheckCircle2,
  Boxes,
  FileText,
  Search,
} from 'lucide-react';
import {
  solicitudesInternasService,
  type NecesidadProcuraItem,
} from '@/services/solicitudesInternas';

export const NecesidadesComprasPage: React.FC = () => {
  const navigate = useNavigate();
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [searchQuery, setSearchQuery] = useState<string>('');

  // TanStack Query for Server State Management
  const {
    data: necesidadesData,
    isLoading: loading,
    refetch,
  } = useQuery({
    queryKey: ['necesidades-compras'],
    queryFn: () => solicitudesInternasService.getNecesidadesCompras(),
    retry: false,
    staleTime: 1000 * 15,
  });

  const necesidades: NecesidadProcuraItem[] = necesidadesData?.necesidades || [];

  const necesidadesFiltradas = useMemo(() => {
    if (!searchQuery.trim()) return necesidades;
    const q = searchQuery.toLowerCase();
    return necesidades.filter(
      (item) =>
        item.producto_nombre?.toLowerCase().includes(q) ||
        item.producto_codigo?.toLowerCase().includes(q)
    );
  }, [necesidades, searchQuery]);

  // KPI Calculations
  const totalRenglones = necesidades.length;
  const totalSolicitudesAfectadas = necesidades.reduce((acc, n) => acc + n.total_solicitudes_afectadas, 0);
  const totalDepartamentosAfectados = Math.max(0, ...necesidades.map((n) => n.total_departamentos_solicitantes), necesidades.length > 0 ? 1 : 0);

  const handleToggleSelect = (prodId: number) => {
    if (selectedIds.includes(prodId)) {
      setSelectedIds(selectedIds.filter((id) => id !== prodId));
    } else {
      setSelectedIds([...selectedIds, prodId]);
    }
  };

  const handleToggleSelectAll = () => {
    if (selectedIds.length === necesidades.length) {
      setSelectedIds([]);
    } else {
      setSelectedIds(necesidades.map((n) => n.producto_id));
    }
  };

  const handleCrearRequisicion = () => {
    if (selectedIds.length === 0) {
      toast.warning('Seleccione al menos un producto consolidado.');
      return;
    }
    toast.info(`Generando Requisición Consolidada de Compras para ${selectedIds.length} renglones...`);
    navigate('/inventario/requisiciones/nueva');
  };

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-3">
          <Button
            variant="outline"
            size="icon"
            onClick={() => navigate('/inventario/solicitudes-internas')}
            className="size-9 shrink-0"
          >
            <ArrowLeft className="size-4" />
          </Button>
          <div>
            <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
              <ShoppingCart className="size-6 text-foreground" />
              Cola de Procura / Compras
            </h1>
            <p className="text-xs text-muted-foreground">
              Demanda insatisfecha originada en solicitudes internas departamentales sin stock.
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            size="sm"
            onClick={handleCrearRequisicion}
            disabled={selectedIds.length === 0}
            className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-primary text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
          >
            <FileCheck className="size-4" />
            Generar Requisición Consolidada ({selectedIds.length})
          </Button>
        </div>
      </div>

      {/* BARRA DE FILTROS (Identica a Asientos Contables) */}
      <Card className="border-border/60 bg-card shadow-2xs">
        <CardContent className="p-3 sm:p-4">
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
            <div className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
              <div className="relative w-full">
                <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar por código o descripción del insumo..."
                  className="pl-9 h-9 text-xs bg-background border-input font-mono"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
              <Button
                variant="outline"
                size="icon"
                className="h-9 w-9"
                onClick={() => refetch()}
                title="Recargar"
              >
                <RefreshCw className={`size-4 ${loading ? 'animate-spin' : ''}`} />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* TARJETAS DE RESUMEN (KPIs) */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Renglones Requeridos
            </CardTitle>
            <Boxes className="size-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : totalRenglones}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Insumos distintos sin stock disponible</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Solicitudes Afectadas
            </CardTitle>
            <FileText className="size-4 text-blue-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : totalSolicitudesAfectadas}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Expedientes SI pendientes de compra</p>
          </CardContent>
        </Card>

        <Card className="border-border/60 shadow-2xs hover:shadow-xs transition-shadow">
          <CardHeader className="flex flex-row items-center justify-between pb-2">
            <CardTitle className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
              Departamentos Afectados
            </CardTitle>
            <Building2 className="size-4 text-emerald-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-black text-foreground">
              {loading ? <Skeleton className="h-7 w-12" /> : totalDepartamentosAfectados}
            </div>
            <p className="text-[11px] text-muted-foreground mt-1">Dependencias institucionales en espera</p>
          </CardContent>
        </Card>
      </div>

      {/* TABLA PRINCIPAL DE PROCURA */}
      <Card className="border-border/60 shadow-2xs overflow-hidden">
        <CardHeader className="bg-muted/40 dark:bg-muted/20 border-b border-border/60 py-3">
          <CardTitle className="text-xs font-semibold text-foreground flex items-center justify-between">
            <div className="flex items-center gap-2">
              <Package className="size-4 text-muted-foreground" />
              <span>Insumos Agrupados Pendientes por Adquisición ({necesidades.length})</span>
            </div>
            {selectedIds.length > 0 && (
              <Badge variant="outline" className="bg-muted/60 text-foreground border-border font-mono text-[11px]">
                {selectedIds.length} seleccionado(s)
              </Badge>
            )}
          </CardTitle>
        </CardHeader>
        <Table>
          <TableHeader className="bg-muted/50 dark:bg-muted/30">
            <TableRow>
              <TableHead className="w-12 text-center">
                <input
                  type="checkbox"
                  checked={necesidades.length > 0 && selectedIds.length === necesidades.length}
                  onChange={handleToggleSelectAll}
                  className="rounded border-input text-primary focus:ring-ring cursor-pointer"
                />
              </TableHead>
              <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Código & Producto</TableHead>
              <TableHead className="text-right w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Stock Actual</TableHead>
              <TableHead className="text-right w-44 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Cant. Requerida Total</TableHead>
              <TableHead className="text-center w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Solicitudes Afectadas</TableHead>
              <TableHead className="text-center w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Deptos. Afectados</TableHead>
              <TableHead className="w-32 text-center text-xs font-semibold text-muted-foreground uppercase tracking-wider">Estado Cola</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {loading ? (
              Array.from({ length: 4 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell className="text-center"><Skeleton className="size-4 mx-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-40" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-20 ml-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-24 ml-auto" /></TableCell>
                  <TableCell><Skeleton className="h-5 w-24 mx-auto" /></TableCell>
                  <TableCell><Skeleton className="h-4 w-20 mx-auto" /></TableCell>
                  <TableCell><Skeleton className="h-5 w-20 mx-auto" /></TableCell>
                </TableRow>
              ))
            ) : necesidadesFiltradas.length === 0 ? (
              <TableRow>
                <TableCell colSpan={7} className="py-16 text-center text-muted-foreground">
                  <div className="flex flex-col items-center gap-2">
                    <CheckCircle2 className="size-8 text-emerald-600/60" />
                    <p className="text-sm font-semibold text-foreground">No existen insumos pendientes en la Cola de Procura</p>
                    <p className="text-xs text-muted-foreground">Todas las solicitudes departamentales han sido cubiertas o no coinciden con la búsqueda.</p>
                  </div>
                </TableCell>
              </TableRow>
            ) : (
              necesidadesFiltradas.map((n) => {
                const isSelected = selectedIds.includes(n.producto_id);
                return (
                  <TableRow
                    key={n.producto_id}
                    className={`hover:bg-muted/40 cursor-pointer transition-colors ${
                      isSelected ? 'bg-muted/60 dark:bg-muted/40' : ''
                    }`}
                    onClick={() => handleToggleSelect(n.producto_id)}
                  >
                    <TableCell className="text-center" onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        checked={isSelected}
                        onChange={() => handleToggleSelect(n.producto_id)}
                        className="rounded border-input text-primary focus:ring-ring cursor-pointer"
                      />
                    </TableCell>
                    <TableCell>
                      <div className="font-medium text-foreground text-sm">{n.producto_nombre}</div>
                      <div className="text-xs font-mono text-muted-foreground">{n.producto_codigo}</div>
                    </TableCell>
                    <TableCell className="text-right font-mono text-xs text-muted-foreground">
                      {n.producto_stock_actual} {n.producto_unidad}
                    </TableCell>
                    <TableCell className="text-right font-mono font-bold text-foreground text-sm">
                      {n.total_cantidad_requerida.toLocaleString('es-VE')} {n.producto_unidad}
                    </TableCell>
                    <TableCell className="text-center font-mono text-xs font-semibold">
                      <Badge variant="outline" className="bg-muted/50 text-foreground">
                        {n.total_solicitudes_afectadas} Solicitud(es)
                      </Badge>
                    </TableCell>
                    <TableCell className="text-center">
                      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <Building2 className="size-3.5 text-muted-foreground/60" />
                        {n.total_departamentos_solicitantes} Depto(s)
                      </span>
                    </TableCell>
                    <TableCell className="text-center">
                      <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800 text-[10px]">
                        <CheckCircle2 className="size-3 mr-1 text-amber-600" /> Pendiente
                      </Badge>
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </Card>
    </div>
  );
};
