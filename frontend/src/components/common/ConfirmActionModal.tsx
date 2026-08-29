import React from 'react'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { CheckCircle2, AlertTriangle, PackageX, RotateCcw, Loader2 } from 'lucide-react'

export type ConfirmActionVariant = 'despachar' | 'cancelar_reserva' | 'anular' | 'devolucion' | 'default'

export interface ConfirmActionModalProps {
  isOpen: boolean
  onClose: () => void
  onConfirm: () => Promise<void> | void
  title: string
  description: string
  variant?: ConfirmActionVariant
  confirmText?: string
  cancelText?: string
  isProcessing?: boolean
}

export const ConfirmActionModal: React.FC<ConfirmActionModalProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  description,
  variant = 'default',
  confirmText,
  cancelText = 'Cancelar',
  isProcessing = false,
}) => {
  const getVariantStyles = () => {
    switch (variant) {
      case 'despachar':
        return {
          icon: <CheckCircle2 className="w-7 h-7 text-emerald-600" />,
          iconBg: 'bg-emerald-100 dark:bg-emerald-900/30 border-emerald-200',
          btnClass: 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-emerald-500/20',
          defaultConfirmText: 'Confirmar Despacho',
        }
      case 'cancelar_reserva':
        return {
          icon: <RotateCcw className="w-7 h-7 text-amber-600" />,
          iconBg: 'bg-amber-100 dark:bg-amber-900/30 border-amber-200',
          btnClass: 'bg-amber-600 hover:bg-amber-700 text-white shadow-amber-500/20',
          defaultConfirmText: 'Liberar Reserva',
        }
      case 'anular':
        return {
          icon: <PackageX className="w-7 h-7 text-red-600" />,
          iconBg: 'bg-red-100 dark:bg-red-900/30 border-red-200',
          btnClass: 'bg-red-600 hover:bg-red-700 text-white shadow-red-500/20',
          defaultConfirmText: 'Anular Orden',
        }
      case 'devolucion':
        return {
          icon: <RotateCcw className="w-7 h-7 text-blue-600" />,
          iconBg: 'bg-blue-100 dark:bg-blue-900/30 border-blue-200',
          btnClass: 'bg-blue-600 hover:bg-blue-700 text-white shadow-blue-500/20',
          defaultConfirmText: 'Procesar Devolución',
        }
      default:
        return {
          icon: <AlertTriangle className="w-7 h-7 text-blue-600" />,
          iconBg: 'bg-blue-100 dark:bg-blue-900/30 border-blue-200',
          btnClass: 'bg-primary hover:bg-primary/90 text-primary-foreground',
          defaultConfirmText: 'Aceptar',
        }
    }
  }

  const vConfig = getVariantStyles()
  const btnText = confirmText || vConfig.defaultConfirmText

  const handleConfirmAction = async () => {
    await onConfirm()
  }

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && !isProcessing && onClose()}>
      <DialogContent className="sm:max-w-md p-6 overflow-hidden border border-border shadow-2xl rounded-xl">
        <DialogHeader className="flex flex-col items-center text-center space-y-3 sm:text-center">
          <div className={`p-3 rounded-full border ${vConfig.iconBg} mb-1 transition-all duration-200`}>
            {vConfig.icon}
          </div>
          <DialogTitle className="text-lg font-bold tracking-tight text-foreground">
            {title}
          </DialogTitle>
          <DialogDescription className="text-xs text-muted-foreground leading-relaxed">
            {description}
          </DialogDescription>
        </DialogHeader>

        <DialogFooter className="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-4 border-t border-border mt-2">
          <Button
            type="button"
            variant="outline"
            onClick={onClose}
            disabled={isProcessing}
            className="text-xs font-medium h-9"
          >
            {cancelText}
          </Button>
          <Button
            type="button"
            onClick={handleConfirmAction}
            disabled={isProcessing}
            className={`text-xs font-semibold h-9 shadow-sm transition-all ${vConfig.btnClass}`}
          >
            {isProcessing ? (
              <>
                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                Procesando...
              </>
            ) : (
              btnText
            )}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export default ConfirmActionModal
