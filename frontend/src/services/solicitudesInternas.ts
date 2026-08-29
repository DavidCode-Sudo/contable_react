import { apiClient, type ApiResponse } from '@/lib/apiClient';

export type EstadoSolicitudInterna =
  | 'borrador'
  | 'enviada'
  | 'aprobada'
  | 'procesada_parcial'
  | 'convertida'
  | 'derivada_compras'
  | 'rechazada'
  | 'anulada';

export type PrioridadSolicitud = 'baja' | 'media' | 'alta' | 'urgente';

export interface SolicitudCatalogoItem {
  id: number;
  codigo: string;
  nombre: string;
  unidad_medida: string;
  permite_decimales: boolean;
  disponible_para_solicitar: boolean;
  existencias?: number;
  stock_reservado?: number;
  stock_disponible?: number;
}

export interface SolicitudInternaListItem {
  id: number;
  numero_solicitud: string;
  anio: number;
  departamento_id: number;
  departamento_nombre: string;
  solicitante_id: number;
  solicitante_nombre: string;
  estado: EstadoSolicitudInterna;
  prioridad: PrioridadSolicitud;
  justificacion: string;
  observaciones_aprobacion?: string;
  usuario_aprobador_id?: number | null;
  aprobador_nombre?: string;
  fecha_solicitud: string;
  fecha_aprobacion?: string | null;
  total_items_distintos: number;
  total_unidades_solicitadas: number;
  orden_entrega_numero?: string | null;
}

export interface SolicitudInternaItemDetail {
  id: number;
  solicitud_interna_id: number;
  producto_id: number;
  producto_codigo: string;
  producto_nombre: string;
  producto_unidad: string;
  permite_decimales: boolean;
  cantidad_solicitada: number;
  cantidad_aprobada: number;
  estado_item: 'pendiente' | 'aprobado' | 'sin_stock_compras' | 'rechazado';
  observaciones?: string;
  disponible_para_solicitar: boolean;
  producto_stock_actual?: number;
  producto_stock_reservado?: number;
  producto_stock_disponible?: number;
}

export interface SolicitudInternaHistorialItem {
  id: number;
  solicitud_interna_id: number;
  usuario_id: number;
  usuario_nombre?: string;
  accion: string;
  estado_anterior?: string | null;
  estado_nuevo: string;
  observaciones?: string | null;
  ip_address: string;
  created_at: string;
}

export interface SolicitudInternaDetailResponse extends SolicitudInternaListItem {
  orden_entrega_id?: number | null;
  orden_entrega_estado?: string | null;
  items: SolicitudInternaItemDetail[];
  historial: SolicitudInternaHistorialItem[];
}

export interface SolicitudesInternasStats {
  borrador: number;
  enviada: number;
  convertida: number;
  procesada_parcial: number;
  derivada_compras: number;
  rechazada: number;
  anulada: number;
}

export interface SolicitudesInternasResponse {
  solicitudes: SolicitudInternaListItem[];
  paginacion: {
    total: number;
    page: number;
    limit: number;
    pages: number;
  };
  estadisticas: SolicitudesInternasStats;
}

export interface CreateSolicitudPayload {
  departamento_id: number;
  justificacion: string;
  prioridad?: PrioridadSolicitud;
  accion?: 'guardar' | 'enviar';
  items: {
    producto_id: number;
    cantidad_solicitada: number;
    observaciones?: string;
  }[];
}

export interface AprobarSolicitudPayload {
  observaciones?: string;
  items?: Record<number, number>;
}

export interface NecesidadProcuraItem {
  producto_id: number;
  producto_codigo: string;
  producto_nombre: string;
  producto_unidad: string;
  producto_stock_actual: number;
  total_cantidad_requerida: number;
  total_solicitudes_afectadas: number;
  total_departamentos_solicitantes: number;
}

export interface AprobarResponse {
  id: number;
  estado: string;
  orden_entrega_id?: number | null;
  orden_entrega_numero?: string | null;
  items_derivados_procura: number;
}

export const solicitudesInternasService = {
  getCatalogo: async (): Promise<SolicitudCatalogoItem[]> => {
    const res = await apiClient<any>('api/inventario/solicitudes-internas/catalogo');
    if (Array.isArray(res)) return res;
    return res?.data ?? res ?? [];
  },

  getAll: async (params?: {
    page?: number;
    limit?: number;
    estado?: string;
    departamento_id?: number;
    q?: string;
  }): Promise<SolicitudesInternasResponse> => {
    const query = new URLSearchParams();
    if (params?.page) query.append('page', params.page.toString());
    if (params?.limit) query.append('limit', params.limit.toString());
    if (params?.estado) query.append('estado', params.estado);
    if (params?.departamento_id) query.append('departamento_id', params.departamento_id.toString());
    if (params?.q) query.append('q', params.q);

    const queryString = query.toString() ? `?${query.toString()}` : '';
    const res = await apiClient<any>(`api/inventario/solicitudes-internas${queryString}`);
    return res?.data ?? res;
  },

  getById: async (id: number): Promise<SolicitudInternaDetailResponse> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}`);
    return res?.data ?? res;
  },

  create: async (payload: CreateSolicitudPayload): Promise<{ id: number; numero_solicitud: string; estado: string }> => {
    const res = await apiClient<any>('api/inventario/solicitudes-internas', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    return res?.data ?? res;
  },

  update: async (id: number, payload: CreateSolicitudPayload): Promise<{ id: number; estado: string }> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}/update`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    return res?.data ?? res;
  },

  retractar: async (id: number): Promise<{ id: number; estado: string }> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}/retractar`, {
      method: 'POST',
    });
    return res?.data ?? res;
  },

  enviar: async (id: number): Promise<{ id: number; estado: string }> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}/enviar`, {
      method: 'POST',
    });
    return res?.data ?? res;
  },

  aprobar: async (id: number, payload: AprobarSolicitudPayload): Promise<AprobarResponse> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}/aprobar`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    return res?.data ?? res;
  },

  rechazar: async (id: number, observaciones: string): Promise<{ id: number; estado: string }> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}/rechazar`, {
      method: 'POST',
      body: JSON.stringify({ observaciones }),
    });
    return res?.data ?? res;
  },

  anular: async (id: number, observaciones: string): Promise<{ id: number; estado: string }> => {
    const res = await apiClient<any>(`api/inventario/solicitudes-internas/${id}/anular`, {
      method: 'POST',
      body: JSON.stringify({ observaciones }),
    });
    return res?.data ?? res;
  },

  getNecesidadesCompras: async (): Promise<{ necesidades: NecesidadProcuraItem[] }> => {
    const res = await apiClient<any>('api/inventario/solicitudes-internas/necesidades-compras');
    return res?.data ?? res;
  },
};
