import { useEffect, useState } from 'react'
import { AlertTriangle, KeyRound, Loader2, ShieldAlert } from 'lucide-react'

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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useEstablecerSaldoInicial } from '@/hooks/useTesoreria'
import { ofuscarNumeroCuenta } from '@/lib/utils'
import type { CuentaBancaria } from '@/types/tesoreria'

interface SaldoInicialModalProps {
  cuenta: CuentaBancaria | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function SaldoInicialModal({ cuenta, open, onOpenChange }: SaldoInicialModalProps) {
  const saldoMutation = useEstablecerSaldoInicial()

  const [nuevoSaldo, setNuevoSaldo] = useState<number>(0)
  const [passwordAdmin, setPasswordAdmin] = useState('')

  useEffect(() => {
    if (cuenta) {
      setNuevoSaldo(cuenta.saldo_inicial ?? 0)
      setPasswordAdmin('')
    }
  }, [cuenta])

  if (!cuenta) return null

  const moneda = cuenta.moneda || 'VES'
  const delta = nuevoSaldo - (cuenta.saldo_inicial ?? 0)

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!passwordAdmin) return

    saldoMutation.mutate(
      {
        id: cuenta.id,
        saldo_inicial: nuevoSaldo,
        password_admin: passwordAdmin,
      },
      {
        onSuccess: () => {
          setPasswordAdmin('')
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
            <ShieldAlert className="h-5 w-5 text-amber-600 dark:text-amber-500" />
            Ajuste de Saldo Inicial (Zona Auditoría)
          </DialogTitle>
          <DialogDescription className="pt-1 text-sm text-muted-foreground">
            Modifique el saldo de apertura de la cuenta{' '}
            <strong className="text-foreground font-semibold">
              {cuenta.banco_nombre} ({ofuscarNumeroCuenta(cuenta.numero_cuenta)})
            </strong>.
          </DialogDescription>
        </DialogHeader>

        <Alert variant="outline" className="bg-muted/40 border-amber-500/30 text-muted-foreground my-1">
          <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-500" />
          <AlertTitle className="font-semibold text-xs uppercase tracking-wide text-foreground">
            Segregación de Funciones Contables
          </AlertTitle>
          <AlertDescription className="text-xs text-muted-foreground pt-1 leading-relaxed">
            Al guardar, se registrará atómicamente un <strong className="text-foreground">Asiento de Ajuste Delta (Δ) en estado BORRADOR</strong> por valor de{' '}
            <strong className="text-foreground font-mono">
              {delta > 0 ? '+' : ''}
              {delta.toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
            </strong>, pendiente por revisión en el Libro Diario.
          </AlertDescription>
        </Alert>

        <form onSubmit={handleSubmit} className="space-y-4 py-2">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <Label className="text-xs text-muted-foreground">Saldo Inicial Actual</Label>
              <div className="text-sm font-semibold font-mono bg-muted/60 p-2.5 rounded-md border text-foreground">
                {cuenta.saldo_inicial.toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
              </div>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="nuevo_saldo" className="text-xs font-medium text-foreground">Nuevo Saldo Inicial ({moneda})</Label>
              <Input
                id="nuevo_saldo"
                type="number"
                step="0.01"
                className="font-mono text-sm"
                value={nuevoSaldo || ''}
                onChange={(e) => setNuevoSaldo(parseFloat(e.target.value) || 0)}
                required
              />
            </div>
          </div>

          <div className="space-y-2 pt-2 border-t">
            <Label htmlFor="password_admin_saldo" className="flex items-center gap-1.5 font-semibold text-xs uppercase tracking-wide text-foreground">
              <KeyRound className="h-4 w-4 text-muted-foreground" />
              Contraseña de Administrador (Autenticación Sudo)
            </Label>
            <Input
              id="password_admin_saldo"
              type="password"
              placeholder="Ingrese su clave de administrador"
              value={passwordAdmin}
              onChange={(e) => setPasswordAdmin(e.target.value)}
              required
            />
          </div>

          <DialogFooter className="pt-3 gap-2">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button
              type="submit"
              variant="default"
              disabled={saldoMutation.isPending || !passwordAdmin}
            >
              {saldoMutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Verificando...
                </>
              ) : (
                'Confirmar Ajuste (Δ)'
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
