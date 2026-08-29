import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'

import {
  actualizarCuentaBancaria,
  cambiarEstadoCuentaBancaria,
  cancelarTransferencia,
  crearCuentaBancaria,
  crearTransferencia,
  establecerSaldoInicial,
  fetchCuentasBancarias,
  fetchTransferencias,
} from '@/services/tesoreria'
import type {
  CancelarTransferenciaPayload,
  CrearCuentaPayload,
  CrearTransferenciaPayload,
  EstablecerSaldoInicialPayload,
} from '@/types/tesoreria'

export function useCuentasBancarias() {
  return useQuery({
    queryKey: ['cuentas-bancarias'],
    queryFn: fetchCuentasBancarias,
    staleTime: 1000 * 60 * 2, // 2 minutos
  })
}

export function useTransferencias(params?: {
  page?: number
  limit?: number
  banco_id?: number
  estado?: string
  q?: string
}) {
  return useQuery({
    queryKey: ['transferencias', params],
    queryFn: () => fetchTransferencias(params),
    staleTime: 1000 * 30, // 30 segundos
  })
}

/**
 * Helper asíncrono para invalidar en cascada con Promise.all y refetchType: 'active'
 * Evita la condición de carrera (race condition) visual en React Query TanStack v5
 */
function useInvalidarCascadaTesoreria() {
  const queryClient = useQueryClient()

  return async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['cuentas-bancarias'], refetchType: 'active' }),
      queryClient.invalidateQueries({ queryKey: ['transferencias'], refetchType: 'active' }),
      queryClient.invalidateQueries({ queryKey: ['asientos'], refetchType: 'active' }),
      queryClient.invalidateQueries({ queryKey: ['libro-diario'], refetchType: 'active' }),
      queryClient.invalidateQueries({ queryKey: ['balance-comprobacion'], refetchType: 'active' }),
    ])
  }
}

export function useCrearCuentaBancaria() {
  const invalidarCascada = useInvalidarCascadaTesoreria()

  return useMutation({
    mutationFn: (payload: CrearCuentaPayload) => crearCuentaBancaria(payload),
    onSuccess: async (res) => {
      await invalidarCascada()
      toast.success(res.message || 'Cuenta bancaria creada exitosamente.')
    },
    onError: (err) => {
      const msg = err instanceof Error ? err.message : 'Error al crear la cuenta bancaria.'
      toast.error(msg)
    },
  })
}

export function useActualizarCuentaBancaria() {
  const invalidarCascada = useInvalidarCascadaTesoreria()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<CrearCuentaPayload> }) =>
      actualizarCuentaBancaria(id, payload),
    onSuccess: async (res) => {
      await invalidarCascada()
      toast.success(res.message || 'Cuenta bancaria actualizada exitosamente.')
    },
    onError: (err) => {
      const msg = err instanceof Error ? err.message : 'Error al actualizar la cuenta bancaria.'
      toast.error(msg)
    },
  })
}

export function useEstablecerSaldoInicial() {
  const invalidarCascada = useInvalidarCascadaTesoreria()

  return useMutation({
    mutationFn: (payload: EstablecerSaldoInicialPayload) => establecerSaldoInicial(payload),
    onSuccess: async (res) => {
      await invalidarCascada()
      toast.success(res.message || 'Saldo inicial actualizado. Asiento de ajuste generado en borrador.')
    },
    onError: (err) => {
      const msg = err instanceof Error ? err.message : 'Error al establecer el saldo inicial.'
      toast.error(msg)
    },
  })
}

function isNetworkOrServerError(err: any): boolean {
  if (!err) return true
  if (typeof navigator !== 'undefined' && !navigator.onLine) return true
  const status = err?.status ?? err?.response?.status ?? (err instanceof TypeError ? 0 : null)
  return status === 0 || status === null || (typeof status === 'number' && status >= 500)
}

export function useCambiarEstadoCuenta() {
  const invalidarCascada = useInvalidarCascadaTesoreria()

  return useMutation({
    mutationFn: ({ id, estado }: { id: number; estado: 'activa' | 'inactiva' }) =>
      cambiarEstadoCuentaBancaria(id, estado),
    onSuccess: async (res) => {
      await invalidarCascada()
      toast.success(res.message || 'Estado de la cuenta bancaria actualizado.')
    },
    onError: async (err) => {
      if (isNetworkOrServerError(err)) {
        toast.error('Falla de conectividad o error de servidor (HTTP 50x / Red). Los cambios no fueron guardados.')
      } else {
        await invalidarCascada()
        const msg = err instanceof Error ? err.message : 'Error al cambiar el estado de la cuenta.'
        toast.error(msg)
      }
    },
  })
}

export function useCrearTransferencia() {
  const invalidarCascada = useInvalidarCascadaTesoreria()

  return useMutation({
    mutationFn: (payload: CrearTransferenciaPayload) => crearTransferencia(payload),
    onSuccess: async (res) => {
      await invalidarCascada()
      toast.success(res.message || 'Transferencia procesada correctamente.')
    },
    onError: async (err) => {
      if (isNetworkOrServerError(err)) {
        toast.error('Falla de conectividad o error de servidor (HTTP 50x / Red). Los cambios no fueron guardados.')
      } else {
        await invalidarCascada()
        const msg = err instanceof Error ? err.message : 'Error al procesar la transferencia.'
        toast.error(msg)
      }
    },
  })
}

export function useCancelarTransferencia() {
  const invalidarCascada = useInvalidarCascadaTesoreria()

  return useMutation({
    mutationFn: (payload: CancelarTransferenciaPayload) => cancelarTransferencia(payload),
    onSuccess: async (res) => {
      await invalidarCascada()
      toast.success(res.message || 'Transferencia cancelada y revertida exitosamente.')
    },
    onError: async (err) => {
      if (isNetworkOrServerError(err)) {
        toast.error('Falla de conectividad o error de servidor (HTTP 50x / Red). Los cambios no fueron guardados.')
      } else {
        await invalidarCascada()
        const msg = err instanceof Error ? err.message : 'Error al cancelar la transferencia.'
        toast.error(msg)
      }
    },
  })
}
