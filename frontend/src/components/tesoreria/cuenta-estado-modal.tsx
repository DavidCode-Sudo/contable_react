import { useState } from 'react'
import { AlertTriangle, Loader2, Power, PowerOff } from 'lucide-react'

import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { useCambiarEstadoCuenta } from '@/hooks/useTesoreria'
import { ofuscarNumeroCuenta } from '@/lib/utils'
import type { CuentaBancaria } from '@/types/tesoreria'

interface CuentaEstadoModalProps {
  cuenta: CuentaBancaria | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function CuentaEstadoModal({ cuenta, open, onOpenChange }: CuentaEstadoModalProps) {
  const cambiarEstadoMutation = useCambiarEstadoCuenta()

  if (!cuenta) return null

  const esDesactivar = cuenta.estado === 'activa'
  const nuevoEstado = esDesactivar ? 'inactiva' : 'activa'

  const handleConfirmar = () => {
    cambiarEstadoMutation.mutate(
      { id: cuenta.id, estado: nuevoEstado },
      {
        onSuccess: () => {
          onOpenChange(false)
        },
      }
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[460px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 font-semibold text-lg text-foreground">
            {esDesactivar ? (
              <>
                <PowerOff className="h-5 w-5 text-amber-600 dark:text-amber-500" />
                ¿Desactivar Cuenta Bancaria?
              </>
            ) : (
              <>
                <Power className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                ¿Reactivar Cuenta Bancaria?
              </>
            )}
          </DialogTitle>
          <DialogDescription className="pt-1 text-sm text-muted-foreground">
            Está a punto de cambiar el estado de la cuenta{' '}
            <strong className="text-foreground font-semibold">
              {cuenta.banco_nombre} ({ofuscarNumeroCuenta(cuenta.numero_cuenta)})
            </strong>.
          </DialogDescription>
        </DialogHeader>

        {esDesactivar ? (
          <Alert variant="outline" className="bg-muted/40 border-amber-500/30 text-muted-foreground my-2">
            <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-500" />
            <AlertTitle className="font-semibold text-xs uppercase tracking-wide text-foreground">
              Efecto en el Ejercicio Fiscal
            </AlertTitle>
            <AlertDescription className="text-xs pt-1 leading-relaxed text-muted-foreground">
              La cuenta pasará a estado <strong className="text-foreground">Inactiva</strong>. No aparecerá en los selectores de origen ni destino para ejecutar nuevas transferencias. Los saldos e historial del Libro Diario no se verán afectados.
            </AlertDescription>
          </Alert>
        ) : (
          <Alert variant="outline" className="bg-emerald-500/5 border-emerald-500/30 text-muted-foreground my-2">
            <Power className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
            <AlertTitle className="font-semibold text-xs uppercase tracking-wide text-foreground">
              Reactivación Operativa
            </AlertTitle>
            <AlertDescription className="text-xs pt-1 leading-relaxed text-muted-foreground">
              La cuenta volverá a estar <strong className="text-foreground">Activa</strong> y disponible para ejecutar transferencias interbancarias e integrar asientos contables.
            </AlertDescription>
          </Alert>
        )}

        <DialogFooter className="pt-3 gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={cambiarEstadoMutation.isPending}
          >
            Cancelar
          </Button>
          <Button
            type="button"
            disabled={cambiarEstadoMutation.isPending}
            onClick={handleConfirmar}
          >
            {cambiarEstadoMutation.isPending ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                Actualizando...
              </>
            ) : esDesactivar ? (
              'Confirmar Desactivación'
            ) : (
              'Confirmar Reactivación'
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
