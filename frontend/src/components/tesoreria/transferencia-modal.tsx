import { useMemo, useState } from 'react'
import { ArrowRightLeft, CheckCircle2, DollarSign, KeyRound, Loader2, Send } from 'lucide-react'
import { toast } from 'sonner'

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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { useCrearTransferencia } from '@/hooks/useTesoreria'
import type { CrearTransferenciaPayload, CuentaBancaria } from '@/types/tesoreria'

interface TransferenciaModalProps {
  cuentas: CuentaBancaria[]
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function TransferenciaModal({ cuentas, open, onOpenChange }: TransferenciaModalProps) {
  const transferenciaMutation = useCrearTransferencia()

  const [formData, setFormData] = useState<CrearTransferenciaPayload>({
    numero_transferencia: '',
    fecha_transferencia: new Date().toISOString().slice(0, 10),
    cuenta_origen_id: 0,
    cuenta_destino_id: 0,
    monto: 0,
    concepto: '',
    observaciones: '',
    password_admin: '',
  })

  // Obtener la cuenta origen seleccionada para verificar disponibilidad real ONAPRE
  const cuentaOrigen = useMemo(() => {
    return cuentas.find((c) => c.id === formData.cuenta_origen_id) ?? null
  }, [cuentas, formData.cuenta_origen_id])

  const disponibleRealONAPRE = cuentaOrigen?.disponible_financiero_real ?? 0
  const sobregiroONAPRE = formData.monto > disponibleRealONAPRE

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!formData.cuenta_origen_id || !formData.cuenta_destino_id) {
      toast.error('Debe seleccionar la cuenta origen y la cuenta destino')
      return
    }

    if (formData.cuenta_origen_id === formData.cuenta_destino_id) {
      toast.error('La cuenta origen y la cuenta destino no pueden ser la misma')
      return
    }

    if (formData.monto <= 0) {
      toast.error('El monto a transferir debe ser mayor a cero')
      return
    }

    if (sobregiroONAPRE) {
      toast.error(`Monto excede la Disponibilidad Financiera Real ONAPRE (${disponibleRealONAPRE.toLocaleString('es-VE')} VES)`)
      return
    }

    if (!formData.password_admin) {
      toast.error('Se requiere la contraseña de Administrador para autorizar la transferencia')
      return
    }

    transferenciaMutation.mutate(formData, {
      onSuccess: () => {
        setFormData((p) => ({ ...p, password_admin: '' }))
        onOpenChange(false)
      },
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto w-[95vw] sm:max-w-[650px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-xl font-bold">
            <ArrowRightLeft className="h-5 w-5 text-primary" />
            Nueva Transferencia Interbancaria
          </DialogTitle>
          <DialogDescription>
            Traspaso de fondos entre cuentas institucionales con generación de asiento automático de Partida Doble.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 py-2">
          <div className="grid grid-cols-2 gap-4">
            <div className="space-y-2">
              <Label htmlFor="cuenta_origen_id">Cuenta Bancaria Origen (Emisora)</Label>
              <Select
                value={formData.cuenta_origen_id ? String(formData.cuenta_origen_id) : ''}
                onValueChange={(val) => {
                  const newOrigenId = Number(val)
                  const newOrigen = cuentas.find((c) => c.id === newOrigenId)
                  setFormData((p) => {
                    const destinoCurrent = cuentas.find((c) => c.id === p.cuenta_destino_id)
                    const keepDestino = destinoCurrent && (destinoCurrent.moneda || 'VES') === (newOrigen?.moneda || 'VES')
                    return {
                      ...p,
                      cuenta_origen_id: newOrigenId,
                      cuenta_destino_id: keepDestino ? p.cuenta_destino_id : 0,
                    }
                  })
                }}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Seleccione cuenta origen" />
                </SelectTrigger>
                <SelectContent>
                  {cuentas.map((c) => (
                    <SelectItem key={c.id} value={String(c.id)}>
                      {c.banco_nombre} - {c.numero_cuenta.slice(-4)} [{c.moneda || 'VES'}] ({c.disponible_financiero_real.toLocaleString('es-VE', { minimumFractionDigits: 2 })} {c.moneda || 'VES'})
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="cuenta_destino_id">Cuenta Bancaria Destino (Receptora)</Label>
              <Select
                value={formData.cuenta_destino_id ? String(formData.cuenta_destino_id) : ''}
                onValueChange={(val) =>
                  setFormData((p) => ({ ...p, cuenta_destino_id: Number(val) }))
                }
              >
                <SelectTrigger>
                  <SelectValue placeholder="Seleccione cuenta destino" />
                </SelectTrigger>
                <SelectContent>
                  {cuentas
                    .filter((c) => c.id !== formData.cuenta_origen_id)
                    .filter((c) => !cuentaOrigen || (c.moneda || 'VES') === (cuentaOrigen.moneda || 'VES'))
                    .map((c) => (
                      <SelectItem key={c.id} value={String(c.id)}>
                        {c.banco_nombre} - {c.numero_cuenta.slice(-4)} [{c.moneda || 'VES'}] ({c.institucion})
                      </SelectItem>
                    ))}
                </SelectContent>
              </Select>
            </div>
          </div>

          {cuentaOrigen && (
            <Alert variant={sobregiroONAPRE ? 'destructive' : 'default'} className="bg-muted/50 border-primary/30">
              <CheckCircle2 className="h-4 w-4 text-primary" />
              <AlertTitle className="font-semibold text-xs uppercase tracking-wide">
                Indicador de Disponibilidad Financiera Real ONAPRE
              </AlertTitle>
              <AlertDescription className="flex justify-between items-center text-sm pt-1">
                <span>Efectivo en Banco: <strong>{cuentaOrigen.saldo_efectivo_real.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES</strong></span>
                <span>Retención Presupuestaria: <strong>{cuentaOrigen.retencion_presupuestaria.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES</strong></span>
                <span>Disponible Real: <strong className="text-primary">{cuentaOrigen.disponible_financiero_real.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES</strong></span>
              </AlertDescription>
            </Alert>
          )}

          <div className="grid grid-cols-3 gap-4">
            <div className="space-y-2">
              <Label htmlFor="numero_transferencia">Número de Referencia / Comprobante</Label>
              <Input
                id="numero_transferencia"
                placeholder="Ej. TRF-982341"
                value={formData.numero_transferencia}
                onChange={(e) => setFormData((p) => ({ ...p, numero_transferencia: e.target.value }))}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="fecha_transferencia">Fecha de Ejecución</Label>
              <Input
                id="fecha_transferencia"
                type="date"
                value={formData.fecha_transferencia}
                onChange={(e) => setFormData((p) => ({ ...p, fecha_transferencia: e.target.value }))}
                required
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="monto">Monto a Transferir (VES)</Label>
              <div className="relative">
                <Input
                  id="monto"
                  type="number"
                  step="0.01"
                  min="0.01"
                  value={formData.monto || ''}
                  onChange={(e) =>
                    setFormData((p) => ({ ...p, monto: parseFloat(e.target.value) || 0 }))
                  }
                  className={sobregiroONAPRE ? 'border-destructive focus-visible:ring-destructive' : ''}
                  required
                />
                <DollarSign className="absolute right-3 top-2.5 h-4 w-4 text-muted-foreground" />
              </div>
              {sobregiroONAPRE && (
                <p className="text-xs text-destructive mt-1 font-medium">
                  El monto supera el disponible ONAPRE ({disponibleRealONAPRE.toLocaleString('es-VE')} VES)
                </p>
              )}
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="concepto">Concepto / Motivo de la Transferencia</Label>
            <Input
              id="concepto"
              placeholder="Ej. Traspaso de fondos para pago de nómina o proveedores..."
              value={formData.concepto}
              onChange={(e) => setFormData((p) => ({ ...p, concepto: e.target.value }))}
              required
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="observaciones">Observaciones Adicionales (Opcional)</Label>
            <Textarea
              id="observaciones"
              rows={2}
              placeholder="Detalles sobre la orden de pago u oficio soporte..."
              value={formData.observaciones}
              onChange={(e) => setFormData((p) => ({ ...p, observaciones: e.target.value }))}
            />
          </div>

          <div className="space-y-2 pt-2 border-t">
            <Label htmlFor="password_admin_trf" className="flex items-center gap-1.5 font-semibold text-xs uppercase tracking-wide text-foreground">
              <KeyRound className="h-4 w-4 text-muted-foreground" />
              Contraseña de Administrador (Autorización Sudo)
            </Label>
            <Input
              id="password_admin_trf"
              type="password"
              placeholder="Ingrese su clave de administrador para autorizar la transferencia"
              value={formData.password_admin}
              onChange={(e) => setFormData((p) => ({ ...p, password_admin: e.target.value }))}
              required
            />
          </div>

          <DialogFooter className="pt-4">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button
              type="submit"
              disabled={
                transferenciaMutation.isPending ||
                sobregiroONAPRE ||
                formData.monto <= 0 ||
                !formData.password_admin
              }
            >
              {transferenciaMutation.isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Procesando ACID...
                </>
              ) : (
                <>
                  <Send className="mr-2 h-4 w-4" />
                  Ejecutar Transferencia
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
