import { useMemo } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'

import {
  fetchRequisicionDetail,
  guardarRequisicion,
  type RequisicionAccion,
} from '@/services/requisiciones'
import {
  RequisicionWizard,
  type RequisicionWizardData,
} from '@/components/requisiciones/requisicion-wizard'

export function RequisicionFormPage() {
  const navigate = useNavigate()
  const params = useParams<{ id?: string }>()
  const requisicionId = params.id ? Number(params.id) : null
  const isEditing = requisicionId !== null && !Number.isNaN(requisicionId)
  const queryClient = useQueryClient()

  // Cargar datos en modo edición
  const { data: detail, isLoading: isLoadingDetail } = useQuery({
    queryKey: ['requisiciones', 'detail', requisicionId],
    queryFn: () => fetchRequisicionDetail(requisicionId!),
    enabled: isEditing,
  })

  // Mutación para guardar / enviar la requisición
  const mutation = useMutation({
    mutationFn: guardarRequisicion,
    onSuccess: (data, variables) => {
      queryClient.invalidateQueries({ queryKey: ['requisiciones'] })
      if (requisicionId) {
        queryClient.invalidateQueries({
          queryKey: ['requisiciones', 'detail', requisicionId],
        })
      }

      if (variables.accion === 'enviar') {
        toast.success('Requisición enviada a aprobación correctamente.')
      } else {
        toast.success('Requisición guardada como borrador.')
      }

      if (data?.id) {
        navigate(`/inventario/requisiciones/${data.id}`)
      } else {
        navigate('/inventario/requisiciones')
      }
    },
    onError: (error) => {
      const message =
        error instanceof Error ? error.message : 'Error al guardar la requisición'
      toast.error(message)
    },
  })

  const handleSubmit = async (
    data: RequisicionWizardData,
    action: 'borrador' | 'enviar'
  ) => {
    const payloadAction: RequisicionAccion = action === 'borrador' ? 'guardar' : 'enviar'

    mutation.mutate({
      accion: payloadAction,
      id: requisicionId ?? undefined,
      requisicion: {
        fecha_solicitud: data.fechaSolicitud,
        fecha_requerida: data.fechaRequerida,
        prioridad: data.prioridad,
        moneda: data.moneda,
        tipo_requisicion: data.tipoRequisicion,
        presupuesto_id: data.presupuestoId ? Number(data.presupuestoId) : undefined,
        observaciones: data.observaciones,
        proveedor: {
          id: data.proveedor.id ? Number(data.proveedor.id) : undefined,
          nombre: data.proveedor.nombre,
          rif: data.proveedor.rif,
          telefono: data.proveedor.telefono,
          email: data.proveedor.email,
          direccion: data.proveedor.direccion,
        },
      },
      items: data.items.map((item) => ({
        producto_id: item.producto_id ?? null,
        descripcion: item.descripcion,
        unidad: item.unidad,
        cantidad: item.cantidad,
        precio: item.precio,
        impuesto: item.impuesto,
      })),
    })
  }

  const initialWizardData: Partial<RequisicionWizardData> | undefined = useMemo(() => {
    if (!detail) return undefined
    return {
      fechaSolicitud: detail.requisicion.fecha_solicitud?.slice(0, 10),
      fechaRequerida: detail.requisicion.fecha_requerida?.slice(0, 10),
      prioridad: detail.requisicion.prioridad ?? 'media',
      moneda: detail.requisicion.moneda ?? 'VES',
      tipoRequisicion: detail.requisicion.tipo_requisicion ?? 'compra',
      presupuestoId: detail.presupuesto?.id ? String(detail.presupuesto.id) : '',
      presupuestoCodigo: detail.presupuesto?.partida?.codigo ?? '',
      presupuestoNombre: detail.presupuesto?.partida?.nombre ?? detail.presupuesto?.descripcion ?? '',
      observaciones: detail.requisicion.justificacion || detail.requisicion.observaciones || '',
      proveedor: {
        id: detail.proveedor?.id ? String(detail.proveedor.id) : '',
        nombre: detail.proveedor?.nombre ?? '',
        rif: detail.proveedor?.rif ?? '',
        telefono: detail.proveedor?.telefono ?? '',
        email: detail.proveedor?.email ?? '',
        direccion: detail.proveedor?.direccion ?? '',
      },
      items: (detail.items || []).map((it) => ({
        id: String(it.id),
        producto_id: (it as any).producto_id ?? null,
        descripcion: it.descripcion,
        unidad: it.unidad,
        cantidad: it.cantidad,
        precio: it.precio,
        impuesto: it.impuesto,
      })),
    }
  }, [detail])

  if (isEditing && isLoadingDetail) {
    return (
      <div className="flex h-64 items-center justify-center">
        <Loader2 className="h-8 w-8 animate-spin text-primary" />
      </div>
    )
  }

  return (
    <RequisicionWizard
      initialData={initialWizardData}
      onSubmit={handleSubmit}
      isSubmitting={mutation.isPending}
      onCancel={() => navigate('/inventario/requisiciones')}
    />
  )
}
