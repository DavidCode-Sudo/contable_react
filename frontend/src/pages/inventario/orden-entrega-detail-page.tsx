import { useState, useEffect } from 'react'
import { useParams, Link } from 'react-router-dom'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Skeleton } from '@/components/ui/skeleton'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  ArrowLeft,
  Printer,
  PackageCheck,
  Building2,
  User,
  Calendar,
  AlertCircle,
  FileText,
  Send,
  RotateCcw,
  Clock,
  History,
  CheckCircle2,
  Lock,
  Edit3,
  Copy,
} from 'lucide-react'
import { toast } from 'sonner'
import { OrdenEntregaDialog } from '@/components/inventario/orden-entrega-dialog'
import { ConfirmActionModal, type ConfirmActionVariant } from '@/components/common/ConfirmActionModal'
import {
  fetchOrdenEntregaDetail,
  despacharOrdenEntrega,
  devolucionOrdenEntrega,
  cancelarReservaOrdenEntrega,
  anularOrdenEntrega,
  getEstadoOrdenMeta,
  type OrdenEntregaDetail,
  type OrdenEntregaItem,
  type OrdenEntregaDevolucionItem,
  type OrdenEntregaAuditoriaItem,
} from '@/services/ordenesEntrega'

const API_BASE =
  (import.meta.env?.VITE_API_BASE as string | undefined) ??
  (window.__APP_CONTEXT__?.baseUrl ?? '')

export function OrdenEntregaDetailPage() {
  const { id } = useParams<{ id: string }>()

  const [loading, setLoading] = useState(true)
  const [isProcessing, setIsProcessing] = useState(false)
  const [isEditOpen, setIsEditOpen] = useState(false)
  const [orden, setOrden] = useState<OrdenEntregaDetail | null>(null)
  const [items, setItems] = useState<OrdenEntregaItem[]>([])
  const [devoluciones, setDevoluciones] = useState<OrdenEntregaDevolucionItem[]>([])
  const [auditoria, setAuditoria] = useState<OrdenEntregaAuditoriaItem[]>([])

  // Modal Devolución RMA
  const [isDevolucionOpen, setIsDevolucionOpen] = useState(false)
  const [motivoDevolucion, setMotivoDevolucion] = useState('')
  const [cantidadesDev, setCantidadesDev] = useState<Record<number, number>>({})

  // Modal Confirmación Consistente
  const [confirmModal, setConfirmModal] = useState<{
    isOpen: boolean
    variant: ConfirmActionVariant
    title: string
    description: string
    onConfirm: () => Promise<void>
  }>({
    isOpen: false,
    variant: 'default',
    title: '',
    description: '',
    onConfirm: async () => {},
  })

  const loadDetail = async () => {
    if (!id) return
    setLoading(true)
    try {
      const data = await fetchOrdenEntregaDetail(Number(id))
      setOrden(data.orden)
      setItems(data.items || [])
      setDevoluciones(data.devoluciones || [])
      setAuditoria(data.auditoria || [])
    } catch (err: any) {
      toast.error(err.message || 'Error al cargar los detalles de la orden de entrega.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    loadDetail()
  }, [id])

  const handleDownloadPdf = () => {
    if (!orden) return
    const token = orden.hash_verificacion ? orden.hash_verificacion.substring(0, 12) : orden.id
    const pdfUrl = `${API_BASE}api/inventario/ordenes-entrega/${token}/pdf`
    window.open(pdfUrl, '_blank')
  }

  const handleDespachar = () => {
    if (!orden) return
    setConfirmModal({
      isOpen: true,
      variant: 'despachar',
      title: `¿Confirmar Despacho Definitivo?`,
      description: `¿Está seguro de procesar el despacho definitivo de la Orden ${orden.numero_orden}? Esta acción descontará físicamente las existencias del inventario y generará el asiento contable ONCOP.`,
      onConfirm: async () => {
        setIsProcessing(true)
        try {
          await despacharOrdenEntrega(orden.id)
          toast.success(`Despacho de almacén procesado con éxito.`)
          setConfirmModal((prev) => ({ ...prev, isOpen: false }))
          await loadDetail()
        } catch (err: any) {
          toast.error(err.message || 'Error al procesar el despacho de la orden.')
        } finally {
          setIsProcessing(false)
        }
      },
    })
  }

  const handleCancelarReserva = () => {
    if (!orden) return
    setConfirmModal({
      isOpen: true,
      variant: 'cancelar_reserva',
      title: `¿Liberar Reserva de Inventario?`,
      description: `¿Desea cancelar la reserva de stock para la Orden ${orden.numero_orden}? El stock reservado volverá a estar disponible en almacén.`,
      onConfirm: async () => {
        setIsProcessing(true)
        try {
          await cancelarReservaOrdenEntrega(orden.id)
          toast.success(`Reserva cancelada exitosamente.`)
          setConfirmModal((prev) => ({ ...prev, isOpen: false }))
          await loadDetail()
        } catch (err: any) {
          toast.error(err.message || 'Error al cancelar la reserva.')
        } finally {
          setIsProcessing(false)
        }
      },
    })
  }

  const handleAnular = () => {
    if (!orden) return
    setConfirmModal({
      isOpen: true,
      variant: 'anular',
      title: `¿Anular Orden de Entrega?`,
      description: `¿Desea anular formalmente la Orden ${orden.numero_orden}? Si ya estaba despachada, se revertirá el inventario al costo histórico y se asentará la reversión contable.`,
      onConfirm: async () => {
        setIsProcessing(true)
        try {
          await anularOrdenEntrega(orden.id)
          toast.success(`Orden ${orden.numero_orden} anulada exitosamente.`)
          setConfirmModal((prev) => ({ ...prev, isOpen: false }))
          await loadDetail()
        } catch (err: any) {
          toast.error(err.message || 'Error al anular la orden de entrega.')
        } finally {
          setIsProcessing(false)
        }
      },
    })
  }

  const handleSubmitDevolucion = async () => {
    if (!orden) return
    if (!motivoDevolucion.trim()) {
      toast.error('Debe indicar la justificación o estado de los productos devueltos.')
      return
    }

    const itemsPayload = Object.entries(cantidadesDev)
      .map(([odeItemId, qty]) => ({
        orden_entrega_item_id: Number(odeItemId),
        cantidad_devuelta: qty,
      }))
      .filter((i) => i.cantidad_devuelta > 0)

    if (itemsPayload.length === 0) {
      toast.error('Debe indicar una cantidad mayor a cero para al menos un producto a devolver.')
      return
    }

    setIsProcessing(true)
    try {
      const res = await devolucionOrdenEntrega(orden.id, {
        motivo: motivoDevolucion,
        items: itemsPayload,
      })
      toast.success(`Devolución física parcial ${res.numero_devolucion} registrada con éxito.`)
      setIsDevolucionOpen(false)
      setMotivoDevolucion('')
      setCantidadesDev({})
      await loadDetail()
    } catch (err: any) {
      toast.error(err.message || 'Error al procesar la devolución física.')
    } finally {
      setIsProcessing(false)
    }
  }

  if (loading) {
    return (
      <div className="space-y-6 pb-12">
        <Skeleton className="h-8 w-48" />
        <Skeleton className="h-12 w-full" />
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <Skeleton className="h-40 md:col-span-2" />
          <Skeleton className="h-40" />
        </div>
      </div>
    )
  }

  if (!orden) {
    return (
      <div className="p-12 text-center text-muted-foreground space-y-4">
        <p className="text-sm font-semibold">Orden de entrega no encontrada.</p>
        <Link to="/inventario/ordenes-entrega">
          <Button variant="outline" size="sm">
            <ArrowLeft className="size-3.5 mr-1" /> Volver al listado
          </Button>
        </Link>
      </div>
    )
  }

  const meta = getEstadoOrdenMeta(orden.estado)
  const totalItemsCount = items.reduce((acc, i) => acc + i.cantidad_solicitada, 0)
  const totalDespachadoCount = items.reduce((acc, i) => acc + i.cantidad_despachada, 0)
  const totalDevueltoCount = items.reduce((acc, i) => acc + i.cantidad_devuelta, 0)
  const totalCostoCalc = items.reduce((acc, i) => acc + (i.costo_total || i.cantidad_despachada * i.costo_unitario), 0)

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL Y ACCIONES */}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-border/60 pb-5">
        <div className="space-y-1.5">
          <Link
            to="/inventario/ordenes-entrega"
            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors font-medium"
          >
            <ArrowLeft className="size-3.5" /> Volver a órdenes de entrega
          </Link>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl font-extrabold tracking-tight text-foreground">
              Orden de Entrega {orden.numero_orden}
            </h1>
            <Badge variant="outline" className={`text-xs font-semibold ${meta.colorClass}`}>
              {meta.label}
            </Badge>
            {orden.hash_verificacion && (
              <button
                type="button"
                onClick={() => {
                  if (orden.hash_verificacion) {
                    navigator.clipboard.writeText(orden.hash_verificacion);
                    toast.success('Firma SHA-256 copiada al portapapeles');
                  }
                }}
                title={`Firma SHA-256: ${orden.hash_verificacion} (Clic para copiar)`}
                className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded border border-emerald-200/80 dark:border-emerald-900/60 bg-emerald-50/50 dark:bg-emerald-950/30 text-[10px] font-mono text-emerald-800 dark:text-emerald-300 hover:bg-emerald-100 transition-colors shadow-2xs"
              >
                <Lock className="size-2.5 text-emerald-600" />
                <span>Hash: {orden.hash_verificacion.substring(0, 8)}...{orden.hash_verificacion.substring(orden.hash_verificacion.length - 6)}</span>
                <Copy className="size-2.5 opacity-60 ml-0.5" />
              </button>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2 shrink-0">
          <Button
            variant="outline"
            size="sm"
            onClick={handleDownloadPdf}
            className="h-8 gap-1.5 text-xs font-semibold shadow-2xs"
          >
            <Printer className="size-3.5 text-muted-foreground" /> Generar Acta Oficial (PDF)
          </Button>

          {['borrador', 'aprobada', 'reserva_vencida'].includes(orden.estado) && (
            <Button
              variant="outline"
              size="sm"
              disabled={isProcessing}
              onClick={() => setIsEditOpen(true)}
              className="h-8 gap-1.5 text-xs font-semibold border-amber-500/60 text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/40"
            >
              <Edit3 className="size-3.5 text-amber-600" /> Editar Orden
            </Button>
          )}

          {['borrador', 'aprobada', 'reserva_vencida'].includes(orden.estado) && (
            <Button
              size="sm"
              disabled={isProcessing}
              onClick={handleDespachar}
              className="h-8 gap-1.5 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs px-3.5"
            >
              <Send className="size-3.5" /> Confirmar y Despachar de Almacén
            </Button>
          )}

          {['despachada', 'despachada_parcial'].includes(orden.estado) && (
            <Button
              variant="outline"
              size="sm"
              disabled={isProcessing}
              onClick={() => setIsDevolucionOpen(true)}
              className="h-8 gap-1.5 text-xs font-semibold border-amber-500/60 text-amber-700 dark:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-950/40"
            >
              <RotateCcw className="size-3.5" /> Registrar Devolución Parcial (RMA)
            </Button>
          )}

          {orden.estado === 'aprobada' && (
            <Button
              variant="ghost"
              size="sm"
              disabled={isProcessing}
              onClick={handleCancelarReserva}
              className="h-8 gap-1 text-xs font-medium text-amber-600 hover:bg-amber-50"
            >
              <Clock className="size-3.5" /> Liberar Reserva
            </Button>
          )}

          {orden.estado !== 'anulada' && (
            <Button
              variant="ghost"
              size="sm"
              disabled={isProcessing}
              onClick={handleAnular}
              className="h-8 gap-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 px-2"
            >
              <AlertCircle className="size-3.5" /> Anular Orden
            </Button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
        {/* COLUMNA IZQUIERDA: DETALLE DE ÍTEMS Y DEVOLUCIONES */}
        <div className="lg:col-span-8 space-y-6">
          <Card className="border-border/60 shadow-2xs">
            <CardHeader className="pb-3 border-b border-border/40">
              <CardTitle className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                <FileText className="size-4 text-emerald-600" />
                Información Operativa del Despacho
              </CardTitle>
            </CardHeader>
            <CardContent className="pt-4 space-y-4">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1">
                  <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider block">
                    Departamento Receptor
                  </span>
                  <div className="text-xs font-extrabold text-foreground flex items-center gap-1.5">
                    <Building2 className="size-4 text-muted-foreground" />
                    {orden.departamento_nombre}
                  </div>
                </div>

                <div className="space-y-1">
                  <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider block">
                    Fecha de Registro
                  </span>
                  <div className="text-xs font-semibold text-foreground flex items-center gap-1.5">
                    <Calendar className="size-4 text-muted-foreground" />
                    {new Date(orden.fecha_orden).toLocaleString('es-VE')}
                  </div>
                </div>

                <div className="space-y-1">
                  <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider block">
                    Tipo de Destino
                  </span>
                  <Badge variant="secondary" className="text-[11px] capitalize font-semibold">
                    {orden.tipo_destino}
                  </Badge>
                </div>

                <div className="space-y-1">
                  <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider block">
                    Despachado por (Almacén)
                  </span>
                  <div className="text-xs font-semibold text-foreground flex items-center gap-1.5">
                    <User className="size-4 text-muted-foreground" />
                    {orden.usuario_despacho_nombre}
                  </div>
                </div>
              </div>

              <div className="space-y-1 pt-2 border-t border-border/40">
                <span className="text-[10px] uppercase font-bold text-muted-foreground tracking-wider block">
                  Justificación / Motivo Operativo
                </span>
                <p className="text-xs text-foreground bg-muted/30 p-3 rounded-md border border-border/50">
                  {orden.justificacion}
                </p>
              </div>
            </CardContent>
          </Card>

          {/* TABLA DE ÍTEMS Y MATERIALES */}
          <Card className="border-border/60 shadow-2xs">
            <CardHeader className="pb-3 border-b border-border/40 flex flex-row items-center justify-between">
              <CardTitle className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                <PackageCheck className="size-4 text-emerald-600" />
                Materiales e Insumos Solicitados ({items.length})
              </CardTitle>
              <div className="flex items-center gap-2">
                <Badge variant="outline" className="text-[10px] font-mono">
                  Entregados: {totalDespachadoCount.toLocaleString('es-VE')} / {totalItemsCount.toLocaleString('es-VE')}
                </Badge>
                {totalDevueltoCount > 0 && (
                  <Badge variant="secondary" className="text-[10px] font-mono text-amber-700 dark:text-amber-300">
                    Devueltos: {totalDevueltoCount.toLocaleString('es-VE')}
                  </Badge>
                )}
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="bg-muted/50 text-muted-foreground font-semibold uppercase tracking-wider text-[10px] border-b border-border/60">
                    <tr>
                      <th className="px-4 py-2.5 text-left">Código / Insumo</th>
                      <th className="px-4 py-2.5 text-center">Unidad</th>
                      <th className="px-4 py-2.5 text-center">Solicitado</th>
                      <th className="px-4 py-2.5 text-center">Despachado</th>
                      <th className="px-4 py-2.5 text-center">Devuelto (RMA)</th>
                      <th className="px-4 py-2.5 text-right">Costo CPP (Bs.)</th>
                      <th className="px-4 py-2.5 text-right">Costo Total</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-border/40">
                    {items.map((it) => {
                      const costoTotalLinea = it.costo_total || it.cantidad_despachada * it.costo_unitario
                      return (
                        <tr key={it.id} className="hover:bg-muted/30">
                          <td className="px-4 py-3 font-medium">
                            <span className="font-bold text-foreground">{it.producto_codigo}</span> - {it.producto_nombre}
                          </td>
                          <td className="px-4 py-3 text-center font-mono">{it.producto_unidad}</td>
                          <td className="px-4 py-3 text-center font-mono font-semibold">
                            {it.cantidad_solicitada.toLocaleString('es-VE')}
                          </td>
                          <td className="px-4 py-3 text-center font-mono font-bold text-emerald-600 dark:text-emerald-400">
                            {it.cantidad_despachada.toLocaleString('es-VE')}
                          </td>
                          <td className="px-4 py-3 text-center font-mono font-bold text-amber-600">
                            {it.cantidad_devuelta > 0 ? it.cantidad_devuelta.toLocaleString('es-VE') : '-'}
                          </td>
                          <td className="px-4 py-3 text-right font-mono">
                            Bs. {it.costo_unitario.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-bold text-foreground">
                            Bs. {costoTotalLinea.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>

          {/* LISTADO DE DEVOLUCIONES PARCIALES REALIZADAS (RMA) */}
          {devoluciones.length > 0 && (
            <Card className="border-border/60 shadow-2xs border-amber-200/60 dark:border-amber-900/60">
              <CardHeader className="pb-3 border-b border-border/40">
                <CardTitle className="text-xs font-bold uppercase tracking-wider text-amber-900 dark:text-amber-300 flex items-center gap-2">
                  <RotateCcw className="size-4 text-amber-600" />
                  Historial de Devoluciones Físicas Parciales ({devoluciones.length})
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <div className="divide-y divide-border/40 text-xs">
                  {devoluciones.map((dev) => (
                    <div key={dev.id} className="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                      <div>
                        <div className="font-bold text-foreground flex items-center gap-2">
                          <span>{dev.numero_devolucion}</span>
                          <span className="text-[11px] font-normal text-muted-foreground">
                            ({new Date(dev.fecha_devolucion).toLocaleString('es-VE')})
                          </span>
                        </div>
                        <p className="text-xs text-muted-foreground mt-0.5">
                          Motivo: <span className="text-foreground">{dev.motivo}</span>
                        </p>
                      </div>
                      <div className="text-right font-mono">
                        <span className="text-[10px] text-muted-foreground block">Monto Revertido</span>
                        <span className="font-bold text-amber-600 dark:text-amber-400">
                          Bs. {Number(dev.costo_total_devuelto).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}

          {/* BITÁCORA DE AUDITORÍA FORENSE INMUTABLE */}
          {auditoria.length > 0 && (
            <Card className="border-border/60 shadow-2xs">
              <CardHeader className="pb-3 border-b border-border/40">
                <CardTitle className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                  <History className="size-4 text-muted-foreground" />
                  Bitácora de Auditoría Forense e Historial
                </CardTitle>
              </CardHeader>
              <CardContent className="p-4">
                <div className="space-y-3">
                  {auditoria.map((aud) => {
                    let parsedDetails: any = null;
                    try {
                      parsedDetails = aud.detalles_json ? JSON.parse(aud.detalles_json) : null;
                    } catch {
                      parsedDetails = null;
                    }

                    const cambiosList: string[] = Array.isArray(parsedDetails?.cambios)
                      ? parsedDetails.cambios
                      : [];

                    const getAccionBadge = (accion: string) => {
                      switch (accion.toLowerCase()) {
                        case 'creacion':
                          return { text: 'Creación de Orden', color: 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/60 dark:text-blue-300' };
                        case 'despacho':
                          return { text: 'Despacho de Almacén', color: 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-300' };
                        case 'edicion_orden':
                        case 'edicion_borrador':
                          return { text: 'Edición de Pedido', color: 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/60 dark:text-amber-300' };
                        case 'cambio_estado':
                          return { text: 'Cambio de Estado', color: 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/60 dark:text-indigo-300' };
                        case 'devolucion_parcial':
                          return { text: 'Devolución Parcial (RMA)', color: 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/60 dark:text-purple-300' };
                        case 'anulacion':
                          return { text: 'Anulación Formal', color: 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/60 dark:text-rose-300' };
                        case 'vencimiento_reserva':
                          return { text: 'Expiración de Reserva', color: 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/60 dark:text-orange-300' };
                        default:
                          return { text: accion.toUpperCase(), color: 'bg-slate-50 text-slate-700 border-slate-200 dark:bg-slate-800 dark:text-slate-300' };
                      }
                    };

                    const badgeInfo = getAccionBadge(aud.accion);

                    return (
                      <div key={aud.id} className="text-xs border-l-2 border-slate-300 dark:border-slate-700 pl-3 py-1.5 space-y-1.5 bg-muted/10 rounded-r-md">
                        <div className="flex flex-wrap items-center justify-between gap-1 font-semibold">
                          <span className={`px-2 py-0.5 rounded text-[10px] font-bold border ${badgeInfo.color}`}>
                            {badgeInfo.text}
                          </span>
                          <span className="text-[10px] text-muted-foreground font-mono">
                            {new Date(aud.created_at).toLocaleString('es-VE')} ({aud.ip_address})
                          </span>
                        </div>

                        <p className="text-muted-foreground text-[11px]">
                          Ejecutado por: <span className="text-foreground font-semibold">{aud.usuario_nombre || 'Sistema'}</span>
                        </p>

                        {/* DESGLOSE DE CAMBIOS AUDITADOS */}
                        {cambiosList.length > 0 ? (
                          <ul className="list-disc list-inside space-y-0.5 text-[11px] text-slate-700 dark:text-slate-300 font-medium bg-background/80 p-2 rounded border border-border/40">
                            {cambiosList.map((cambio, idx) => (
                              <li key={idx} className="leading-snug">
                                {cambio.replace(/(\d+)\.000\s+unidades/gi, '$1 unidad(es)')}
                              </li>
                            ))}
                          </ul>
                        ) : parsedDetails ? (
                          <div className="text-[11px] text-muted-foreground font-mono bg-background/60 p-1.5 rounded border border-border/40">
                            {parsedDetails.motivo && (
                              <p className="font-sans">Motivo: <span className="font-semibold text-foreground">{parsedDetails.motivo}</span></p>
                            )}
                            {parsedDetails.total_articulos && (
                              <p className="font-sans">Total Insumos: <span className="font-semibold text-foreground">{parsedDetails.total_articulos}</span></p>
                            )}
                          </div>
                        ) : null}
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
          )}

          {/* CONTROL ADMINISTRATIVO Y EMISIÓN DE ACTA IMPRESA */}
          <Card className="border-border/60 shadow-2xs bg-muted/20">
            <CardHeader className="pb-2">
              <CardTitle className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-2">
                <Printer className="size-4 text-emerald-600" />
                Control Administrativo y Acta de Entrega Física
              </CardTitle>
            </CardHeader>
            <CardContent className="pt-2 pb-4 space-y-3 text-xs">
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-background p-3 rounded-md border border-border/60">
                <div>
                  <span className="text-[10px] uppercase font-bold text-muted-foreground block">Responsable de Entrega (Almacén)</span>
                  <span className="font-semibold text-foreground">{orden.usuario_despacho_nombre || 'Sistema'}</span>
                </div>
                <div>
                  <span className="text-[10px] uppercase font-bold text-muted-foreground block">Unidad / Depto. Receptor</span>
                  <span className="font-semibold text-foreground">{orden.departamento_nombre}</span>
                </div>
              </div>

              <p className="text-[11px] text-muted-foreground pt-1 leading-relaxed">
                Las firmas autógrafas y sellos húmedos institucionales se estampan únicamente en el acta física en papel generada desde la opción principal de la barra superior.
              </p>
            </CardContent>
          </Card>
        </div>

        {/* COLUMNA DERECHA: RESUMEN FINANCIERO Y CONTROL */}
        <div className="lg:col-span-4 space-y-6">
          <Card className="border-border/60 shadow-2xs lg:sticky lg:top-6">
            <CardHeader className="pb-3 border-b border-border/40">
              <CardTitle className="text-xs font-bold uppercase tracking-wider text-foreground">
                Resumen de Valoración de Almacén
              </CardTitle>
            </CardHeader>
            <CardContent className="pt-4 space-y-4">
              <div className="flex justify-between items-center text-xs">
                <span className="text-muted-foreground font-semibold">Total Ítems Distintos:</span>
                <span className="font-bold font-mono">{items.length}</span>
              </div>

              <div className="flex justify-between items-center text-xs">
                <span className="text-muted-foreground font-semibold">Total Unidades Solicitadas:</span>
                <span className="font-bold font-mono">{totalItemsCount.toLocaleString('es-VE')}</span>
              </div>

              <div className="flex justify-between items-center text-xs">
                <span className="text-muted-foreground font-semibold">Total Entregadas:</span>
                <span className="font-bold font-mono text-emerald-600">{totalDespachadoCount.toLocaleString('es-VE')}</span>
              </div>

              <div className="border-t border-border/60 pt-3 flex justify-between items-center">
                <span className="text-xs font-extrabold text-foreground uppercase">Valor Total Despacho:</span>
                <span className="text-base font-black text-emerald-600 dark:text-emerald-400 font-mono">
                  Bs. {totalCostoCalc.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                </span>
              </div>

              {['despachada', 'despachada_parcial'].includes(orden.estado) && (
                <div className="p-3 bg-emerald-50 dark:bg-emerald-950/40 rounded-md border border-emerald-200 dark:border-emerald-900 text-xs space-y-1 text-emerald-900 dark:text-emerald-300">
                  <p className="font-bold flex items-center gap-1">
                    <CheckCircle2 className="size-3.5 text-emerald-600" /> Despacho ACID Completado
                  </p>
                  <p className="text-[11px]">
                    Existencias deducidas con CPP en vivo y respaldo hash SHA-256.
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>

      {/* MODAL DE DEVOLUCIÓN FÍSICA PARCIAL (RMA) */}
      <Dialog open={isDevolucionOpen} onOpenChange={setIsDevolucionOpen}>
        <DialogContent className="max-w-2xl">
          <DialogHeader>
            <DialogTitle className="text-lg font-bold flex items-center gap-2 text-amber-700 dark:text-amber-300">
              <RotateCcw className="size-5" />
              Registrar Devolución Física Parcial (RMA)
            </DialogTitle>
            <DialogDescription>
              Indique las cantidades devueltas por la unidad receptora. Insumos reingresarán al almacén con el CPP histórico exacto.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-2">
            <div className="space-y-1.5">
              <label className="text-xs font-semibold">Motivo / Estado de los Materiales Devueltos *</label>
              <Textarea
                rows={2}
                placeholder="Ej. Devolución sobrante no consumida durante la actividad de mantenimiento."
                value={motivoDevolucion}
                onChange={(e) => setMotivoDevolucion(e.target.value)}
                className="text-xs"
              />
            </div>

            <div className="space-y-2">
              <span className="text-xs font-bold uppercase tracking-wider block">Seleccionar Cantidades a Devolver</span>
              <div className="border rounded-md divide-y overflow-hidden max-h-60 overflow-y-auto text-xs">
                {items
                  .filter((i) => i.cantidad_despachada - i.cantidad_devuelta > 0)
                  .map((it) => {
                    const maxDev = it.cantidad_despachada - it.cantidad_devuelta
                    return (
                      <div key={it.id} className="p-3 flex items-center justify-between gap-3">
                        <div className="flex-1">
                          <p className="font-bold text-foreground">{it.producto_codigo} - {it.producto_nombre}</p>
                          <p className="text-[11px] text-muted-foreground">
                            Entregado: {it.cantidad_despachada} | Ya Devuelto: {it.cantidad_devuelta} | <span className="font-bold text-emerald-600">Máx Devolver: {maxDev}</span>
                          </p>
                        </div>
                        <Input
                          type="number"
                          min="0"
                          max={maxDev}
                          step="0.001"
                          placeholder="0"
                          value={cantidadesDev[it.id] ?? ''}
                          onChange={(e) =>
                            setCantidadesDev({
                              ...cantidadesDev,
                              [it.id]: parseFloat(e.target.value) || 0,
                            })
                          }
                          className="w-24 h-8 text-xs font-bold text-center"
                        />
                      </div>
                    )
                  })}
              </div>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDevolucionOpen(false)} disabled={isProcessing}>
              Cancelar
            </Button>
            <Button
              onClick={handleSubmitDevolucion}
              disabled={isProcessing}
              className="bg-amber-600 hover:bg-amber-700 text-white font-bold"
            >
              Procesar Devolución (RMA)
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* MODAL DE EDICIÓN DE ORDEN */}
      <OrdenEntregaDialog
        open={isEditOpen}
        onOpenChange={setIsEditOpen}
        onSuccess={loadDetail}
        editData={orden}
        initialItems={items}
      />

      {/* MODAL DE CONFIRMACIÓN CONSISTENTE (Reemplazo de window.confirm) */}
      <ConfirmActionModal
        isOpen={confirmModal.isOpen}
        onClose={() => setConfirmModal((prev) => ({ ...prev, isOpen: false }))}
        onConfirm={confirmModal.onConfirm}
        title={confirmModal.title}
        description={confirmModal.description}
        variant={confirmModal.variant}
        isProcessing={isProcessing}
      />
    </div>
  )
}
