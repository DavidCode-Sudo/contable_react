export type TipoAsiento = 'manual' | 'automatico' | 'cierre' | 'ajuste';
export type EstadoAsiento = 'borrador' | 'confirmado' | 'anulado';

export interface DetalleAsientoInput {
  id?: number;
  cuenta_id: number;
  moneda_origen: string;
  monto_origen: number;
  tasa_cambio: number;
  debe: number;
  haber: number;
  concepto?: string;
  orden?: number;
}

export interface DetalleAsiento extends DetalleAsientoInput {
  id: number;
  asiento_id: number;
  cuenta_codigo?: string;
  cuenta_nombre?: string;
  cuenta_naturaleza?: string;
}

export interface AsientoInput {
  fecha: string;
  concepto: string;
  tipo: TipoAsiento;
  tipo_ingreso?: string;
  documento?: string;
  es_automatico?: boolean;
  origen?: string;
  origen_id?: number;
  detalles: DetalleAsientoInput[];
}

export interface Asiento {
  id: number;
  numero: string;
  fecha: string;
  concepto: string;
  tipo: TipoAsiento;
  estado: EstadoAsiento;
  es_automatico: number | boolean;
  origen?: string;
  origen_id?: number;
  asiento_anulacion_id?: number;
  total_debe: number;
  total_haber: number;
  usuario_id: number;
  usuario_nombre?: string;
  created_at: string;
  updated_at: string;
  detalles?: DetalleAsiento[];
}

export interface AsientoFiltros {
  estado?: EstadoAsiento;
  q?: string;
  last_fecha?: string;
  last_id?: number;
  limit?: number;
}

export interface KeysetPaginationResponse<T> {
  success: boolean;
  data: T[];
  next_cursor?: {
    last_fecha: string;
    last_id: number;
  } | null;
  has_more: boolean;
  message?: string;
}

export interface ResumenLibroMayor {
  cuenta_id: number;
  codigo: string;
  nombre: string;
  naturaleza: string;
  saldo_inicial_base: number;
  debitos_base: number;
  creditos_base: number;
  saldo_final_base: number;
  saldo_inicial_origen: number;
  debitos_origen: number;
  creditos_origen: number;
  saldo_final_origen: number;
}

export interface MovimientoLibroMayor extends DetalleAsiento {
  asiento_numero: string;
  asiento_fecha: string;
  asiento_concepto: string;
}

export interface FilaBalanceComprobacion {
  id: number;
  codigo: string;
  nombre: string;
  naturaleza: string;
  saldo_inicial_base: number;
  debitos_base: number;
  creditos_base: number;
  saldo_final_base: number;
  saldo_inicial_origen: number;
  debitos_origen: number;
  creditos_origen: number;
  saldo_final_origen: number;
}
