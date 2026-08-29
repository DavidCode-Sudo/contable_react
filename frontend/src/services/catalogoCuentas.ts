import { apiClient, getApiUrl, type ApiResponse } from '@/lib/apiClient';

export type TipoCuenta = 'activo' | 'pasivo' | 'patrimonio' | 'ingreso' | 'gasto' | 'orden' | 'cierre' | '';
export type NaturalezaCuenta = 'deudora' | 'acreedora';
export type VistaCatalogo = 'contables' | 'presupuestarias' | 'configuracion';

export const NATURALEZA_SUGERIDA_MAP: Record<TipoCuenta, NaturalezaCuenta> = {
  activo: 'deudora',
  pasivo: 'acreedora',
  patrimonio: 'acreedora',
  ingreso: 'acreedora',
  gasto: 'deudora',
  orden: 'deudora',
  cierre: 'acreedora',
  '': 'deudora',
};

export function obtenerNaturalezaSugerida(tipo: TipoCuenta): NaturalezaCuenta {
  return NATURALEZA_SUGERIDA_MAP[tipo] || 'deudora';
}

export interface CuentaContable {
  id: number;
  codigo: string;
  codigo_display: string;
  nombre: string;
  tipo: TipoCuenta;
  naturaleza: NaturalezaCuenta;
  categoria: string;
  estado: 'activa' | 'inactiva';
  es_partida_presupuestaria: boolean;
  cuenta_padre_id?: number | null;
  cuenta_padre_nombre?: string | null;
  
  // Campos presupuestarios
  numero_partida?: string;
  generica?: string;
  especifica?: string;
  subespecifica?: string;
  
  // Campos calculados por el motor jerárquico SQL (Restaurados ONAPRE)
  nivel_partida_calculado?: string;
  nivel_indentacion: number;
  tipo_clasificacion: string;
  orden_jerarquico?: string;
  orden_jerarquico_completo?: string;
  grupo_clase?: number;
  subgrupo?: number;
  rubro?: number;
  codigo_padre?: string;
  saldo_actual: number;
}

export interface CatalogoParams {
  vista: VistaCatalogo;
  page?: number;
  limit?: number;
  tipo?: string;
  categoria?: string;
  estado?: string;
  q?: string;
}

export interface CatalogoResponse {
  cuentas: CuentaContable[];
  paginacion: {
    total: number;
    page: number;
    limit: number;
    pages: number;
  };
  estadisticas: {
    total_general: number;
    total_presupuestarias: number;
    activas_presupuestarias: number;
    total_contables: number;
    activas_contables: number;
  };
}

export interface SaveCuentaPayload {
  codigo: string;
  nombre: string;
  tipo: TipoCuenta;
  naturaleza: NaturalezaCuenta;
  categoria?: string;
  cuenta_padre_id?: number | null;
  estado: 'activa' | 'inactiva';
  es_partida_presupuestaria: boolean;
  numero_partida?: string;
  generica?: string;
  especifica?: string;
  subespecifica?: string;
}

export const catalogoCuentasService = {
  getAll: async (params: CatalogoParams): Promise<CatalogoResponse> => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== '') {
        query.append(key, value.toString());
      }
    });

    return apiClient<CatalogoResponse>(`api/catalogo/cuentas?${query.toString()}`);
  },

  getById: async (id: number): Promise<CuentaContable> => {
    return apiClient<CuentaContable>(`api/catalogo/cuentas/${id}`);
  },

  create: async (payload: SaveCuentaPayload): Promise<CuentaContable> => {
    return apiClient<CuentaContable>('api/catalogo/cuentas', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  update: async (id: number, payload: SaveCuentaPayload): Promise<CuentaContable> => {
    return apiClient<CuentaContable>(`api/catalogo/cuentas/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  toggleEstado: async (id: number, estadoTarget: 'activa' | 'inactiva'): Promise<void> => {
    await apiClient<void>(`api/catalogo/cuentas/${id}/estado`, {
      method: 'PATCH',
      body: JSON.stringify({ estado: estadoTarget }),
    });
  },

  crearInventario: async (): Promise<{ id: number; message: string }> => {
    return apiClient<{ id: number; message: string }>('api/catalogo/cuentas/crear-inventario', {
      method: 'POST',
    });
  },

  validarCampo: async (campo: 'codigo' | 'nombre', valor: string, omitirId?: number): Promise<{ valido: boolean; mensaje: string }> => {
    return apiClient<{ valido: boolean; mensaje: string }>('api/catalogo/cuentas/validar', {
      method: 'POST',
      body: JSON.stringify({ campo, valor, omitir_id: omitirId }),
    });
  },

  validarCodigoPartida: async (
    payload: { numero_partida: string; generica?: string; especifica?: string; subespecifica?: string; omitir_id?: number }
  ): Promise<{ valido: boolean; mensaje: string; codigo_completo?: string }> => {
    return apiClient<{ valido: boolean; mensaje: string; codigo_completo?: string }>('api/catalogo/cuentas/validar-partida', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  importarMasivo: async (formData: FormData): Promise<{
    mensaje: string;
    lote_id: string;
    procesados: number;
    insertados: number;
    actualizados: number;
    omitidos: number;
    errores: number;
    detalles_errores?: string[];
  }> => {
    return apiClient('api/catalogo/cuentas/importar', {
      method: 'POST',
      body: formData,
    });
  },

  deshacerUltimoLote: async (): Promise<{ mensaje: string; eliminados: number; lote_id: string }> => {
    return apiClient('api/catalogo/cuentas/deshacer-ultimo-lote', {
      method: 'POST',
    });
  },

  vaciarCatalogo: async (tipo: 'presupuestario' | 'patrimonial'): Promise<{ mensaje: string; eliminados: number }> => {
    return apiClient('api/catalogo/cuentas/vaciar', {
      method: 'POST',
      body: JSON.stringify({ tipo }),
    });
  },

  getExportUrl: (tipo?: string): string => {
    const query = tipo ? `?tipo=${encodeURIComponent(tipo)}` : '';
    return getApiUrl(`api/catalogo/cuentas/exportar${query}`);
  }
};
