import React, { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import { toast } from 'sonner';
import { ArrowLeft, FileText, Building2, User, Calendar, CheckCircle2, XCircle, Ban, Clock, AlertCircle, ShoppingCart, Printer, History, Send, RotateCcw, Info } from 'lucide-react';
import { solicitudesInternasService, type EstadoSolicitudInterna } from '@/services/solicitudesInternas';

export const SolicitudInternaDetailPage: React.FC = () => {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const solicitudId = parseInt(id || '0', 10);

  // Send & Retract Modal States
  const [sendConfirmModalOpen, setSendConfirmModalOpen] = useState(false);
  const [retractConfirmModalOpen, setRetractConfirmModalOpen] = useState(false);

  // Approval Modal State
  const [approvalModalOpen, setApprovalModalOpen] = useState(false);
  const [cantidadesAprobadas, setCantidadesAprobadas] = useState<Record<number, string>>({});
  const [obsAprobacion, setObsAprobacion] = useState('');

  // Reject Modal State
  const [rejectModalOpen, setRejectModalOpen] = useState(false);
  const [motivoRechazo, setMotivoRechazo] = useState('');

  // Annul Modal State
  const [annulModalOpen, setAnnulModalOpen] = useState(false);
  const [motivoAnulacion, setMotivoAnulacion] = useState('');

  // TanStack Query: Fetch Detail
  const { data: solicitud, isLoading: loading, isError } = useQuery({
    queryKey: ['solicitud-interna-detail', solicitudId],
    queryFn: async () => {
      const res = await solicitudesInternasService.getById(solicitudId);
      const initialMap: Record<number, string> = {};
      if (res && Array.isArray(res.items)) {
        res.items.forEach((it) => {
          initialMap[it.id] = it.cantidad_solicitada.toString();
        });
      }
      setCantidadesAprobadas(initialMap);
      return res;
    },
    enabled: solicitudId > 0,
    retry: false,
  });

  // TanStack Mutations
  const enviarMutation = useMutation({
    mutationFn: (idNum: number) => solicitudesInternasService.enviar(idNum),
    onSuccess: () => {
      toast.success('Solicitud enviada a revisión exitosamente.');
      setSendConfirmModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['solicitud-interna-detail', solicitudId] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al enviar la solicitud.');
    },
  });

  const retractarMutation = useMutation({
    mutationFn: (idNum: number) => solicitudesInternasService.retractar(idNum),
    onSuccess: () => {
      toast.success('Solicitud retractada a borrador correctamente.');
      setRetractConfirmModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['solicitud-interna-detail', solicitudId] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al retractar la solicitud.');
    },
  });

  const anularMutation = useMutation({
    mutationFn: (payload: { idNum: number; obs: string }) => solicitudesInternasService.anular(payload.idNum, payload.obs),
    onSuccess: () => {
      toast.success('Solicitud anulada exitosamente.');
      setAnnulModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['solicitud-interna-detail', solicitudId] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al anular la solicitud.');
    },
  });

  const aprobarMutation = useMutation({
    mutationFn: (payload: { idNum: number; obs: string; itemsMap: Record<number, number> }) =>
      solicitudesInternasService.aprobar(payload.idNum, {
        observaciones: payload.obs,
        items: payload.itemsMap,
      }),
    onSuccess: (res) => {
      toast.success(res.orden_entrega_numero ? `Solicitud procesada. ODE ${res.orden_entrega_numero}.` : `Solicitud derivada a Compras.`);
      setApprovalModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['solicitud-interna-detail', solicitudId] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al procesar la aprobación.');
    },
  });

  const rechazarMutation = useMutation({
    mutationFn: (payload: { idNum: number; obs: string }) => solicitudesInternasService.rechazar(payload.idNum, payload.obs),
    onSuccess: () => {
      toast.success('Solicitud rechazada correctamente.');
      setRejectModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['solicitud-interna-detail', solicitudId] });
    },
    onError: (err: Error) => {
      toast.error(err.message || 'Error al rechazar la solicitud.');
    },
  });

  const handleOpenApprovalModal = () => {
    if (!solicitud) return;
    const map: Record<number, string> = {};
    solicitud.items.forEach((it) => {
      const cantSol = Number(it.cantidad_solicitada);
      const cantYaAprob = Number(it.cantidad_aprobada || 0);
      const saldoPend = Math.max(0, cantSol - cantYaAprob);
      const stockDisp = typeof it.producto_stock_disponible === 'number' ? Math.max(0, Number(it.producto_stock_disponible)) : saldoPend;
      const sugerido = Math.min(saldoPend, stockDisp);
      map[it.id] = sugerido.toString();
    });
    setCantidadesAprobadas(map);
    setObsAprobacion('');
    setApprovalModalOpen(true);
  };

  const handleConfirmApproval = () => {
    if (!solicitud) return;
    const parsedMap: Record<number, number> = {};
    Object.keys(cantidadesAprobadas).forEach((keyStr) => {
      const key = Number(keyStr);
      parsedMap[key] = parseFloat(cantidadesAprobadas[key]) || 0;
    });

    aprobarMutation.mutate({
      idNum: solicitud.id,
      obs: obsAprobacion.trim(),
      itemsMap: parsedMap,
    });
  };

  const getStatusBadge = (estado: EstadoSolicitudInterna) => {
    const estadoClean = (estado || '').trim().toLowerCase();
    switch (estadoClean) {
      case 'borrador':
        return <Badge variant="outline" className="bg-muted/60 text-muted-foreground border-border"><Clock className="size-3.5 mr-1.5" /> Borrador</Badge>;
      case 'enviada':
        return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800"><Send className="size-3.5 mr-1.5 text-blue-500" /> Enviada (En Revisión)</Badge>;
      case 'convertida':
      case 'aprobada':
        return <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800"><CheckCircle2 className="size-3.5 mr-1.5 text-emerald-600" /> Convertida (ODE)</Badge>;
      case 'procesada_parcial':
        return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800"><CheckCircle2 className="size-3.5 mr-1.5 text-blue-600" /> Procesada Parcial</Badge>;
      case 'derivada_compras':
        return <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800"><ShoppingCart className="size-3.5 mr-1.5 text-amber-600" /> Derivada a Compras</Badge>;
      case 'rechazada':
        return <Badge variant="outline" className="bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800"><AlertCircle className="size-3.5 mr-1.5 text-rose-600" /> Rechazada</Badge>;
      case 'anulada':
        return <Badge variant="outline" className="bg-muted text-muted-foreground border-border"><Ban className="size-3.5 mr-1.5" /> Anulada</Badge>;
      default:
        if (solicitud?.orden_entrega_id) {
          return <Badge variant="outline" className="bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800"><CheckCircle2 className="size-3.5 mr-1.5 text-blue-600" /> Procesada Parcial</Badge>;
        }
        return <Badge variant="outline">{estado || 'Enviada'}</Badge>;
    }
  };

  if (loading) return <div className="p-12 text-center text-muted-foreground">Cargando expediente...</div>;
  if (isError || !solicitud) return <div className="p-12 text-center text-destructive font-semibold">Solicitud no encontrada.</div>;

  const estadoActual = (solicitud.estado || '').trim().toLowerCase();
  const tieneODE = Boolean(solicitud.orden_entrega_id);
  const esProcesadaParcial = estadoActual === 'procesada_parcial' || (tieneODE && estadoActual !== 'convertida' && estadoActual !== 'anulada');
  const esDerivadaCompras = estadoActual === 'derivada_compras';
  const esBorrador = estadoActual === 'borrador';
  const esEnviada = estadoActual === 'enviada' && !tieneODE;

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-border/60 pb-5">
        <div className="flex items-center gap-3">
          <Button variant="outline" size="icon" onClick={() => navigate('/inventario/solicitudes-internas')} className="size-9 shrink-0">
            <ArrowLeft className="size-4" />
          </Button>
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-2xl font-mono font-extrabold tracking-tight text-foreground">{solicitud.numero_solicitud}</h1>
              {getStatusBadge(solicitud.estado)}
            </div>
            <p className="text-xs text-muted-foreground mt-1">Expediente Institucional • Año Fiscal {solicitud.anio}</p>
          </div>
        </div>

        {/* BOTONES DE ACCIÓN */}
        <div className="flex flex-wrap items-center gap-2">
          {esBorrador && (
            <Button onClick={() => setSendConfirmModalOpen(true)} disabled={enviarMutation.isPending} size="sm" className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-primary text-primary-foreground hover:bg-primary/90">
              <Send className="size-4" /> Enviar a Aprobación
            </Button>
          )}

          {esEnviada && (
            <>
              <Button variant="outline" onClick={() => setRetractConfirmModalOpen(true)} disabled={retractarMutation.isPending} size="sm" className="h-9 gap-1.5 text-xs font-medium border-amber-300 text-amber-700 hover:bg-amber-50">
                <RotateCcw className="size-4" /> Retractar a Borrador
              </Button>
              <Button variant="outline" onClick={() => setRejectModalOpen(true)} size="sm" className="h-9 gap-1.5 text-xs font-medium border-rose-300 text-rose-700 hover:bg-rose-50">
                <XCircle className="size-4" /> Rechazar
              </Button>
              <Button onClick={handleOpenApprovalModal} size="sm" className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-emerald-600 hover:bg-emerald-700 text-white">
                <CheckCircle2 className="size-4" /> Aprobar / Procesar
              </Button>
            </>
          )}

          {(esProcesadaParcial || esDerivadaCompras) && (
            <>
              {esProcesadaParcial && (
                <Button onClick={handleOpenApprovalModal} size="sm" className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-emerald-600 hover:bg-emerald-700 text-white">
                  <CheckCircle2 className="size-4" /> Emitir Entrega Complementaria (ODE)
                </Button>
              )}
              <Button onClick={() => navigate('/inventario/necesidades-compras')} size="sm" className="h-9 gap-1.5 text-xs font-bold shadow-2xs bg-amber-600 hover:bg-amber-700 text-white">
                <ShoppingCart className="size-4" /> Ver en Cola de Procura
              </Button>
            </>
          )}

          {!['anulada', 'rechazada'].includes(solicitud.estado) && (
            <Button variant="ghost" onClick={() => setAnnulModalOpen(true)} disabled={anularMutation.isPending} size="sm" className="h-9 text-xs text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/30 gap-1.5">
              <Ban className="size-4" /> Anular
            </Button>
          )}

          <Button variant="outline" size="sm" onClick={() => window.print()} className="h-9 gap-1.5 text-xs font-medium">
            <Printer className="size-4" /> Imprimir
          </Button>
        </div>
      </div>

      {/* OVERVIEW CARDS */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card className="md:col-span-2 border-border/60 shadow-2xs">
          <CardHeader className="pb-3 border-b border-border/60">
            <CardTitle className="text-sm font-semibold text-foreground flex items-center gap-2">
              <FileText className="size-4 text-muted-foreground" /> Datos de la Solicitud
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-4 grid grid-cols-2 gap-4 text-sm">
            <div>
              <span className="text-xs text-muted-foreground block mb-0.5">Departamento Solicitante</span>
              <div className="font-semibold text-foreground flex items-center gap-1.5">
                <Building2 className="size-4 text-muted-foreground" /> {solicitud.departamento_nombre}
              </div>
            </div>
            <div>
              <span className="text-xs text-muted-foreground block mb-0.5">Solicitado Por</span>
              <div className="font-semibold text-foreground flex items-center gap-1.5">
                <User className="size-4 text-muted-foreground" /> {solicitud.solicitante_nombre}
              </div>
            </div>
            <div>
              <span className="text-xs text-muted-foreground block mb-0.5">Fecha de Emisión</span>
              <div className="font-mono text-foreground text-xs flex items-center gap-1.5">
                <Calendar className="size-4 text-muted-foreground" /> {(() => {
                  if (!solicitud.fecha_solicitud) return 'N/A';
                  const match = solicitud.fecha_solicitud.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/);
                  if (match) {
                    const [_, year, month, day, hours, minutes, seconds] = match;
                    if (!hours) return `${day}/${month}/${year}`;
                    const h = parseInt(hours, 10);
                    const ampm = h >= 12 ? 'p. m.' : 'a. m.';
                    const h12 = h % 12 || 12;
                    return `${parseInt(day, 10)}/${parseInt(month, 10)}/${year}, ${h12}:${minutes}:${seconds || '00'} ${ampm}`;
                  }
                  return solicitud.fecha_solicitud;
                })()}
              </div>
            </div>
            <div>
              <span className="text-xs text-muted-foreground block mb-0.5">Prioridad</span>
              <Badge variant="outline" className="capitalize font-semibold">{solicitud.prioridad}</Badge>
            </div>
            <div className="col-span-2 pt-2 border-t border-border/60">
              <span className="text-xs text-muted-foreground block mb-1">Motivo / Justificación</span>
              <p className="text-foreground bg-muted/40 p-3 rounded border border-border/60 text-xs leading-relaxed">
                {solicitud.justificacion}
              </p>
            </div>
          </CardContent>
        </Card>

        {/* STATUS & ODE INFO */}
        <Card className="border-border/60 shadow-2xs">
          <CardHeader className="pb-3 border-b border-border/60">
            <CardTitle className="text-sm font-semibold text-foreground flex items-center gap-2">
              <CheckCircle2 className="size-4 text-emerald-600" /> Trazabilidad ODE
            </CardTitle>
          </CardHeader>
          <CardContent className="pt-4 space-y-4 text-sm">
            {Array.isArray((solicitud as any).ordenes_entrega_vinculadas) && (solicitud as any).ordenes_entrega_vinculadas.length > 0 ? (
              <div className="space-y-2">
                <span className="text-xs text-emerald-700 font-semibold block">Órdenes de Entrega Vinculadas ({(solicitud as any).ordenes_entrega_vinculadas.length})</span>
                {(solicitud as any).ordenes_entrega_vinculadas.map((ode: any) => (
                  <div key={ode.id} className="bg-emerald-50 dark:bg-emerald-950/40 p-2.5 rounded-lg border border-emerald-200 dark:border-emerald-800 flex items-center justify-between">
                    <div>
                      <div className="font-mono font-bold text-sm text-emerald-800 dark:text-emerald-300">{ode.numero_orden}</div>
                      <div className="text-[11px] text-emerald-600">Estado: <strong className="capitalize">{ode.estado}</strong></div>
                    </div>
                    <Button variant="link" size="sm" className="p-0 h-auto text-emerald-700 underline text-xs font-bold" onClick={() => navigate(`/inventario/ordenes-entrega/${ode.id}`)}>
                      Ver ODE →
                    </Button>
                  </div>
                ))}
              </div>
            ) : solicitud.orden_entrega_numero ? (
              <div className="bg-emerald-50 dark:bg-emerald-950/40 p-3 rounded-lg border border-emerald-200 dark:border-emerald-800 space-y-2">
                <span className="text-xs text-emerald-700 font-semibold block">Orden de Entrega Vinculada</span>
                <div className="font-mono font-bold text-lg text-emerald-800 dark:text-emerald-300">{solicitud.orden_entrega_numero}</div>
                <div className="text-xs text-emerald-600 flex items-center justify-between">
                  <span>Estado ODE: <strong className="capitalize">{solicitud.orden_entrega_estado}</strong></span>
                  <Button variant="link" size="sm" className="p-0 h-auto text-emerald-700 underline text-xs" onClick={() => navigate(`/inventario/ordenes-entrega/${solicitud.orden_entrega_id}`)}>
                    Ver ODE →
                  </Button>
                </div>
              </div>
            ) : (
              <div className="p-3 bg-muted/40 rounded border border-border/60 text-xs text-muted-foreground">
                Esta solicitud aún no ha generado Orden de Entrega.
              </div>
            )}

            {(solicitud.estado === 'procesada_parcial' || solicitud.estado === 'derivada_compras') && (
              <div className="bg-amber-50 dark:bg-amber-950/40 p-3 rounded-lg border border-amber-200 dark:border-amber-800 space-y-2">
                <span className="text-xs text-amber-800 dark:text-amber-300 font-semibold block flex items-center gap-1.5">
                  <ShoppingCart className="size-3.5" /> Requerimientos en Cola de Procura
                </span>
                <p className="text-xs text-amber-700 dark:text-amber-400">
                  Las cantidades remanentes no cubiertas por Almacén fueron derivadas a Compras para generar su Requisición de Adquisición.
                </p>
                <Button variant="link" size="sm" className="p-0 h-auto text-amber-800 dark:text-amber-300 font-bold underline text-xs" onClick={() => navigate('/inventario/necesidades-compras')}>
                  Ver en Cola de Procura →
                </Button>
              </div>
            )}

            {solicitud.usuario_aprobador_id && (
              <div className="text-xs space-y-1 pt-2 border-t border-border/60">
                <span className="text-muted-foreground block">Procesado por:</span>
                <div className="font-semibold text-foreground">{solicitud.aprobador_nombre}</div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* TABLA DE INSUMOS */}
      <Card className="border-border/60 shadow-2xs overflow-hidden">
        <CardHeader className="pb-3 border-b border-border/60 bg-muted/40">
          <CardTitle className="text-sm font-semibold text-foreground">Ítems e Insumos Solicitados ({solicitud.items.length})</CardTitle>
        </CardHeader>
        <Table>
          <TableHeader className="bg-muted/50 dark:bg-muted/30">
            <TableRow>
              <TableHead className="w-12 text-xs font-semibold text-muted-foreground uppercase tracking-wider">#</TableHead>
              <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Código & Insumo</TableHead>
              <TableHead className="text-right w-32 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Cant. Solicitada</TableHead>
              <TableHead className="text-right w-32 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Cant. Entregada</TableHead>
              <TableHead className="text-right w-32 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Pendiente</TableHead>
              <TableHead className="text-center w-36 text-xs font-semibold text-muted-foreground uppercase tracking-wider">Estado Ítem</TableHead>
              <TableHead className="text-xs font-semibold text-muted-foreground uppercase tracking-wider">Observaciones</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {solicitud.items.map((it, idx) => {
              const cantSol = Number(it.cantidad_solicitada);
              const cantAprob = Number(it.cantidad_aprobada || 0);
              const pend = Math.max(0, cantSol - cantAprob);

              return (
                <TableRow key={it.id}>
                  <TableCell className="font-mono text-xs text-muted-foreground">{idx + 1}</TableCell>
                  <TableCell>
                    <div className="font-medium text-foreground text-sm">{it.producto_nombre}</div>
                    <div className="text-xs font-mono text-muted-foreground">{it.producto_codigo}</div>
                  </TableCell>
                  <TableCell className="text-right font-mono font-semibold text-foreground text-xs">{cantSol} {it.producto_unidad}</TableCell>
                  <TableCell className="text-right font-mono font-bold text-emerald-600 text-xs">{cantAprob} {it.producto_unidad}</TableCell>
                  <TableCell className="text-right font-mono text-xs">
                    {pend > 0 ? (
                      <Badge variant="outline" className="bg-amber-50 text-amber-800 border-amber-300 dark:bg-amber-950/40 dark:text-amber-300 font-mono">
                        {pend} {it.producto_unidad}
                      </Badge>
                    ) : (
                      <span className="text-emerald-600 font-semibold">0 {it.producto_unidad}</span>
                    )}
                  </TableCell>
                  <TableCell className="text-center"><Badge variant="outline">{it.estado_item}</Badge></TableCell>
                  <TableCell className="text-xs text-muted-foreground">{it.observaciones || '-'}</TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </Card>

      {/* HISTORIAL DE AUDITORÍA */}
      <Card className="border-border/60 shadow-2xs">
        <CardHeader className="pb-3 border-b border-border/60">
          <CardTitle className="text-sm font-semibold text-foreground flex items-center gap-2">
            <History className="size-4 text-muted-foreground" /> Línea de Tiempo y Auditoría CGR
          </CardTitle>
        </CardHeader>
        <CardContent className="pt-4">
          <div className="space-y-4">
            {solicitud.historial.map((h) => (
              <div key={h.id} className="flex gap-4 items-start text-xs border-l-2 border-border pl-4 py-1">
                <div className="space-y-0.5">
                  <div className="flex items-center gap-2">
                    <span className="font-semibold text-foreground uppercase font-mono">{h.accion}</span>
                    <Badge variant="outline" className="text-[10px] py-0">{h.estado_anterior || 'INICIO'} → {h.estado_nuevo}</Badge>
                    <span className="text-muted-foreground font-mono text-[10px]">{new Date(h.created_at).toLocaleString('es-VE')}</span>
                  </div>
                  <div className="text-muted-foreground">Usuario: <strong>{h.usuario_nombre || `ID #${h.usuario_id}`}</strong> • IP: {h.ip_address}</div>
                  {h.observaciones && <p className="text-muted-foreground italic mt-1 bg-muted/40 p-2 rounded border border-border/60">{h.observaciones}</p>}
                </div>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>

      {/* MODAL DE CONFIRMACIÓN DE ENVÍO */}
      <Dialog open={sendConfirmModalOpen} onOpenChange={setSendConfirmModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-primary">
              <Send className="size-5" /> Enviar Solicitud a Aprobación
            </DialogTitle>
            <DialogDescription>
              ¿Está seguro de enviar la solicitud <strong className="font-mono text-foreground">{solicitud.numero_solicitud}</strong> a revisión formal por la administración?
            </DialogDescription>
          </DialogHeader>
          <div className="p-3 bg-muted/40 rounded border border-border/60 text-xs text-muted-foreground">
            Una vez enviada, la solicitud pasará a revisión de Almacén para su evaluación y procesamiento.
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setSendConfirmModalOpen(false)}>Cancelar</Button>
            <Button
              className="bg-primary text-primary-foreground hover:bg-primary/90 font-bold"
              onClick={() => enviarMutation.mutate(solicitud.id)}
              disabled={enviarMutation.isPending}
            >
              {enviarMutation.isPending ? 'Enviando...' : 'Confirmar Envío'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* MODAL DE CONFIRMACIÓN DE RETRACTACIÓN */}
      <Dialog open={retractConfirmModalOpen} onOpenChange={setRetractConfirmModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-amber-600">
              <RotateCcw className="size-5" /> Retractar Solicitud a Borrador
            </DialogTitle>
            <DialogDescription>
              ¿Desea devolver la solicitud <strong className="font-mono text-foreground">{solicitud.numero_solicitud}</strong> a estado Borrador para hacer modificaciones?
            </DialogDescription>
          </DialogHeader>
          <div className="p-3 bg-amber-50 dark:bg-amber-950/30 rounded border border-amber-200 dark:border-amber-800 text-xs text-amber-800 dark:text-amber-300">
            La solicitud regresar a su estado Borrador y podrá agregar, eliminar o ajustar los ítems requeridos.
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRetractConfirmModalOpen(false)}>Cancelar</Button>
            <Button
              className="bg-amber-600 text-white hover:bg-amber-700 font-bold"
              onClick={() => retractarMutation.mutate(solicitud.id)}
              disabled={retractarMutation.isPending}
            >
              {retractarMutation.isPending ? 'Procesando...' : 'Confirmar Retractación'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* MODAL DE APROBACIÓN CUANTITATIVA */}
      <Dialog open={approvalModalOpen} onOpenChange={setApprovalModalOpen}>
        <DialogContent className="max-w-4xl">
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2">
              <CheckCircle2 className="size-5 text-emerald-600" /> Aprobación Cuantitativa Parcial
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="p-3 bg-blue-50 dark:bg-blue-950/40 rounded border border-blue-200 dark:border-blue-800 text-xs text-blue-800 dark:text-blue-300 leading-relaxed flex items-start gap-2">
              <Info className="size-4 text-blue-600 dark:text-blue-400 shrink-0 mt-0.5" />
              <div>
                <strong>Regla Institucional de Entrega y Procura:</strong> La cantidad en <em>Cant. a Aprobar (ODE)</em> es la que se enviará para despacho inmediato de Almacén. <u>Cualquier saldo no entregado (ej. si colocas 0 o menos del pendiente) permanecerá derivado a la Cola de Procura / Compras</u>.
              </div>
            </div>

            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Insumo</TableHead>
                  <TableHead className="text-right">Solicitada</TableHead>
                  <TableHead className="text-right">Entregada Prev.</TableHead>
                  <TableHead className="text-right">Pendiente</TableHead>
                  <TableHead className="text-right w-44">Cant. a Aprobar (ODE)</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {solicitud.items.map((it) => {
                  const cantSol = Number(it.cantidad_solicitada);
                  const cantYaAprob = Number(it.cantidad_aprobada || 0);
                  const saldoPend = Math.max(0, cantSol - cantYaAprob);

                  return (
                    <TableRow key={it.id}>
                      <TableCell>
                        <div className="font-medium text-sm">{it.producto_nombre}</div>
                        <div className="text-xs text-muted-foreground font-mono flex items-center gap-2">
                          <span>{it.producto_codigo}</span>
                          {typeof it.producto_stock_disponible === 'number' && (
                            <span className={`px-1.5 py-0.5 rounded text-[10px] font-bold ${it.producto_stock_disponible > 0 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300' : 'bg-rose-50 text-rose-700 border border-rose-200 dark:bg-rose-950/60 dark:text-rose-300'}`}>
                              Stock Almacén: {it.producto_stock_disponible} {it.producto_unidad}
                            </span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-right font-mono text-sm">{cantSol} {it.producto_unidad}</TableCell>
                      <TableCell className="text-right font-mono text-sm text-emerald-600 font-semibold">{cantYaAprob} {it.producto_unidad}</TableCell>
                      <TableCell className="text-right font-mono text-sm">
                        <Badge variant="outline" className="bg-amber-50 text-amber-800 border-amber-300 dark:bg-amber-950/40 dark:text-amber-300 font-mono font-bold">
                          {saldoPend} {it.producto_unidad}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-right">
                        <Input
                          type="number"
                          step={it.permite_decimales ? '0.001' : '1'}
                          min="0"
                          max={saldoPend}
                          value={cantidadesAprobadas[it.id] ?? ''}
                          onChange={(e) => setCantidadesAprobadas({ ...cantidadesAprobadas, [it.id]: e.target.value })}
                          className="w-32 text-right font-mono font-bold text-foreground ml-auto bg-background"
                        />
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
            <div className="space-y-1.5">
              <Label className="text-xs font-semibold">Observaciones / Notas de Aprobación</Label>
              <Textarea placeholder="Observaciones formales..." value={obsAprobacion} onChange={(e) => setObsAprobacion(e.target.value)} rows={2} />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setApprovalModalOpen(false)}>Cancelar</Button>
            <Button onClick={handleConfirmApproval} className="bg-emerald-600 text-white hover:bg-emerald-700">Confirmar Aprobación</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* MODAL DE RECHAZO */}
      <Dialog open={rejectModalOpen} onOpenChange={setRejectModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-rose-600">
              <XCircle className="size-5" /> Rechazar Solicitud
            </DialogTitle>
            <DialogDescription>Indique las razones presupuestarias o técnicas del rechazo (Mínimo 15 caracteres).</DialogDescription>
          </DialogHeader>
          <div className="space-y-2 py-2">
            <Label className="text-xs font-semibold">Motivo del Rechazo *</Label>
            <Textarea
              value={motivoRechazo}
              onChange={(e) => setMotivoRechazo(e.target.value)}
              placeholder="Indique las razones presupuestarias o técnicas del rechazo..."
              rows={3}
            />
            <span className="text-[11px] text-muted-foreground block text-right">{motivoRechazo.trim().length} / 15 caracteres mínimos</span>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setRejectModalOpen(false)}>Cancelar</Button>
            <Button
              className="bg-rose-600 text-white hover:bg-rose-700"
              onClick={() => rechazarMutation.mutate({ idNum: solicitud.id, obs: motivoRechazo })}
              disabled={motivoRechazo.trim().length < 15 || rechazarMutation.isPending}
            >
              Confirmar Rechazo
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* MODAL DE ANULACIÓN */}
      <Dialog open={annulModalOpen} onOpenChange={setAnnulModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-rose-600">
              <Ban className="size-5" /> Anular Expediente y Liberar Reservas
            </DialogTitle>
            <DialogDescription>Por normas de la CGR, especifique el motivo legal u operativo para anular este requerimiento (Mínimo 15 caracteres).</DialogDescription>
          </DialogHeader>
          <div className="space-y-2 py-2">
            <Label className="text-xs font-semibold">Justificación de Anulación *</Label>
            <Textarea
              value={motivoAnulacion}
              onChange={(e) => setMotivoAnulacion(e.target.value)}
              placeholder="Especifique el motivo legal u operativo para anular este requerimiento..."
              rows={3}
            />
            <span className="text-[11px] text-muted-foreground block text-right">{motivoAnulacion.trim().length} / 15 caracteres mínimos</span>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setAnnulModalOpen(false)}>Cancelar</Button>
            <Button
              className="bg-rose-600 text-white hover:bg-rose-700"
              onClick={() => anularMutation.mutate({ idNum: solicitud.id, obs: motivoAnulacion })}
              disabled={motivoAnulacion.trim().length < 15 || anularMutation.isPending}
            >
              Confirmar Anulación
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
};
