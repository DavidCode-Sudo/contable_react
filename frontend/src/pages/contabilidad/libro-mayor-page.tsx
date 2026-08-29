import React, { useState, useEffect } from 'react';
import { useLibroMayor } from '@/hooks/useAsientos';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Input } from '@/components/ui/input';
import { BookMarked, Printer } from 'lucide-react';
import { apiClient } from '@/lib/apiClient';

export const LibroMayorPage: React.FC = () => {
  const [cuentas, setCuentas] = useState<Array<{ id: number; codigo: string; nombre: string }>>([]);
  const [cuentaId, setCuentaId] = useState<number>(0);
  const [ejercicio, setEjercicio] = useState<number>(new Date().getFullYear());
  const [mes, setMes] = useState<number>(new Date().getMonth() + 1);
  const [moneda, setMoneda] = useState<string>('VES');

  useEffect(() => {
    const fetchCuentas = async () => {
      try {
        const res = await apiClient<{ data: Array<{ id: number; codigo: string; nombre: string }> }>('api/catalogo/cuentas?imputable=1');
        const list = res.data || [];
        setCuentas(list);
        if (list.length > 0) setCuentaId(list[0].id);
      } catch (e) {
        console.error('Error al cargar catálogo:', e);
      }
    };
    fetchCuentas();
  }, []);

  const { data, isLoading } = useLibroMayor(cuentaId, ejercicio, mes, moneda);

  const resumen = data?.resumen;
  const movimientos = data?.movimientos || [];

  return (
    <div className="container mx-auto p-6 space-y-6">
      <div className="flex flex-wrap justify-between items-center gap-4 border-b pb-4 print:hidden">
        <div>
          <h1 className="text-2xl font-bold text-foreground flex items-center gap-2">
            <BookMarked className="h-7 w-7 text-primary" /> Libro Mayor (Tarjeta de Cuenta $O(1)$)
          </h1>
          <p className="text-sm text-muted-foreground">
            Resumen atómico bimonetario materializado en 5 milisegundos.
          </p>
        </div>

        <Button onClick={() => window.print()} className="flex items-center gap-2">
          <Printer className="h-4 w-4" /> Imprimir Mayor
        </Button>
      </div>

      {/* Controles de Selección */}
      <div className="bg-muted/30 p-4 rounded-lg border grid grid-cols-1 md:grid-cols-4 gap-4 print:hidden">
        <div>
          <label className="text-xs font-semibold text-muted-foreground block mb-1">Cuenta Contable</label>
          <Select value={cuentaId.toString()} onValueChange={(v) => setCuentaId(Number(v))}>
            <SelectTrigger>
              <SelectValue placeholder="Seleccione cuenta" />
            </SelectTrigger>
            <SelectContent>
              {cuentas.map((c) => (
                <SelectItem key={c.id} value={c.id.toString()}>
                  {c.codigo} - {c.nombre}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        <div>
          <label className="text-xs font-semibold text-muted-foreground block mb-1">Ejercicio Fiscal</label>
          <Input type="number" value={ejercicio} onChange={(e) => setEjercicio(Number(e.target.value))} />
        </div>

        <div>
          <label className="text-xs font-semibold text-muted-foreground block mb-1">Mes Contable</label>
          <Input type="number" min="1" max="14" value={mes} onChange={(e) => setMes(Number(e.target.value))} />
        </div>

        <div>
          <label className="text-xs font-semibold text-muted-foreground block mb-1">Divisa de Reporte</label>
          <Select value={moneda} onValueChange={(v) => setMoneda(v)}>
            <SelectTrigger>
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="VES">VES - Bolívares</SelectItem>
              <SelectItem value="USD">USD - Dólares</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Tarjeta de Mayor */}
      {isLoading ? (
        <div className="p-8 text-center text-muted-foreground">Consultando saldo mensual O(1)...</div>
      ) : !resumen ? (
        <div className="p-8 text-center text-muted-foreground">Seleccione una cuenta para consultar su tarjeta de Mayor.</div>
      ) : (
        <div className="bg-card p-6 border rounded-lg shadow-sm space-y-6">
          <div className="border-b pb-4 flex justify-between items-start">
            <div>
              <h2 className="text-xl font-bold font-mono text-primary">{resumen.codigo} - {resumen.nombre}</h2>
              <span className="text-xs text-muted-foreground uppercase font-semibold">Naturaleza: {resumen.naturaleza}</span>
            </div>
            <div className="text-right">
              <span className="text-xs text-muted-foreground block">Moneda de Reporte</span>
              <span className="font-bold text-md">{moneda}</span>
            </div>
          </div>

          {/* Resumen Bimonetario Materializado */}
          <div className="grid grid-cols-2 md:grid-cols-4 gap-4 bg-muted/30 p-4 rounded-lg">
            <div>
              <span className="text-xs text-muted-foreground block">Saldo Inicial</span>
              <span className="text-base font-bold">
                {Number(moneda === 'VES' ? resumen.saldo_inicial_base : resumen.saldo_inicial_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
              </span>
            </div>
            <div>
              <span className="text-xs text-muted-foreground block">Total Débitos</span>
              <span className="text-base font-bold text-emerald-600">
                {Number(moneda === 'VES' ? resumen.debitos_base : resumen.debitos_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
              </span>
            </div>
            <div>
              <span className="text-xs text-muted-foreground block">Total Créditos</span>
              <span className="text-base font-bold text-blue-600">
                {Number(moneda === 'VES' ? resumen.creditos_base : resumen.creditos_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
              </span>
            </div>
            <div>
              <span className="text-xs text-muted-foreground block">Saldo Final Acumulado</span>
              <span className="text-base font-bold text-primary">
                {Number(moneda === 'VES' ? resumen.saldo_final_base : resumen.saldo_final_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
              </span>
            </div>
          </div>

          {/* Desglose de Movimientos */}
          <div className="border rounded-md overflow-hidden">
            <table className="w-full text-sm text-left">
              <thead className="bg-muted text-muted-foreground uppercase text-xs">
                <tr>
                  <th className="p-2">N° Asiento</th>
                  <th className="p-2">Fecha</th>
                  <th className="p-2">Concepto</th>
                  <th className="p-2 text-right">Debe</th>
                  <th className="p-2 text-right">Haber</th>
                </tr>
              </thead>
              <tbody>
                {movimientos.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="p-4 text-center text-muted-foreground">
                      No hubo movimientos registrados en este mes. Saldo arrastrado del período anterior.
                    </td>
                  </tr>
                ) : (
                  movimientos.map((m) => (
                    <tr key={m.id} className="border-t">
                      <td className="p-2 font-mono font-bold">{m.asiento_numero}</td>
                      <td className="p-2">{m.asiento_fecha}</td>
                      <td className="p-2">{m.asiento_concepto}</td>
                      <td className="p-2 text-right font-medium text-emerald-600">
                        {Number(moneda === 'VES' ? m.debe : m.monto_origen && Number(m.debe) > 0 ? m.monto_origen : 0) > 0
                          ? Number(moneda === 'VES' ? m.debe : m.monto_origen).toFixed(2)
                          : ''}
                      </td>
                      <td className="p-2 text-right font-medium text-blue-600">
                        {Number(moneda === 'VES' ? m.haber : m.monto_origen && Number(m.haber) > 0 ? m.monto_origen : 0) > 0
                          ? Number(moneda === 'VES' ? m.haber : m.monto_origen).toFixed(2)
                          : ''}
                      </td>
                    </tr>
                  ))
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
};
