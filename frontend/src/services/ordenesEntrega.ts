export type EstadoOrdenEntrega =
  | 'borrador'
  | 'aprobada'
  | 'despachada_parcial'
  | 'despachada'
  | 'reserva_vencida'
  | 'anulada'

export type TipoDestinoOrdenEntrega = 'departamento' | 'empleado' | 'evento' | 'merma_baja'

export interface OrdenEntregaListItem {
  id: number
  numero_orden: string
  fecha_orden: string
  estado: EstadoOrdenEntrega
  tipo_destino: TipoDestinoOrdenEntrega
  justificacion: string
  observaciones?: string
  total_articulos: number
  costo_total_despacho: number
  hash_verificacion?: string
  departamento_id: number | null
  departamento_nombre: string
  centro_costo_id: number | null
  solicitante_id: number | null
  usuario_despacho_nombre: string
}

export interface OrdenEntregaItem {
  id: number
  orden_entrega_id: number
  producto_id: number
  producto_codigo: string
  producto_nombre: string
  producto_unidad: string
  producto_stock_actual: number
  producto_stock_reservado: number
  producto_stock_disponible: number
  producto_costo_actual: number
  cantidad_solicitada: number
  cantidad_despachada: number
  cantidad_devuelta: number
  costo_unitario: number
  costo_total: number
  observaciones?: string
}

export interface OrdenEntregaDevolucionItem {
  id: number
  numero_devolucion: string
  fecha_devolucion: string
  motivo: string
  costo_total_devuelto: number
  usuario_recibe_nombre: string
}

export interface OrdenEntregaAuditoriaItem {
  id: number
  accion: string
  detalles_json: string
  ip_address: string
  created_at: string
  usuario_nombre: string
}

export interface OrdenEntregaDetail extends OrdenEntregaListItem {
  solicitante_id: number | null
  usuario_despacho_id: number
  created_at: string
  updated_at: string
}

export interface OrdenEntregaKPIs {
  total_despachos_mes: number
  total_unidades_entregadas: number
  ordenes_pendientes: number
}

export interface OrdenesEntregaListResponse {
  ordenes: OrdenEntregaListItem[]
  kpis: OrdenEntregaKPIs
  total: number
  limit: number
  offset: number
}

export interface OrdenEntregaDetailResponse {
  orden: OrdenEntregaDetail
  items: OrdenEntregaItem[]
  devoluciones?: OrdenEntregaDevolucionItem[]
  auditoria?: OrdenEntregaAuditoriaItem[]
}

export interface ItemOrdenEntregaInput {
  producto_id: number
  cantidad_solicitada: number
  observaciones?: string
}

export interface CrearOrdenEntregaInput {
  tipo_destino: TipoDestinoOrdenEntrega
  departamento_id?: number | null
  centro_costo_id?: number | null
  solicitante_id?: number | null
  justificacion: string
  observaciones?: string
  estado?: 'borrador' | 'aprobada'
  items: ItemOrdenEntregaInput[]
}

export interface DevolucionItemInput {
  orden_entrega_item_id: number
  cantidad_devuelta: number
  observaciones?: string
}

export interface DevolucionInput {
  motivo: string
  items: DevolucionItemInput[]
}

export interface DepartamentoOption {
  id: number
  nombre: string
  codigo?: string
}

export interface ProductoSearchOption {
  id: number
  codigo: string
  nombre: string
  unidad_medida: string
  existencias: number
  stock_reservado?: number
  costo: number
}

const API_BASE =
  (import.meta.env?.VITE_API_BASE as string | undefined) ??
  (window.__APP_CONTEXT__?.baseUrl ?? '')

export async function fetchOrdenesEntregaList(params: {
  q?: string
  estado?: string
  departamento_id?: number
  centro_costo_id?: number
  limit?: number
  offset?: number
}): Promise<OrdenesEntregaListResponse> {
  const query = new URLSearchParams()
  if (params.q) query.append('q', params.q)
  if (params.estado) query.append('estado', params.estado)
  if (params.departamento_id) query.append('departamento_id', String(params.departamento_id))
  if (params.centro_costo_id) query.append('centro_costo_id', String(params.centro_costo_id))
  if (params.limit) query.append('limit', String(params.limit))
  if (params.offset) query.append('offset', String(params.offset))

  const endpoint = `${API_BASE}api/inventario/ordenes-entrega?${query.toString()}`
  const response = await fetch(endpoint, {
    method: 'GET',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
  })

  if (!response.ok) {
    const errText = await response.text()
    let msg = 'Error al cargar listado de órdenes de entrega.'
    try {
      const errJson = JSON.parse(errText)
      if (errJson.message) msg = errJson.message
    } catch {}
    throw new Error(msg)
  }

  const rawText = await response.text()
  if (!rawText.trim()) {
    return {
      ordenes: [],
      kpis: { total_despachos_mes: 0, total_unidades_entregadas: 0, ordenes_pendientes: 0 },
      total: 0,
      limit: 50,
      offset: 0,
    }
  }

  const json = JSON.parse(rawText)
  return json.data ?? json
}

export async function fetchOrdenEntregaDetail(id: number): Promise<OrdenEntregaDetailResponse> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega/${id}`
  const response = await fetch(endpoint, {
    method: 'GET',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
  })

  if (!response.ok) {
    const errText = await response.text()
    let msg = 'Error al cargar detalle de la orden de entrega.'
    try {
      const errJson = JSON.parse(errText)
      if (errJson.message) msg = errJson.message
    } catch {}
    throw new Error(msg)
  }

  const rawText = await response.text()
  if (!rawText.trim()) {
    throw new Error('La respuesta del servidor estuvo vacía.')
  }
  const json = JSON.parse(rawText)
  return json.data ?? json
}

export async function crearOrdenEntrega(input: CrearOrdenEntregaInput): Promise<{ id: number; numero_orden: string; estado: string; hash_verificacion?: string }> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega`
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input),
  })

  const rawText = await response.text()
  let json: any = null
  try {
    json = JSON.parse(rawText)
  } catch {
    throw new Error('Respuesta no válida del servidor al crear la orden.')
  }

  if (!response.ok || json?.success === false) {
    throw new Error(json?.message || 'Error al registrar la orden de entrega.')
  }

  return json.data
}

export async function actualizarOrdenEntrega(
  id: number,
  input: Partial<CrearOrdenEntregaInput>
): Promise<{ id: number; numero_orden: string; estado: string }> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega/${id}`
  const response = await fetch(endpoint, {
    method: 'PUT',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input),
  })

  const rawText = await response.text()
  let json: any = null
  try {
    json = JSON.parse(rawText)
  } catch {
    throw new Error('Respuesta no válida del servidor al actualizar la orden.')
  }

  if (!response.ok || json?.success === false) {
    throw new Error(json?.message || 'Error al actualizar la orden de entrega.')
  }

  return json.data
}

export async function despacharOrdenEntrega(
  id: number,
  cantidadesDespacho?: Record<number, number>
): Promise<{ id: number; estado: string; costo_total_despacho: number; hash_verificacion?: string }> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega/${id}/despachar`
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cantidades_despacho: cantidadesDespacho }),
  })

  const rawText = await response.text()
  let json: any = null
  try {
    json = JSON.parse(rawText)
  } catch {
    const cleanText = rawText.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim()
    console.error('[despacharOrdenEntrega] Respuesta bruta:', rawText)
    throw new Error(cleanText ? `Error en servidor: ${cleanText.substring(0, 150)}` : 'Respuesta no válida del servidor al procesar el despacho.')
  }

  if (!response.ok || json?.success === false) {
    throw new Error(json?.message || 'Error al despachar la orden de entrega.')
  }

  return json.data ?? json
}

export async function devolucionOrdenEntrega(
  id: number,
  input: DevolucionInput
): Promise<{ id: number; numero_devolucion: string; costo_total_devuelto: number }> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega/${id}/devolucion`
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input),
  })

  const rawText = await response.text()
  let json: any = null
  try {
    json = JSON.parse(rawText)
  } catch {
    throw new Error('Respuesta no válida del servidor al registrar la devolución.')
  }

  if (!response.ok || json?.success === false) {
    throw new Error(json?.message || 'Error al procesar la devolución física.')
  }

  return json.data
}

export async function cancelarReservaOrdenEntrega(id: number): Promise<{ id: number; estado: string }> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega/${id}/cancelar-reserva`
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
  })

  const rawText = await response.text()
  let json: any = null
  try {
    json = JSON.parse(rawText)
  } catch {
    throw new Error('Respuesta no válida del servidor al cancelar la reserva.')
  }

  if (!response.ok || json?.success === false) {
    throw new Error(json?.message || 'Error al cancelar la reserva de stock.')
  }

  return json.data
}

export async function anularOrdenEntrega(id: number): Promise<{ id: number; estado: string }> {
  const endpoint = `${API_BASE}api/inventario/ordenes-entrega/${id}/anular`
  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
  })

  const rawText = await response.text()
  let json: any = null
  try {
    json = JSON.parse(rawText)
  } catch {
    throw new Error('Respuesta no válida del servidor al anular la orden.')
  }

  if (!response.ok || json?.success === false) {
    throw new Error(json?.message || 'Error al anular la orden de entrega.')
  }

  return json.data
}

export async function fetchDepartamentosList(): Promise<DepartamentoOption[]> {
  try {
    const endpoint = `${API_BASE}api/inventario/departamentos`
    const response = await fetch(endpoint, {
      method: 'GET',
      credentials: 'include',
    })
    const rawText = await response.text()
    let json: any = null
    try {
      json = JSON.parse(rawText)
    } catch {}

    if (json && json.success && Array.isArray(json.data?.departamentos)) {
      return json.data.departamentos
    }
    if (json && Array.isArray(json.departamentos)) {
      return json.departamentos
    }
  } catch (err) {
    console.error('Error al consultar lista dinámica de departamentos:', err)
  }

  return []
}

export function getEstadoOrdenMeta(estado: EstadoOrdenEntrega | string): { label: string; colorClass: string } {
  switch (estado) {
    case 'despachada':
      return {
        label: 'Despachada (Total)',
        colorClass: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-300/80',
      }
    case 'despachada_parcial':
      return {
        label: 'Despachada Parcial',
        colorClass: 'bg-cyan-100 text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300 border-cyan-300/80',
      }
    case 'aprobada':
      return {
        label: 'Aprobada (Stock Reservado)',
        colorClass: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-300/80',
      }
    case 'reserva_vencida':
      return {
        label: 'Reserva Vencida',
        colorClass: 'bg-orange-100 text-orange-800 dark:bg-orange-950/60 dark:text-orange-300 border-orange-300/80',
      }
    case 'borrador':
      return {
        label: 'Borrador',
        colorClass: 'bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-400 border-slate-300/80',
      }
    case 'anulada':
      return {
        label: 'Anulada',
        colorClass: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border-rose-300/80',
      }
    default:
      return {
        label: estado,
        colorClass: 'bg-slate-100 text-slate-700 border-slate-300/80',
      }
  }
}
