import { apiClient, type ApiResponse } from '@/lib/apiClient';

export type TipoOperacion = 'pago' | 'compromiso' | 'causacion' | 'devengado' | 'gasto' | 'ingreso' | 'otro';

export interface MatrizConversion {
  id: number;
  partida_presupuestaria_id: number;
  tipo_operacion: TipoOperacion;
  cuenta_contable_debe_id: number;
  cuenta_contable_haber_id?: number | null;
  descripcion?: string;
  estado: 'activa' | 'inactiva';
  
  clasificador_economico_codigo?: string | null;
  clasificador_economico_nombre?: string | null;
  accion_centralizada_proyecto?: string | null;

  // Nombres y códigos resueltos desde los JOINs
  partida_codigo?: string;
  partida_codigo_completo?: string;
  partida_nombre?: string;
  debe_codigo?: string;
  debe_nombre?: string;
  haber_codigo?: string;
  haber_nombre?: string;
}

export interface MatrizPayload {
  partida_presupuestaria_id: number;
  tipo_operacion: TipoOperacion;
  cuenta_contable_debe_id: number;
  cuenta_contable_haber_id?: number | null;
  descripcion?: string;
  estado: 'activa' | 'inactiva';
  clasificador_economico_codigo?: string;
  clasificador_economico_nombre?: string;
  accion_centralizada_proyecto?: string;
}

export interface MatrizParams {
  page?: number;
  limit?: number;
  tipo_operacion?: string;
  estado?: string;
  q?: string;
}

export interface MatrizResponse {
  items: MatrizConversion[];
  paginacion: {
    total: number;
    page: number;
    limit: number;
    pages: number;
  };
  estadisticas?: {
    total_general: number;
    activas: number;
    gastos: number;
    ingresos: number;
  };
}

export interface SearchCuentaItem {
  id: number;
  codigo: string;
  codigo_completo?: string;
  nombre: string;
  categoria?: string;
  tipo?: string;
  naturaleza?: string;
}

export const matrizConversionService = {
  getAll: async (params: MatrizParams = {}): Promise<MatrizResponse> => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, val]) => {
      if (val !== undefined && val !== '') {
        query.append(key, val.toString());
      }
    });
    return apiClient<MatrizResponse>(`api/catalogo/matriz?${query.toString()}`);
  },

  create: async (payload: MatrizPayload): Promise<{ id: number; mensaje: string }> => {
    return apiClient<{ id: number; mensaje: string }>('api/catalogo/matriz', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  update: async (id: number, payload: MatrizPayload): Promise<{ mensaje: string }> => {
    return apiClient<{ mensaje: string }>(`api/catalogo/matriz/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  toggleEstado: async (id: number, estadoTarget: 'activa' | 'inactiva'): Promise<void> => {
    await apiClient<void>(`api/catalogo/matriz/${id}/estado`, {
      method: 'PATCH',
      body: JSON.stringify({ estado: estadoTarget }),
    });
  },

  searchPartidas: async (q: string): Promise<SearchCuentaItem[]> => {
    const res = await apiClient<{ items: SearchCuentaItem[] }>(`api/catalogo/cuentas/search-partidas?q=${encodeURIComponent(q)}&limit=1000`);
    return res?.items || [];
  },

  searchContables: async (q: string): Promise<SearchCuentaItem[]> => {
    const res = await apiClient<{ items: SearchCuentaItem[] }>(`api/catalogo/cuentas/search-contables?q=${encodeURIComponent(q)}&limit=1000`);
    return res?.items || [];
  },

  importarMasivo: async (formData: FormData): Promise<{ mensaje: string; procesados: number; insertados: number; errores: number; detalles: string[] }> => {
    return apiClient<{ mensaje: string; procesados: number; insertados: number; errores: number; detalles: string[] }>('api/catalogo/matriz/importar', {
      method: 'POST',
      body: formData,
      headers: {},
    });
  },

  obtenerUrlPlantilla: (): string => {
    const API_BASE = (import.meta.env?.VITE_API_BASE as string | undefined) ?? '';
    return `${API_BASE.replace(/\/$/, '')}/api/catalogo/matriz/plantilla`;
  },

  obtenerUrlExportacion: (): string => {
    const API_BASE = (import.meta.env?.VITE_API_BASE as string | undefined) ?? '';
    return `${API_BASE.replace(/\/$/, '')}/api/catalogo/matriz/exportar`;
  },

  vaciarMatriz: async (): Promise<{ mensaje: string }> => {
    return apiClient<{ mensaje: string }>('api/catalogo/matriz/vaciar', {
      method: 'POST',
    });
  },

  deshacerUltimoLote: async (): Promise<{ mensaje: string; eliminados: number; lote_id: string }> => {
    return apiClient<{ mensaje: string; eliminados: number; lote_id: string }>('api/catalogo/matriz/deshacer-ultimo-lote', {
      method: 'POST',
    });
  }
};
