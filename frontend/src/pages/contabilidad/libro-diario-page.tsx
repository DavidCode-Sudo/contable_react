import React, { useState } from 'react';
import { useLibroDiario } from '@/hooks/useAsientos';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Printer, BookOpen, Calendar } from 'lucide-react';

export const LibroDiarioPage: React.FC = () => {
  const [desde, setDesde] = useState<string>(`${new Date().getFullYear()}-01-01`);
  const [hasta, setHasta] = useState<string>(new Date().toISOString().split('T')[0]);

  const { data: asientos = [], isLoading } = useLibroDiario(desde, hasta);

  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="flex flex-wrap justify-between items-center gap-4 border-b pb-4 print:hidden">
        <div>
          <h1 className="text-2xl font-bold text-foreground flex items-center gap-2">
            <BookOpen className="h-7 w-7 text-primary" /> Libro Diario Oficial
          </h1>
          <p className="text-sm text-muted-foreground">
            Reporte oficial foliado de comprobantes contables confirmados.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <Calendar className="h-4 w-4 text-muted-foreground" />
            <Input type="date" value={desde} onChange={(e) => setDesde(e.target.value)} className="w-36" />
            <span>a</span>
            <Input type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} className="w-36" />
          </div>

          <Button onClick={handlePrint} className="flex items-center gap-2">
            <Printer className="h-4 w-4" /> Imprimir Libro
          </Button>
        </div>
      </div>

      {/* Formato de Impresión Oficial */}
      <div className="bg-card p-6 border rounded-lg shadow-sm print:shadow-none print:border-none print:p-0">
        <div className="text-center space-y-1 mb-6">
          <h2 className="text-xl font-bold uppercase">REPUBLICA BOLIVARIANA DE VENEZUELA</h2>
          <h3 className="text-lg font-semibold uppercase text-primary">LIBRO DIARIO OFICIAL DE CONTABILIDAD</h3>
          <p className="text-sm text-muted-foreground">
            Período: {desde} al {hasta} | Expresado en Bolívares (VES)
          </p>
        </div>

        {isLoading ? (
          <div className="p-8 text-center text-muted-foreground">Generando Libro Diario...</div>
        ) : asientos.length === 0 ? (
          <div className="p-8 text-center text-muted-foreground">No existen asientos confirmados en el rango de fechas.</div>
        ) : (
          <div className="space-y-6">
            {asientos.map((a) => (
              <div key={a.id} className="border rounded-md overflow-hidden break-inside-avoid">
                <div className="bg-muted/40 p-3 flex justify-between items-center text-sm font-semibold border-b">
                  <span className="font-mono text-primary">Comprobante N° {a.numero}</span>
                  <span>Fecha: {a.fecha}</span>
                </div>
                <div className="p-3 text-sm italic text-muted-foreground border-b">
                  Glosa / Concepto: {a.concepto}
                </div>
                <table className="w-full text-sm text-left">
                  <thead className="bg-muted/20 text-xs uppercase text-muted-foreground">
                    <tr>
                      <th className="p-2">Código</th>
                      <th className="p-2">Cuenta Contable</th>
                      <th className="p-2 text-right">Debe (VES)</th>
                      <th className="p-2 text-right">Haber (VES)</th>
                    </tr>
                  </thead>
                  <tbody>
                    {a.detalles?.map((d) => (
                      <tr key={d.id} className="border-t">
                        <td className="p-2 font-mono text-xs">{d.cuenta_codigo}</td>
                        <td className="p-2 font-medium">{d.cuenta_nombre}</td>
                        <td className="p-2 text-right font-medium text-emerald-600">
                          {Number(d.debe) > 0 ? Number(d.debe).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : ''}
                        </td>
                        <td className="p-2 text-right font-medium text-blue-600">
                          {Number(d.haber) > 0 ? Number(d.haber).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : ''}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                  <tfoot className="bg-muted/30 font-bold border-t text-sm">
                    <tr>
                      <td colSpan={2} className="p-2 text-right">TOTAL COMPROBANTE:</td>
                      <td className="p-2 text-right text-emerald-600">
                        {Number(a.total_debe).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                      </td>
                      <td className="p-2 text-right text-blue-600">
                        {Number(a.total_haber).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                      </td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  );
};
