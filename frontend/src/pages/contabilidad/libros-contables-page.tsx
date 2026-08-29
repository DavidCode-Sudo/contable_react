import React, { useState, useEffect, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useLibroDiario, useLibroMayor, useBalanceComprobacion } from '@/hooks/useAsientos';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { BookOpen, BookMarked, Scale, Printer, Calendar, Search } from 'lucide-react';
import { apiClient } from '@/lib/apiClient';

type TabType = 'diario' | 'mayor' | 'balance';

export const LibrosContablesPage: React.FC = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const initialTab = (searchParams.get('tab') as TabType) || 'diario';
  const [activeTab, setActiveTab] = useState<TabType>(initialTab);

  // Filtros unificados de fecha y búsqueda (idénticos al sistema anterior)
  const currentYear = new Date().getFullYear();
  const currentMonth = new Date().getMonth() + 1;

  const [fechaInicio, setFechaInicio] = useState<string>(`${currentYear}-01-01`);
  const [fechaFin, setFechaFin] = useState<string>(new Date().toISOString().split('T')[0]);
  const [searchQuery, setSearchQuery] = useState<string>('');

  // Filtros específicos para Mayor y Balance
  const [cuentas, setCuentas] = useState<Array<{ id: number; codigo: string; nombre: string }>>([]);
  const [cuentaId, setCuentaId] = useState<number>(0);
  const [ejercicio, setEjercicio] = useState<number>(currentYear);
  const [mes, setMes] = useState<number>(currentMonth);
  const [moneda, setMoneda] = useState<string>('VES');

  // Sincronizar tab en URL
  const handleTabChange = (tab: TabType) => {
    setActiveTab(tab);
    setSearchParams({ tab });
  };

  // Cargar catálogo de cuentas imputables
  useEffect(() => {
    const fetchCuentas = async () => {
      try {
        const res = await apiClient<any>('api/catalogo/cuentas?imputable=1');
        const list = Array.isArray(res)
          ? res
          : Array.isArray(res?.cuentas)
          ? res.cuentas
          : Array.isArray(res?.data)
          ? res.data
          : [];
        setCuentas(list);
      } catch (e) {
        console.error('Error al cargar catálogo de cuentas:', e);
      }
    };
    fetchCuentas();
  }, []);

  // Consultas React Query según la pestaña activa
  const { data: diarioAsientos = [], isLoading: isLoadingDiario } = useLibroDiario(fechaInicio, fechaFin);
  const { data: mayorData, isLoading: isLoadingMayor } = useLibroMayor(cuentaId, ejercicio, mes, moneda, fechaInicio, fechaFin);
  const { data: balanceFilas = [], isLoading: isLoadingBalance } = useBalanceComprobacion(ejercicio, mes, moneda);

  // Filtrado reactivo en cliente por texto de búsqueda
  const diarioFiltrado = useMemo(() => {
    if (!searchQuery.trim()) return diarioAsientos;
    const q = searchQuery.toLowerCase();
    return diarioAsientos.filter(
      (a) =>
        a.numero.toLowerCase().includes(q) ||
        a.concepto.toLowerCase().includes(q) ||
        a.detalles?.some((d) => (d.cuenta_nombre || '').toLowerCase().includes(q) || (d.cuenta_codigo || '').includes(q))
    );
  }, [diarioAsientos, searchQuery]);

  const mayorMovimientos = mayorData?.movimientos || [];
  const mayorResumen = mayorData?.resumen;

  const mayorMovimientosFiltrados = useMemo(() => {
    if (!searchQuery.trim()) return mayorMovimientos;
    const q = searchQuery.toLowerCase();
    return mayorMovimientos.filter(
      (m) =>
        m.asiento_numero.toLowerCase().includes(q) ||
        m.asiento_concepto.toLowerCase().includes(q)
    );
  }, [mayorMovimientos, searchQuery]);

  const balanceFiltrado = useMemo(() => {
    if (!searchQuery.trim()) return balanceFilas;
    const q = searchQuery.toLowerCase();
    return balanceFilas.filter(
      (f) => f.codigo.toLowerCase().includes(q) || f.nombre.toLowerCase().includes(q)
    );
  }, [balanceFilas, searchQuery]);

  // Formateador de rangos para la etiqueta de período superior
  const formatFechaVE = (fechaStr: string) => {
    if (!fechaStr) return '';
    const [y, m, d] = fechaStr.split('-');
    return `${d}/${m}/${y}`;
  };

  const handlePrint = () => {
    window.print();
  };

  return (
    <div className="space-y-6 pb-12">
      {/* 1. Encabezado del Módulo con Icono y Metadata de Período */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between print:hidden">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <BookOpen className="size-6 text-foreground" />
            Libros Contables
          </h1>
          <p className="text-xs text-muted-foreground">
            Consulta consolidada de Libro Diario, Libro Mayor y Balance de Comprobación.
          </p>
        </div>

        <div className="flex items-center gap-2 text-xs font-semibold text-muted-foreground bg-muted/50 px-3 py-1.5 rounded-full border">
          <Calendar className="h-4 w-4 text-primary" />
          <span>Período: {formatFechaVE(fechaInicio)} - {formatFechaVE(fechaFin)}</span>
        </div>
      </div>

      {/* 2. Barra de Pestañas (Segmented Controls) */}
      <div className="flex flex-wrap gap-2 border-b border-border/60 pb-3 print:hidden">
        <Button
          variant={activeTab === 'diario' ? 'default' : 'outline'}
          size="sm"
          className="flex items-center gap-2 font-semibold"
          onClick={() => handleTabChange('diario')}
        >
          <BookOpen className="h-4 w-4" /> Libro Diario
        </Button>

        <Button
          variant={activeTab === 'mayor' ? 'default' : 'outline'}
          size="sm"
          className="flex items-center gap-2 font-semibold"
          onClick={() => handleTabChange('mayor')}
        >
          <BookMarked className="h-4 w-4" /> Libro Mayor
        </Button>

        <Button
          variant={activeTab === 'balance' ? 'default' : 'outline'}
          size="sm"
          className="flex items-center gap-2 font-semibold"
          onClick={() => handleTabChange('balance')}
        >
          <Scale className="h-4 w-4" /> Balance de Comprobación
        </Button>
      </div>

      {/* 3. BARRA DE FILTROS (Identica a Asientos Contables) */}
      <Card className="border-border/60 bg-card shadow-2xs print:hidden">
        <CardContent className="p-3 sm:p-4">
          <div className="flex flex-col md:flex-row gap-3 items-center justify-between">
            <div className="flex gap-2 w-full md:w-auto flex-1 max-w-md">
              <div className="relative w-full">
                <Search className="absolute left-3 top-2.5 size-4 text-muted-foreground" />
                <Input
                  placeholder="Buscar en libros..."
                  className="pl-9 h-9 text-xs bg-background border-input"
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                />
              </div>
            </div>

            <div className="flex flex-wrap items-center gap-3 w-full md:w-auto">
              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Desde:</span>
                <Input
                  type="date"
                  value={fechaInicio}
                  onChange={(e) => setFechaInicio(e.target.value)}
                  className="w-[140px] h-9 text-xs bg-background border-input"
                />
              </div>

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Hasta:</span>
                <Input
                  type="date"
                  value={fechaFin}
                  onChange={(e) => setFechaFin(e.target.value)}
                  className="w-[140px] h-9 text-xs bg-background border-input"
                />
              </div>

              {activeTab === 'mayor' && (
                <div className="flex items-center gap-2">
                  <span className="text-xs font-semibold text-muted-foreground">Cuenta:</span>
                  <Select value={cuentaId.toString()} onValueChange={(v) => setCuentaId(Number(v))}>
                    <SelectTrigger className="w-[180px] h-9 text-xs bg-background border-input"><SelectValue placeholder="Todas las cuentas" /></SelectTrigger>
                    <SelectContent className="max-h-60">
                      <SelectItem value="0">Todas las Cuentas</SelectItem>
                      {cuentas.map((c) => (
                        <SelectItem key={c.id} value={c.id.toString()}>
                          {c.codigo} - {c.nombre}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}

              {activeTab === 'balance' && (
                <div className="flex items-center gap-2">
                  <span className="text-xs font-semibold text-muted-foreground">Ejercicio:</span>
                  <Input
                    type="number"
                    value={ejercicio}
                    onChange={(e) => setEjercicio(Number(e.target.value))}
                    className="w-[90px] h-9 text-xs bg-background border-input"
                  />
                </div>
              )}

              <div className="flex items-center gap-2">
                <span className="text-xs font-semibold text-muted-foreground">Moneda:</span>
                <Select value={moneda} onValueChange={(v) => setMoneda(v)}>
                  <SelectTrigger className="w-[110px] h-9 text-xs bg-background border-input"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="VES">VES (Bs.)</SelectItem>
                    <SelectItem value="USD">USD ($)</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* 4. Contenido Dinámico de la Pestaña Activa */}
      <div className="bg-card border border-border/60 rounded-xl shadow-2xs p-4 sm:p-6">
        {/* Cabecera del Panel con Badge e Impresión */}
        <div className="flex flex-wrap justify-between items-center gap-3 border-b border-border/60 pb-4 mb-6 print:hidden">
          <div className="flex items-center gap-3">
            <h2 className="text-lg font-bold text-foreground flex items-center gap-2">
              {activeTab === 'diario' && <BookOpen className="h-5 w-5 text-primary" />}
              {activeTab === 'mayor' && <BookMarked className="h-5 w-5 text-primary" />}
              {activeTab === 'balance' && <Scale className="h-5 w-5 text-primary" />}
              {activeTab === 'diario' && 'Libro Diario'}
              {activeTab === 'mayor' && 'Libro Mayor'}
              {activeTab === 'balance' && 'Balance de Comprobación'}
            </h2>

            <Badge variant="secondary" className="text-xs px-2.5 py-0.5 font-bold">
              {activeTab === 'diario' && `${diarioFiltrado.length} asientos`}
              {activeTab === 'mayor' &&
                (Array.isArray(mayorData)
                  ? `${mayorData.length} cuentas`
                  : `${mayorMovimientosFiltrados.length} movimientos`)}
              {activeTab === 'balance' && `${balanceFiltrado.length} cuentas`}
            </Badge>
          </div>

          <Button onClick={handlePrint} variant="outline" size="sm" className="flex items-center gap-2">
            <Printer className="h-4 w-4" /> Imprimir Documento
          </Button>
        </div>

        {/* Header para Impresión Oficial */}
        <div className="hidden print:block text-center space-y-1 mb-6">
          <h2 className="text-xl font-bold uppercase">REPÚBLICA BOLIVARIANA DE VENEZUELA</h2>
          <h3 className="text-lg font-semibold uppercase text-primary">
            {activeTab === 'diario' && 'LIBRO DIARIO OFICIAL DE CONTABILIDAD'}
            {activeTab === 'mayor' && 'LIBRO MAYOR OFICIAL (TARJETA DE CUENTA)'}
            {activeTab === 'balance' && 'BALANCE DE COMPROBACIÓN CONSOLIDADO'}
          </h3>
          <p className="text-sm text-muted-foreground">
            Período: {formatFechaVE(fechaInicio)} al {formatFechaVE(fechaFin)} | Expresado en {moneda}
          </p>
        </div>

        {/* VISTA 1: LIBRO DIARIO */}
        {activeTab === 'diario' && (
          <div>
            {isLoadingDiario ? (
              <div className="p-8 text-center text-muted-foreground">Generando Libro Diario...</div>
            ) : diarioFiltrado.length === 0 ? (
              <div className="p-8 text-center text-muted-foreground">No existen asientos confirmados en el rango de fechas.</div>
            ) : (
              <div className="space-y-6">
                {diarioFiltrado.map((a) => (
                  <div key={a.id} className="border rounded-lg overflow-hidden break-inside-avoid shadow-xs">
                    <div className="bg-muted/40 p-3 flex justify-between items-center text-sm font-semibold border-b">
                      <span className="font-mono text-primary font-bold">Comprobante N° {a.numero}</span>
                      <span>Fecha: {formatFechaVE(a.fecha)}</span>
                    </div>
                    <div className="p-3 text-sm italic text-muted-foreground border-b bg-background/50">
                      Glosa / Concepto: {a.concepto}
                    </div>
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm text-left">
                        <thead className="bg-muted/20 text-xs uppercase text-muted-foreground">
                          <tr>
                            <th className="p-2.5">Código</th>
                            <th className="p-2.5">Cuenta Contable</th>
                            <th className="p-2.5 text-right">Debe (VES)</th>
                            <th className="p-2.5 text-right">Haber (VES)</th>
                          </tr>
                        </thead>
                        <tbody>
                          {a.detalles?.map((d) => (
                            <tr key={d.id} className="border-t hover:bg-muted/30">
                              <td className="p-2.5 font-mono text-xs">{d.cuenta_codigo}</td>
                              <td className="p-2.5 font-medium">{d.cuenta_nombre}</td>
                              <td className="p-2.5 text-right font-medium text-emerald-600">
                                {Number(d.debe) > 0 ? Number(d.debe).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : ''}
                              </td>
                              <td className="p-2.5 text-right font-medium text-blue-600">
                                {Number(d.haber) > 0 ? Number(d.haber).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : ''}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                        <tfoot className="bg-muted/30 font-bold border-t text-sm">
                          <tr>
                            <td colSpan={2} className="p-2.5 text-right">TOTAL COMPROBANTE:</td>
                            <td className="p-2.5 text-right text-emerald-600">
                              {Number(a.total_debe).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                            </td>
                            <td className="p-2.5 text-right text-blue-600">
                              {Number(a.total_haber).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                            </td>
                          </tr>
                        </tfoot>
                      </table>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* VISTA 2: LIBRO MAYOR */}
        {activeTab === 'mayor' && (
          <div>
            {isLoadingMayor ? (
              <div className="p-8 text-center text-muted-foreground">Consultando Libro Mayor...</div>
            ) : cuentaId === 0 ? (
              /* MODO ÍNDICE DE TARJETAS: Muestra el catálogo imputable para acceder a la tarjeta individual */
              <div className="space-y-4">
                <div className="flex justify-between items-center bg-muted/30 p-3 rounded-lg border">
                  <span className="text-xs font-bold uppercase tracking-wider text-muted-foreground">
                    Índice de Tarjetas del Libro Mayor (Solo Cuentas Imputables / Movimiento)
                  </span>
                  <Badge variant="outline" className="text-xs font-semibold">
                    {Array.isArray(mayorData) ? `${mayorData.length} Cuentas Imputables` : '0 Cuentas'}
                  </Badge>
                </div>

                <div className="border rounded-xl overflow-hidden shadow-2xs bg-card">
                  <table className="w-full text-xs text-left min-w-[750px]">
                    <thead className="bg-muted/50 dark:bg-muted/30 text-muted-foreground uppercase text-[11px] font-bold tracking-wider border-b">
                      <tr>
                        <th className="p-3 w-40">CÓDIGO</th>
                        <th className="p-3">CUENTA CONTABLE</th>
                        <th className="p-3 w-28">TIPO</th>
                        <th className="p-3 w-28">NATURALEZA</th>
                        <th className="p-3 w-36 text-right">TOTAL DEBE</th>
                        <th className="p-3 w-36 text-right">TOTAL HABER</th>
                        <th className="p-3 w-48 text-right">SALDO CONTABLE</th>
                        <th className="p-3 w-32 text-center print:hidden">TARJETA</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border/60">
                      {mayorData.length === 0 ? (
                        <tr>
                          <td colSpan={8} className="p-8 text-center text-muted-foreground text-xs">
                            No existen cuentas imputables con movimientos en el período seleccionado.
                          </td>
                        </tr>
                      ) : (
                        mayorData.map((c: any) => {
                          const montoSaldo = Number(c.saldo_monto ?? Math.abs(c.saldo_neto ?? 0));
                          const esAnomalo = Boolean(c.saldo_anomalo);
                          const natTexto = c.saldo_naturaleza || (c.naturaleza === 'acreedora' ? 'Acreedor' : 'Deudor');

                          return (
                            <tr key={c.id} className="hover:bg-muted/20 transition-colors">
                              <td className="p-3 font-mono font-bold text-primary">{c.codigo}</td>
                              <td className="p-3 font-medium text-foreground">{c.nombre}</td>
                              <td className="p-3">
                                <Badge variant="outline" className="text-[10px] uppercase font-semibold">
                                  {c.tipo || 'Activo'}
                                </Badge>
                              </td>
                              <td className="p-3 capitalize text-muted-foreground">{c.naturaleza}</td>
                              <td className="p-3 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                {Number(c.total_debe || 0) > 0 ? `Bs. ${Number(c.total_debe).toLocaleString('es-VE', { minimumFractionDigits: 2 })}` : '-'}
                              </td>
                              <td className="p-3 text-right font-mono font-bold text-blue-600 dark:text-blue-400">
                                {Number(c.total_haber || 0) > 0 ? `Bs. ${Number(c.total_haber).toLocaleString('es-VE', { minimumFractionDigits: 2 })}` : '-'}
                              </td>
                              <td className="p-3 text-right font-mono font-bold text-foreground">
                                <span>Bs. {montoSaldo.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</span>
                                {esAnomalo ? (
                                  <span className="block text-[10px] font-extrabold text-rose-600 dark:text-rose-400">
                                    ⚠️ {natTexto}
                                  </span>
                                ) : (
                                  <span className="block text-[10px] text-muted-foreground font-normal">
                                    ({natTexto})
                                  </span>
                                )}
                              </td>
                              <td className="p-3 text-center print:hidden">
                                <Button
                                  variant="ghost"
                                  size="sm"
                                  onClick={() => setCuentaId(c.id)}
                                  className="h-7 text-[11px] font-bold text-primary hover:text-primary hover:bg-primary/10"
                                >
                                  Ver Mayor →
                                </Button>
                              </td>
                            </tr>
                          );
                        })
                      )}
                    </tbody>
                  </table>
                </div>

                {/* Tarjetas de Resumen Consolidado de Transacciones en Cuentas Imputables */}
                {Array.isArray(mayorData) && mayorData.length > 0 && (
                  <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 bg-muted/30 p-4 rounded-xl border border-border/60 mt-4">
                    <div className="text-center sm:text-left border-b sm:border-b-0 sm:border-r border-border/40 pb-3 sm:pb-0 sm:pr-3">
                      <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                        TOTAL DEBE (CUENTAS IMPUTABLES)
                      </span>
                      <span className="text-base font-extrabold text-foreground">
                        Bs. {mayorData.reduce((acc: number, c: any) => acc + Number(c.total_debe || 0), 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                      </span>
                    </div>

                    <div className="text-center sm:text-left border-b sm:border-b-0 sm:border-r border-border/40 pb-3 sm:pb-0 sm:pr-3">
                      <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                        TOTAL HABER (CUENTAS IMPUTABLES)
                      </span>
                      <span className="text-base font-extrabold text-foreground">
                        Bs. {mayorData.reduce((acc: number, c: any) => acc + Number(c.total_haber || 0), 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                      </span>
                    </div>

                    <div className="text-center sm:text-left">
                      <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                        DIFERENCIA (DESCUADRE MIGRACIÓN LEGACY)
                      </span>
                      {(() => {
                        const totDebe = mayorData.reduce((acc: number, c: any) => acc + Number(c.total_debe || 0), 0);
                        const totHaber = mayorData.reduce((acc: number, c: any) => acc + Number(c.total_haber || 0), 0);
                        const dif = totDebe - totHaber;
                        return (
                          <span className={`text-base font-extrabold ${Math.abs(dif) < 0.01 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                            Bs. {dif.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                          </span>
                        );
                      })()}
                    </div>
                  </div>
                )}
              </div>
            ) : !mayorResumen ? (
              <div className="p-8 text-center text-muted-foreground">
                <p>No se encontraron datos para la cuenta seleccionada.</p>
                <Button variant="outline" size="sm" onClick={() => setCuentaId(0)} className="mt-3 text-xs font-semibold">
                  ← Volver a Cuentas con Movimientos
                </Button>
              </div>
            ) : (
              /* MODO DETALLE: Muestra la tarjeta y los movimientos de una sola cuenta */
              <div className="space-y-6">
                <div className="border-b pb-4 flex flex-wrap justify-between items-start gap-4">
                  <div>
                    <div className="flex items-center gap-3">
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setCuentaId(0)}
                        className="text-xs font-semibold h-8 print:hidden"
                      >
                        ← Ver Todas las Cuentas
                      </Button>
                      <h2 className="text-xl font-bold font-mono text-primary">{mayorResumen.codigo} - {mayorResumen.nombre}</h2>
                    </div>
                    <span className="text-xs text-muted-foreground uppercase font-semibold mt-1 block">Naturaleza: {mayorResumen.naturaleza}</span>
                  </div>
                  <div className="text-right">
                    <span className="text-xs text-muted-foreground block">Moneda de Reporte</span>
                    <span className="font-bold text-md">{moneda}</span>
                  </div>
                </div>

                <div className="grid grid-cols-2 md:grid-cols-4 gap-4 bg-muted/30 p-4 rounded-xl">
                  <div>
                    <span className="text-xs text-muted-foreground block">Saldo Inicial</span>
                    <span className="text-base font-bold">
                      {Number(moneda === 'VES' ? mayorResumen.saldo_inicial_base : mayorResumen.saldo_inicial_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
                    </span>
                  </div>
                  <div>
                    <span className="text-xs text-muted-foreground block">Total Débitos</span>
                    <span className="text-base font-bold text-emerald-600">
                      {Number(moneda === 'VES' ? mayorResumen.debitos_base : mayorResumen.debitos_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
                    </span>
                  </div>
                  <div>
                    <span className="text-xs text-muted-foreground block">Total Créditos</span>
                    <span className="text-base font-bold text-blue-600">
                      {Number(moneda === 'VES' ? mayorResumen.creditos_base : mayorResumen.creditos_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
                    </span>
                  </div>
                  <div>
                    <span className="text-xs text-muted-foreground block">Saldo Final Acumulado</span>
                    <span className="text-base font-bold text-primary">
                      {Number(moneda === 'VES' ? mayorResumen.saldo_final_base : mayorResumen.saldo_final_origen).toLocaleString('es-VE', { minimumFractionDigits: 2 })} {moneda}
                    </span>
                  </div>
                </div>

                <div className="border rounded-xl overflow-hidden shadow-xs">
                  <table className="w-full text-sm text-left">
                    <thead className="bg-muted text-muted-foreground uppercase text-xs">
                      <tr>
                        <th className="p-3">N° Asiento</th>
                        <th className="p-3">Fecha</th>
                        <th className="p-3">Concepto</th>
                        <th className="p-3 text-right">Debe</th>
                        <th className="p-3 text-right">Haber</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {mayorMovimientosFiltrados.length === 0 ? (
                        <tr>
                          <td colSpan={5} className="p-6 text-center text-muted-foreground text-xs">
                            No existen movimientos registrados para esta cuenta en el período.
                          </td>
                        </tr>
                      ) : (
                        mayorMovimientosFiltrados.map((m) => (
                          <tr key={m.id} className="hover:bg-muted/20 transition-colors">
                            <td className="p-3 font-mono font-bold text-primary">{m.asiento_numero}</td>
                            <td className="p-3 text-xs">{formatFechaVE(m.asiento_fecha)}</td>
                            <td className="p-3 text-xs">{m.concepto || m.asiento_concepto}</td>
                            <td className="p-3 text-right font-mono font-bold text-emerald-600">
                              {Number(m.debe) > 0 ? Number(m.debe).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : '-'}
                            </td>
                            <td className="p-3 text-right font-mono font-bold text-blue-600">
                              {Number(m.haber) > 0 ? Number(m.haber).toLocaleString('es-VE', { minimumFractionDigits: 2 }) : '-'}
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
        )}

        {/* VISTA 3: BALANCE DE COMPROBACIÓN (ESTÁNDAR CONTABLE OFICIAL) */}
        {activeTab === 'balance' && (
          <div>
            {isLoadingBalance ? (
              <div className="p-8 text-center text-muted-foreground">Generando Balance de Comprobación Oficial...</div>
            ) : balanceFiltrado.length === 0 ? (
              <div className="p-8 text-center text-muted-foreground">No existen cuentas imputables con saldo o movimientos en el período.</div>
            ) : (
              <div className="space-y-6">
                <div className="border border-border/60 rounded-xl overflow-x-auto shadow-2xs bg-card">
                  <table className="w-full text-xs text-left min-w-[900px]">
                    <thead className="bg-muted/60 dark:bg-muted/30 text-muted-foreground uppercase text-[11px] font-bold tracking-wider border-b">
                      <tr>
                        <th rowSpan={2} className="p-3 w-36 align-middle border-r">CÓDIGO</th>
                        <th rowSpan={2} className="p-3 align-middle border-r">CUENTA CONTABLE</th>
                        <th colSpan={2} className="p-2 text-center border-b border-r bg-emerald-500/10 text-emerald-900 dark:text-emerald-300">
                          MOVIMIENTOS DEL PERÍODO
                        </th>
                        <th colSpan={2} className="p-2 text-center border-b border-r bg-blue-500/10 text-blue-900 dark:text-blue-300">
                          SALDOS FINALES ACUMULADOS
                        </th>
                        <th rowSpan={2} className="p-3 w-28 align-middle border-r">TIPO</th>
                        <th rowSpan={2} className="p-3 w-28 align-middle">NATURALEZA</th>
                      </tr>
                      <tr>
                        <th className="p-2.5 text-right w-36 border-r text-emerald-700 dark:text-emerald-400">DEBE</th>
                        <th className="p-2.5 text-right w-36 border-r text-emerald-700 dark:text-emerald-400">HABER</th>
                        <th className="p-2.5 text-right w-36 border-r text-blue-700 dark:text-blue-400">DEUDOR</th>
                        <th className="p-2.5 text-right w-36 border-r text-blue-700 dark:text-blue-400">ACREEDOR</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-border/60">
                      {balanceFiltrado.map((f: any) => {
                        const debePer = Number(f.debe_periodo || 0);
                        const haberPer = Number(f.haber_periodo || 0);
                        const sDeudor = Number(f.saldo_deudor || 0);
                        const sAcreedor = Number(f.saldo_acreedor || 0);

                        return (
                          <tr key={f.id} className="hover:bg-muted/20 transition-colors">
                            <td className="p-3 font-mono font-bold text-primary border-r">{f.codigo}</td>
                            <td className="p-3 font-medium text-foreground border-r">{f.nombre}</td>
                            <td className="p-3 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400 border-r">
                              {debePer > 0 ? `Bs. ${debePer.toLocaleString('es-VE', { minimumFractionDigits: 2 })}` : '-'}
                            </td>
                            <td className="p-3 text-right font-mono font-semibold text-emerald-600 dark:text-emerald-400 border-r">
                              {haberPer > 0 ? `Bs. ${haberPer.toLocaleString('es-VE', { minimumFractionDigits: 2 })}` : '-'}
                            </td>
                            <td className="p-3 text-right font-mono font-bold text-foreground border-r">
                              {sDeudor > 0 ? `Bs. ${sDeudor.toLocaleString('es-VE', { minimumFractionDigits: 2 })}` : '-'}
                            </td>
                            <td className="p-3 text-right font-mono font-bold text-foreground border-r">
                              {sAcreedor > 0 ? `Bs. ${sAcreedor.toLocaleString('es-VE', { minimumFractionDigits: 2 })}` : '-'}
                            </td>
                            <td className="p-3 border-r">
                              <Badge variant="outline" className="text-[10px] uppercase font-semibold">
                                {f.tipo || 'Activo'}
                              </Badge>
                            </td>
                            <td className="p-3 capitalize text-muted-foreground">{f.naturaleza}</td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {/* Tarjetas de Verificación Auditada y Cuadre de Partida Doble */}
                {(() => {
                  const sumDebePer = balanceFiltrado.reduce((acc, f: any) => acc + Number(f.debe_periodo || 0), 0);
                  const sumHaberPer = balanceFiltrado.reduce((acc, f: any) => acc + Number(f.haber_periodo || 0), 0);
                  const sumDeudor = balanceFiltrado.reduce((acc, f: any) => acc + Number(f.saldo_deudor || 0), 0);
                  const sumAcreedor = balanceFiltrado.reduce((acc, f: any) => acc + Number(f.saldo_acreedor || 0), 0);
                  const difSaldos = sumDeudor - sumAcreedor;
                  const estaCuadrado = Math.abs(difSaldos) < 0.01;

                  return (
                    <div className="grid grid-cols-1 sm:grid-cols-5 gap-4 bg-muted/30 p-4 rounded-xl border border-border/60">
                      <div className="text-center sm:text-left border-b sm:border-b-0 sm:border-r border-border/40 pb-3 sm:pb-0 sm:pr-3">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                          MOVIMIENTOS DEBE
                        </span>
                        <span className="text-base font-extrabold text-emerald-600 dark:text-emerald-400">
                          Bs. {sumDebePer.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </span>
                      </div>

                      <div className="text-center sm:text-left border-b sm:border-b-0 sm:border-r border-border/40 pb-3 sm:pb-0 sm:pr-3">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                          MOVIMIENTOS HABER
                        </span>
                        <span className="text-base font-extrabold text-emerald-600 dark:text-emerald-400">
                          Bs. {sumHaberPer.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </span>
                      </div>

                      <div className="text-center sm:text-left border-b sm:border-b-0 sm:border-r border-border/40 pb-3 sm:pb-0 sm:pr-3">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                          TOTAL SALDO DEUDOR
                        </span>
                        <span className="text-base font-extrabold text-foreground">
                          Bs. {sumDeudor.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </span>
                      </div>

                      <div className="text-center sm:text-left border-b sm:border-b-0 sm:border-r border-border/40 pb-3 sm:pb-0 sm:pr-3">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                          TOTAL SALDO ACREEDOR
                        </span>
                        <span className="text-base font-extrabold text-foreground">
                          Bs. {sumAcreedor.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </span>
                      </div>

                      <div className="text-center sm:text-left">
                        <span className="text-[11px] font-bold uppercase tracking-wider text-muted-foreground block mb-1">
                          DIFERENCIA (DESCUADRE)
                        </span>
                        <span className={`text-base font-extrabold ${estaCuadrado ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'}`}>
                          Bs. {difSaldos.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                        </span>
                      </div>
                    </div>
                  );
                })()}
              </div>
            )}
          </div>
        )}
      </div>
    </div>
  );
};
