export interface CuentaBancaria {
  id: number
  institucion: string
  tipo_razon: string
  rif: string
  sucursal?: string
  numero_cuenta: string
  banco_nombre: string
  tipo_cuenta: 'corriente' | 'ahorros' | 'chequera' | 'virtual' | 'otra'
  moneda?: string
  estado: 'activa' | 'inactiva'
  saldo_inicial: number
  cuenta_id: number | null
  fuente_financiamiento_id: number | null
  saldo_contable: number
  saldo_efectivo_real: number
  retencion_presupuestaria: number
  disponible_financiero_real: number
}

export interface SaldosBancariosStats {
  total_efectivo: number
  total_disponibilidad_real: number
  total_cuentas: number
  cuentas_activas: number
}

export interface TransferenciaBancaria {
  id: number
  numero_transferencia: string
  fecha_transferencia: string
  cuenta_origen_id: number
  cuenta_destino_id: number
  monto: number
  concepto: string
  observaciones?: string
  estado: 'procesada' | 'cancelada'
  asiento_id: number | null
  asiento_reversion_id: number | null
  motivo_cancelacion?: string
  created_at?: string
  banco_origen_nombre: string
  banco_origen_numero: string
  banco_destino_nombre: string
  banco_destino_numero: string
}

export interface CrearCuentaPayload {
  institucion: string
  tipo_razon: string
  rif: string
  sucursal?: string
  numero_cuenta: string
  banco_nombre: string
  tipo_cuenta: 'corriente' | 'ahorros' | 'chequera' | 'virtual' | 'otra'
  estado: 'activa' | 'inactiva'
  saldo_inicial: number
  cuenta_id?: number | null
  fuente_financiamiento_id?: number
}

export interface CrearTransferenciaPayload {
  numero_transferencia: string
  fecha_transferencia: string
  cuenta_origen_id: number
  cuenta_destino_id: number
  monto: number
  concepto: string
  observaciones?: string
  password_admin: string
}

export interface CancelarTransferenciaPayload {
  id: number
  motivo: string
  password_admin: string
}

export interface EstablecerSaldoInicialPayload {
  id: number
  saldo_inicial: number
  password_admin: string
}
