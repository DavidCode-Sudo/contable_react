import { apiClient } from '@/lib/apiClient'
import type {
  CancelarTransferenciaPayload,
  CrearCuentaPayload,
  CrearTransferenciaPayload,
  CuentaBancaria,
  EstablecerSaldoInicialPayload,
  SaldosBancariosStats,
  TransferenciaBancaria,
} from '@/types/tesoreria'

export interface CuentasBancariasResponse {
  success: boolean
  data: CuentaBancaria[]
  stats: SaldosBancariosStats
}

export interface TransferenciasResponse {
  success: boolean
  data: TransferenciaBancaria[]
  pagination: {
    total: number
    page: number
    limit: number
    pages: number
  }
}

export async function fetchCuentasBancarias(): Promise<CuentasBancariasResponse> {
  return apiClient<CuentasBancariasResponse>('/api/tesoreria/cuentas-bancarias')
}

export async function crearCuentaBancaria(
  payload: CrearCuentaPayload
): Promise<{ success: boolean; message: string; data: CuentaBancaria }> {
  return apiClient<{ success: boolean; message: string; data: CuentaBancaria }>(
    '/api/tesoreria/cuentas-bancarias',
    {
      method: 'POST',
      body: JSON.stringify(payload),
    }
  )
}

export async function establecerSaldoInicial(
  payload: EstablecerSaldoInicialPayload
): Promise<{ success: boolean; message: string; data: CuentaBancaria }> {
  return apiClient<{ success: boolean; message: string; data: CuentaBancaria }>(
    `/api/tesoreria/cuentas-bancarias/${payload.id}/saldo-inicial`,
    {
      method: 'POST',
      body: JSON.stringify({
        saldo_inicial: payload.saldo_inicial,
        password_admin: payload.password_admin,
      }),
    }
  )
}

export async function cambiarEstadoCuentaBancaria(
  id: number,
  estado: 'activa' | 'inactiva'
): Promise<{ success: boolean; message: string; data: CuentaBancaria }> {
  return apiClient<{ success: boolean; message: string; data: CuentaBancaria }>(
    `/api/tesoreria/cuentas-bancarias/${id}/estado`,
    {
      method: 'POST',
      body: JSON.stringify({ estado }),
    }
  )
}

export async function actualizarCuentaBancaria(
  id: number,
  payload: Partial<CrearCuentaPayload>
): Promise<{ success: boolean; message: string; data: CuentaBancaria }> {
  return apiClient<{ success: boolean; message: string; data: CuentaBancaria }>(
    `/api/tesoreria/cuentas-bancarias/${id}`,
    {
      method: 'PUT',
      body: JSON.stringify(payload),
    }
  )
}

export async function fetchTransferencias(params?: {
  page?: number
  limit?: number
  banco_id?: number
  estado?: string
  q?: string
}): Promise<TransferenciasResponse> {
  const queryParams = new URLSearchParams()
  if (params?.page) queryParams.append('page', String(params.page))
  if (params?.limit) queryParams.append('limit', String(params.limit))
  if (params?.banco_id) queryParams.append('banco_id', String(params.banco_id))
  if (params?.estado) queryParams.append('estado', params.estado)
  if (params?.q) queryParams.append('q', params.q)

  const queryString = queryParams.toString()
  const endpoint = `/api/tesoreria/transferencias${queryString ? `?${queryString}` : ''}`
  return apiClient<TransferenciasResponse>(endpoint)
}

export async function crearTransferencia(
  payload: CrearTransferenciaPayload
): Promise<{ success: boolean; message: string; data: { id: number; asiento_id: number | null } }> {
  return apiClient<
    { success: boolean; message: string; data: { id: number; asiento_id: number | null } }
  >('/api/tesoreria/transferencias', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export async function cancelarTransferencia(
  payload: CancelarTransferenciaPayload
): Promise<{ success: boolean; message: string; data: { id: number; asiento_reversion_id: number | null } }> {
  return apiClient<
    { success: boolean; message: string; data: { id: number; asiento_reversion_id: number | null } }
  >(`/api/tesoreria/transferencias/${payload.id}/cancelar`, {
    method: 'POST',
    body: JSON.stringify({
      motivo: payload.motivo,
      password_admin: payload.password_admin,
    }),
  })
}

export async function adjuntarArchivoTransferencia(
  id: number,
  file: File
): Promise<{ success: boolean; message: string; data: { filename: string; path: string } }> {
  const formData = new FormData()
  formData.append('archivo', file)

  return apiClient<{ success: boolean; message: string; data: { filename: string; path: string } }>(
    `/api/tesoreria/transferencias/${id}/adjuntos`,
    {
      method: 'POST',
      body: formData,
    }
  )
}
