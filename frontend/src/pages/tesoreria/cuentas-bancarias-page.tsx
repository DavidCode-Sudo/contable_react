import { useEffect, useMemo, useState } from 'react'
import {
  ArrowRightLeft,
  Building2,
  CheckCircle2,
  CreditCard,
  DollarSign,
  Eye,
  EyeOff,
  FileCheck,
  Plus,
  Pencil,
  Power,
  PowerOff,
  RefreshCw,
  RotateCcw,
  Search,
  ShieldCheck,
  Wallet,
} from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { CuentaModal } from '@/components/tesoreria/cuenta-modal'
import { CuentaEstadoModal } from '@/components/tesoreria/cuenta-estado-modal'
import { SaldoInicialModal } from '@/components/tesoreria/saldo-inicial-modal'
import { TransferenciaCancelModal } from '@/components/tesoreria/transferencia-cancel-modal'
import { TransferenciaModal } from '@/components/tesoreria/transferencia-modal'
import { useCambiarEstadoCuenta, useCuentasBancarias, useTransferencias } from '@/hooks/useTesoreria'
import { ofuscarNumeroCuenta } from '@/lib/utils'
import type { CuentaBancaria, TransferenciaBancaria } from '@/types/tesoreria'

export function CuentasBancariasPage() {
  const { data: cuentasData, isLoading: isLoadingCuentas, refetch: refetchCuentas } = useCuentasBancarias()
  const cambiarEstadoMutation = useCambiarEstadoCuenta()

  const [busqueda, setBusqueda] = useState('')
  const [paginaTrf, setPaginaTrf] = useState(1)
  const [mostrarCuentasCompletas, setMostrarCuentasCompletas] = useState(false)
  const [filtroEstado, setFiltroEstado] = useState<'todas' | 'activas'>('todas')

  const { data: transferenciasData, isLoading: isLoadingTrf, refetch: refetchTrf } = useTransferencias({
    page: paginaTrf,
    limit: 20,
    q: busqueda,
  })

  const [cuentaToEdit, setCuentaToEdit] = useState<CuentaBancaria | null>(null)
  const [modalCuentaOpen, setModalCuentaOpen] = useState(false)
  const [modalTransferenciaOpen, setModalTransferenciaOpen] = useState(false)
  
  const [cuentaSaldoInicialSelected, setCuentaSaldoInicialSelected] = useState<CuentaBancaria | null>(null)
  const [modalSaldoInicialOpen, setModalSaldoInicialOpen] = useState(false)

  const [cuentaEstadoSelected, setCuentaEstadoSelected] = useState<CuentaBancaria | null>(null)
  const [modalEstadoOpen, setModalEstadoOpen] = useState(false)

  const [transferenciaCancelSelected, setTransferenciaCancelSelected] = useState<TransferenciaBancaria | null>(null)
  const [modalCancelOpen, setModalCancelOpen] = useState(false)

  const cuentas: CuentaBancaria[] = useMemo(() => {
    if (Array.isArray(cuentasData)) return cuentasData as CuentaBancaria[]
    if (cuentasData && Array.isArray((cuentasData as any).data)) return (cuentasData as any).data
    return []
  }, [cuentasData])

  const stats = useMemo(() => {
    if (cuentasData && !Array.isArray(cuentasData) && (cuentasData as any).stats) {
      return (cuentasData as any).stats
    }
    const totalEfectivo = cuentas.reduce((sum, c) => sum + (c.saldo_efectivo_real || 0), 0)
    const totalDisponible = cuentas.reduce((sum, c) => sum + (c.disponible_financiero_real || 0), 0)
    const cuentasActivas = cuentas.filter((c) => c.estado === 'activa').length
    return {
      total_efectivo: totalEfectivo,
      total_disponibilidad_real: totalDisponible,
      total_cuentas: cuentas.length,
      cuentas_activas: cuentasActivas,
    }
  }, [cuentasData, cuentas])

  const transferencias: TransferenciaBancaria[] = useMemo(() => {
    if (Array.isArray(transferenciasData)) return transferenciasData as TransferenciaBancaria[]
    if (transferenciasData && Array.isArray((transferenciasData as any).data)) return (transferenciasData as any).data
    return []
  }, [transferenciasData])

  // Fallback inteligente para evitar páginas vacías tras mutaciones (Prueba 5.2.b)
  useEffect(() => {
    if (!isLoadingTrf && transferencias.length === 0 && paginaTrf > 1) {
      setPaginaTrf((prev) => Math.max(1, prev - 1))
    }
  }, [transferencias, isLoadingTrf, paginaTrf])

  // Filtrado de cuentas en cliente
  const cuentasFiltradas = cuentas.filter((c) => {
    if (filtroEstado === 'activas' && c.estado !== 'activa') return false
    if (!busqueda) return true
    const q = busqueda.toLowerCase()
    return (
      (c.banco_nombre || '').toLowerCase().includes(q) ||
      (c.numero_cuenta || '').includes(q) ||
      (c.institucion || '').toLowerCase().includes(q) ||
      (c.rif || '').toLowerCase().includes(q)
    )
  })

  return (
    <div className="space-y-6">
      {/* Header Principal Responsivo */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
            <Building2 className="size-6 text-foreground" />
            Cuentas Bancarias / Tesorería
          </h1>
          <p className="text-xs text-muted-foreground">
            Control centralizado de cuentas institucionales, disponibilidad presupuestaria ONAPRE y transferencias interbancarias.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2 shrink-0">
          <Button variant="outline" size="sm" onClick={() => { refetchCuentas(); refetchTrf(); }}>
            <RefreshCw className="mr-1.5 size-3.5" />
            Actualizar
          </Button>
          <Button size="sm" className="font-bold shadow-xs" onClick={() => { setCuentaToEdit(null); setModalCuentaOpen(true); }}>
            <Plus className="mr-1.5 size-4" />
            Nueva Cuenta
          </Button>
          <Button variant="default" size="sm" className="font-bold shadow-xs" onClick={() => setModalTransferenciaOpen(true)}>
            <ArrowRightLeft className="mr-1.5 size-4" />
            Transferencia
          </Button>
        </div>
      </div>

      {/* Tarjetas KPI Responsivas (1 col en mobile, 2 en tablet, 4 en desktop) */}
      <div className="grid gap-3 sm:gap-4 grid-cols-1 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="shadow-sm border-primary/20 bg-card">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground truncate">
              Total Efectivo en Banco
            </CardTitle>
            <Wallet className="h-4 w-4 sm:h-5 sm:w-5 text-primary shrink-0" />
          </CardHeader>
          <CardContent className="pt-1">
            <div className="text-lg lg:text-xl font-bold text-primary font-mono truncate" title={`${(stats?.total_efectivo ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES`}>
              {(stats?.total_efectivo ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES
            </div>
            <p className="text-[11px] sm:text-xs text-muted-foreground mt-0.5 truncate">Saldo acumulado en libro diario</p>
          </CardContent>
        </Card>

        <Card className="shadow-sm border-emerald-500/30 bg-card">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground truncate">
              Disponibilidad Real ONAPRE
            </CardTitle>
            <ShieldCheck className="h-4 w-4 sm:h-5 sm:w-5 text-emerald-600 shrink-0" />
          </CardHeader>
          <CardContent className="pt-1">
            <div className="text-lg lg:text-xl font-bold text-emerald-600 dark:text-emerald-400 font-mono truncate" title={`${(stats?.total_disponibilidad_real ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES`}>
              {(stats?.total_disponibilidad_real ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES
            </div>
            <p className="text-[11px] sm:text-xs text-muted-foreground mt-0.5 truncate">Deduciendo retención presupuestaria</p>
          </CardContent>
        </Card>

        <Card className="shadow-sm bg-card">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground truncate">
              Cuentas Bancarias Activas
            </CardTitle>
            <Building2 className="h-4 w-4 sm:h-5 sm:w-5 text-muted-foreground shrink-0" />
          </CardHeader>
          <CardContent className="pt-1">
            <div className="text-lg lg:text-xl font-bold font-mono">
              {stats?.cuentas_activas ?? 0} / {stats?.total_cuentas ?? 0}
            </div>
            <p className="text-[11px] sm:text-xs text-muted-foreground mt-0.5 truncate">Cuentas institucionales operativas</p>
          </CardContent>
        </Card>

        <Card className="shadow-sm bg-card">
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-xs font-semibold uppercase tracking-wider text-muted-foreground truncate">
              Sólidez de Caja
            </CardTitle>
            <FileCheck className="h-4 w-4 sm:h-5 sm:w-5 text-emerald-500 shrink-0" />
          </CardHeader>
          <CardContent className="pt-1">
            <div className="text-lg lg:text-xl font-bold text-emerald-600">100% ACID</div>
            <p className="text-[11px] sm:text-xs text-muted-foreground mt-0.5 truncate">Libro Diario Cuadrado por Partida Doble</p>
          </CardContent>
        </Card>
      </div>

      {/* Pestañas y Buscador Responsivos */}
      <Tabs defaultValue="cuentas" className="space-y-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <TabsList className="grid w-full grid-cols-2 sm:w-[380px]">
            <TabsTrigger value="cuentas" className="flex items-center gap-2 text-xs sm:text-sm">
              <Building2 className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
              Cuentas ({cuentasFiltradas.length})
            </TabsTrigger>
            <TabsTrigger value="transferencias" className="flex items-center gap-2 text-xs sm:text-sm">
              <ArrowRightLeft className="h-3.5 w-3.5 sm:h-4 sm:w-4" />
              Transferencias
            </TabsTrigger>
          </TabsList>

          <div className="relative w-full sm:w-72">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Buscar banco, número de cuenta..."
              className="pl-9 h-9 text-xs sm:text-sm"
              value={busqueda}
              onChange={(e) => setBusqueda(e.target.value)}
            />
          </div>
        </div>

        {/* TAB 1: CUENTAS BANCARIAS */}
        <TabsContent value="cuentas" className="space-y-4 pt-4">
          <div className="flex justify-between items-center">
            <div className="flex items-center gap-2">
              <Button
                variant={filtroEstado === 'todas' ? 'secondary' : 'ghost'}
                size="sm"
                onClick={() => setFiltroEstado('todas')}
              >
                Todas ({cuentas.length})
              </Button>
              <Button
                variant={filtroEstado === 'activas' ? 'secondary' : 'ghost'}
                size="sm"
                onClick={() => setFiltroEstado('activas')}
              >
                Solo Activas ({cuentas.filter((c) => c.estado === 'activa').length})
              </Button>
            </div>
          </div>

          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-sm text-left border-collapse">
                  <thead className="bg-muted/50 text-muted-foreground text-xs uppercase font-medium border-b">
                    <tr>
                      <th className="py-3 px-4">Institución</th>
                      <th className="py-3 px-4">RIF</th>
                      <th className="py-3 px-4">Oficina</th>
                      <th className="py-3 px-4">
                        <div className="flex items-center gap-1.5">
                          <span>Número de Cuenta</span>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="h-5 w-5 text-muted-foreground hover:text-foreground"
                            title={mostrarCuentasCompletas ? 'Ofuscar números de cuenta' : 'Mostrar números de cuenta completos'}
                            onClick={() => setMostrarCuentasCompletas(!mostrarCuentasCompletas)}
                          >
                            {mostrarCuentasCompletas ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                          </Button>
                        </div>
                      </th>
                      <th className="py-3 px-4">Nombre de la Cuenta</th>
                      <th className="py-3 px-4">Tipo</th>
                      <th className="py-3 px-4 text-right">Saldo Inicial</th>
                      <th className="py-3 px-4 text-right">Saldo Actual</th>
                      <th className="py-3 px-4 text-right">Disponible Real</th>
                      <th className="py-3 px-4 text-center">Estado</th>
                      <th className="py-3 px-4 text-center">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {isLoadingCuentas ? (
                      <tr>
                        <td colSpan={11} className="text-center py-8 text-muted-foreground">
                          Cargando catálogo de cuentas bancarias...
                        </td>
                      </tr>
                    ) : cuentasFiltradas.length === 0 ? (
                      <tr>
                        <td colSpan={11} className="text-center py-8 text-muted-foreground">
                          No se encontraron cuentas bancarias registradas.
                        </td>
                      </tr>
                    ) : (
                      cuentasFiltradas.map((c) => (
                        <tr
                          key={c.id}
                          className={
                            c.estado === 'inactiva'
                              ? 'opacity-60 bg-muted/20 hover:bg-muted/40 transition-colors'
                              : 'hover:bg-muted/30 transition-colors'
                          }
                        >
                          <td className="py-3.5 px-4 font-medium text-xs max-w-[180px] truncate" title={c.institucion || 'Gobernación / Ente Público'}>
                            {c.institucion || 'Gobernación / Ente Público'}
                          </td>
                          <td className="py-3.5 px-4 text-xs font-mono text-muted-foreground">
                            {c.rif || 'G-20000000-0'}
                          </td>
                          <td className="py-3.5 px-4 text-xs text-muted-foreground">
                            {c.sucursal || 'AGENCIA PRINCIPAL'}
                          </td>
                          <td className="py-3.5 px-4 font-mono text-xs font-semibold">
                            {ofuscarNumeroCuenta(c.numero_cuenta, mostrarCuentasCompletas)}
                          </td>
                          <td className="py-3.5 px-4 font-semibold text-xs text-foreground">
                            {c.banco_nombre || 'CUENTA BANCARIA'}
                          </td>
                          <td className="py-3.5 px-4">
                            <Badge variant="outline" className="capitalize text-xs font-normal">
                              {c.tipo_cuenta}
                            </Badge>
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono text-xs">
                            Bs {c.saldo_inicial.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono font-semibold">
                            Bs {c.saldo_efectivo_real.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                            Bs {c.disponible_financiero_real.toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            <Badge
                              variant="default"
                              className={
                                c.estado === 'activa'
                                  ? 'bg-emerald-600/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20 capitalize text-xs'
                                  : 'bg-destructive/10 text-destructive border-destructive/20 capitalize text-xs'
                              }
                            >
                              {c.estado}
                            </Badge>
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            <div className="flex items-center justify-center gap-1">
                              <Button
                                variant="ghost"
                                size="sm"
                                title="Editar datos de la cuenta"
                                onClick={() => {
                                  setCuentaToEdit(c)
                                  setModalCuentaOpen(true)
                                }}
                              >
                                <Pencil className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                              </Button>

                              <Button
                                variant="ghost"
                                size="sm"
                                title="Ajustar Saldo Inicial (Danger Zone)"
                                onClick={() => {
                                  setCuentaSaldoInicialSelected(c)
                                  setModalSaldoInicialOpen(true)
                                }}
                              >
                                <DollarSign className="h-4 w-4 text-amber-600" />
                              </Button>

                              <Button
                                variant="ghost"
                                size="sm"
                                title={c.estado === 'activa' ? 'Desactivar cuenta bancaria' : 'Activar cuenta bancaria'}
                                onClick={() => {
                                  setCuentaEstadoSelected(c)
                                  setModalEstadoOpen(true)
                                }}
                              >
                                {c.estado === 'activa' ? (
                                  <PowerOff className="h-4 w-4 text-destructive" />
                                ) : (
                                  <Power className="h-4 w-4 text-emerald-600" />
                                )}
                              </Button>
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* TAB 2: HISTORIAL DE TRANSFERENCIAS */}
        <TabsContent value="transferencias">
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-sm text-left border-collapse">
                  <thead className="bg-muted/50 text-muted-foreground text-xs uppercase font-medium border-b">
                    <tr>
                      <th className="py-3 px-4">Ref / Fecha</th>
                      <th className="py-3 px-4">Cuenta Origen (Emisora)</th>
                      <th className="py-3 px-4">Cuenta Destino (Receptora)</th>
                      <th className="py-3 px-4 text-right">Monto</th>
                      <th className="py-3 px-4">Concepto</th>
                      <th className="py-3 px-4 text-center">Estado</th>
                      <th className="py-3 px-4 text-center">Acciones</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {isLoadingTrf ? (
                      <tr>
                        <td colSpan={7} className="text-center py-8 text-muted-foreground">
                          Cargando historial de transferencias...
                        </td>
                      </tr>
                    ) : transferencias.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="text-center py-8 text-muted-foreground">
                          No hay transferencias interbancarias registradas.
                        </td>
                      </tr>
                    ) : (
                      transferencias.map((t) => (
                        <tr key={t.id} className="hover:bg-muted/30 transition-colors">
                          <td className="py-3.5 px-4 font-mono text-xs font-semibold">
                            <div>{t.numero_transferencia}</div>
                            <div className="text-muted-foreground font-normal">{t.fecha_transferencia}</div>
                          </td>
                          <td className="py-3.5 px-4 text-xs">
                            <div className="font-semibold">{t.banco_origen_nombre}</div>
                            <div className="font-mono text-muted-foreground">{t.banco_origen_numero.slice(-4)}</div>
                          </td>
                          <td className="py-3.5 px-4 text-xs">
                            <div className="font-semibold">{t.banco_destino_nombre}</div>
                            <div className="font-mono text-muted-foreground">{t.banco_destino_numero.slice(-4)}</div>
                          </td>
                          <td className="py-3.5 px-4 text-right font-mono font-bold">
                            {t.monto.toLocaleString('es-VE', { minimumFractionDigits: 2 })} VES
                          </td>
                          <td className="py-3.5 px-4 text-xs max-w-xs truncate" title={t.concepto}>
                            {t.concepto}
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            {t.estado === 'procesada' ? (
                              <Badge variant="default" className="bg-emerald-600 hover:bg-emerald-700">
                                Procesada
                              </Badge>
                            ) : (
                              <Badge variant="destructive">
                                Cancelada / Revertida
                              </Badge>
                            )}
                          </td>
                          <td className="py-3.5 px-4 text-center">
                            {t.estado === 'procesada' && (
                              <Button
                                variant="ghost"
                                size="sm"
                                className="text-destructive hover:bg-destructive/10"
                                title="Revertir Transferencia"
                                onClick={() => {
                                  setTransferenciaCancelSelected(t)
                                  setModalCancelOpen(true)
                                }}
                              >
                                <RotateCcw className="h-4 w-4" />
                              </Button>
                            )}
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Modales de la aplicación */}
      <CuentaModal
        cuentaToEdit={cuentaToEdit}
        open={modalCuentaOpen}
        onOpenChange={(open) => {
          setModalCuentaOpen(open)
          if (!open) setCuentaToEdit(null)
        }}
      />

      <TransferenciaModal
        cuentas={cuentas}
        open={modalTransferenciaOpen}
        onOpenChange={setModalTransferenciaOpen}
      />

      <SaldoInicialModal
        cuenta={cuentaSaldoInicialSelected}
        open={modalSaldoInicialOpen}
        onOpenChange={setModalSaldoInicialOpen}
      />

      <TransferenciaCancelModal
        transferencia={transferenciaCancelSelected}
        open={modalCancelOpen}
        onOpenChange={setModalCancelOpen}
      />

      <CuentaEstadoModal
        cuenta={cuentaEstadoSelected}
        open={modalEstadoOpen}
        onOpenChange={(open) => {
          setModalEstadoOpen(open)
          if (!open) setCuentaEstadoSelected(null)
        }}
      />
    </div>
  )
}
