import React, { useState } from 'react';
import { useBalanceComprobacion } from '@/hooks/useAsientos';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Scale, Printer } from 'lucide-react';

export const BalanceComprobacionPage: React.FC = () => {
  const [ejercicio, setEjercicio] = useState<number>(new Date().getFullYear());
  const [mes, setMes] = useState<number>(new Date().getMonth() + 1);
  const [moneda, setMoneda] = useState<string>('VES');

  const { data: filas = [], isLoading } = useBalanceComprobacion(ejercicio, mes, moneda);

  const totalSaldoInicial = filas.reduce((acc, f) => acc + (moneda === 'VES' ? Number(f.saldo_inicial_base) : Number(f.saldo_inicial_origen)), 0);
  const totalDebitos = filas.reduce((acc, f) => acc + (moneda === 'VES' ? Number(f.debitos_base) : Number(f.debitos_origen)), 0);
  const totalCreditos = filas.reduce((acc, f) => acc + (moneda === 'VES' ? Number(f.creditos_base) : Number(f.creditos_origen)), 0);
  const totalSaldoFinal = filas.reduce((acc, f) => acc + (moneda === 'VES' ? Number(f.saldo_final_base) : Number(f.saldo_final_origen)), 0);

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="flex flex-wrap justify-between items-center gap-4 border-b pb-4 print:hidden">
        <div>
          <h1 className="text-2xl font-bold text-foreground flex items-center gap-2">
            <Scale className="h-7 w-7 text-primary" /> Balance de Comprobación ($O(1)$)
          </h1>
          <p className="text-sm text-muted-foreground">
            Resumen consolidado bimonetario con arrastre atómico interanual y subquery fallback.
          </p>
        </div>

        <div className="flex items-center gap-3">
          <div className="flex items-center gap-2">
            <Input type="number" value={ejercicio} onChange={(e) => setEjercicio(Number(e.target.value))} className="w-24" />
            <Input type="number" min="1" max="14" value={mes} onChange={(e) => setMes(Number(e.target.value))} className="w-20" />
            <Select value={moneda} onValueChange={(v) => setMoneda(v)}>
              <SelectTrigger className="w-28">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="VES">VES</SelectItem>
                <SelectItem value="USD">USD</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <Button onClick={() => window.print()} className="flex items-center gap-2">
            <Printer className="h-4 w-4" /> Imprimir Balance
          </Button>
        </div>
      </div>

      <div className="bg-card p-6 border rounded-lg shadow-sm print:shadow-none print:border-none print:p-0">
        <div className="text-center space-y-1 mb-6">
          <h2 className="text-xl font-bold uppercase">REPUBLICA BOLIVARIANA DE VENEZUELA</h2>
          <h3 className="text-lg font-semibold uppercase text-primary">BALANCE DE COMPROBACIÓN</h3>
          <p className="text-sm text-muted-foreground">
            Ejercicio {ejercicio} | Mes {mes} | Expresado en {moneda}
          </p>
        </div>

        {isLoading ? (
          <div className="p-8 text-center text-muted-foreground">Calculando Balance de Comprobación O(1)...</div>
        ) : (
          <div className="border rounded-md overflow-x-auto">
            <table className="w-full text-sm text-left">
              <thead className="bg-muted text-muted-foreground uppercase text-xs">
                <tr>
                  <th className="p-2">Código</th>
                  <th className="p-2">Cuenta Contable</th>
                  <th className="p-2 text-right">Saldo Inicial</th>
                  <th className="p-2 text-right">Débitos</th>
                  <th className="p-2 text-right">Créditos</th>
                  <th className="p-2 text-right">Saldo Final</th>
                </tr>
              </thead>
              <tbody>
                {filas.map((f) => {
                  const sIni = moneda === 'VES' ? Number(f.saldo_inicial_base) : Number(f.saldo_inicial_origen);
                  const deb = moneda === 'VES' ? Number(f.debitos_base) : Number(f.debitos_origen);
                  const cred = moneda === 'VES' ? Number(f.creditos_base) : Number(f.creditos_origen);
                  const sFin = moneda === 'VES' ? Number(f.saldo_final_base) : Number(f.saldo_final_origen);

                  return (
                    <tr key={f.id} className="border-t hover:bg-muted/50">
                      <td className="p-2 font-mono font-medium text-xs">{f.codigo}</td>
                      <td className="p-2 font-medium">{f.nombre}</td>
                      <td className="p-2 text-right">{sIni.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                      <td className="p-2 text-right text-emerald-600">{deb.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                      <td className="p-2 text-right text-blue-600">{cred.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                      <td className="p-2 text-right font-bold">{sFin.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                    </tr>
                  );
                })}
              </tbody>
              <tfoot className="bg-muted font-bold border-t text-sm">
                <tr>
                  <td colSpan={2} className="p-2 text-right">TOTALES GENERALES:</td>
                  <td className="p-2 text-right">{totalSaldoInicial.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                  <td className="p-2 text-right text-emerald-600">{totalDebitos.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                  <td className="p-2 text-right text-blue-600">{totalCreditos.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                  <td className="p-2 text-right text-primary">{totalSaldoFinal.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};
