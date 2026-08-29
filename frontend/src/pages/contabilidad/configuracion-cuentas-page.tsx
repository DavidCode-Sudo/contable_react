import React, { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Skeleton } from '@/components/ui/skeleton';
import { 
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter 
} from '@/components/ui/dialog';
import { toast } from 'sonner';
import { 
  Settings, Wrench, RefreshCw, CheckCircle2, AlertTriangle, Edit, Save, X, Search, Layers, Link2, ShieldAlert, Check
} from 'lucide-react';
import { 
  configuracionCuentasService, 
  type ConfiguracionCuenta 
} from '@/services/configuracionCuentas';

interface ConfiguracionCuentasPageProps {
  embedded?: boolean;
}

export const ConfiguracionCuentasPage: React.FC<ConfiguracionCuentasPageProps> = ({ embedded = false }) => {
  const queryClient = useQueryClient();

  const [editingId, setEditingId] = useState<number | null>(null);
  const [editCodigo, setEditCodigo] = useState<string>('');
  const [editDesc, setEditDesc] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState<string>('');
  const [autoHealingModalOpen, setAutoHealingModalOpen] = useState<boolean>(false);

  const { data, isLoading, isFetching, refetch } = useQuery({
    queryKey: ['configuracion-cuentas'],
    queryFn: () => configuracionCuentasService.getAll(),
  });

  const allItems = data?.items || [];
  
  const filteredItems = allItems.filter((item) => {
    if (!searchQuery.trim()) return true;
    const q = searchQuery.toLowerCase();
    return (
      item.concepto.toLowerCase().includes(q) ||
      item.cuenta_codigo.toLowerCase().includes(q) ||
      (item.cuenta_nombre && item.cuenta_nombre.toLowerCase().includes(q)) ||
      (item.descripcion && item.descripcion.toLowerCase().includes(q))
    );
  });

  const totalConceptos = allItems.length;
  const vinculadas = allItems.filter((i) => !!i.cuenta_nombre).length;
  const faltantes = totalConceptos - vinculadas;

  const updateMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: { cuenta_codigo: string; descripcion?: string } }) =>
      configuracionCuentasService.update(id, payload),
    onSuccess: () => {
      toast.success('Configuración de cuenta guardada exitosamente.');
      queryClient.invalidateQueries({ queryKey: ['configuracion-cuentas'] });
      setEditingId(null);
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const autoHealingMutation = useMutation({
    mutationFn: () => configuracionCuentasService.crearFaltantes(),
    onSuccess: (res) => {
      toast.success(res.mensaje);
      queryClient.invalidateQueries({ queryKey: ['configuracion-cuentas'] });
      queryClient.invalidateQueries({ queryKey: ['catalogo-cuentas'] });
    },
    onError: (err: Error) => toast.error(err.message),
  });

  const startEdit = (item: ConfiguracionCuenta) => {
    setEditingId(item.id);
    setEditCodigo(item.cuenta_codigo);
    setEditDesc(item.descripcion || '');
  };

  const cancelEdit = () => {
    setEditingId(null);
  };

  const saveEdit = (id: number) => {
    if (!editCodigo.trim()) {
      toast.error('El código de cuenta no puede estar vacío.');
      return;
    }
    updateMutation.mutate({
      id,
      payload: {
        cuenta_codigo: editCodigo.trim(),
        descripcion: editDesc.trim(),
      },
    });
  };

  const formatConceptoLabel = (concepto: string) => {
    return concepto
      .replace(/^(cuenta_|config_)/i, '')
      .replace(/_/g, ' ')
      .toUpperCase();
  };

  const handleConfirmAutoHealing = () => {
    setAutoHealingModalOpen(false);
    autoHealingMutation.mutate();
  };

  return (
    <div className="space-y-4">
      {/* HEADER PAGE (solamente si no está embebido) */}
      {!embedded && (
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2">
              <Settings className="size-7 text-primary" />
              Configuración de Cuentas del Sistema
            </h1>
            <p className="text-xs text-muted-foreground">
              Asignación de cuentas patrimoniales por defecto para procesos automáticos de caja, bancos y retenciones.
            </p>
          </div>
          <div className="flex items-center gap-2">
            <Button
              onClick={() => setAutoHealingModalOpen(true)}
              disabled={autoHealingMutation.isPending}
              className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold shadow-xs text-xs gap-1.5"
            >
              <Wrench className={`size-4 ${autoHealingMutation.isPending ? 'animate-spin' : ''}`} />
              {autoHealingMutation.isPending ? 'Auto-Sanando...' : 'Crear Cuentas Faltantes (ONCOP)'}
            </Button>
            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              className="size-9 text-muted-foreground"
              title="Recargar"
            >
              <RefreshCw className={`size-4 ${isFetching ? 'animate-spin' : ''}`} />
            </Button>
          </div>
        </div>
      )}

      {/* METRIC CARDS GRID (solamente si no está embebido) */}
      {!embedded && (
        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
            <div className="size-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center font-bold">
              <Layers className="size-5" />
            </div>
            <div>
              <p className="text-[11px] font-medium text-muted-foreground uppercase tracking-wider">Total Conceptos</p>
              <h3 className="text-xl font-bold text-foreground">{isLoading ? <Skeleton className="h-6 w-12" /> : totalConceptos}</h3>
            </div>
          </Card>

          <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
            <div className="size-10 rounded-lg bg-emerald-500/10 text-emerald-600 flex items-center justify-center font-bold">
              <Link2 className="size-5" />
            </div>
            <div>
              <p className="text-[11px] font-medium text-emerald-600 dark:text-emerald-400 uppercase tracking-wider">Cuentas Vinculadas</p>
              <h3 className="text-xl font-bold text-foreground">{isLoading ? <Skeleton className="h-6 w-12" /> : vinculadas}</h3>
            </div>
          </Card>

          <Card className="p-4 border-border/60 shadow-xs flex items-center gap-3">
            <div className="size-10 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center font-bold">
              <ShieldAlert className="size-5" />
            </div>
            <div>
              <p className="text-[11px] font-medium text-rose-600 dark:text-rose-400 uppercase tracking-wider">Cuentas Faltantes</p>
              <h3 className="text-xl font-bold text-foreground">{isLoading ? <Skeleton className="h-6 w-12" /> : faltantes}</h3>
            </div>
          </Card>
        </div>
      )}

      {/* BARRA DE FILTROS Y BÚSQUEDA */}
      <Card className="p-4 border-border/60 shadow-xs">
        <div className="flex flex-col sm:flex-row gap-3 items-center justify-between">
          <div className="relative w-full sm:w-80">
            <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
            <Input
              placeholder="Buscar por concepto o código de cuenta..."
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="pl-9 h-9 text-xs bg-background"
            />
          </div>

          <div className="flex items-center gap-2">
            {searchQuery && (
              <Button
                variant="outline"
                size="sm"
                onClick={() => setSearchQuery('')}
                className="h-9 text-xs"
              >
                Limpiar búsqueda
              </Button>
            )}

            {embedded && (
              <>
                <Button
                  onClick={() => setAutoHealingModalOpen(true)}
                  disabled={autoHealingMutation.isPending}
                  className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold shadow-xs text-xs gap-1.5 h-9"
                >
                  <Wrench className={`size-3.5 ${autoHealingMutation.isPending ? 'animate-spin' : ''}`} />
                  {autoHealingMutation.isPending ? 'Auto-Sanando...' : 'Crear Cuentas Faltantes (ONCOP)'}
                </Button>
                <Button
                  variant="outline"
                  size="icon"
                  onClick={() => refetch()}
                  className="size-9 text-muted-foreground"
                  title="Recargar"
                >
                  <RefreshCw className={`size-3.5 ${isFetching ? 'animate-spin' : ''}`} />
                </Button>
              </>
            )}
          </div>
        </div>
      </Card>

      {/* TABLA PRINCIPAL DE CONFIGURACIÓN */}
      <Card className="border-border/60 shadow-xs overflow-hidden">
        <Table className="w-full table-fixed">
          <TableHeader className="bg-muted/50">
            <TableRow className="hover:bg-transparent border-b">
              <TableHead className="w-[28%] text-xs font-semibold text-muted-foreground px-3 py-3">Concepto del Sistema</TableHead>
              <TableHead className="w-[22%] text-xs font-semibold text-muted-foreground px-3 py-3">Código de Cuenta</TableHead>
              <TableHead className="w-[32%] text-xs font-semibold text-muted-foreground px-3 py-3">Cuenta Patrimonial Resuelta</TableHead>
              <TableHead className="w-[10%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Estado</TableHead>
              <TableHead className="w-[8%] text-center text-xs font-semibold text-muted-foreground px-2 py-3">Acciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: 5 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell className="px-3 py-3"><Skeleton className="h-5 w-40 rounded" /></TableCell>
                  <TableCell className="px-3 py-3"><Skeleton className="h-5 w-28 rounded" /></TableCell>
                  <TableCell className="px-3 py-3"><Skeleton className="h-5 w-56 rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-3"><Skeleton className="h-5 w-14 mx-auto rounded" /></TableCell>
                  <TableCell className="text-center px-2 py-3"><Skeleton className="h-5 w-10 mx-auto rounded" /></TableCell>
                </TableRow>
              ))
            ) : filteredItems.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center py-12 text-muted-foreground">
                  {searchQuery ? 'No se encontraron conceptos que coincidan con la búsqueda.' : 'No se encontraron configuraciones de cuentas de sistema.'}
                </TableCell>
              </TableRow>
            ) : (
              filteredItems.map((item) => {
                const isEditingThis = editingId === item.id;
                const tieneCuentaValida = !!item.cuenta_nombre;

                return (
                  <TableRow key={item.id} className="hover:bg-muted/30 transition-colors border-b border-border/40">
                    <TableCell className="px-3 py-3">
                      <div className="font-semibold text-xs text-foreground tracking-tight">
                        {formatConceptoLabel(item.concepto)}
                      </div>
                      <div className="font-mono text-[10px] text-muted-foreground tracking-tighter truncate" title={item.concepto}>
                        clave: {item.concepto}
                      </div>
                      <div className="text-[11px] text-muted-foreground truncate mt-0.5" title={item.descripcion}>
                        {item.descripcion || 'Sin descripción'}
                      </div>
                    </TableCell>
                    <TableCell className="px-3 py-3 font-mono text-xs">
                      {isEditingThis ? (
                        <Input
                          value={editCodigo}
                          onChange={(e) => setEditCodigo(e.target.value)}
                          className="h-8 text-xs font-mono bg-background"
                        />
                      ) : (
                        <span className="font-semibold text-primary">{item.cuenta_codigo}</span>
                      )}
                    </TableCell>
                    <TableCell className="px-3 py-3 text-xs">
                      {tieneCuentaValida ? (
                        <div className="truncate text-foreground font-medium" title={item.cuenta_nombre}>
                          {item.cuenta_nombre}
                        </div>
                      ) : (
                        <span className="text-rose-600 dark:text-rose-400 font-medium italic flex items-center gap-1 text-[11px]">
                          <AlertTriangle className="size-3" /> No existe en catálogo (Presione Auto-Sanar)
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="text-center px-2 py-3">
                      {tieneCuentaValida ? (
                        <Badge className="bg-emerald-500/10 text-emerald-600 border-emerald-500/20 text-[10px] py-0 h-5">
                          <CheckCircle2 className="size-3 mr-1" /> Vinculada
                        </Badge>
                      ) : (
                        <Badge variant="outline" className="bg-rose-500/10 text-rose-600 border-rose-500/20 text-[10px] py-0 h-5">
                          Faltante
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="text-center px-2 py-3">
                      {isEditingThis ? (
                        <div className="flex items-center justify-center gap-1">
                          <Button
                            size="icon"
                            variant="ghost"
                            className="size-7 text-emerald-600 hover:bg-emerald-500/10"
                            onClick={() => saveEdit(item.id)}
                            disabled={updateMutation.isPending}
                          >
                            <Save className="size-3.5" />
                          </Button>
                          <Button
                            size="icon"
                            variant="ghost"
                            className="size-7 text-muted-foreground hover:bg-muted"
                            onClick={cancelEdit}
                          >
                            <X className="size-3.5" />
                          </Button>
                        </div>
                      ) : (
                        <Button
                          size="icon"
                          variant="ghost"
                          className="size-7 text-muted-foreground hover:text-primary hover:bg-primary/10"
                          onClick={() => startEdit(item)}
                        >
                          <Edit className="size-3.5" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })
            )}
          </TableBody>
        </Table>
      </Card>

      {/* MODAL EXPLICATIVO DE AUTO-SANACIÓN */}
      <Dialog open={autoHealingModalOpen} onOpenChange={setAutoHealingModalOpen}>
        <DialogContent className="max-w-lg border-border shadow-lg">
          <DialogHeader className="pb-3 border-b border-border/60">
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-foreground">
              <div className="size-8 rounded-md bg-primary/10 text-primary flex items-center justify-center">
                <Wrench className="size-4" />
              </div>
              Auto-Sanación de Cuentas del Sistema (ONCOP)
            </DialogTitle>
            <DialogDescription className="text-xs text-muted-foreground">
              Proceso automático de verificación y auto-generación de cuentas patrimoniales por defecto.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2 text-xs">
            <div className="p-3 bg-primary/5 border border-primary/20 rounded-lg space-y-1.5 text-foreground">
              <h4 className="font-bold flex items-center gap-1.5 text-xs text-primary">
                <ShieldAlert className="size-4" />
                ¿Qué realiza esta acción en el sistema?
              </h4>
              <p className="text-[11px] leading-relaxed text-muted-foreground">
                Este asistente inspecciona la tabla de <strong>Configuración de Cuentas del Sistema</strong> (Caja, Bancos, Retenciones ISLR/IVA/SSO/FAOV y Nómina) y la compara contra el Catálogo de Cuentas oficial.
              </p>
            </div>

            <div className="space-y-2.5">
              <h4 className="font-semibold text-foreground text-xs">Pasos que ejecutará la rutina:</h4>
              <ul className="space-y-2 text-muted-foreground text-[11px]">
                <li className="flex items-start gap-2">
                  <div className="size-4 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[10px] mt-0.5 shrink-0">1</div>
                  <span><strong>Identificación de Vacíos:</strong> Detecta aquellas cuentas del sistema que no existen en el Catálogo de Cuentas.</span>
                </li>
                <li className="flex items-start gap-2">
                  <div className="size-4 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[10px] mt-0.5 shrink-0">2</div>
                  <span><strong>Creación Segura:</strong> Genera automáticamente las cuentas patrimoniales faltantes con su tipo (Activo, Pasivo, Gasto) y naturaleza contable recomendada.</span>
                </li>
                <li className="flex items-start gap-2">
                  <div className="size-4 rounded-full bg-primary/10 text-primary flex items-center justify-center font-bold text-[10px] mt-0.5 shrink-0">3</div>
                  <span><strong>Garantía de No Sobreescritura:</strong> Las cuentas que ya existen y están vinculadas se respetan íntegramente.</span>
                </li>
              </ul>
            </div>

            <div className="p-3 bg-muted/40 border border-border/60 rounded-lg flex items-center justify-between text-[11px]">
              <span className="text-muted-foreground font-medium">Estado Actual:</span>
              <span className="font-bold text-foreground">
                {vinculadas} de {totalConceptos} Cuentas Vinculadas ({faltantes} faltantes)
              </span>
            </div>
          </div>

          <DialogFooter className="border-t border-border/60 pt-3 mt-2">
            <Button 
              variant="outline" 
              type="button" 
              size="sm" 
              onClick={() => setAutoHealingModalOpen(false)} 
              className="h-9 text-xs"
            >
              Cancelar
            </Button>
            <Button 
              type="button" 
              size="sm" 
              onClick={handleConfirmAutoHealing}
              disabled={autoHealingMutation.isPending}
              className="bg-primary hover:bg-primary/90 text-primary-foreground font-semibold h-9 text-xs px-4 gap-1.5"
            >
              {autoHealingMutation.isPending ? <Wrench className="size-3.5 animate-spin" /> : <Check className="size-3.5" />}
              Iniciar Auto-Sanación
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
};
