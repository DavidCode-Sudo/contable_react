import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useParams } from 'react-router-dom'
import {
  AlertCircle,
  AlertTriangle,
  ArrowLeft,
  Building2,
  Calendar,
  CheckCircle2,
  Clock,
  DollarSign,
  FileText,
  Loader2,
  Package,
  Printer,
  Send,
  ShieldCheck,
  User,
  XCircle,
} from 'lucide-react'
import { toast } from 'sonner'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import {
  cambiarEstadoRequisicion,
  fetchRequisicionDetail,
  getRequisicionEstadoMeta,
  type CambiarEstadoInput,
} from '@/services/requisiciones'

const API_BASE =
  (import.meta.env?.VITE_API_BASE as string | undefined) ??
  (window.__APP_CONTEXT__?.baseUrl ?? '')

const LABEL_CLASS =
  'text-[10px] uppercase tracking-wider text-muted-foreground font-semibold block mb-0.5'

function formatNumberAmount(amount: number): string {
  return (amount || 0).toLocaleString('es-VE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

function formatRifCanonical(rifRaw?: string | null): string {
  if (!rifRaw) return 'Sin RIF'
  const clean = rifRaw.trim().toUpperCase()
  if (clean.includes('-')) return clean
  const match = clean.match(/^([JVEGP])(\d{7,9})(\d)$/)
  if (match) {
    return `${match[1]}-${match[2]}-${match[3]}`
  }
  return clean
}

function formatEstadoHistorial(estado: string | null | undefined): string {
  if (!estado) return 'Registro de Evento'
  const est = estado.toLowerCase().trim()
  switch (est) {
    case 'enviada':
    case 'pendiente':
    case 'pendiente_direccion':
      return 'Enviada a Dirección Ejecutiva'
    case 'pendiente_presupuesto':
    case 'pendiente_nivel_2':
      return 'Aprobada por Dirección — Enviada a Presupuesto'
    case 'aprobada':
      return 'Compromiso Presupuestario Aprobado'
    case 'recibida':
      return 'Bienes/Servicios Recibidos en Almacén'
    case 'rechazada':
      return 'Solicitud Rechazada'
    case 'anulada':
      return 'Requisición Anulada'
    case 'borrador':
      return 'Guardada en Borrador'
    default:
      return estado.replace(/_/g, ' ')
  }
}

export function RequisicionDetailPage() {
  const params = useParams<{ id: string }>()
  const id = Number(params.id ?? 0)
  const queryClient = useQueryClient()

  const [modalAction, setModalAction] = useState<
    'enviada' | 'aprobada' | 'pendiente_presupuesto' | 'rechazada' | 'anulada' | null
  >(null)
  const [comentarioModal, setComentarioModal] = useState('')

  const query = useQuery({
    queryKey: ['requisiciones', 'detail', id],
    queryFn: () => fetchRequisicionDetail(id),
    enabled: id > 0,
  })

  const data = query.data
  const presupuestoId = data?.presupuesto?.id
  const totalRequisicion = data?.requisicion?.total ?? 0

  // 1. ENDPOINT DE VERIFICACIÓN DE SALDO EN TIEMPO REAL
  const budgetCheckQuery = useQuery({
    queryKey: ['presupuesto', 'saldo', presupuestoId],
    queryFn: async () => {
      if (!presupuestoId) return null
      const endpoint = `${API_BASE}api/requisiciones/buscar_presupuestos.php?id=${presupuestoId}`
      const res = await fetch(endpoint, { credentials: 'include' })
      if (!res.ok) throw new Error('Error al consultar disponibilidad presupuestaria.')
      const json = (await res.json()) as {
        success: boolean
        presupuesto?: {
          id: number
          cuenta_codigo: string | null
          cuenta_nombre: string | null
          saldo_disponible: number
        }
      }
      if (!json.success || !json.presupuesto) {
        throw new Error('No se encontró la partida presupuestaria.')
      }
      return json.presupuesto
    },
    enabled: modalAction === 'aprobada' && !!presupuestoId,
  })

  const estadoMutation = useMutation({
    mutationFn: (input: CambiarEstadoInput) => cambiarEstadoRequisicion(id, input),
    onSuccess: (dataRes) => {
      queryClient.invalidateQueries({ queryKey: ['requisiciones'] })
      queryClient.invalidateQueries({ queryKey: ['requisiciones', 'detail', id] })
      toast.success(
        dataRes.estado_nuevo === 'enviada'
          ? 'Requisición enviada a aprobación de Dirección Ejecutiva con éxito.'
          : dataRes.estado_nuevo === 'pendiente_presupuesto'
          ? 'Requisición aprobada por Dirección Ejecutiva y enviada a Presupuesto.'
          : dataRes.estado_nuevo === 'aprobada'
          ? 'Requisición aprobada con éxito y compromiso generado.'
          : `Estado actualizado a ${dataRes.estado_nuevo}.`
      )
      setModalAction(null)
      setComentarioModal('')
    },
    onError: (error) => {
      const msg = error instanceof Error ? error.message : 'Error al cambiar estado'
      toast.error(msg)
    },
  })

  // 2. EVALUACIÓN Y BLOQUEO DE SALDO (ÚNICAMENTE EN APROBACIÓN DEFINITIVA DE PRESUPUESTO)
  const saldoDisponible = budgetCheckQuery.data?.saldo_disponible ?? 0
  const isFinalApprovalAction = modalAction === 'aprobada'
  const isBudgetCheckLoading = budgetCheckQuery.isLoading && isFinalApprovalAction && !!presupuestoId
  const hasNoBudget = isFinalApprovalAction && !presupuestoId
  const isBudgetInsufficient =
    isFinalApprovalAction &&
    !!presupuestoId &&
    !isBudgetCheckLoading &&
    saldoDisponible < totalRequisicion

  const isConfirmDisabled =
    estadoMutation.isPending ||
    isBudgetCheckLoading ||
    hasNoBudget ||
    isBudgetInsufficient ||
    !comentarioModal.trim()

  const handleConfirmAction = () => {
    if (!modalAction) return
    if (!comentarioModal.trim()) {
      toast.error('Debe ingresar un comentario justificando la acción.')
      return
    }

    if (modalAction === 'aprobada' && isBudgetInsufficient) {
      toast.error('No se puede aprobar. Saldo presupuestario insuficiente.')
      return
    }

    estadoMutation.mutate({
      estado_nuevo: modalAction,
      comentario: comentarioModal,
    })
  }

  const handleDownloadPdf = () => {
    if (!id) return
    const pdfUrl = `${API_BASE}api/requisiciones/${id}/pdf`
    window.open(pdfUrl, '_blank')
  }

  const estLower = (data?.requisicion?.estado || '').toLowerCase().trim()
  const isBorrador = ['borrador', 'draft', 'nuevo'].includes(estLower)
  const isPendienteDireccion = ['enviada', 'pendiente', 'pendiente_direccion', 'pendiente_ejecutiva', 'enviada_direccion', 'pendiente_aprobacion', 'en_revision'].includes(estLower)
  const isPendientePresupuesto = ['pendiente_presupuesto', 'pendiente_nivel_2', 'en_presupuesto', 'presupuesto'].includes(estLower)
  const isAprobada = ['aprobada', 'approved', 'orden_pago', 'comprometida'].includes(estLower)

  return (
    <div className="space-y-6 pb-12">
      {/* HEADER PRINCIPAL Y BARRA UNIFICADA DE ACCIONES */}
      <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between border-b border-border/60 pb-5">
        <div className="space-y-1.5">
          <Link
            to="/inventario/requisiciones"
            className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground transition-colors font-medium"
          >
            <ArrowLeft className="size-3.5" /> Volver al listado
          </Link>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-2xl font-extrabold tracking-tight text-foreground">
              {data ? `Requisición ${data.requisicion.numero ?? `#${data.requisicion.id}`}` : 'Detalle de Requisición'}
            </h1>
            {data && <EstadoBadge estado={data.requisicion.estado} />}
          </div>
        </div>

        {/* ÚNICO PUNTO DE INTERACCIÓN: TODAS LAS ACCIONES AGRUPADAS EN UNA SOLA FILA */}
        {data && (
          <div className="flex flex-wrap items-center gap-1.5 shrink-0">
            <Button
              variant="outline"
              size="sm"
              onClick={handleDownloadPdf}
              className="h-8 gap-1.5 text-xs font-semibold px-3 shadow-2xs border-border/80"
            >
              <Printer className="size-3.5 text-muted-foreground" /> Generar PDF
            </Button>

            {/* ESTADO BORRADOR: Botón de Enviar a Dirección Ejecutiva + Anular */}
            {isBorrador && (
              <>
                <Button
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('enviada')
                    setComentarioModal('Envío a aprobación de Dirección Ejecutiva.')
                  }}
                  className="h-8 gap-1.5 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs px-3.5"
                >
                  <Send className="size-3.5" /> Enviar a Dirección Ejecutiva
                </Button>

                <Button
                  variant="ghost"
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('anulada')
                    setComentarioModal('')
                  }}
                  className="h-8 gap-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 px-2"
                >
                  <AlertCircle className="size-3.5 text-rose-600" /> Anular
                </Button>
              </>
            )}

            {/* ESTADO ENVIADA: Dirección Ejecutiva aprueba y envía a Presupuesto */}
            {isPendienteDireccion && (
              <>
                <Button
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('pendiente_presupuesto')
                    setComentarioModal('Aprobación por Dirección Ejecutiva y envío a Presupuesto.')
                  }}
                  className="h-8 gap-1.5 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs px-3.5"
                >
                  <CheckCircle2 className="size-3.5" /> ✓ Aprobar y Enviar a Presupuesto
                </Button>

                <Button
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('rechazada')
                    setComentarioModal('')
                  }}
                  className="h-8 gap-1.5 text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-2xs px-3"
                >
                  <XCircle className="size-3.5" /> ✕ Rechazar
                </Button>

                <Button
                  variant="ghost"
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('anulada')
                    setComentarioModal('')
                  }}
                  className="h-8 gap-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 px-2"
                >
                  <AlertCircle className="size-3.5 text-rose-600" /> Anular
                </Button>
              </>
            )}

            {/* ESTADO PENDIENTE POR PRESUPUESTO: Validar Presupuesto y Enviar a Orden de Pago */}
            {isPendientePresupuesto && (
              <>
                <Button
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('aprobada')
                    setComentarioModal('Validación presupuestaria completada y emisión de Orden de Pago.')
                  }}
                  className="h-8 gap-1.5 text-xs font-bold bg-emerald-600 hover:bg-emerald-700 text-white shadow-2xs px-3.5"
                >
                  <CheckCircle2 className="size-3.5" /> ✓ Validar Presupuesto y Enviar a Orden de Pago
                </Button>

                <Button
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('rechazada')
                    setComentarioModal('')
                  }}
                  className="h-8 gap-1.5 text-xs font-bold bg-rose-600 hover:bg-rose-700 text-white shadow-2xs px-3"
                >
                  <XCircle className="size-3.5" /> ✕ Rechazar Presupuesto
                </Button>

                <Button
                  variant="ghost"
                  size="sm"
                  disabled={estadoMutation.isPending}
                  onClick={() => {
                    setModalAction('anulada')
                    setComentarioModal('')
                  }}
                  className="h-8 gap-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 px-2"
                >
                  <AlertCircle className="size-3.5 text-rose-600" /> Anular
                </Button>
              </>
            )}

            {/* ESTADO APROBADA */}
            {isAprobada && (
              <Button
                variant="ghost"
                size="sm"
                disabled={estadoMutation.isPending}
                onClick={() => {
                  setModalAction('anulada')
                  setComentarioModal('')
                }}
                className="h-8 gap-1 text-xs font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 dark:hover:bg-rose-950/40 px-2"
              >
                <AlertCircle className="size-3.5 text-rose-600" /> Anular Requisición
              </Button>
            )}
          </div>
        )}
      </div>

      {query.isLoading ? (
        <DetailSkeleton />
      ) : query.isError ? (
        <Card className="border-destructive/40 bg-destructive/5">
          <CardContent className="p-6 flex items-center gap-3 text-xs text-destructive">
            <AlertCircle className="size-5 shrink-0" />
            <span>{(query.error as Error).message}</span>
          </CardContent>
        </Card>
      ) : data ? (
        <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
          {/* COLUMNA IZQUIERDA (col-span-8) */}
          <div className="lg:col-span-8 space-y-6">
            {/* 1. ÍTEMS / SERVICIOS SOLICITADOS (MONTOS ESTIMADOS PARA RESERVA PRESUPUESTARIA) */}
            <Card className="border-border/60 shadow-xs">
              <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
                <CardTitle className="text-sm font-bold flex items-center gap-2">
                  <Package className="size-4 text-primary" /> Detalle de Ítems / Servicios Solicitados ({data.items.length})
                </CardTitle>
              </CardHeader>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/50 text-[10px] uppercase tracking-wider font-semibold text-muted-foreground border-b border-border/40">
                      <tr>
                        <th className="p-3 text-left">#</th>
                        <th className="p-3 text-left">Descripción / Producto</th>
                        <th className="p-3 text-center">Cant.</th>
                        <th className="p-3 text-right">Precio Est. Unit. ({data.requisicion.moneda})</th>
                        <th className="p-3 text-right">Alícuota Estimada</th>
                        <th className="p-3 text-right">Base Imponible Est. ({data.requisicion.moneda})</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border/40">
                      {data.items.map((item, idx) => {
                        const baseImponibleFila = item.cantidad * item.precio
                        return (
                          <tr key={item.id || idx} className="hover:bg-muted/20">
                            <td className="p-3 font-bold text-muted-foreground">{idx + 1}</td>
                            <td className="p-3 font-semibold text-foreground">{item.descripcion}</td>
                            <td className="p-3 text-center font-bold">
                              {item.cantidad}{' '}
                              <span className="text-[10px] text-muted-foreground font-normal uppercase">
                                {item.unidad}
                              </span>
                            </td>
                            <td className="p-3 text-right font-mono">{formatNumberAmount(item.precio)}</td>
                            <td className="p-3 text-right font-mono">
                              {item.impuesto > 0 ? `${item.impuesto}%` : 'EXENTO'}
                            </td>
                            <td className="p-3 text-right font-mono font-bold text-foreground">
                              {formatNumberAmount(baseImponibleFila)}
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              </CardContent>
            </Card>

            {/* 2. INFORMACIÓN GENERAL Y JUSTIFICACIÓN */}
            <Card className="border-border/60 shadow-xs">
              <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
                <CardTitle className="text-sm font-bold flex items-center gap-2">
                  <FileText className="size-4 text-primary" /> Información General de la Requisición
                </CardTitle>
              </CardHeader>
              <CardContent className="p-4 space-y-4">
                <div className="grid grid-cols-2 sm:grid-cols-4 gap-4 text-xs p-3.5 rounded-xl bg-muted/40 border border-border/40">
                  <div>
                    <span className={LABEL_CLASS}>Tipo</span>
                    <p className="font-semibold capitalize text-foreground">{data.requisicion.tipo_requisicion}</p>
                  </div>
                  <div>
                    <span className={LABEL_CLASS}>Prioridad</span>
                    <p className="font-semibold capitalize text-foreground">{data.requisicion.prioridad}</p>
                  </div>
                  <div>
                    <span className={LABEL_CLASS}>Fecha Solicitud</span>
                    <p className="font-semibold text-foreground flex items-center gap-1">
                      <Calendar className="size-3 text-muted-foreground" /> {formatDate(data.requisicion.fecha_solicitud)}
                    </p>
                  </div>
                  <div>
                    <span className={LABEL_CLASS}>Fecha Requerida</span>
                    <p className="font-semibold text-foreground flex items-center gap-1">
                      <Clock className="size-3 text-muted-foreground" /> {formatDate(data.requisicion.fecha_requerida)}
                    </p>
                  </div>
                </div>

                <div className="space-y-1">
                  <span className={LABEL_CLASS}>Justificación / Motivo Operativo</span>
                  <div className="p-3.5 rounded-lg bg-slate-50 dark:bg-slate-900/50 border border-slate-200/80 dark:border-slate-800 text-xs text-slate-700 dark:text-slate-300 leading-relaxed font-normal">
                    {data.requisicion.justificacion || data.requisicion.observaciones || 'Sin justificación especificada.'}
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* 3. PROVEEDOR ASIGNADO */}
            <Card className="border-border/60 shadow-xs">
              <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
                <CardTitle className="text-sm font-bold flex items-center gap-2">
                  <Building2 className="size-4 text-primary" /> Datos Fiscales del Proveedor
                </CardTitle>
              </CardHeader>
              <CardContent className="p-4">
                <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                  <div className="sm:col-span-2">
                    <span className={LABEL_CLASS}>Razón Social / Nombre Comercial</span>
                    <p className="font-bold text-foreground text-sm leading-snug">{data.proveedor.nombre || 'Sin asignar'}</p>
                  </div>
                  <div>
                    <span className={LABEL_CLASS}>RIF Fiscal</span>
                    <p className="font-mono font-bold text-foreground bg-muted/40 px-2.5 py-1 rounded inline-block border border-border/40">
                      {formatRifCanonical(data.proveedor.rif)}
                    </p>
                  </div>
                  <div>
                    <span className={LABEL_CLASS}>Contacto / Medios</span>
                    <p className="font-medium text-foreground">
                      {[data.proveedor.telefono, data.proveedor.email].filter(Boolean).join(' • ') || 'Sin información de contacto'}
                    </p>
                  </div>
                  <div className="sm:col-span-2">
                    <span className={LABEL_CLASS}>Dirección Fiscal Domiciliada</span>
                    <p className="font-medium text-foreground leading-relaxed bg-muted/20 p-2.5 rounded border border-border/40">
                      {data.proveedor.direccion || 'No especificada'}
                    </p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* COLUMNA DERECHA (col-span-4) - STICKY PARA SCROLL CONTINUO */}
          <div className="lg:col-span-4 space-y-6 lg:sticky lg:top-6 self-start">
            <Card className="border-border/60 shadow-xs bg-card">
              <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
                <CardTitle className="text-sm font-bold flex items-center gap-2">
                  <DollarSign className="size-4 text-emerald-600" /> Estimación Presupuestaria & Totales
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 p-4 text-xs">
                <div className="flex justify-between items-center text-muted-foreground">
                  <span className={LABEL_CLASS}>Base Imponible Est. (Gravable 16%)</span>
                  <span className="font-semibold text-foreground font-mono">
                    {formatNumberAmount(data.requisicion.subtotal)} {data.requisicion.moneda}
                  </span>
                </div>
                <div className="flex justify-between items-center text-muted-foreground">
                  <span className={LABEL_CLASS}>Monto Exento / Exonerado Est.</span>
                  <span className="font-semibold text-foreground font-mono">
                    0,00 {data.requisicion.moneda}
                  </span>
                </div>
                <div className="flex justify-between items-center text-muted-foreground">
                  <span className={LABEL_CLASS}>IVA Estimado (16%)</span>
                  <span className="font-semibold text-foreground font-mono">
                    {formatNumberAmount(data.requisicion.impuestos)} {data.requisicion.moneda}
                  </span>
                </div>
                <div className="flex justify-between items-center pt-2.5 border-t border-border/60">
                  <span className="font-bold text-xs text-foreground uppercase tracking-wider">Total General Estimado</span>
                  <span className="font-extrabold text-base text-primary font-mono">
                    {formatNumberAmount(data.requisicion.total)} {data.requisicion.moneda}
                  </span>
                </div>
                <div className="p-2.5 rounded-lg bg-emerald-50/70 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 text-[11px] text-muted-foreground flex justify-between items-center">
                  <span className="text-[10px] uppercase font-bold text-emerald-800 dark:text-emerald-300">Ref. USD Estimado (Tasa BCV del registro)</span>
                  <span className="font-extrabold text-emerald-700 dark:text-emerald-300 font-mono text-xs">${formatNumberAmount(data.requisicion.monto_total_usd)} USD</span>
                </div>

                <div className="mt-2.5 p-2.5 rounded-lg bg-blue-50/70 dark:bg-blue-950/40 border border-blue-200/80 dark:border-blue-900/60 text-[10px] text-blue-800 dark:text-blue-300 leading-normal flex items-start gap-1.5">
                  <AlertCircle className="size-3.5 shrink-0 text-blue-600 mt-0.5" />
                  <span><strong>Nota Operativa:</strong> Los montos e IVA son estimaciones para reserva de partida. La liquidación fiscal definitiva se expedirá en la Orden de Compra/Factura.</span>
                </div>
              </CardContent>
            </Card>

            <Card className="border-border/60 shadow-xs">
              <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
                <CardTitle className="text-sm font-bold flex items-center gap-2">
                  <ShieldCheck className="size-4 text-primary" /> Historial & Trazabilidad
                </CardTitle>
              </CardHeader>
              <CardContent className="p-4">
                {data.historial.length === 0 ? (
                  <p className="text-xs text-muted-foreground text-center py-4">Sin historial registrado.</p>
                ) : (
                  <div className="relative border-l border-border/60 ml-2 space-y-6 py-1">
                    {data.historial.map((hist) => (
                      <div key={hist.id} className="relative pl-6">
                        <div className="absolute -left-2 top-0.5 h-4 w-4 rounded-full bg-background border-2 border-primary flex items-center justify-center">
                          <div className="h-1.5 w-1.5 rounded-full bg-primary" />
                        </div>
                        <div className="space-y-1">
                          <div className="flex items-center justify-between gap-2 flex-wrap">
                            <span className="font-bold text-xs text-foreground">
                              {formatEstadoHistorial(hist.estado_hasta)}
                            </span>
                            <span className="text-[10px] text-muted-foreground font-mono">{hist.fecha}</span>
                          </div>
                          <p className="text-[11px] text-muted-foreground flex items-center gap-1">
                            <User className="size-3" /> {hist.usuario || 'Sistema'}
                          </p>
                          {hist.comentario && (
                            <p className="text-xs italic bg-muted/30 p-2 rounded border border-border/40 text-foreground mt-1">
                              "{hist.comentario}"
                            </p>
                          )}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>
      ) : null}

      {/* MODAL DIALOG DE CONFIRMACIÓN CON VALIDACIÓN DE SALDO EN TIEMPO REAL */}
      <Dialog open={modalAction !== null} onOpenChange={() => !estadoMutation.isPending && setModalAction(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle className="text-lg font-bold">
              {modalAction === 'enviada'
                ? 'Enviar Requisición a Dirección Ejecutiva'
                : modalAction === 'pendiente_presupuesto'
                ? 'Aprobar y Enviar a Presupuesto'
                : modalAction === 'aprobada'
                ? 'Aprobar Requisición Definitivamente'
                : modalAction === 'rechazada'
                ? 'Rechazar Requisición'
                : 'Anular Requisición'}
            </DialogTitle>
            <DialogDescription className="text-xs">
              {modalAction === 'enviada'
                ? 'Al enviar la requisición, esta cambiará a estado Pendiente por Dirección Ejecutiva para su revisión y aprobación.'
                : modalAction === 'pendiente_presupuesto'
                ? 'Al aprobar por Dirección Ejecutiva, la requisición se enviará al departamento de Presupuesto para su validación.'
                : modalAction === 'aprobada'
                ? 'Al aprobar la requisición se afectará el presupuesto y se generará el compromiso financiero.'
                : modalAction === 'rechazada'
                ? 'Indique el motivo o justificación del rechazo para guardar en la auditoría.'
                : 'Indique el motivo o justificación para anular esta requisición.'}
            </DialogDescription>
          </DialogHeader>

          {/* BANNER DE VALIDACIÓN DE PRESUPUESTO (ÚNICAMENTE EN APROBACIÓN DEFINITIVA DE PRESUPUESTO) */}
          {modalAction === 'aprobada' && (
            <div className="py-1">
              {isBudgetCheckLoading ? (
                <div className="flex items-center gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 text-xs text-blue-700 dark:text-blue-300">
                  <Loader2 className="size-4 animate-spin shrink-0 text-blue-600" />
                  <span className="font-medium">Verificando disponibilidad presupuestaria en tiempo real...</span>
                </div>
              ) : hasNoBudget ? (
                <div className="p-3.5 rounded-xl bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900/60 text-xs text-amber-800 dark:text-amber-300 flex items-start gap-2">
                  <AlertTriangle className="size-4 text-amber-600 shrink-0 mt-0.5" />
                  <div>
                    <p className="font-bold">Partida Presupuestaria No Asignada</p>
                    <p className="text-[11px] opacity-90">
                      Esta requisición no tiene una partida asignada. Asigne una partida antes de aprobar.
                    </p>
                  </div>
                </div>
              ) : isBudgetInsufficient ? (
                <div className="p-3.5 rounded-xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 text-xs space-y-1.5">
                  <div className="flex items-center gap-2 font-bold text-red-700 dark:text-red-400">
                    <AlertTriangle className="size-4 shrink-0 text-red-600" />
                    <span>SALDO INSUFICIENTE EN PARTIDA PRESUPUESTARIA</span>
                  </div>
                  <p className="text-red-600 dark:text-red-300 text-[11px] leading-normal">
                    La partida <strong>[{budgetCheckQuery.data?.cuenta_codigo || 'N/A'}]</strong> tiene un saldo disponible de{' '}
                    <strong>Bs. {formatNumberAmount(saldoDisponible)}</strong>, el cual es inferior al total requerido de{' '}
                    <strong>Bs. {formatNumberAmount(totalRequisicion)}</strong>.
                  </p>
                </div>
              ) : (
                <div className="p-3 rounded-xl bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-900/60 text-xs flex items-center justify-between text-emerald-800 dark:text-emerald-300">
                  <div className="flex items-center gap-2">
                    <ShieldCheck className="size-4 text-emerald-600 shrink-0" />
                    <span className="font-bold">Partida con Fondos Disponibles</span>
                  </div>
                  <Badge
                    variant="outline"
                    className="bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300 font-mono text-[10px] border-emerald-300"
                  >
                    Saldo: Bs. {formatNumberAmount(saldoDisponible)}
                  </Badge>
                </div>
              )}
            </div>
          )}

          <div className="space-y-3 py-1">
            <label className="text-xs font-semibold text-foreground">
              Comentario / Observación Obligatoria *
            </label>
            <textarea
              rows={3}
              value={comentarioModal}
              disabled={estadoMutation.isPending}
              onChange={(e) => setComentarioModal(e.target.value)}
              placeholder="Describa la observación o motivo..."
              className="w-full rounded-lg border border-border bg-background p-3 text-xs focus:ring-2 focus:ring-primary focus:outline-none disabled:opacity-50"
            />
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              variant="outline"
              size="sm"
              disabled={estadoMutation.isPending}
              onClick={() => setModalAction(null)}
            >
              Cancelar
            </Button>
            <Button
              size="sm"
              disabled={isConfirmDisabled}
              onClick={handleConfirmAction}
              className={
                modalAction === 'enviada' || modalAction === 'aprobada' || modalAction === 'pendiente_presupuesto'
                  ? 'bg-emerald-600 hover:bg-emerald-700 text-white font-bold disabled:opacity-50 disabled:cursor-not-allowed'
                  : 'bg-destructive text-destructive-foreground font-bold disabled:opacity-50 disabled:cursor-not-allowed'
              }
            >
              {estadoMutation.isPending ? 'Procesando...' : 'Confirmar Acción'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function EstadoBadge({ estado }: { estado: string }) {
  const meta = getRequisicionEstadoMeta(estado)

  return (
    <Badge variant="outline" className={`font-bold text-[11px] px-3 py-1 uppercase tracking-wider rounded-lg shadow-2xs ${meta.colorClass}`}>
      {meta.label}
    </Badge>
  )
}

function formatDate(dateStr?: string | null) {
  if (!dateStr) return 'N/A'
  try {
    return new Date(dateStr).toLocaleDateString('es-VE')
  } catch {
    return dateStr
  }
}

function DetailSkeleton() {
  return (
    <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
      <div className="lg:col-span-8 space-y-6">
        <Card className="border-border/60">
          <CardHeader>
            <Skeleton className="h-6 w-48" />
          </CardHeader>
          <CardContent className="space-y-4">
            <Skeleton className="h-16 w-full rounded-xl" />
            <Skeleton className="h-20 w-full rounded-lg" />
          </CardContent>
        </Card>
        <Card className="border-border/60">
          <CardHeader>
            <Skeleton className="h-6 w-40" />
          </CardHeader>
          <CardContent>
            <Skeleton className="h-24 w-full rounded-lg" />
          </CardContent>
        </Card>
      </div>

      <div className="lg:col-span-4 space-y-6">
        <Card className="border-border/60">
          <CardHeader>
            <Skeleton className="h-6 w-32" />
          </CardHeader>
          <CardContent className="space-y-3">
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-5 w-full" />
            <Skeleton className="h-8 w-full rounded-lg" />
          </CardContent>
        </Card>
        <Card className="border-border/60">
          <CardHeader>
            <Skeleton className="h-6 w-40" />
          </CardHeader>
          <CardContent>
            <Skeleton className="h-48 w-full rounded-lg" />
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
