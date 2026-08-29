import { useState } from 'react'
import { AlertCircle, KeyRound, Loader2, RotateCcw } from 'lucide-react'

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
import { Textarea } from '@/components/ui/textarea'
import { useCancelarTransferencia } from '@/hooks/useTesoreria'
import type { TransferenciaBancaria } from '@/types/tesoreria'

interface TransferenciaCancelModalProps {
  transferencia: TransferenciaBancaria | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function TransferenciaCancelModal({
  transferencia,
  open,
  onOpenChange,
}: TransferenciaCancelModalProps) {
  const cancelMutation = useCancelarTransferencia()

  const [motivo, setMotivo] = useState('')
  const [passwordAdmin, setPasswordAdmin] = useState('')

  if (!transferencia) return null

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!motivo || !passwordAdmin) return

    cancelMutation.mutate(
      {
        id: transferencia.id,
        motivo,
        password_admin: passwordAdmin,
      },
      {
        onSuccess: () => {
          setMotivo('')
          setPasswordAdmin('')
          onOpenChange(false)
        },
      }
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md border-destructive/40">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-destructive font-bold">
            <RotateCcw className="h-5 w-5" />
            Cancelar y Revertir Transferencia Ref: {transferencia.numero_transferencia}
          </DialogTitle>
          <DialogDescription>
            Fricción Intencional de Seguridad: Esta acción generará un <strong>Asiento Contable de Reversión Confirmado</strong>.
          </DialogDescription>
        </DialogHeader>

        <Alert variant="destructive" className="bg-destructive/10 border-destructive/30">
          <AlertCircle className="h-4 w-4" />
          <AlertTitle className="font-semibold text-xs uppercase tracking-wider">
            {transferencia.origen_banco} → {transferencia.destino_banco} (Bs {transferencia.monto.toLocaleString('es-VE', { minimumFractionDigits: 2 })})
          </AlertTitle>
          <AlertDescription className="text-xs pt-1">
            El asiento original (#{transferencia.asiento_id}) permanecerá como <strong>confirmado</strong> y se creará el asiento de reverso invirtiendo Debe y Haber para 100% de trazabilidad.
          </AlertDescription>
        </Alert>

        <form onSubmit={handleSubmit} className="space-y-4 py-2">
          <div className="space-y-2">
            <Label htmlFor="motivo_cancelacion">Motivo Obligatorio de Cancelación</Label>
            <Textarea
              id="motivo_cancelacion"
              rows={3}
              placeholder="Explique detalladamente la razón de la reversión para el expediente de auditoría..."
              value={motivo}
              onChange={(e) => setMotivo(e.target.value)}
              required
            />
          </div>

          <div className="space-y-2 pt-2 border-t">
            <Label htmlFor="password_admin_cancel" className="flex items-center gap-1.5 font-semibold text-destructive">
              <KeyRound className="h-4 w-4" />
              Contraseña de Administrador (Modo Sudo Persistido)
            </Label>
            <Input
              id="password_admin_cancel"
              type="password"
              placeholder="Ingrese su clave de administrador"
              value={passwordAdmin}
              onChange={(e) => setPasswordAdmin(e.target.value)}
              required
            />
          </div>

          <DialogFooter className="pt-4">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button
              type="submit"
              variant="destructive"
              disabled={cancelMutation.isPending || !motivo || !passwordAdmin}
            >
              {cancelMutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Revirtiendo Asiento...
                </>
              ) : (
                'Confirmar Reversión Auditable'
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
