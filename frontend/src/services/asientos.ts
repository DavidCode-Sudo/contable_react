import { apiClient } from '@/lib/apiClient';
import type {
  Asiento,
  AsientoInput,
  AsientoFiltros,
  KeysetPaginationResponse,
  ResumenLibroMayor,
  MovimientoLibroMayor,
  FilaBalanceComprobacion,
} from '../types/asientos';

export const asientosService = {
  listar: async (filtros?: AsientoFiltros): Promise<KeysetPaginationResponse<Asiento>> => {
    const params = new URLSearchParams();
    if (filtros?.estado) params.append('estado', filtros.estado);
    if (filtros?.q) params.append('q', filtros.q);
    if (filtros?.last_fecha) params.append('last_fecha', filtros.last_fecha);
    if (filtros?.last_id) params.append('last_id', filtros.last_id.toString());
    if (filtros?.limit) params.append('limit', filtros.limit.toString());

    return apiClient<KeysetPaginationResponse<Asiento>>(
      `api/contabilidad/asientos?${params.toString()}`
    );
  },

  obtenerPorId: async (id: number): Promise<Asiento> => {
    const res = await apiClient<{ success: boolean; data: Asiento }>(
      `api/contabilidad/asientos/${id}`
    );
    return res.data;
  },

  crear: async (input: AsientoInput): Promise<{ id: number; numero: string }> => {
    const res = await apiClient<{ success: boolean; data: { id: number; numero: string }; message: string }>(
      'api/contabilidad/asientos',
      {
        method: 'POST',
        body: JSON.stringify(input),
      }
    );
    return res.data;
  },

  actualizar: async (id: number, input: Partial<AsientoInput>): Promise<void> => {
    await apiClient(`api/contabilidad/asientos/${id}`, {
      method: 'PUT',
      body: JSON.stringify(input),
    });
  },

  confirmar: async (id: number): Promise<void> => {
    await apiClient(`api/contabilidad/asientos/${id}/confirmar`, {
      method: 'POST',
    });
  },

  anular: async (id: number, fechaReversion?: string, motivo?: string): Promise<void> => {
    await apiClient(`api/contabilidad/asientos/${id}/anular`, {
      method: 'POST',
      body: JSON.stringify({ fecha_reversion: fechaReversion, motivo }),
    });
  },

  obtenerLibroDiario: async (desde?: string, hasta?: string): Promise<Asiento[]> => {
    const params = new URLSearchParams();
    if (desde) params.append('desde', desde);
    if (hasta) params.append('hasta', hasta);

    const res = await apiClient<any>(
      `api/contabilidad/libros/diario?${params.toString()}`
    );
    if (Array.isArray(res)) return res;
    if (Array.isArray(res?.data)) return res.data;
    return [];
  },

  obtenerLibroMayor: async (
    cuentaId: number = 0,
    ejercicio?: number,
    mes?: number,
    moneda: string = 'VES',
    desde?: string,
    hasta?: string
  ): Promise<any> => {
    const params = new URLSearchParams();
    if (cuentaId > 0) params.append('cuenta_id', cuentaId.toString());
    if (moneda) params.append('moneda', moneda);
    if (ejercicio) params.append('ejercicio', ejercicio.toString());
    if (mes) params.append('mes', mes.toString());
    if (desde) params.append('desde', desde);
    if (hasta) params.append('hasta', hasta);

    const res = await apiClient<any>(`api/contabilidad/libros/mayor?${params.toString()}`);
    if (Array.isArray(res)) return res;
    if (Array.isArray(res?.data)) return res.data;
    if (res && ('resumen' in res || 'movimientos' in res)) {
      return res;
    }
    if (res?.data) {
      return res.data;
    }
    return [];
  },

  obtenerBalanceComprobacion: async (
    ejercicio?: number,
    mes?: number,
    moneda: string = 'VES',
    desde?: string,
    hasta?: string
  ): Promise<FilaBalanceComprobacion[]> => {
    const params = new URLSearchParams({ moneda });
    if (ejercicio) params.append('ejercicio', ejercicio.toString());
    if (mes) params.append('mes', mes.toString());
    if (desde) params.append('desde', desde);
    if (hasta) params.append('hasta', hasta);

    const res = await apiClient<any>(
      `api/contabilidad/libros/balance-comprobacion?${params.toString()}`
    );
    if (Array.isArray(res)) return res;
    if (Array.isArray(res?.data)) return res.data;
    return [];
  },
};
