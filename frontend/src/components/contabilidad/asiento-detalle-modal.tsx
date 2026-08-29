import React from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  FileText,
  CheckCircle2,
  Clock,
  Ban,
  User,
  Calendar,
  Tag,
  ArrowLeftRight,
  AlignLeft,
} from 'lucide-react';
import type { Asiento } from '@/types/asientos';

interface AsientoDetalleModalProps {
  isOpen: boolean;
  onClose: () => void;
  asiento: Asiento | null;
}

export const AsientoDetalleModal: React.FC<AsientoDetalleModalProps> = ({
  isOpen,
  onClose,
  asiento,
}) => {
  if (!asiento) return null;

  const fechaLimpia = asiento.fecha
    ? asiento.fecha.split(' ')[0].split('-').reverse().join('/')
    : 'N/A';

  const renderBadgeEstado = (estado: string) => {
    switch ((estado || '').toLowerCase()) {
      case 'confirmado':
        return (
          <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800">
            <CheckCircle2 className="size-3 mr-1 text-emerald-600" /> Confirmado
          </Badge>
        );
      case 'borrador':
        return (
          <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800">
            <Clock className="size-3 mr-1 text-amber-500" /> Borrador
          </Badge>
        );
      case 'anulado':
        return (
          <Badge variant="outline" className="bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800">
            <Ban className="size-3 mr-1 text-rose-500" /> Anulado
          </Badge>
        );
      default:
        return <Badge variant="outline">{estado}</Badge>;
    }
  };

  const detallesList = asiento.detalles || [];
  const sumDebe = detallesList.reduce((acc, d) => acc + Number(d.debe || 0), 0);
  const sumHaber = detallesList.reduce((acc, d) => acc + Number(d.haber || 0), 0);
  const totalDebe = sumDebe > 0 ? sumDebe : Number(asiento.total_debe || 0);
  const totalHaber = sumHaber > 0 ? sumHaber : Number(asiento.total_haber || 0);

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-4xl w-[95vw] max-h-[90vh] overflow-y-auto rounded-2xl shadow-xl border-border/80 p-6 space-y-4 font-sans">
        <DialogHeader className="space-y-1 text-left border-b border-border/60 pb-3">
          <div className="flex items-center justify-between gap-4">
            <DialogTitle className="flex items-center gap-2.5 text-xl font-extrabold tracking-tight">
              <FileText className="size-6 text-foreground shrink-0" />
              <span>Comprobante N°</span>
              <span className="font-mono font-bold text-primary">{asiento.numero || `AS-${asiento.id}`}</span>
            </DialogTitle>
            <div>{renderBadgeEstado(asiento.estado)}</div>
          </div>
          <DialogDescription className="text-xs text-muted-foreground">
            Inspección de partida doble e historial de imputación contable.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-1">
          {/* Ficha Informativa de Cabecera */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 p-3.5 bg-muted/40 rounded-xl border border-border/60 text-xs">
            <div className="space-y-1">
              <span className="text-muted-foreground font-semibold flex items-center gap-1.5">
                <Calendar className="size-3.5 text-muted-foreground" /> Fecha del Asiento:
              </span>
              <p className="font-bold text-foreground">{fechaLimpia}</p>
            </div>

            <div className="space-y-1">
              <span className="text-muted-foreground font-semibold flex items-center gap-1.5">
                <FileText className="size-3.5 text-muted-foreground" /> Documento Respaldo:
              </span>
              <p className="font-bold text-foreground">{asiento.documento || 'No especificado'}</p>
            </div>

            <div className="space-y-1">
              <span className="text-muted-foreground font-semibold flex items-center gap-1.5">
                <Tag className="size-3.5 text-muted-foreground" /> Tipo de Asiento:
              </span>
              <p className="font-bold text-foreground capitalize">{asiento.tipo || 'manual'}</p>
            </div>

            {asiento.usuario_nombre && (
              <div className="space-y-1 sm:col-span-3 border-t border-border/40 pt-2 mt-1">
                <span className="text-muted-foreground font-semibold flex items-center gap-1.5">
                  <User className="size-3.5 text-muted-foreground" /> Usuario Registrador:
                </span>
                <p className="font-medium text-foreground">{asiento.usuario_nombre}</p>
              </div>
            )}
          </div>

          {/* Concepto General */}
          <div className="space-y-1.5">
            <span className="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-1.5">
              <AlignLeft className="size-3.5 text-muted-foreground" /> Concepto / Glosa General:
            </span>
            <div className="p-3.5 bg-card border border-border/60 rounded-xl text-xs font-medium text-foreground leading-relaxed shadow-2xs">
              {asiento.concepto}
            </div>
          </div>

          {/* Tabla de Renglones de Partida Doble */}
          <div className="space-y-2">
            <h4 className="text-xs font-bold uppercase tracking-wider text-muted-foreground flex items-center gap-1.5">
              <ArrowLeftRight className="size-3.5 text-foreground" /> Renglones de Partida Doble ({detallesList.length})
            </h4>

            <div className="border border-border/60 rounded-xl overflow-x-auto bg-card shadow-2xs">
              <table className="w-full text-xs text-left min-w-[650px]">
                <thead className="bg-muted/50 dark:bg-muted/30 text-muted-foreground uppercase text-[11px] font-bold tracking-wider border-b">
                  <tr>
                    <th className="p-3 w-36">CÓDIGO</th>
                    <th className="p-3">CUENTA CONTABLE</th>
                    <th className="p-3">CONCEPTO RENGLÓN</th>
                    <th className="p-3 w-36 text-right">DEBE (VES)</th>
                    <th className="p-3 w-36 text-right">HABER (VES)</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/60">
                  {detallesList.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="p-8 text-center text-muted-foreground text-xs">
                        No hay detalles cargados para este comprobante.
                      </td>
                    </tr>
                  ) : (
                    detallesList.map((d, i) => (
                      <tr key={i} className="hover:bg-muted/30 transition-colors">
                        <td className="p-3 font-mono font-bold text-primary">
                          {d.cuenta_codigo || `ID-${d.cuenta_id}`}
                        </td>
                        <td className="p-3 font-medium text-foreground">
                          {d.cuenta_nombre || `Cuenta N° ${d.cuenta_id}`}
                        </td>
                        <td className="p-3 text-muted-foreground">
                          {d.concepto || asiento.concepto}
                        </td>
                        <td className="p-3 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                          {Number(d.debe || 0) > 0 ? Number(d.debe).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : '-'}
                        </td>
                        <td className="p-3 text-right font-mono font-bold text-blue-600 dark:text-blue-400">
                          {Number(d.haber || 0) > 0 ? Number(d.haber).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : '-'}
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>

              {/* Totales Integrados */}
              <div className="bg-muted/40 border-t border-border/60 p-3 flex justify-between items-center text-xs font-bold">
                <span className="uppercase tracking-wider text-muted-foreground">TOTALES:</span>
                <div className="flex items-center gap-6">
                  <span className="text-emerald-600 dark:text-emerald-400">
                    Bs. {totalDebe.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                  </span>
                  <span className="text-blue-600 dark:text-blue-400">
                    Bs. {totalHaber.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                  </span>
                  <Badge variant="outline" className="bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800 text-[10px] font-bold px-2.5 py-0.5">
                    <CheckCircle2 className="size-3 mr-1 text-emerald-600" /> CUADRADO
                  </Badge>
                </div>
              </div>
            </div>
          </div>
        </div>

        <DialogFooter className="pt-2 border-t border-border/60">
          <Button variant="outline" size="sm" onClick={onClose} className="text-xs font-semibold">
            Cerrar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
};
