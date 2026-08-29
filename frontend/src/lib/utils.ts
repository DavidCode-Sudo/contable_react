import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Ofusca un número de cuenta bancaria dejando visibles el código de banco (4 dígitos) y los últimos 4 dígitos.
 * Ej: 01020123456789012345 -> 0102-••••-••-••••••2345
 */
export function ofuscarNumeroCuenta(numero: string, mostrarCompleto = false): string {
  const limpio = (numero || '').replace(/\D/g, '')
  if (limpio.length !== 20) return numero || ''

  if (mostrarCompleto) {
    return `${limpio.slice(0, 4)}-${limpio.slice(4, 8)}-${limpio.slice(8, 10)}-${limpio.slice(10)}`
  }

  const banco = limpio.slice(0, 4)
  const ultimos4 = limpio.slice(-4)
  return `${banco}-••••-••-••••••${ultimos4}`
}
