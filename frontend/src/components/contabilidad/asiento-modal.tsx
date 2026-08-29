import React, { useState, useCallback, useMemo, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Select2Cuenta } from '@/components/ui/select2-cuenta';
import { Badge } from '@/components/ui/badge';
import { Plus, Trash2, Scale, Loader2, AlertCircle, ChevronDown, ChevronUp, SlidersHorizontal } from 'lucide-react';
import type { AsientoInput, DetalleAsientoInput, TipoAsiento } from '@/types/asientos';
import { apiClient } from '@/lib/apiClient';

interface AsientoModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSave: (asiento: AsientoInput) => Promise<void>;
  cuentas: Array<{ id: number; codigo: string; nombre: string; naturaleza: string }>;
  isSubmitting?: boolean;
}

export const AsientoModal: React.FC<AsientoModalProps> = ({
  isOpen,
  onClose,
  onSave,
  cuentas,
  isSubmitting = false,
}) => {
  const [fecha, setFecha] = useState<string>(new Date().toISOString().split('T')[0]);
  const [concepto, setConcepto] = useState<string>('');
  const [tipo, setTipo] = useState<TipoAsiento>('manual');
  const [tipoIngreso, setTipoIngreso] = useState<string>('');
  const [correlativoIngreso, setCorrelativoIngreso] = useState<string>('');
  const [documento, setDocumento] = useState<string>('');
  const [mostrarClasificacionIngreso, setMostrarClasificacionIngreso] = useState<boolean>(false);
  const [monedaGlobal, setMonedaGlobal] = useState<'VES' | 'USD'>('VES');
  const [tasaCambioGlobal, setTasaCambioGlobal] = useState<number>(36.50);

  const [detalles, setDetalles] = useState<DetalleAsientoInput[]>([
    { cuenta_id: cuentas[0]?.id ?? 1, moneda_origen: 'VES', monto_origen: 0, tasa_cambio: 1.0, debe: 0, haber: 0, concepto: '' },
    { cuenta_id: cuentas[1]?.id ?? 2, moneda_origen: 'VES', monto_origen: 0, tasa_cambio: 1.0, debe: 0, haber: 0, concepto: '' },
  ]);

  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  // Sincronizar automáticamente los IDs por defecto con las cuentas reales cargadas del catálogo
  useEffect(() => {
    if (cuentas.length > 0) {
      setDetalles((prev) =>
        prev.map((d, index) => {
          const idExiste = cuentas.some((c) => c.id === d.cuenta_id);
          if (!idExiste) {
            const fallbackId = cuentas[index % cuentas.length]?.id ?? cuentas[0].id;
            return { ...d, cuenta_id: fallbackId };
          }
          return d;
        })
      );
    }
  }, [cuentas]);

  // Mapa de nombres y prefijos para tipos de ingreso
  const mapaTiposIngreso: Record<string, { prefijo: string; nombre: string }> = {
    'Ingresos Propios': { prefijo: 'ING-PRO', nombre: 'Ingresos Propios' },
    'Transferencias Recibidas': { prefijo: 'TRANS-REC', nombre: 'Transferencias Recibidas' },
    'Donaciones': { prefijo: 'DON', nombre: 'Donaciones' },
    'Otros Ingresos': { prefijo: 'OTROS-ING', nombre: 'Otros Ingresos' },
  };

  const infoTipoSeleccionado = useMemo(() => {
    if (!tipoIngreso || tipoIngreso === '-- Seleccione tipo --' || !mostrarClasificacionIngreso) return null;
    return mapaTiposIngreso[tipoIngreso] || null;
  }, [tipoIngreso, mostrarClasificacionIngreso]);

  // CRÍTICA 1 CORREGIDA: El correlativo NO contamina ni sobreescribe el campo "Documento Respaldo / Referencia"
  useEffect(() => {
    if (!infoTipoSeleccionado || !mostrarClasificacionIngreso) {
      setCorrelativoIngreso('');
      return;
    }

    let isMounted = true;

    const fetchCorrelativo = async () => {
      try {
        const res = await apiClient<{ success: boolean; numero: string }>(
          `api/contabilidad/asientos/correlativo-ingreso?tipo_ingreso=${encodeURIComponent(tipoIngreso)}&fecha=${fecha}`
        );
        if (isMounted && res.success && res.numero) {
          setCorrelativoIngreso(res.numero);
        }
      } catch (e) {
        if (isMounted) {
          const anio = new Date(fecha).getFullYear() || new Date().getFullYear();
          const fallbackNum = `${infoTipoSeleccionado.prefijo}-${anio}-000001`;
          setCorrelativoIngreso(fallbackNum);
        }
      }
    };

    fetchCorrelativo();

    return () => {
      isMounted = false;
    };
  }, [tipoIngreso, fecha, infoTipoSeleccionado, mostrarClasificacionIngreso]);

  // CRÍTICA 3 SOLUCIONADA: Propagación Inteligente de la Descripción Principal hacia los Renglones
  const handleConceptoHeaderChange = (val: string) => {
    const prevConcepto = concepto;
    setConcepto(val);
    setDetalles((prev) =>
      prev.map((d) => {
        if (!d.concepto || d.concepto === prevConcepto) {
          return { ...d, concepto: val };
        }
        return d;
      })
    );
  };

  const totalDebe = useMemo(() => {
    return Number(detalles.reduce((acc, d) => acc + Number(d.debe || 0), 0).toFixed(2));
  }, [detalles]);

  const totalHaber = useMemo(() => {
    return Number(detalles.reduce((acc, d) => acc + Number(d.haber || 0), 0).toFixed(2));
  }, [detalles]);

  const delta = useMemo(() => {
    return Math.abs(Number((totalDebe - totalHaber).toFixed(2)));
  }, [totalDebe, totalHaber]);

  const estaCuadrado = useMemo(() => {
    return delta < 0.01 && totalDebe > 0;
  }, [delta, totalDebe]);

  const handleAddDetalle = useCallback(() => {
    setDetalles((prev) => [
      ...prev,
      {
        cuenta_id: cuentas[0]?.id ?? 1,
        moneda_origen: monedaGlobal,
        monto_origen: 0,
        tasa_cambio: monedaGlobal === 'USD' ? tasaCambioGlobal : 1.0,
        debe: 0,
        haber: 0,
        concepto: concepto || '',
      },
    ]);
  }, [cuentas, monedaGlobal, tasaCambioGlobal, concepto]);

  const handleRemoveDetalle = useCallback((index: number) => {
    setDetalles((prev) => {
      if (prev.length <= 2) return prev;
      return prev.filter((_, i) => i !== index);
    });
  }, []);

  const handleDetalleChange = useCallback(
    (index: number, field: keyof DetalleAsientoInput, value: any) => {
      setDetalles((prev) => {
        const copy = [...prev];
        const row = { ...copy[index], [field]: value };

        if (field === 'monto_origen' || field === 'tasa_cambio') {
          const monto = field === 'monto_origen' ? Number(value) : row.monto_origen;
          const tasa = field === 'tasa_cambio' ? Number(value) : row.tasa_cambio;
          const equivalenteVES = Number((monto * tasa).toFixed(2));

          if (row.debe > 0 || row.haber === 0) {
            row.debe = equivalenteVES;
          } else {
            row.haber = equivalenteVES;
          }
        }

        if (field === 'debe') {
          const valNum = Number(value);
          row.debe = valNum;
          if (row.moneda_origen !== 'VES' && row.tasa_cambio > 0) {
            row.monto_origen = Number((valNum / row.tasa_cambio).toFixed(2));
          }
        }

        if (field === 'haber') {
          const valNum = Number(value);
          row.haber = valNum;
          if (row.moneda_origen !== 'VES' && row.tasa_cambio > 0) {
            row.monto_origen = Number((valNum / row.tasa_cambio).toFixed(2));
          }
        }

        copy[index] = row;
        return copy;
      });
    },
    []
  );

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);

    if (!concepto.trim()) {
      setErrorMsg('La descripción del asiento es obligatoria');
      return;
    }

    if (!estaCuadrado) {
      setErrorMsg(`El asiento presenta un descuadre contable de ${delta.toFixed(2)} VES`);
      return;
    }

    const docFinal = documento.trim() || (mostrarClasificacionIngreso && correlativoIngreso ? correlativoIngreso : undefined);

    try {
      await onSave({
        fecha,
        concepto,
        tipo,
        tipo_ingreso: mostrarClasificacionIngreso && tipoIngreso !== '-- Seleccione tipo --' ? tipoIngreso : undefined,
        documento: docFinal,
        detalles,
      });
      onClose();
    } catch (err: any) {
      setErrorMsg(err.message || 'Error al guardar el comprobante');
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-6xl w-[95vw] max-h-[92vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-xl font-bold">
            <Scale className="h-5 w-5 text-primary" />
            Nuevo Asiento Contable Manual
          </DialogTitle>
          <DialogDescription>
            Registre comprobantes contables con validación estricta de partida doble y soporte bimonetario (VES / USD).
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-5 py-2">
          {errorMsg && (
            <div className="p-3 bg-destructive/15 text-destructive rounded-md text-sm font-medium flex items-center gap-2">
              <AlertCircle className="h-4 w-4 shrink-0" />
              <span>{errorMsg}</span>
            </div>
          )}

          {/* Cabecera Limpia Estándar Enterprise */}
          <div className="space-y-4">
            {/* Fila 1: Fecha, Documento Respaldo y Moneda Bimonetaria Compacta */}
            <div className="grid grid-cols-1 md:grid-cols-12 gap-3 items-start">
              {/* Fecha: 3/12 del espacio */}
              <div className="space-y-1.5 md:col-span-3">
                <Label htmlFor="fecha" className="text-xs font-semibold">
                  Fecha del Asiento <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="fecha"
                  type="date"
                  value={fecha}
                  onChange={(e) => setFecha(e.target.value)}
                  required
                />
              </div>

              {/* Documento Respaldo: 4/12 del espacio */}
              <div className="space-y-1.5 md:col-span-4">
                <Label htmlFor="documento" className="text-xs font-semibold flex items-center justify-between">
                  <span>Documento Respaldo</span>
                  <span className="text-muted-foreground font-normal text-[11px]">(Auditoría)</span>
                </Label>
                <Input
                  id="documento"
                  placeholder="Ej: Memo N° 45, Res. 002-2026"
                  value={documento}
                  onChange={(e) => setDocumento(e.target.value)}
                />
              </div>

              {/* Moneda Global (VES/USD) y Tasa BCV: 5/12 del espacio */}
              <div className="space-y-1.5 md:col-span-5">
                <div className="flex items-center justify-between">
                  <Label htmlFor="moneda" className="text-xs font-semibold">
                    Moneda de Registro
                  </Label>
                  {monedaGlobal === 'USD' && (
                    <Label htmlFor="tasa" className="text-xs font-bold text-emerald-600">
                      💱 Tasa BCV (Bs./$)
                    </Label>
                  )}
                </div>

                <div className="flex items-center gap-2">
                  <Select
                    value={monedaGlobal}
                    onValueChange={(v: 'VES' | 'USD') => {
                      setMonedaGlobal(v);
                      setDetalles((prev) =>
                        prev.map((d) => ({
                          ...d,
                          moneda_origen: v,
                          tasa_cambio: v === 'USD' ? tasaCambioGlobal : 1.0,
                        }))
                      );
                    }}
                  >
                    <SelectTrigger className="h-9 text-xs bg-background shrink-0 w-24">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="VES">Bs. VES</SelectItem>
                      <SelectItem value="USD">$ USD</SelectItem>
                    </SelectContent>
                  </Select>

                  {monedaGlobal === 'USD' && (
                    <div className="relative flex-1 min-w-[110px] animate-in fade-in-50 duration-200">
                      <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-xs font-bold text-muted-foreground pointer-events-none select-none">
                        Bs.
                      </span>
                      <Input
                        id="tasa"
                        type="number"
                        step="0.01"
                        min="0.01"
                        placeholder="36.50"
                        value={tasaCambioGlobal || ''}
                        onChange={(e) => {
                          const nuevaTasa = Number(e.target.value);
                          setTasaCambioGlobal(nuevaTasa);
                          setDetalles((prev) =>
                            prev.map((d) => ({
                              ...d,
                              tasa_cambio: nuevaTasa,
                              debe: d.moneda_origen === 'USD' ? Number((d.monto_origen * nuevaTasa).toFixed(2)) : d.debe,
                              haber: d.moneda_origen === 'USD' ? Number((d.monto_origen * nuevaTasa).toFixed(2)) : d.haber,
                            }))
                          );
                        }}
                        className="h-9 text-right font-mono font-bold text-xs bg-background pl-8 pr-2.5 [appearance:textfield] [&::-webkit-outer-spin-button]:appearance-none [&::-webkit-inner-spin-button]:appearance-none"
                      />
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* Fila 2: Descripción General del Asiento */}
            <div className="space-y-1.5">
              <Label htmlFor="concepto" className="text-xs font-semibold">
                Descripción / Concepto del Asiento <span className="text-destructive">*</span>
              </Label>
              <Input
                id="concepto"
                placeholder="Ej: Registro de depreciación acumulada de bienes de uso correspondiente al ejercicio"
                value={concepto}
                onChange={(e) => handleConceptoHeaderChange(e.target.value)}
                required
              />
            </div>

            {/* Ocultamiento Condicional (Progressive Disclosure): Módulo Opcional de Clasificación de Ingresos */}
            <div className="pt-1">
              <button
                type="button"
                onClick={() => {
                  const stateNext = !mostrarClasificacionIngreso;
                  setMostrarClasificacionIngreso(stateNext);
                  if (!stateNext) {
                    setTipoIngreso('');
                    setCorrelativoIngreso('');
                  }
                }}
                className="flex items-center gap-1.5 text-xs font-semibold text-primary hover:text-primary/80 transition-colors focus:outline-none"
              >
                <SlidersHorizontal className="h-3.5 w-3.5" />
                <span>
                  {mostrarClasificacionIngreso
                    ? 'Ocultar Clasificación Específica de Ingreso'
                    : '⚙️ Clasificar como Ingreso Específico (Opcional Recaudación)'}
                </span>
                {mostrarClasificacionIngreso ? (
                  <ChevronUp className="h-3.5 w-3.5 ml-0.5" />
                ) : (
                  <ChevronDown className="h-3.5 w-3.5 ml-0.5" />
                )}
              </button>

              {mostrarClasificacionIngreso && (
                <div className="mt-3 p-3.5 bg-muted/30 border rounded-xl grid grid-cols-1 md:grid-cols-2 gap-4 animate-in fade-in-50 duration-200">
                  <div className="space-y-1.5">
                    <Label htmlFor="tipoIngreso" className="text-xs font-semibold">
                      Tipo de Ingreso Específico
                    </Label>
                    <Select
                      value={tipoIngreso}
                      onValueChange={(v) => setTipoIngreso(v)}
                    >
                      <SelectTrigger id="tipoIngreso">
                        <SelectValue placeholder="-- Seleccione tipo de ingreso --" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="-- Seleccione tipo --">-- Ninguno / Asiento General --</SelectItem>
                        <SelectItem value="Ingresos Propios">Ingresos Propios</SelectItem>
                        <SelectItem value="Transferencias Recibidas">Transferencias Recibidas</SelectItem>
                        <SelectItem value="Donaciones">Donaciones</SelectItem>
                        <SelectItem value="Otros Ingresos">Otros Ingresos</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  <div className="space-y-1.5">
                    <Label htmlFor="correlativoIngreso" className="text-xs font-semibold">
                      Número Correlativo de Recaudación <span className="text-muted-foreground font-normal">(Bloqueado)</span>
                    </Label>
                    <Input
                      id="correlativoIngreso"
                      value={correlativoIngreso || (infoTipoSeleccionado ? 'Cargando...' : 'Seleccione un tipo de ingreso arriba')}
                      readOnly
                      disabled
                      className="bg-card font-mono font-bold text-primary cursor-not-allowed"
                    />
                    <p className="text-[11px] text-muted-foreground mt-0.5">
                      Número inmutable generado automáticamente para conciliación fiscal ONCOP.
                    </p>
                  </div>
                </div>
              )}
            </div>
          </div>

          {/* 3. Sección de Detalles de Partida Doble */}
          <div className="border rounded-xl p-4 bg-muted/20 space-y-4">
            <div className="flex justify-between items-center border-b pb-3">
              <div>
                <h3 className="text-sm font-bold text-foreground uppercase tracking-wide">
                  Detalles del Asiento
                </h3>
                <p className="text-xs text-muted-foreground">
                  Ingrese las cuentas imputables del catálogo con sus montos en Debe o Haber.
                </p>
              </div>

              <Button
                type="button"
                variant="outline"
                size="sm"
                className="flex items-center gap-1.5 text-xs font-semibold"
                onClick={handleAddDetalle}
              >
                <Plus className="h-4 w-4" /> Agregar Detalle
              </Button>
            </div>

            {/* Grilla Dinámica de Renglones */}
            <div className="border rounded-lg overflow-x-auto bg-card shadow-xs">
              <table className="w-full text-sm text-left min-w-[780px]">
                <thead className="bg-muted text-muted-foreground uppercase text-[11px] font-bold tracking-wider">
                  <tr>
                    <th className="p-3 w-5/12 min-w-[260px]">CUENTA CONTABLE *</th>
                    <th className="p-3 w-3/12 min-w-[180px]">DESCRIPCIÓN *</th>
                    <th className="p-3 w-44 min-w-[160px] text-right">DEBE</th>
                    <th className="p-3 w-44 min-w-[160px] text-right">HABER</th>
                    <th className="p-3 w-12 text-center"></th>
                  </tr>
                </thead>
                <tbody>
                  {detalles.map((d, index) => (
                    <tr key={index} className="border-t hover:bg-muted/30 transition-colors">
                      <td className="p-2.5">
                        <Select2Cuenta
                          value={d.cuenta_id}
                          cuentas={cuentas}
                          onChange={(val) => handleDetalleChange(index, 'cuenta_id', val ? Number(val) : 0)}
                          placeholder="-- Buscar código o nombre de cuenta... --"
                        />
                      </td>

                      <td className="p-2.5">
                        <Input
                          placeholder="Descripción del movimiento"
                          className="h-9 text-xs"
                          value={d.concepto || ''}
                          onChange={(e) => handleDetalleChange(index, 'concepto', e.target.value)}
                        />
                      </td>

                      <td className="p-2.5">
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0,00"
                          className="h-9 text-right font-semibold text-emerald-600 text-xs font-mono px-3 w-full"
                          value={d.debe || ''}
                          onChange={(e) => handleDetalleChange(index, 'debe', Number(e.target.value))}
                        />
                      </td>

                      <td className="p-2.5">
                        <Input
                          type="number"
                          step="0.01"
                          min="0"
                          placeholder="0,00"
                          className="h-9 text-right font-semibold text-blue-600 text-xs font-mono px-3 w-full"
                          value={d.haber || ''}
                          onChange={(e) => handleDetalleChange(index, 'haber', Number(e.target.value))}
                        />
                      </td>

                      <td className="p-2.5 text-center">
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon"
                          className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10"
                          disabled={detalles.length <= 2}
                          onClick={() => handleRemoveDetalle(index)}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {/* Resumen de Totales Integrado */}
              <div className="bg-muted/40 border-t p-3 flex flex-wrap justify-between items-center text-sm font-bold">
                <span className="uppercase text-xs tracking-wider text-muted-foreground">TOTALES:</span>
                <div className="flex items-center gap-6">
                  <span className="text-emerald-600">Bs. {totalDebe.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</span>
                  <span className="text-blue-600">Bs. {totalHaber.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</span>
                  {totalDebe === 0 && totalHaber === 0 ? (
                    <Badge variant="outline" className="text-xs font-medium text-muted-foreground border-muted-foreground/30 bg-muted/40 px-2.5 py-0.5">
                      ⚪ ASIENTO VACÍO
                    </Badge>
                  ) : estaCuadrado ? (
                    <Badge variant="outline" className="bg-emerald-500/15 text-emerald-700 dark:text-emerald-400 border-emerald-500/30 text-xs font-bold px-2.5 py-0.5">
                      🟢 CUADRADO
                    </Badge>
                  ) : (
                    <Badge variant="outline" className="bg-destructive/15 text-destructive border-destructive/30 text-xs font-bold px-2.5 py-0.5">
                      🔴 DESCUADRADO ({delta.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES)
                    </Badge>
                  )}
                </div>
              </div>
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={onClose}
              disabled={isSubmitting}
            >
              Cancelar
            </Button>

            <Button
              type="submit"
              disabled={!estaCuadrado || isSubmitting}
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Guardando...
                </>
              ) : (
                'Guardar Asiento (Borrador)'
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
};
