const API_BASE =
  (import.meta.env?.VITE_API_BASE as string | undefined) ??
  (window.__APP_CONTEXT__?.baseUrl ?? '')

/**
 * Representa un registro resumido de requisición para el listado.
 */
export interface RequisicionListItem {
  id: number
  numero: string | null
  fecha_solicitud: string
  fecha_requerida: string
  estado: string
  prioridad: string
  tipo_requisicion: string
  monto_total_bs: number
  monto_total_usd: number
  moneda_original: string
  solicitante: string | null
  proveedor: {
    nombre: string | null
    rif: string | null
    telefono: string | null
    email: string | null
  }
  presupuesto: {
    id: number | null
    descripcion: string | null
    partida: {
      codigo: string | null
      nombre: string | null
    }
  }
  aprobaciones: {
    nivel_1: string
    nivel_2: string
    validacion_presupuestaria: string
  }
  orden_pago: {
    estado: string | null
    monto: number | null
  }
  observaciones: {
    publica: string | null
    interna: string | null
  }
}

export interface RequisicionesSummary {
  total: number
  pendientes: number
  aprobadas: number
}

export interface RequisicionesListResponse {
  items: RequisicionListItem[]
  summary: RequisicionesSummary
  tasa_cambio: number
  meta: {
    limit: number
    offset: number
  }
}

export type RequisicionProveedorDetail = RequisicionListItem['proveedor'] & {
  id: number | null
  direccion: string | null
}

export interface RequisicionDetail {
  requisicion: {
    id: number
    numero: string | null
    estado: string
    prioridad: string
    tipo_requisicion: string
    fecha_solicitud: string
    fecha_requerida: string
    fecha_aprobacion_1: string | null
    fecha_aprobacion_2: string | null
    justificacion: string | null
    observaciones: string | null
    observaciones_internas: string | null
    moneda: string
    tasa_cambio: number
    monto_total_bs: number
    monto_total_usd: number
    subtotal: number
    impuestos: number
    total: number
    monto_presupuestario: number
    validacion_presupuestaria: string | null
    aprobaciones: RequisicionListItem['aprobaciones'] & {
      aprobador_nivel_1: string | null
      aprobador_nivel_2: string | null
    }
  }
  solicitante: {
    id: number | null
    nombre: string | null
    email: string | null
  }
  proveedor: RequisicionProveedorDetail
  presupuesto: RequisicionListItem['presupuesto'] & {
    partida: {
      id: number | null
      codigo: string | null
      nombre: string | null
    }
  }
  items: {
    id: number
    descripcion: string
    unidad: string
    cantidad: number
    precio: number
    impuesto: number
    total_linea: number
    es_producto_catalogo: boolean
    producto_id: number | null
    solicitar_catalogar: boolean
    categoria_sugerida: string | null
  }[]
  historial: {
    id: number
    estado_desde: string | null
    estado_hasta: string | null
    comentario: string | null
    fecha: string
    usuario: string | null
  }[]
  orden_pago: {
    id: number
    numero: string | null
    estado: string | null
    monto: number | null
    fecha_orden: string | null
    fecha_pago: string | null
  } | null
}

export type RequisicionAccion = 'guardar' | 'enviar'

export interface RequisicionProveedorInput {
  id?: number | null
  nombre?: string
  rif?: string
  telefono?: string
  email?: string
  direccion?: string
}

export interface RequisicionFormInput {
  fecha_solicitud: string
  fecha_requerida: string
  prioridad: string
  moneda: string
  tipo_requisicion: string
  presupuesto_id?: number | null
  monto_presupuestario?: number
  observaciones?: string
  observaciones_internas?: string
  proveedor: RequisicionProveedorInput
}

export interface RequisicionItemInput {
  descripcion: string
  unidad: string
  cantidad: number
  precio: number
  impuesto?: number
  es_producto_catalogo?: boolean
  producto_id?: number | null
  solicitar_catalogar?: boolean
  categoria_sugerida?: string | null
}

export interface GuardarRequisicionInput {
  id?: number
  accion?: RequisicionAccion
  requisicion: RequisicionFormInput
  items: RequisicionItemInput[]
}

export interface GuardarRequisicionResponse {
  id: number
  estado: string
  numero: string | null
  totales: {
    subtotal: number
    impuestos: number
    total: number
  }
}

export interface CambiarEstadoInput {
  estado_nuevo: 'enviada' | 'pendiente_presupuesto' | 'aprobada' | 'rechazada' | 'anulada'
  comentario: string
}

export interface CambiarEstadoResponse {
  id: number
  estado_anterior: string
  estado_nuevo: string
  numero: string | null
  compromiso_id?: number | null
}

export async function fetchRequisicionDetail(
  id: number,
): Promise<RequisicionDetail> {
  const endpoint = `${API_BASE}api/requisiciones/show.php?id=${encodeURIComponent(String(id))}`

  const response = await fetch(endpoint, { credentials: 'include' })
  if (!response.ok) {
    const payload = await safeJson(response)
    const errorMessage =
      (payload && payload.message) ||
      `Error ${response.status}: ${response.statusText}`
    throw new Error(errorMessage)
  }

  const json = (await response.json()) as {
    success: boolean
    data?: RequisicionDetail
    message?: string
  }

  if (!json.success || !json.data) {
    throw new Error(json.message || 'No se pudo obtener la requisición')
  }

  return json.data
}

export async function cambiarEstadoRequisicion(
  id: number,
  input: CambiarEstadoInput,
): Promise<CambiarEstadoResponse> {
  const endpoint = `${API_BASE}api/requisiciones/${id}/estado`

  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(input),
  })

  const rawText = await response.text()
  let json: { success?: boolean; data?: CambiarEstadoResponse; message?: string } | null = null

  try {
    json = JSON.parse(rawText)
  } catch {
    const cleanText = rawText.replace(/<[^>]*>/g, '').trim()
    throw new Error(cleanText.slice(0, 150) || `Error ${response.status}: Respuesta del servidor no válida`)
  }

  if (!response.ok || !json?.success || !json?.data) {
    throw new Error(json?.message || `Error ${response.status}: No se pudo cambiar el estado`)
  }

  return json.data
}

export async function guardarRequisicion(
  payload: GuardarRequisicionInput,
): Promise<GuardarRequisicionResponse> {
  const endpoint = `${API_BASE}api/requisiciones/save.php`

  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })

  if (!response.ok) {
    const payloadJson = await safeJson(response)
    const errorMessage =
      (payloadJson && (payloadJson.message as string | undefined)) ||
      `Error ${response.status}: ${response.statusText}`
    throw new Error(errorMessage)
  }

  const json = (await response.json()) as {
    success: boolean
    data?: GuardarRequisicionResponse
    message?: string
  }

  if (!json.success || !json.data) {
    throw new Error(json.message || 'No se pudo guardar la requisición')
  }

  return json.data
}

export interface RequisicionesListFilters {
  q?: string
  estado?: string
  prioridad?: string
  fechaDesde?: string
  fechaHasta?: string
  limit?: number
  offset?: number
}

export async function fetchRequisicionesList(
  filters: RequisicionesListFilters,
): Promise<RequisicionesListResponse> {
  const params = new URLSearchParams()

  if (filters.q) params.set('q', filters.q)
  if (filters.estado) params.set('estado', filters.estado)
  if (filters.prioridad) params.set('prioridad', filters.prioridad)
  if (filters.fechaDesde) params.set('fecha_desde', filters.fechaDesde)
  if (filters.fechaHasta) params.set('fecha_hasta', filters.fechaHasta)

  params.set('limit', String(filters.limit ?? 200))
  params.set('offset', String(filters.offset ?? 0))

  const endpoint = `${API_BASE}api/requisiciones/index.php`
  const response = await fetch(`${endpoint}?${params.toString()}`, {
    credentials: 'include',
  })

  if (!response.ok) {
    const payload = await safeJson(response)
    const errorMessage =
      (payload && payload.message) ||
      `Error ${response.status}: ${response.statusText}`
    throw new Error(errorMessage)
  }

  const json = (await response.json()) as {
    success: boolean
    data?: RequisicionesListResponse
    message?: string
  }

  if (!json.success || !json.data) {
    throw new Error(json.message || 'No se pudo obtener el listado')
  }

  return json.data
}

export interface PresupuestoResumen {
  id: number
  cuenta_id: number | null
  cuenta_codigo: string | null
  cuenta_nombre: string | null
  tipo_accion?: string | null
  proyecto_nombre?: string | null
  proyecto_codigo?: string | null
  proyecto_info?: string | null
  periodo_nombre: string | null
  descripcion: string | null
  saldo_disponible: number | null
}

export async function buscarPresupuestos(
  query: string,
  tipoFiltro: string = 'todos',
): Promise<PresupuestoResumen[]> {
  try {
    const endpoint = `${API_BASE}api/requisiciones/buscar_presupuestos.php?q=${encodeURIComponent(query)}&tipo_filtro=${encodeURIComponent(tipoFiltro)}`
    const response = await fetch(endpoint, { credentials: 'include' })
    if (!response.ok) return []
    const json = (await response.json()) as { success: boolean; presupuestos?: PresupuestoResumen[] }
    return json.success && Array.isArray(json.presupuestos) ? json.presupuestos : []
  } catch {
    return []
  }
}

export interface ProveedorResumen {
  id: number
  nombre: string
  rif: string
  telefono?: string
  email?: string
  direccion?: string
}

export async function buscarProveedores(query: string = ''): Promise<ProveedorResumen[]> {
  try {
    const endpoint = `${API_BASE}api/requisiciones/buscar_proveedores.php?q=${encodeURIComponent(query)}`
    const response = await fetch(endpoint, { credentials: 'include' })
    if (!response.ok) return []
    const json = (await response.json()) as { success: boolean; proveedores?: ProveedorResumen[] }
    return json.success && Array.isArray(json.proveedores) ? json.proveedores : []
  } catch {
    return []
  }
}

async function safeJson(response: Response) {
  try {
    return await response.json()
  } catch {
    return null
  }
}

export interface RequisicionEstadoMeta {
  label: string
  colorClass: string
}

export function getRequisicionEstadoMeta(estado: string | null | undefined): RequisicionEstadoMeta {
  const estLower = (estado || '').toLowerCase().trim()

  switch (estLower) {
    case 'pendiente_presupuesto':
    case 'pendiente_nivel_2':
      return {
        label: 'PENDIENTE POR PRESUPUESTO',
        colorClass: 'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300 border-blue-300/80',
      }
    case 'aprobada':
      return {
        label: 'APROBADA',
        colorClass: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300 border-emerald-300/80',
      }
    case 'recibida':
      return {
        label: 'RECIBIDA',
        colorClass: 'bg-purple-100 text-purple-800 dark:bg-purple-950/60 dark:text-purple-300 border-purple-300/80',
      }
    case 'rechazada':
      return {
        label: 'RECHAZADA',
        colorClass: 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300 border-rose-300/80',
      }
    case 'anulada':
      return {
        label: 'ANULADA',
        colorClass: 'bg-slate-100 text-slate-800 dark:bg-slate-900 dark:text-slate-300 border-slate-300/80',
      }
    case 'borrador':
      return {
        label: 'BORRADOR',
        colorClass: 'bg-slate-100 text-slate-700 dark:bg-slate-900 dark:text-slate-400 border-slate-300/80',
      }
    case 'enviada':
    case 'pendiente':
    case 'pendiente_direccion':
    default:
      return {
        label: 'PENDIENTE POR DIRECCIÓN EJECUTIVA',
        colorClass: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300 border-amber-300/80',
      }
  }
}
