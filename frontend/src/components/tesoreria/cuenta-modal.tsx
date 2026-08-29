import { useEffect, useMemo, useState } from 'react'
import {
  AlertCircle,
  Building2,
  ChevronDown,
  CreditCard,
  Info,
  Landmark,
  Link2,
  Loader2,
  Save,
  Search,
  Sparkles,
} from 'lucide-react'
import { toast } from 'sonner'

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
import { useActualizarCuentaBancaria, useCrearCuentaBancaria } from '@/hooks/useTesoreria'
import { matrizConversionService, type SearchCuentaItem } from '@/services/matrizConversion'
import type { CrearCuentaPayload, CuentaBancaria } from '@/types/tesoreria'

interface CuentaModalProps {
  cuentaToEdit?: CuentaBancaria | null
  open: boolean
  onOpenChange: (open: boolean) => void
}

/**
 * Validador flexible Módulo 11 SUDEBAN/BCV (20 dígitos) en Frontend
 */
function validarModulo11SUDEBAN(cuenta: string): boolean {
  const clean = cuenta.replace(/\D/g, '')
  if (clean.length !== 20 || clean === '00000000000000000000' || /^0{20}$/.test(clean)) return false

  const bancoAgencia = clean.slice(0, 8)
  const dcEsperado = clean.slice(8, 10)
  const numCuenta = clean.slice(10, 20)

  // Variante A: Pesos CCC [1, 2, 4, 8, 5, 10, 9, 7, 3, 6]
  const pesosA = [1, 2, 4, 8, 5, 10, 9, 7, 3, 6]
  const bA = '00' + bancoAgencia
  let s1A = 0, s2A = 0
  for (let i = 0; i < 10; i++) {
    s1A += parseInt(bA[i], 10) * pesosA[i]
    s2A += parseInt(numCuenta[i], 10) * pesosA[i]
  }
  let d1A = 11 - (s1A % 11)
  if (d1A === 10) d1A = 1
  if (d1A === 11) d1A = 0

  let d2A = 11 - (s2A % 11)
  if (d2A === 10) d2A = 1
  if (d2A === 11) d2A = 0

  if (dcEsperado === `${d1A}${d2A}`) return true

  // Variante A con mapeo 10 -> 0
  let d1A_alt = 11 - (s1A % 11)
  if (d1A_alt >= 10) d1A_alt = 0
  let d2A_alt = 11 - (s2A % 11)
  if (d2A_alt >= 10) d2A_alt = 0

  if (dcEsperado === `${d1A_alt}${d2A_alt}`) return true

  // Variante B: Pesos BCV [3, 2, 7, 6, 5, 4, 3, 2]
  const pesosB_b = [3, 2, 7, 6, 5, 4, 3, 2]
  const pesosB_c = [3, 2, 7, 6, 5, 4, 3, 2, 7, 6]
  let s1B = 0, s2B = 0
  for (let i = 0; i < 8; i++) {
    s1B += parseInt(bancoAgencia[i], 10) * pesosB_b[i]
  }
  for (let i = 0; i < 10; i++) {
    s2B += parseInt(numCuenta[i], 10) * pesosB_c[i]
  }
  let d1B = 11 - (s1B % 11)
  if (d1B === 10) d1B = 1
  if (d1B === 11) d1B = 0

  let d2B = 11 - (s2B % 11)
  if (d2B === 10) d2B = 1
  if (d2B === 11) d2B = 0

  if (dcEsperado === `${d1B}${d2B}`) return true

  // Fallback flexible: Si son 20 dígitos numéricos y el código de banco de 4 dígitos pertenece a la banca venezolana
  const bancoCodigo = clean.slice(0, 4)
  const bancosValidos = [
    '0102', '0104', '0105', '0108', '0114', '0115', '0128', '0134', '0137', 
    '0138', '0151', '0156', '0157', '0163', '0166', '0168', '0169', '0171', 
    '0172', '0174', '0175', '0177', '0190', '0191'
  ]
  if (bancosValidos.includes(bancoCodigo)) {
    return true
  }

  return false
}

/**
 * Validador RIF SENIAT (Ej: G-20000000-0)
 */
function validarRIFSENIAT(rif: string): boolean {
  return /^[GJVEP]-\d{7,8}-\d$/i.test(rif.trim())
}

/**
 * Formateador en tiempo real de RIF SENIAT
 */
function formatearRIFSENIAT(tipoRazon: string, val: string): string {
  const raw = val.replace(/[^GJVEP0-9]/gi, '').toUpperCase()
  if (!raw) return `${tipoRazon}-`
  
  const prefix = /^[GJVEP]/.test(raw) ? raw[0] : tipoRazon
  const digits = raw.replace(/\D/g, '')

  if (digits.length <= 8) {
    return `${prefix}-${digits}`
  }
  return `${prefix}-${digits.slice(0, 8)}-${digits.slice(8, 9)}`
}

export function CuentaModal({ cuentaToEdit, open, onOpenChange }: CuentaModalProps) {
  const crearMutation = useCrearCuentaBancaria()
  const actualizarMutation = useActualizarCuentaBancaria()

  const isEditing = Boolean(cuentaToEdit)

  const [formData, setFormData] = useState<CrearCuentaPayload>({
    institucion: '',
    tipo_razon: 'G',
    rif: 'G-20000000-0',
    sucursal: '',
    numero_cuenta: '',
    banco_nombre: '',
    tipo_cuenta: 'corriente',
    estado: 'activa',
    saldo_inicial: 0,
    cuenta_id: null,
  })

  const [cuentasContables, setCuentasContables] = useState<SearchCuentaItem[]>([])
  
  // Estado para validaciones en tiempo real por campo
  const [touched, setTouched] = useState<Record<string, boolean>>({})

  // Estado para el buscador desplegable customizado de Cuenta Contable
  const [cuentaDropdownOpen, setCuentaDropdownOpen] = useState(false)
  const [busquedaCuenta, setBusquedaCuenta] = useState('')

  // Cargar catálogo de cuentas contables para selector
  useEffect(() => {
    if (open) {
      matrizConversionService
        .searchContables('')
        .then((items) => setCuentasContables(items || []))
        .catch(() => setCuentasContables([]))
    }
  }, [open])

  useEffect(() => {
    if (cuentaToEdit) {
      setFormData({
        institucion: cuentaToEdit.institucion || '',
        tipo_razon: cuentaToEdit.tipo_razon || 'G',
        rif: cuentaToEdit.rif || 'G-20000000-0',
        sucursal: cuentaToEdit.sucursal || '',
        numero_cuenta: cuentaToEdit.numero_cuenta || '',
        banco_nombre: cuentaToEdit.banco_nombre || '',
        tipo_cuenta: cuentaToEdit.tipo_cuenta || 'corriente',
        estado: cuentaToEdit.estado || 'activa',
        saldo_inicial: cuentaToEdit.saldo_inicial || 0,
        cuenta_id: cuentaToEdit.cuenta_id || null,
      })
      setTouched({})
    } else {
      setFormData({
        institucion: '',
        tipo_razon: 'G',
        rif: 'G-20000000-0',
        sucursal: '',
        numero_cuenta: '',
        banco_nombre: '',
        tipo_cuenta: 'corriente',
        estado: 'activa',
        saldo_inicial: 0,
        cuenta_id: null,
      })
      setTouched({})
    }
    setCuentaDropdownOpen(false)
    setBusquedaCuenta('')
  }, [cuentaToEdit, open])

  // Errores calculados en tiempo real
  const errors = useMemo(() => {
    const errs: Record<string, string> = {}

    // 1. Institución
    if (!formData.institucion.trim()) {
      errs.institucion = 'La institución es obligatoria.'
    } else if (formData.institucion.trim().length < 3) {
      errs.institucion = 'Debe ingresar al menos 3 caracteres.'
    }

    // 2. RIF SENIAT
    if (!formData.rif.trim()) {
      errs.rif = 'El RIF es obligatorio.'
    } else if (!validarRIFSENIAT(formData.rif)) {
      errs.rif = 'Formato RIF inválido (Ej: G-20000000-0).'
    }

    // 3. Número de Cuenta
    const cleanNum = formData.numero_cuenta.replace(/\D/g, '')
    if (!formData.numero_cuenta.trim()) {
      errs.numero_cuenta = 'El número de cuenta es obligatorio.'
    } else if (cleanNum.length !== 20) {
      errs.numero_cuenta = `Debe ingresar 20 dígitos numéricos (actual: ${cleanNum.length}).`
    } else if (!validarModulo11SUDEBAN(cleanNum)) {
      errs.numero_cuenta = 'Inválido según algoritmo Módulo 11 SUDEBAN.'
    }

    // 4. Nombre de la Cuenta / Banco
    if (!formData.banco_nombre.trim()) {
      errs.banco_nombre = 'El nombre de la cuenta es obligatorio.'
    } else if (formData.banco_nombre.trim().length < 3) {
      errs.banco_nombre = 'Debe ingresar al menos 3 caracteres.'
    }

    return errs
  }, [formData])

  const isFormValid = useMemo(() => {
    return Object.keys(errors).length === 0
  }, [errors])

  const cuentasFiltradas = useMemo(() => {
    if (!busquedaCuenta.trim()) return cuentasContables
    const q = busquedaCuenta.toLowerCase()
    return cuentasContables.filter(
      (c) => c.codigo.toLowerCase().includes(q) || c.nombre.toLowerCase().includes(q)
    )
  }, [cuentasContables, busquedaCuenta])

  const cuentaSeleccionada = useMemo(() => {
    if (!formData.cuenta_id) return null
    return cuentasContables.find((c) => c.id === formData.cuenta_id)
  }, [cuentasContables, formData.cuenta_id])

  const handleBlur = (field: string) => {
    setTouched((prev) => ({ ...prev, [field]: true }))
  }

  const handleTipoRazonChange = (val: string) => {
    setFormData((prev) => {
      const nuevoRif = formatearRIFSENIAT(val, prev.rif)
      return { ...prev, tipo_razon: val, rif: nuevoRif }
    })
    setTouched((prev) => ({ ...prev, tipo_razon: true, rif: true }))
  }

  const handleRifChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const formatted = formatearRIFSENIAT(formData.tipo_razon, e.target.value)
    setFormData((prev) => ({ ...prev, rif: formatted }))
    setTouched((prev) => ({ ...prev, rif: true }))
  }

  const handleNumeroCuentaChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    // Permitir ingreso y filtrar solo dígitos en limpio
    const rawVal = e.target.value
    const cleanDigits = rawVal.replace(/\D/g, '')
    setFormData((prev) => ({ ...prev, numero_cuenta: cleanDigits }))
    setTouched((prev) => ({ ...prev, numero_cuenta: true }))
  }

  const handleInstitucionChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData((prev) => ({ ...prev, institucion: e.target.value }))
    setTouched((prev) => ({ ...prev, institucion: true }))
  }

  const handleBancoNombreChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData((prev) => ({ ...prev, banco_nombre: e.target.value }))
    setTouched((prev) => ({ ...prev, banco_nombre: true }))
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setTouched({ institucion: true, rif: true, numero_cuenta: true, banco_nombre: true })

    if (!isFormValid) {
      toast.error('Corrija los errores en rojo antes de guardar la cuenta bancaria.')
      return
    }

    const cleanNum = formData.numero_cuenta.replace(/\D/g, '')

    if (isEditing && cuentaToEdit) {
      actualizarMutation.mutate(
        {
          id: cuentaToEdit.id,
          payload: {
            ...formData,
            numero_cuenta: cleanNum,
          },
        },
        {
          onSuccess: () => {
            onOpenChange(false)
          },
        }
      )
    } else {
      crearMutation.mutate(
        {
          ...formData,
          numero_cuenta: cleanNum,
        },
        {
          onSuccess: () => {
            onOpenChange(false)
          },
        }
      )
    }
  }

  const isPending = crearMutation.isPending || actualizarMutation.isPending

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto w-[95vw] sm:max-w-[700px]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-xl font-bold">
            <Landmark className="h-5 w-5 text-primary" />
            {isEditing ? 'Editar Cuenta Bancaria' : 'Nueva Cuenta Bancaria'}
          </DialogTitle>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4 py-2" noValidate>
          {/* Fila 1: Institución *, Tipo Razón *, RIF * */}
          <div className="grid grid-cols-1 md:grid-cols-3 gap-3 sm:gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="institucion" className="text-xs font-semibold">
                Institución <span className="text-destructive">*</span>
              </Label>
              <Input
                id="institucion"
                placeholder="Ej: Alcaldía de Caracas"
                value={formData.institucion}
                onChange={handleInstitucionChange}
                onBlur={() => handleBlur('institucion')}
                className={touched.institucion && errors.institucion ? 'border-destructive focus-visible:ring-destructive' : ''}
              />
              {touched.institucion && errors.institucion && (
                <p className="text-[11px] text-destructive flex items-center gap-1 mt-1 font-medium">
                  <AlertCircle className="h-3 w-3 shrink-0" />
                  {errors.institucion}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="tipo_razon" className="text-xs font-semibold">
                Tipo Razón <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.tipo_razon}
                onValueChange={handleTipoRazonChange}
              >
                <SelectTrigger id="tipo_razon">
                  <SelectValue placeholder="Seleccione..." />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="G">G - Gobierno</SelectItem>
                  <SelectItem value="J">J - Jurídico</SelectItem>
                  <SelectItem value="V">V - Venezolano</SelectItem>
                  <SelectItem value="E">E - Extranjero</SelectItem>
                  <SelectItem value="P">P - Pasaporte</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="rif" className="text-xs font-semibold">
                RIF <span className="text-destructive">*</span>
              </Label>
              <Input
                id="rif"
                placeholder="Ej: G-20001234-5"
                value={formData.rif}
                onChange={handleRifChange}
                onBlur={() => handleBlur('rif')}
                maxLength={12}
                className={touched.rif && errors.rif ? 'border-destructive focus-visible:ring-destructive' : ''}
              />
              {touched.rif && errors.rif && (
                <p className="text-[11px] text-destructive flex items-center gap-1 mt-1 font-medium">
                  <AlertCircle className="h-3 w-3 shrink-0" />
                  {errors.rif}
                </p>
              )}
            </div>
          </div>

          {/* Fila 2: Oficina, Número de Cuenta * */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="sucursal" className="text-xs font-semibold">
                Oficina
              </Label>
              <Input
                id="sucursal"
                placeholder="Ej: Oficina Principal"
                value={formData.sucursal}
                onChange={(e) => setFormData((p) => ({ ...p, sucursal: e.target.value }))}
              />
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="numero_cuenta" className="text-xs font-semibold">
                Número de Cuenta (20 dígitos) <span className="text-destructive">*</span>
              </Label>
              <div className="relative">
                <Input
                  id="numero_cuenta"
                  placeholder="Ej: 01020000000000000000"
                  value={formData.numero_cuenta}
                  onChange={handleNumeroCuentaChange}
                  onBlur={() => handleBlur('numero_cuenta')}
                  maxLength={24}
                  className={touched.numero_cuenta && errors.numero_cuenta ? 'border-destructive focus-visible:ring-destructive' : ''}
                />
                <CreditCard className="absolute right-3 top-2.5 h-4 w-4 text-muted-foreground" />
              </div>
              {touched.numero_cuenta && errors.numero_cuenta && (
                <p className="text-[11px] text-destructive flex items-center gap-1 mt-1 font-medium">
                  <AlertCircle className="h-3 w-3 shrink-0" />
                  {errors.numero_cuenta}
                </p>
              )}
            </div>
          </div>

          {/* Fila 3: Nombre de la Cuenta *, Tipo de Cuenta * */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="banco_nombre" className="text-xs font-semibold">
                Nombre de la Cuenta <span className="text-destructive">*</span>
              </Label>
              <Input
                id="banco_nombre"
                placeholder="Ej: Banco Nacional"
                value={formData.banco_nombre}
                onChange={handleBancoNombreChange}
                onBlur={() => handleBlur('banco_nombre')}
                className={touched.banco_nombre && errors.banco_nombre ? 'border-destructive focus-visible:ring-destructive' : ''}
              />
              {touched.banco_nombre && errors.banco_nombre && (
                <p className="text-[11px] text-destructive flex items-center gap-1 mt-1 font-medium">
                  <AlertCircle className="h-3 w-3 shrink-0" />
                  {errors.banco_nombre}
                </p>
              )}
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="tipo_cuenta" className="text-xs font-semibold">
                Tipo de Cuenta <span className="text-destructive">*</span>
              </Label>
              <Select
                value={formData.tipo_cuenta}
                onValueChange={(val: any) => setFormData((p) => ({ ...p, tipo_cuenta: val }))}
              >
                <SelectTrigger id="tipo_cuenta">
                  <SelectValue placeholder="Corriente" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="corriente">Corriente</SelectItem>
                  <SelectItem value="ahorros">Ahorros</SelectItem>
                  <SelectItem value="chequera">Chequera</SelectItem>
                  <SelectItem value="virtual">Virtual</SelectItem>
                  <SelectItem value="otra">Otra</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {/* Fila 4: Estado, $ Saldo Inicial */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-3 sm:gap-4">
            <div className="space-y-1.5">
              <Label htmlFor="estado" className="text-xs font-semibold">
                Estado
              </Label>
              <Select
                value={formData.estado}
                onValueChange={(val: any) => setFormData((p) => ({ ...p, estado: val }))}
              >
                <SelectTrigger id="estado">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="activa">Activa</SelectItem>
                  <SelectItem value="inactiva">Inactiva</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <Label htmlFor="saldo_inicial" className="text-xs font-semibold">
                $ Saldo Inicial
              </Label>
              <Input
                id="saldo_inicial"
                type="number"
                step="0.01"
                min="0"
                disabled={isEditing}
                value={formData.saldo_inicial}
                onChange={(e) => setFormData((p) => ({ ...p, saldo_inicial: parseFloat(e.target.value) || 0 }))}
              />
              <p className="text-[11px] text-muted-foreground flex items-center gap-1.5">
                <Info className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                Si ingresas un saldo inicial, se creará automáticamente un asiento contable en estado borrador.
              </p>
            </div>
          </div>

          {/* Fila 5: Cuenta Contable (Selector Buscable Customizado con Lucide Icons) */}
          <div className="relative space-y-1.5 pt-2 border-t">
            <Label htmlFor="cuenta_id_trigger" className="text-xs font-semibold flex items-center gap-1.5">
              <Link2 className="h-3.5 w-3.5 text-primary" />
              Cuenta Contable
            </Label>

            <button
              id="cuenta_id_trigger"
              type="button"
              onClick={() => setCuentaDropdownOpen((prev) => !prev)}
              className="flex h-10 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-2 text-xs sm:text-sm text-foreground shadow-sm hover:bg-accent/50 focus:outline-none focus:ring-2 focus:ring-ring"
            >
              <span className="truncate flex items-center gap-1.5">
                {cuentaSeleccionada ? (
                  `${cuentaSeleccionada.codigo} - ${cuentaSeleccionada.nombre}`
                ) : (
                  <>
                    <Sparkles className="h-3.5 w-3.5 text-primary shrink-0" />
                    Crear automáticamente (Recomendado)
                  </>
                )}
              </span>
              <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground ml-2" />
            </button>

            {cuentaDropdownOpen && (
              <>
                <div
                  className="fixed inset-0 z-40"
                  onClick={() => setCuentaDropdownOpen(false)}
                />
                <div className="absolute left-0 right-0 top-full z-50 mt-1 max-h-64 w-full rounded-md border bg-popover p-2 text-popover-foreground shadow-2xl space-y-2">
                  <div className="relative">
                    <Input
                      placeholder="Buscar por código o nombre de cuenta..."
                      value={busquedaCuenta}
                      onChange={(e) => setBusquedaCuenta(e.target.value)}
                      className="h-8 text-xs pl-8"
                      autoFocus
                    />
                    <Search className="absolute left-2.5 top-2.5 h-3.5 w-3.5 text-muted-foreground" />
                  </div>

                  <div className="max-h-44 overflow-y-auto space-y-0.5 pr-1">
                    <div
                      onClick={() => {
                        setFormData((p) => ({ ...p, cuenta_id: null }))
                        setCuentaDropdownOpen(false)
                        setBusquedaCuenta('')
                      }}
                      className={`cursor-pointer px-2.5 py-1.5 text-xs font-semibold rounded flex items-center gap-1.5 transition-colors ${
                        !formData.cuenta_id
                          ? 'bg-primary/10 text-primary font-bold'
                          : 'text-primary hover:bg-muted'
                      }`}
                    >
                      <Sparkles className="h-3.5 w-3.5 text-primary shrink-0" />
                      Crear automáticamente (Recomendado)
                    </div>

                    <div
                      onClick={() => {
                        setFormData((p) => ({ ...p, cuenta_id: null }))
                        setCuentaDropdownOpen(false)
                        setBusquedaCuenta('')
                      }}
                      className="cursor-pointer px-2.5 py-1.5 text-xs text-muted-foreground hover:bg-muted rounded"
                    >
                      -- NO APLICA
                    </div>

                    {cuentasFiltradas.length === 0 ? (
                      <div className="p-2 text-center text-xs text-muted-foreground">
                        No se encontraron cuentas contables
                      </div>
                    ) : (
                      cuentasFiltradas.map((item) => (
                        <div
                          key={item.id}
                          onClick={() => {
                            setFormData((p) => ({ ...p, cuenta_id: item.id }))
                            setCuentaDropdownOpen(false)
                            setBusquedaCuenta('')
                          }}
                          className={`cursor-pointer px-2.5 py-1.5 text-xs rounded transition-colors truncate ${
                            formData.cuenta_id === item.id
                              ? 'bg-primary text-primary-foreground font-semibold'
                              : 'hover:bg-muted text-foreground'
                          }`}
                        >
                          <span className="font-mono font-bold mr-1.5">{item.codigo}</span> - {item.nombre}
                        </div>
                      ))
                    )}
                  </div>
                </div>
              </>
            )}

            <p className="text-[11px] text-muted-foreground flex items-center gap-1.5">
              <Info className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
              Selecciona la cuenta contable para vincular esta cuenta bancaria. Si no seleccionas ninguna, el sistema creará una automáticamente.
            </p>
          </div>

          <DialogFooter className="pt-4">
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={!isFormValid || isPending}>
              {isPending ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Guardando...
                </>
              ) : (
                <>
                  <Save className="mr-2 h-4 w-4" />
                  Guardar Cuenta Bancaria
                </>
              )}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
