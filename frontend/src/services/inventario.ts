const API_BASE =
  (import.meta.env?.VITE_API_BASE as string | undefined) ??
  (window.__APP_CONTEXT__?.baseUrl ?? '')

export interface Producto {
  id: number
  codigo: string
  nombre: string
  descripcion: string | null
  costo: number
  precio: number
  existencias: number
  stock_reservado?: number
  stock_disponible?: number
  stock_minimo: number
  stock_maximo: number
  punto_reorden: number
  unidad_medida: string
  ubicacion: string | null
  estado: 'activo' | 'inactivo'
  alerta_stock: 'normal' | 'bajo_stock' | 'sin_stock'
  categoria: {
    id: number | null
    nombre: string
  }
  proveedor?: {
    id: number | null
    nombre: string | null
  }
  created_at?: string
  updated_at?: string
}

export interface CategoriaProducto {
  id: number
  nombre: string
  descripcion: string | null
  cuenta_contable_id?: number | null
  cuenta_codigo?: string | null
  cuenta_nombre?: string | null
  estado: 'activo' | 'inactivo'
  total_productos: number
}

export async function saveCategoria(payload: { 
  id?: number | null; 
  nombre: string; 
  descripcion?: string; 
  cuenta_contable_id?: number | null;
  estado: 'activo' | 'inactivo' 
}): Promise<{ success: boolean; message: string; id: number }> {
  const endpoint = `${API_BASE}api/inventario/categorias`
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    credentials: 'include',
  })
  return await parseJsonResponse<{ success: boolean; message: string; id: number }>(res, 'Error al guardar categoría.')
}

export interface MovimientoInventario {
  id: number
  producto_id: number
  producto_codigo: string | null
  producto_nombre: string
  unidad_medida: string
  cantidad: number
  tipo: 'entrada' | 'salida'
  razon: string
  motivo_codigo?: string | null
  documento_referencia?: string | null
  precio_unitario: number
  valor_total: number
  stock_anterior: number
  stock_nuevo: number
  usuario: string
  requisicion?: {
    id: number
    numero: string | null
  } | null
  fecha: string
}

export interface InventarioSummary {
  total_productos: number
  sin_stock: number
  bajo_stock: number
  stock_normal: number
  valor_total_inventario: number
}

export interface InventarioListFilters {
  q?: string
  categoria_id?: number | null
  alerta_stock?: string
  limit?: number
  offset?: number
}

export interface InventarioListResponse {
  success: boolean
  items: Producto[]
  summary: InventarioSummary
  proximo_codigo?: string
  meta: {
    total: number
    limit: number
    offset: number
  }
}

export interface GuardarProductoPayload {
  id?: number | null
  codigo?: string
  nombre: string
  descripcion?: string
  costo: number
  precio: number
  stock_minimo: number
  stock_maximo: number
  unidad_medida: string
  ubicacion?: string
  categoria_id?: number | null
  estado: 'activo' | 'inactivo'
  cantidad_inicial?: number
  tipo_ingreso?: string
  documento_referencia?: string
  observaciones_ingreso?: string
}

export interface AjustarStockPayload {
  producto_id: number
  cantidad: number
  tipo: 'entrada' | 'salida'
  razon?: string
  motivo?: string
  motivo_label?: string
  documento_referencia?: string
  observaciones?: string
  costo_unitario?: number | null
}

export const inventarioKeys = {
  all: ['inventario'] as const,
  productosList: (filters?: InventarioListFilters) => [...inventarioKeys.all, 'productos', filters] as const,
  productoDetail: (id: number) => [...inventarioKeys.all, 'producto', id] as const,
  categorias: () => [...inventarioKeys.all, 'categorias'] as const,
  movimientos: (filters?: any) => [...inventarioKeys.all, 'movimientos', filters] as const,
}

async function parseJsonResponse<T>(res: Response, defaultMessage: string): Promise<T> {
  const contentType = res.headers.get('content-type') ?? ''

  if (!res.ok || !contentType.includes('application/json')) {
    let rawText = ''
    try {
      rawText = await res.text()
      const json = JSON.parse(rawText)
      const msg = json?.message || (Array.isArray(json?.errors) ? json.errors.join(' ') : null) || defaultMessage
      throw new Error(msg)
    } catch (e: any) {
      if (e.message && !e.message.startsWith('Unexpected token') && !e.message.includes('JSON')) {
        throw e
      }
      if (rawText.includes('<') && rawText.includes('>')) {
        const stripped = rawText.replace(/<[^>]*>?/gm, ' ').replace(/\s+/g, ' ').trim()
        throw new Error(stripped.length > 150 ? stripped.substring(0, 150) + '...' : (stripped || defaultMessage))
      }
      throw new Error(rawText || defaultMessage)
    }
  }

  const json = await res.json()
  if (json && typeof json === 'object' && 'success' in json && json.success === false) {
    throw new Error(json.message || defaultMessage)
  }
  return json as T
}

export async function fetchProductosList(filters: InventarioListFilters = {}): Promise<InventarioListResponse> {
  const params = new URLSearchParams()
  if (filters.q) params.set('q', filters.q)
  if (filters.categoria_id) params.set('categoria_id', String(filters.categoria_id))
  if (filters.alerta_stock) params.set('alerta_stock', filters.alerta_stock)
  if (filters.limit) params.set('limit', String(filters.limit))
  if (filters.offset) params.set('offset', String(filters.offset))

  const endpoint = `${API_BASE}api/inventario/productos?${params.toString()}`
  const res = await fetch(endpoint, { credentials: 'include' })
  return await parseJsonResponse<InventarioListResponse>(res, 'Error al consultar catálogo de inventario.')
}

export async function fetchProductoDetail(id: number): Promise<{ success: boolean; producto: Producto; movimientos_recientes: MovimientoInventario[] }> {
  const endpoint = `${API_BASE}api/inventario/productos/${id}`
  const res = await fetch(endpoint, { credentials: 'include' })
  return await parseJsonResponse<{ success: boolean; producto: Producto; movimientos_recientes: MovimientoInventario[] }>(res, `Error al consultar producto #${id}.`)
}

export async function saveProducto(payload: GuardarProductoPayload): Promise<{ success: boolean; message: string; id: number }> {
  const endpoint = `${API_BASE}api/inventario/productos`
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    credentials: 'include',
  })
  return await parseJsonResponse<{ success: boolean; message: string; id: number }>(res, 'Error al guardar producto.')
}

export async function ajustarStock(payload: AjustarStockPayload): Promise<{ success: boolean; message: string; resultado: any }> {
  const endpoint = `${API_BASE}api/inventario/ajustes`
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    credentials: 'include',
  })
  return await parseJsonResponse<{ success: boolean; message: string; resultado: any }>(res, 'Error al ajustar stock.')
}

export async function fetchCategorias(): Promise<CategoriaProducto[]> {
  const endpoint = `${API_BASE}api/inventario/categorias`
  const res = await fetch(endpoint, { credentials: 'include' })
  try {
    const json = await parseJsonResponse<{ success: boolean; categorias: CategoriaProducto[] }>(res, 'Error al consultar categorías.')
    return json.success && Array.isArray(json.categorias) ? json.categorias : []
  } catch {
    return []
  }
}

export async function fetchMovimientos(filters: { q?: string; tipo?: string; producto_id?: number | null } = {}): Promise<{ success: boolean; movimientos: MovimientoInventario[]; meta: { total: number } }> {
  const params = new URLSearchParams()
  if (filters.q) params.set('q', filters.q)
  if (filters.tipo) params.set('tipo', filters.tipo)
  if (filters.producto_id) params.set('producto_id', String(filters.producto_id))

  const endpoint = `${API_BASE}api/inventario/movimientos?${params.toString()}`
  const res = await fetch(endpoint, { credentials: 'include' })
  return await parseJsonResponse<{ success: boolean; movimientos: MovimientoInventario[]; meta: { total: number } }>(res, 'Error al consultar trazabilidad de movimientos.')
}

export async function fetchDepartamentosList(): Promise<{ id: number; nombre: string }[]> {
  try {
    const endpoint = `${API_BASE}api/inventario/departamentos`
    const res = await fetch(endpoint, { credentials: 'include' })
    const json = await res.json()
    if (json && json.success && Array.isArray(json.data?.departamentos)) {
      return json.data.departamentos
    }
    if (json && Array.isArray(json.departamentos)) {
      return json.departamentos
    }
    if (json && Array.isArray(json.data)) {
      return json.data
    }
    return []
  } catch {
    return []
  }
}

export const inventarioService = {
  getDepartamentos: fetchDepartamentosList,
  getCategorias: fetchCategorias,
  saveCategoria,
  saveProducto,
  ajustarStock,
  fetchProductoDetail,
  fetchMovimientos,
}

