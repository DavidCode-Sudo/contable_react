import { apiClient } from '@/lib/apiClient';

export interface ConfiguracionCuenta {
  id: number;
  concepto: string;
  cuenta_codigo: string;
  descripcion?: string;
  activa: number | boolean;
  cuenta_nombre?: string;
  cuenta_id?: number;
  fecha_actualizacion?: string;
}

export interface ConfiguracionCuentasResponse {
  items: ConfiguracionCuenta[];
  total: number;
}

export interface CrearFaltantesResponse {
  mensaje: string;
  creadas: number;
  errores: number;
  detalles: string[];
}

export const configuracionCuentasService = {
  getAll: async (): Promise<ConfiguracionCuentasResponse> => {
    return apiClient<ConfiguracionCuentasResponse>('api/contabilidad/configuracion-cuentas');
  },

  update: async (
    id: number,
    payload: { cuenta_codigo: string; descripcion?: string; activa?: boolean }
  ): Promise<{ mensaje: string }> => {
    return apiClient<{ mensaje: string }>(`api/contabilidad/configuracion-cuentas/${id}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  crearFaltantes: async (): Promise<CrearFaltantesResponse> => {
    return apiClient<CrearFaltantesResponse>('api/contabilidad/configuracion-cuentas/crear-faltantes', {
      method: 'POST',
    });
  },
};
