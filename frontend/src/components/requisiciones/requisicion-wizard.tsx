import { createContext, useContext, useEffect, useState } from 'react'
import {
  ArrowLeft,
  ArrowRight,
  Building2,
  Calculator,
  CheckCircle2,
  FileText,
  FolderGit2,
  ListFilter,
  Package,
  Plus,
  RefreshCw,
  Save,
  Send,
  Trash2,
  AlertCircle,
  X,
  Truck,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import {
  buscarPresupuestos,
  buscarProveedores,
  type PresupuestoResumen,
  type ProveedorResumen,
} from '@/services/requisiciones'
import { fetchProductosList, type Producto } from '@/services/inventario'

export interface RequisicionItem {
  id?: string | number
  producto_id?: number | null
  producto_codigo?: string | null
  stock_disponible?: number | null
  descripcion: string
  unidad: string
  cantidad: number
  precio: number
  impuesto: number
}

export interface RequisicionWizardData {
  fechaSolicitud: string
  fechaRequerida: string
  prioridad: string
  moneda: string
  tipoRequisicion: string
  presupuestoId: string
  presupuestoCodigo: string
  presupuestoNombre: string
  observaciones: string
  proveedor: {
    id: string
    nombre: string
    rif: string
    telefono: string
    email: string
    direccion: string
  }
  items: RequisicionItem[]
}

export type WizardErrors = Record<string, string>

interface FormContextType {
  data: RequisicionWizardData
  updateData: (fields: Partial<RequisicionWizardData>) => void
  errors: WizardErrors
  clearError: (field: string) => void
}

const FormContext = createContext<FormContextType | undefined>(undefined)

export function useFormWizard() {
  const context = useContext(FormContext)
  if (!context) {
    throw new Error('useFormWizard debe ser usado dentro de FormProvider')
  }
  return context
}

interface RequisicionWizardProps {
  initialData?: Partial<RequisicionWizardData>
  onSubmit: (data: RequisicionWizardData, action: 'borrador' | 'enviar') => Promise<void>
  isSubmitting?: boolean
  onCancel?: () => void
}

const STEPS = [
  { id: 1, title: 'Información General', icon: FileText, description: 'Básicos y fechas' },
  { id: 2, title: 'Proveedor', icon: Building2, description: 'Datos fiscales' },
  { id: 3, title: 'Ítems & Servicios', icon: Package, description: 'Productos y montos' },
  { id: 4, title: 'Resumen & Envío', icon: CheckCircle2, description: 'Confirmación final' },
]

export function RequisicionWizard({
  initialData,
  onSubmit,
  isSubmitting = false,
  onCancel,
}: RequisicionWizardProps) {
  const [currentStep, setCurrentStep] = useState(1)
  const [errors, setErrors] = useState<WizardErrors>({})

  const [formData, setFormData] = useState<RequisicionWizardData>({
    fechaSolicitud: initialData?.fechaSolicitud || new Date().toISOString().slice(0, 10),
    fechaRequerida: initialData?.fechaRequerida || '',
    prioridad: initialData?.prioridad || 'media',
    moneda: initialData?.moneda || 'VES',
    tipoRequisicion: initialData?.tipoRequisicion || 'compra',
    presupuestoId: initialData?.presupuestoId || '',
    presupuestoCodigo: initialData?.presupuestoCodigo || '',
    presupuestoNombre: initialData?.presupuestoNombre || '',
    observaciones: initialData?.observaciones || '',
    proveedor: initialData?.proveedor || {
      id: '',
      nombre: '',
      rif: '',
      telefono: '',
      email: '',
      direccion: '',
    },
    items: initialData?.items || [
      { descripcion: '', unidad: 'UNID', cantidad: 1, precio: 0, impuesto: 16 },
    ],
  })

  const updateFormData = (fields: Partial<RequisicionWizardData>) => {
    setFormData((prev) => ({ ...prev, ...fields }))
  }

  const [hasInitialized, setHasInitialized] = useState(false)

  // Sincronizar datos cargados asincrónicamente en modo edición (persistencia estricta)
  useEffect(() => {
    if (initialData && !hasInitialized) {
      setFormData({
        fechaSolicitud: initialData.fechaSolicitud || new Date().toISOString().slice(0, 10),
        fechaRequerida: initialData.fechaRequerida || '',
        prioridad: initialData.prioridad || 'media',
        moneda: initialData.moneda || 'VES',
        tipoRequisicion: initialData.tipoRequisicion || 'compra',
        presupuestoId: initialData.presupuestoId || '',
        presupuestoCodigo: initialData.presupuestoCodigo || '',
        presupuestoNombre: initialData.presupuestoNombre || '',
        observaciones: initialData.observaciones || '',
        proveedor: initialData.proveedor || { id: '', nombre: '', rif: '', telefono: '', email: '', direccion: '' },
        items: initialData.items && initialData.items.length > 0 ? initialData.items : [{ descripcion: '', unidad: 'UNID', cantidad: 1, precio: 0, impuesto: 16 }],
      })
      setHasInitialized(true)
    }
  }, [initialData, hasInitialized])

  const clearError = (field: string) => {
    setErrors((prev) => {
      const next = { ...prev }
      delete next[field]
      return next
    })
  }

  /* VALIDACIÓN ESTRICTA POR PASO (Bloqueo si hay campos requeridos vacíos) */
  const validateStep = (step: number): boolean => {
    const newErrors: WizardErrors = {}

    if (step === 1) {
      if (!formData.presupuestoId) {
        newErrors.presupuestoId = 'Debe seleccionar un presupuesto'
      }
      if (!formData.fechaSolicitud) {
        newErrors.fechaSolicitud = 'La fecha de solicitud es requerida.'
      }
      if (!formData.fechaRequerida) {
        newErrors.fechaRequerida = 'La fecha requerida de entrega es obligatoria.'
      } else if (formData.fechaRequerida < formData.fechaSolicitud) {
        newErrors.fechaRequerida = 'La fecha de entrega no puede ser anterior a la solicitud.'
      }
      const obsTrim = formData.observaciones.trim()
      const invalidRegex = /^(sadasd|asdasd|qwerty|123456|test|prueba)+$/i
      if (!obsTrim) {
        newErrors.observaciones = 'La justificación o motivo del requerimiento es obligatoria.'
      } else if (obsTrim.length < 15) {
        newErrors.observaciones = 'La justificación debe tener al menos 15 caracteres explicativos de la necesidad.'
      } else if (invalidRegex.test(obsTrim)) {
        newErrors.observaciones = 'Ingrese una justificación operacional válida (evite textos de prueba o teclado aleatorio).'
      }
    }

    if (step === 2) {
      if (!formData.proveedor.nombre.trim()) {
        newErrors['proveedor.nombre'] = 'La Razón Social o Nombre del proveedor es obligatorio.'
      } else if (formData.proveedor.nombre.trim().length < 3) {
        newErrors['proveedor.nombre'] = 'El nombre del proveedor debe tener al menos 3 caracteres.'
      }

      const rifVal = formData.proveedor.rif.trim().toUpperCase()
      const rifRegex = /^[JVEGP]-?[0-9]{7,9}-?[0-9]$|^[JVEGP][0-9]{8,10}$/i
      if (!rifVal) {
        newErrors['proveedor.rif'] = 'El RIF o Identificación fiscal es requerido.'
      } else if (!rifRegex.test(rifVal)) {
        newErrors['proveedor.rif'] = 'Formato de RIF inválido (ej: J-12345678-0 o V-12345678-0).'
      }

      const telVal = formData.proveedor.telefono.trim()
      if (telVal && telVal.replace(/\D/g, '').length < 5) {
        newErrors['proveedor.telefono'] = 'Ingrese al menos 5 dígitos numéricos en el teléfono de contacto.'
      }

      const emailVal = formData.proveedor.email.trim()
      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
      if (emailVal && !emailRegex.test(emailVal)) {
        newErrors['proveedor.email'] = 'Formato de correo electrónico inválido (ej: contacto@empresa.com).'
      }
    }

    if (step === 3) {
      if (formData.items.length === 0) {
        newErrors['items'] = 'Debe agregar al menos un ítem o servicio.'
      }
      formData.items.forEach((item, index) => {
        if (!item.descripcion.trim()) {
          newErrors[`item_${index}_descripcion`] = 'Descripción requerida.'
        }
        if (item.cantidad <= 0) {
          newErrors[`item_${index}_cantidad`] = 'La cantidad debe ser mayor a 0.'
        }
      })
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleNext = () => {
    if (validateStep(currentStep)) {
      setCurrentStep((prev) => Math.min(4, prev + 1))
    }
  }

  const handlePrev = () => {
    setCurrentStep((prev) => Math.max(1, prev - 1))
  }

  return (
    <FormContext.Provider value={{ data: formData, updateData: updateFormData, errors, clearError }}>
      {/* 1. CONTENEDOR PADRE SIN OVERFLOW GENERAL (Cero doble barra de scroll) */}
      <div className="flex flex-col h-full w-full overflow-hidden bg-slate-50/50 dark:bg-slate-950/40 relative">
        
        {/* STEPPER SUPERIOR HORIZONTAL */}
        <div className="px-6 py-4 bg-background border-b border-border/60 shrink-0">
          <div className="grid grid-cols-4 gap-2 sm:gap-4 max-w-5xl mx-auto">
            {STEPS.map((step) => {
              const isActive = currentStep === step.id
              const isCompleted = currentStep > step.id
              return (
                <div
                  key={step.id}
                  onClick={() => isCompleted && setCurrentStep(step.id)}
                  className={`flex items-center gap-3 p-2.5 sm:p-3 rounded-xl border transition-all ${
                    isCompleted ? 'cursor-pointer' : 'cursor-default'
                  } ${
                    isActive
                      ? 'border-primary bg-primary/5 text-primary shadow-xs'
                      : isCompleted
                      ? 'border-emerald-500/40 bg-emerald-500/5 text-emerald-600 dark:text-emerald-400'
                      : 'border-border/60 bg-background text-muted-foreground opacity-70'
                  }`}
                >
                  <div
                    className={`flex h-7 w-7 sm:h-8 sm:w-8 shrink-0 items-center justify-center rounded-lg font-bold text-xs ${
                      isActive
                        ? 'bg-primary text-primary-foreground'
                        : isCompleted
                        ? 'bg-emerald-500 text-white'
                        : 'bg-muted text-muted-foreground'
                    }`}
                  >
                    {isCompleted ? <CheckCircle2 className="size-4" /> : step.id}
                  </div>
                  <div className="hidden sm:flex flex-col truncate">
                    <span className="text-xs font-bold truncate">{step.title}</span>
                    <span className="text-[10px] text-muted-foreground truncate">{step.description}</span>
                  </div>
                </div>
              )
            })}
          </div>
        </div>

        {/* 2. ÁREA DE CONTENIDO CON SCROLL UNICO Y PADDING INFERIOR OPTIMIZADO (pb-16) */}
        <div className="flex-1 overflow-y-auto px-6 py-4 pb-16">
          <div className="max-w-5xl mx-auto space-y-6">
            {currentStep === 1 && <StepGeneralInfo />}
            {currentStep === 2 && <StepProveedor />}
            {currentStep === 3 && <StepItems />}
            {currentStep === 4 && <StepResumen />}
          </div>
        </div>

        {/* 3. STICKY FOOTER REORGANIZADO (Cancelar a la Izquierda, Navegación a la Derecha, Delimitado en md:left-64) */}
        <div className="fixed bottom-0 left-0 md:left-64 right-0 z-30 bg-background/95 backdrop-blur border-t border-border/60 px-6 sm:px-8 py-3.5 flex items-center justify-between shadow-lg transition-all duration-300">
          {/* Izquierda: Cancelar */}
          {onCancel ? (
            <Button
              variant="ghost"
              size="sm"
              onClick={onCancel}
              className="text-xs font-medium text-muted-foreground hover:text-foreground"
            >
              Cancelar
            </Button>
          ) : <div />}

          {/* Derecha: Navegación y Guardado */}
          <div className="flex items-center gap-3">
            <Button
              variant="outline"
              size="sm"
              onClick={handlePrev}
              disabled={currentStep === 1 || isSubmitting}
              className="gap-2 text-xs font-semibold"
            >
              <ArrowLeft className="size-4" />
              Anterior
            </Button>

            {currentStep < 4 ? (
              <Button size="sm" onClick={handleNext} className="gap-2 text-xs font-semibold px-6">
                Siguiente
                <ArrowRight className="size-4" />
              </Button>
            ) : (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={isSubmitting}
                  onClick={() => onSubmit(formData, 'borrador')}
                  className="gap-2 text-xs font-semibold border-border/80"
                >
                  <Save className="size-4 text-muted-foreground" />
                  Guardar Borrador
                </Button>
                <Button
                  size="sm"
                  disabled={isSubmitting}
                  onClick={() => onSubmit(formData, 'enviar')}
                  className="gap-2 text-xs font-semibold px-6 bg-emerald-600 hover:bg-emerald-700 text-white"
                >
                  <Send className="size-4" />
                  {isSubmitting ? 'Enviando...' : 'Enviar a Aprobación'}
                </Button>
              </>
            )}
          </div>
        </div>
      </div>
    </FormContext.Provider>
  )
}

/* ---------------- PASO 1: INFORMACIÓN GENERAL & PRESUPUESTARIA ---------------- */
function StepGeneralInfo() {
  const { data, updateData, errors, clearError } = useFormWizard()
  const [tipoFiltroAccion, setTipoFiltroAccion] = useState<'todos' | 'centralizada' | 'proyecto'>('todos')
  const [termPresupuesto, setTermPresupuesto] = useState('')
  const [isSearchingPresupuesto, setIsSearchingPresupuesto] = useState(false)
  const [presupuestosList, setPresupuestosList] = useState<PresupuestoResumen[]>([])
  const [showDropdownPresupuesto, setShowDropdownPresupuesto] = useState(false)

  const handleSearchPresupuestos = async (q: string, filtro = tipoFiltroAccion) => {
    setTermPresupuesto(q)
    setIsSearchingPresupuesto(true)
    try {
      const res = await buscarPresupuestos(q, filtro)
      setPresupuestosList(res)
      setShowDropdownPresupuesto(true)
    } catch {
      setPresupuestosList([])
    } finally {
      setIsSearchingPresupuesto(false)
    }
  }

  const handleFilterChange = (filtro: 'todos' | 'centralizada' | 'proyecto') => {
    setTipoFiltroAccion(filtro)
    handleSearchPresupuestos(termPresupuesto, filtro)
  }

  const handleSelectPresupuesto = (p: PresupuestoResumen) => {
    clearError('presupuestoId')
    updateData({
      presupuestoId: String(p.id),
      presupuestoCodigo: p.cuenta_codigo || '',
      presupuestoNombre: p.cuenta_nombre || p.descripcion || '',
    })
    setShowDropdownPresupuesto(false)
    setTermPresupuesto('')
  }

  return (
    <div className="space-y-6">
      {/* TARJETA 1: INFORMACIÓN PRESUPUESTARIA (MOCKUP EXACTO) */}
      <Card className="border-border/60 shadow-xs">
        <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
          <CardTitle className="text-sm font-bold flex items-center gap-2 text-foreground">
            <Calculator className="size-4 text-primary" /> Información Presupuestaria
          </CardTitle>
        </CardHeader>
        <CardContent className="p-6 space-y-5">
          <div className="grid grid-cols-1 md:grid-cols-12 gap-6 items-start">
            {/* FILTRAR PRESUPUESTOS POR TIPO DE ACCIÓN (col-span-8) */}
            <div className="md:col-span-8 space-y-2">
              <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
                Filtrar Presupuestos por Tipo de Acción
              </label>
              <div className="inline-flex rounded-lg border border-border/80 bg-background p-1 shadow-xs text-xs font-semibold">
                <button
                  type="button"
                  onClick={() => handleFilterChange('todos')}
                  className={`px-4 py-2 rounded-md transition-all flex items-center gap-2 ${
                    tipoFiltroAccion === 'todos'
                      ? 'bg-foreground text-background shadow-xs font-bold'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
                  }`}
                >
                  <ListFilter className="size-3.5" /> Todos
                </button>
                <button
                  type="button"
                  onClick={() => handleFilterChange('centralizada')}
                  className={`px-4 py-2 rounded-md transition-all flex items-center gap-2 ${
                    tipoFiltroAccion === 'centralizada'
                      ? 'bg-foreground text-background shadow-xs font-bold'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
                  }`}
                >
                  <Building2 className="size-3.5" /> Acción Centralizada
                </button>
                <button
                  type="button"
                  onClick={() => handleFilterChange('proyecto')}
                  className={`px-4 py-2 rounded-md transition-all flex items-center gap-2 ${
                    tipoFiltroAccion === 'proyecto'
                      ? 'bg-foreground text-background shadow-xs font-bold'
                      : 'text-muted-foreground hover:text-foreground hover:bg-muted/50'
                  }`}
                >
                  <FolderGit2 className="size-3.5" /> Proyecto
                </button>
              </div>
            </div>

            {/* TIPO DE REQUISICIÓN (col-span-4) */}
            <div className="md:col-span-4 space-y-2">
              <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
                Tipo de Requisición *
              </label>
              <select
                value={data.tipoRequisicion}
                onChange={(e) => updateData({ tipoRequisicion: e.target.value })}
                className="w-full rounded-lg border border-border/80 bg-background px-3 py-2 text-xs font-medium focus:ring-2 focus:ring-primary shadow-xs"
              >
                <option value="compra">Compra</option>
                <option value="servicio">Servicio</option>
                <option value="inventario">Reposición de Inventario</option>
              </select>
            </div>
          </div>

          {/* CAMPO DE SELECCIÓN DE PRESUPUESTO */}
          <div className="space-y-1.5 relative">
            <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block flex items-center justify-between">
              <span>Presupuesto *</span>
              {data.presupuestoId && (
                <button
                  type="button"
                  onClick={() => updateData({ presupuestoId: '', presupuestoCodigo: '', presupuestoNombre: '' })}
                  className="text-[11px] text-destructive hover:underline capitalize font-normal"
                >
                  Quitar selección
                </button>
              )}
            </label>

            {data.presupuestoId ? (
              <div className="p-3.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-300 dark:border-emerald-800 flex items-center justify-between text-xs">
                <div className="flex items-center gap-3">
                  <Badge
                    variant="outline"
                    className="font-mono text-xs bg-emerald-100 dark:bg-emerald-900 text-emerald-700 dark:text-emerald-300 border-emerald-300"
                  >
                    {data.presupuestoCodigo || 'SIN CÓDIGO'}
                  </Badge>
                  <span className="font-bold text-foreground text-sm">{data.presupuestoNombre}</span>
                </div>
              </div>
            ) : (
              <div className="relative">
                {/* Backdrop transparente para cerrar al hacer clic afuera */}
                {showDropdownPresupuesto && (
                  <div
                    className="fixed inset-0 z-10 bg-transparent"
                    onClick={() => setShowDropdownPresupuesto(false)}
                  />
                )}

                <div className="relative z-20">
                  <Input
                    placeholder="Buscar presupuesto..."
                    value={termPresupuesto}
                    onChange={(e) => handleSearchPresupuestos(e.target.value)}
                    onFocus={() => handleSearchPresupuestos(termPresupuesto)}
                    className={`text-xs pr-8 ${
                      errors.presupuestoId
                        ? 'border-destructive focus-visible:ring-destructive'
                        : 'border-border'
                    }`}
                  />
                  <div className="absolute right-2.5 top-2.5 flex items-center z-30">
                    {isSearchingPresupuesto ? (
                      <RefreshCw className="size-4 animate-spin text-muted-foreground" />
                    ) : showDropdownPresupuesto || termPresupuesto !== '' ? (
                      <button
                        type="button"
                        onClick={(e) => {
                          e.stopPropagation()
                          setShowDropdownPresupuesto(false)
                          setTermPresupuesto('')
                        }}
                        className="p-0.5 hover:bg-muted rounded text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                        title="Cerrar y limpiar búsqueda"
                      >
                        <X className="size-4 text-muted-foreground hover:text-destructive" />
                      </button>
                    ) : null}
                  </div>
                </div>

                {showDropdownPresupuesto && (
                  <div className="absolute z-30 top-full left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-xl text-xs">
                    {presupuestosList.length === 0 ? (
                      <p className="p-3 text-muted-foreground text-center text-[11px]">
                        No se encontraron presupuestos en la categoría {tipoFiltroAccion}.
                      </p>
                    ) : (
                      presupuestosList.map((p) => (
                        <div
                          key={p.id}
                          onClick={() => handleSelectPresupuesto(p)}
                          className="p-2.5 hover:bg-accent rounded-md cursor-pointer flex flex-col sm:flex-row sm:items-center sm:justify-between border-b border-border/30 last:border-0 gap-1"
                        >
                          <div className="space-y-0.5">
                            <div className="flex items-center gap-1.5 flex-wrap">
                              <span className="font-mono font-bold text-primary">
                                [{p.cuenta_codigo || 'N/A'}]
                              </span>
                              <span className="font-semibold text-foreground">
                                {p.cuenta_nombre || p.descripcion}
                              </span>
                              <Badge
                                variant="outline"
                                className={`text-[9px] px-1.5 py-0 uppercase font-bold ${
                                  p.tipo_accion === 'proyecto'
                                    ? 'bg-purple-50 text-purple-700 border-purple-300 dark:bg-purple-950/40 dark:text-purple-300'
                                    : 'bg-blue-50 text-blue-700 border-blue-300 dark:bg-blue-950/40 dark:text-blue-300'
                                }`}
                              >
                                {p.tipo_accion === 'proyecto' ? 'Proyecto' : 'Centralizada'}
                              </Badge>
                            </div>
                            {p.proyecto_info && (
                              <p className="text-[10px] text-muted-foreground font-medium truncate max-w-md">
                                {p.proyecto_info}
                              </p>
                            )}
                          </div>
                          {p.saldo_disponible !== null && (
                            <Badge
                              variant="outline"
                              className="text-[10px] font-mono text-emerald-600 bg-emerald-50 dark:bg-emerald-950/40 border-emerald-200 shrink-0 self-start sm:self-auto"
                            >
                              Bs. {p.saldo_disponible.toLocaleString('es-VE')}
                            </Badge>
                          )}
                        </div>
                      ))
                    )}
                  </div>
                )}
              </div>
            )}

            {errors.presupuestoId ? (
              <p className="text-[11px] font-semibold text-destructive flex items-center gap-1 mt-1">
                <AlertCircle className="size-3 shrink-0" />
                {errors.presupuestoId}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground mt-1">
                Busque y seleccione el presupuesto del cual se descontarán los fondos
              </p>
            )}
          </div>
        </CardContent>
      </Card>

      {/* TARJETA 2: DATOS GENERALES Y FECHAS */}
      <Card className="border-border/60 shadow-xs">
        <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
          <CardTitle className="text-sm font-bold flex items-center gap-2 text-foreground">
            <FileText className="size-4 text-primary" /> Datos Generales de la Solicitud
          </CardTitle>
        </CardHeader>
        <CardContent className="p-6 space-y-5">
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Prioridad
              </label>
              <select
                value={data.prioridad}
                onChange={(e) => updateData({ prioridad: e.target.value })}
                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium focus:ring-2 focus:ring-primary shadow-xs"
              >
                <option value="baja">Baja</option>
                <option value="media">Media</option>
                <option value="alta">Alta</option>
                <option value="urgente">Urgente</option>
              </select>
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Moneda
              </label>
              <select
                value={data.moneda}
                onChange={(e) => updateData({ moneda: e.target.value })}
                className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium focus:ring-2 focus:ring-primary shadow-xs"
              >
                <option value="VES">Bolívares (VES)</option>
                <option value="USD">Dólares (USD)</option>
                <option value="EUR">Euros (EUR)</option>
              </select>
            </div>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div className="space-y-1.5 max-w-xs">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Fecha de Solicitud *
              </label>
              <Input
                type="date"
                value={data.fechaSolicitud}
                onChange={(e) => {
                  clearError('fechaSolicitud')
                  updateData({ fechaSolicitud: e.target.value })
                }}
                className={`text-xs ${
                  errors.fechaSolicitud ? 'border-destructive focus-visible:ring-destructive' : ''
                }`}
              />
              {errors.fechaSolicitud && (
                <p className="text-[11px] font-semibold text-destructive flex items-center gap-1">
                  <AlertCircle className="size-3 shrink-0" />
                  {errors.fechaSolicitud}
                </p>
              )}
            </div>

            <div className="space-y-1.5 max-w-xs">
              <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
                Fecha Requerida de Entrega *
              </label>
              <Input
                type="date"
                value={data.fechaRequerida}
                onChange={(e) => {
                  clearError('fechaRequerida')
                  updateData({ fechaRequerida: e.target.value })
                }}
                className={`text-xs ${
                  errors.fechaRequerida ? 'border-destructive focus-visible:ring-destructive' : ''
                }`}
              />
              {errors.fechaRequerida && (
                <p className="text-[11px] font-semibold text-destructive flex items-center gap-1">
                  <AlertCircle className="size-3 shrink-0" />
                  {errors.fechaRequerida}
                </p>
              )}
            </div>
          </div>

          <div className="space-y-1.5">
            <label className="text-xs font-semibold text-slate-700 dark:text-slate-300">
              Justificación / Observaciones *
            </label>
            <textarea
              rows={4}
              value={data.observaciones}
              onChange={(e) => {
                clearError('observaciones')
                updateData({ observaciones: e.target.value })
              }}
              placeholder="Describa detalladamente el motivo o destino de esta requisición..."
              className={`w-full rounded-lg border bg-background px-3 py-2 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-primary ${
                errors.observaciones ? 'border-destructive focus:ring-destructive' : 'border-border'
              }`}
            />
            {errors.observaciones && (
              <p className="text-[11px] font-semibold text-destructive flex items-center gap-1">
                <AlertCircle className="size-3 shrink-0" />
                {errors.observaciones}
              </p>
            )}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

/* ---------------- PASO 2: PROVEEDOR ---------------- */
function StepProveedor() {
  const { data, updateData, errors, clearError } = useFormWizard()
  const prov = data.proveedor
  const [proveedoresList, setProveedoresList] = useState<ProveedorResumen[]>([])
  const [isLoadingProveedores, setIsLoadingProveedores] = useState(false)
  const [termProveedor, setTermProveedor] = useState('')
  const [showDropdownProveedor, setShowDropdownProveedor] = useState(false)
  const [isSearchingProveedor, setIsSearchingProveedor] = useState(false)

  useEffect(() => {
    let isMounted = true
    setIsLoadingProveedores(true)
    buscarProveedores('')
      .then((res) => {
        if (isMounted) {
          setProveedoresList(res)
        }
      })
      .catch(() => {
        if (isMounted) setProveedoresList([])
      })
      .finally(() => {
        if (isMounted) setIsLoadingProveedores(false)
      })
    return () => {
      isMounted = false
    }
  }, [])

  const handleSearchProveedoresLive = async (queryText: string) => {
    setTermProveedor(queryText)
    setIsSearchingProveedor(true)
    try {
      const res = await buscarProveedores(queryText)
      setProveedoresList(res)
      setShowDropdownProveedor(true)
    } catch {
      setProveedoresList([])
    } finally {
      setIsSearchingProveedor(false)
    }
  }

  const handleSelectProveedorObj = (p: ProveedorResumen | null) => {
    setShowDropdownProveedor(false)
    setTermProveedor('')
    if (!p) {
      updateData({
        proveedor: {
          id: '',
          nombre: '',
          rif: '',
          telefono: '',
          email: '',
          direccion: '',
        },
      })
      return
    }

    clearError('proveedor.nombre')
    clearError('proveedor.rif')
    updateData({
      proveedor: {
        id: String(p.id),
        nombre: p.nombre,
        rif: p.rif || '',
        telefono: p.telefono || '',
        email: p.email || '',
        direccion: p.direccion || '',
      },
    })
  }

  const handleProvChange = (key: keyof typeof prov, value: string) => {
    clearError(`proveedor.${key}`)
    updateData({
      proveedor: { ...prov, [key]: value },
    })
  }

  return (
    <Card className="border-border/60 shadow-xs overflow-hidden">
      <CardHeader className="pb-3 border-b border-border/40 bg-muted/20">
        <CardTitle className="text-sm font-bold flex items-center gap-2 text-foreground">
          <Truck className="size-4 text-primary" /> Información del Proveedor
        </CardTitle>
      </CardHeader>
      <CardContent className="p-6 space-y-5">
        {/* SELECT2 COMBOBOX DE PROVEEDORES */}
        <div className="space-y-1.5 relative">
          <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block flex items-center justify-between">
            <span>Seleccionar Proveedor (Select2 / Búsqueda en vivo)</span>
            {isLoadingProveedores && (
              <span className="text-[10px] text-muted-foreground animate-pulse">Consultando BD...</span>
            )}
          </label>

          <div className="relative">
            {/* Backdrop para cerrar al hacer clic afuera */}
            {showDropdownProveedor && (
              <div
                className="fixed inset-0 z-10 bg-transparent"
                onClick={() => setShowDropdownProveedor(false)}
              />
            )}

            <div className="relative z-20">
              <Input
                placeholder="Buscar por RIF o Razón Social (ej. Villamizar)..."
                value={termProveedor}
                onChange={(e) => handleSearchProveedoresLive(e.target.value)}
                onFocus={() => handleSearchProveedoresLive(termProveedor)}
                className="text-xs pr-8 border-border shadow-xs"
              />
              <div className="absolute right-2.5 top-2.5 flex items-center z-30">
                {isSearchingProveedor ? (
                  <RefreshCw className="size-4 animate-spin text-muted-foreground" />
                ) : showDropdownProveedor || termProveedor !== '' ? (
                  <button
                    type="button"
                    onClick={(e) => {
                      e.stopPropagation()
                      setShowDropdownProveedor(false)
                      setTermProveedor('')
                    }}
                    className="p-0.5 hover:bg-muted rounded text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                    title="Cerrar búsqueda"
                  >
                    <X className="size-4 text-muted-foreground hover:text-destructive" />
                  </button>
                ) : null}
              </div>
            </div>

            {showDropdownProveedor && (
              <div className="absolute z-30 top-full left-0 right-0 mt-1 max-h-60 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-xl text-xs">
                {/* OPCIÓN 1: INGRESO MANUAL / NUEVO */}
                <div
                  onClick={() => handleSelectProveedorObj(null)}
                  className="p-2.5 hover:bg-accent rounded-md cursor-pointer flex items-center justify-between border-b border-border/40 text-primary font-bold bg-primary/5 mb-1"
                >
                  <span className="flex items-center gap-2 text-xs">
                    <Plus className="size-3.5" /> + Ingreso Manual / Proveedor Nuevo
                  </span>
                  <Badge variant="outline" className="text-[9px] bg-primary/10 text-primary border-primary/30">
                    NUEVO
                  </Badge>
                </div>

                {proveedoresList.length === 0 ? (
                  <p className="p-3 text-muted-foreground text-center text-[11px]">
                    No se encontraron proveedores coincidentes.
                  </p>
                ) : (
                  proveedoresList.map((p) => (
                    <div
                      key={p.id}
                      onClick={() => handleSelectProveedorObj(p)}
                      className="p-2.5 hover:bg-accent rounded-md cursor-pointer flex items-center justify-between border-b border-border/30 last:border-0"
                    >
                      <div className="space-y-0.5">
                        <div className="flex items-center gap-2">
                          <span className="font-semibold text-foreground text-xs">{p.nombre}</span>
                          <Badge variant="outline" className="font-mono text-[10px] bg-muted/60 text-muted-foreground">
                            {p.rif || 'SIN RIF'}
                          </Badge>
                        </div>
                        {(p.telefono || p.email) && (
                          <p className="text-[10px] text-muted-foreground font-mono">
                            {[p.telefono, p.email].filter(Boolean).join(' | ')}
                          </p>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
          </div>
          <p className="text-[11px] text-muted-foreground mt-1">
            Escriba para filtrar y seleccionar un proveedor registrado o elija "Ingreso Manual"
          </p>
        </div>

        {/* FILA 2: NOMBRE DEL PROVEEDOR & RIF */}
        <div className="grid grid-cols-1 md:grid-cols-12 gap-5 items-start">
          <div className="md:col-span-8 space-y-1.5">
            <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
              Nombre del Proveedor *
            </label>
            <Input
              placeholder="Nombre de la empresa o proveedor"
              value={prov.nombre}
              onChange={(e) => handleProvChange('nombre', e.target.value)}
              className={`text-xs ${
                errors['proveedor.nombre'] ? 'border-destructive focus-visible:ring-destructive' : 'border-border'
              }`}
            />
            {errors['proveedor.nombre'] ? (
              <p className="text-[11px] font-semibold text-destructive flex items-center gap-1 mt-1">
                <AlertCircle className="size-3 shrink-0" />
                {errors['proveedor.nombre']}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground mt-1">
                Se carga automáticamente al seleccionar proveedor
              </p>
            )}
          </div>

          <div className="md:col-span-4 space-y-1.5">
            <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
              RIF *
            </label>
            <Input
              placeholder="J-12345678-9"
              value={prov.rif}
              onChange={(e) => handleProvChange('rif', e.target.value)}
              className={`text-xs uppercase font-mono ${
                errors['proveedor.rif'] ? 'border-destructive focus-visible:ring-destructive' : 'border-border'
              }`}
            />
            {errors['proveedor.rif'] ? (
              <p className="text-[11px] font-semibold text-destructive flex items-center gap-1 mt-1">
                <AlertCircle className="size-3 shrink-0" />
                {errors['proveedor.rif']}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground mt-1">
                Se carga automáticamente
              </p>
            )}
          </div>
        </div>

        {/* FILA 3: TELÉFONO & EMAIL */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-5 items-start">
          <div className="space-y-1.5">
            <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
              Teléfono
            </label>
            <Input
              placeholder="0414-1234567"
              value={prov.telefono}
              onChange={(e) => handleProvChange('telefono', e.target.value)}
              className={`text-xs font-mono ${
                errors['proveedor.telefono'] ? 'border-destructive focus-visible:ring-destructive' : 'border-border'
              }`}
            />
            {errors['proveedor.telefono'] ? (
              <p className="text-[11px] font-semibold text-destructive flex items-center gap-1 mt-1">
                <AlertCircle className="size-3 shrink-0" />
                {errors['proveedor.telefono']}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground mt-1">
                Se carga automáticamente
              </p>
            )}
          </div>

          <div className="space-y-1.5">
            <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
              Email
            </label>
            <Input
              type="email"
              placeholder="proveedor@email.com"
              value={prov.email}
              onChange={(e) => handleProvChange('email', e.target.value)}
              className={`text-xs ${
                errors['proveedor.email'] ? 'border-destructive focus-visible:ring-destructive' : 'border-border'
              }`}
            />
            {errors['proveedor.email'] ? (
              <p className="text-[11px] font-semibold text-destructive flex items-center gap-1 mt-1">
                <AlertCircle className="size-3 shrink-0" />
                {errors['proveedor.email']}
              </p>
            ) : (
              <p className="text-[11px] text-muted-foreground mt-1">
                Se carga automáticamente
              </p>
            )}
          </div>
        </div>

        {/* FILA 4: DIRECCIÓN FISCAL */}
        <div className="space-y-1.5">
          <label className="text-[10px] uppercase tracking-wider text-muted-foreground font-bold block">
            Dirección
          </label>
          <textarea
            rows={3}
            placeholder="Dirección completa del proveedor..."
            value={prov.direccion}
            onChange={(e) => handleProvChange('direccion', e.target.value)}
            className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-medium focus:outline-none focus:ring-2 focus:ring-primary shadow-xs"
          />
          <p className="text-[11px] text-muted-foreground mt-1">
            Se carga automáticamente al seleccionar proveedor
          </p>
        </div>
      </CardContent>
    </Card>
  )
}

/* ---------------- COMPONENTE AUTOCOMPLETE PREDICTIVO HÍBRIDO DE PRODUCTOS ---------------- */
function ProductoPredictiveInput({
  value,
  productoId,
  productoCodigo,
  stockDisponible,
  error,
  onChange,
}: {
  value: string
  productoId?: number | null
  productoCodigo?: string | null
  stockDisponible?: number | null
  error?: string
  onChange: (fields: {
    descripcion: string
    producto_id?: number | null
    producto_codigo?: string | null
    stock_disponible?: number | null
    unidad?: string
    precio?: number
  }) => void
}) {
  const [searchTerm, setSearchTerm] = useState(value)
  const [isSearching, setIsSearching] = useState(false)
  const [results, setResults] = useState<Producto[]>([])
  const [showDropdown, setShowDropdown] = useState(false)

  // Sincronización externa
  useEffect(() => {
    setSearchTerm(value)
  }, [value])

  // Debounce predictivo de 300ms para consulta a la API de catálogo
  useEffect(() => {
    if (!showDropdown) return
    const timer = setTimeout(async () => {
      setIsSearching(true)
      try {
        const res = await fetchProductosList({ q: searchTerm, limit: 10 })
        setResults(res.items || [])
      } catch {
        setResults([])
      } finally {
        setIsSearching(false)
      }
    }, 300)

    return () => clearTimeout(timer)
  }, [searchTerm, showDropdown])

  const handleSelectProducto = (p: Producto) => {
    const precioSugerido = p.precio > 0 ? p.precio : p.costo
    onChange({
      descripcion: p.nombre,
      producto_id: p.id,
      producto_codigo: p.codigo,
      stock_disponible: p.existencias,
      unidad: p.unidad_medida || 'UNID',
      precio: precioSugerido,
    })
    setShowDropdown(false)
  }

  const handleUnlinkCatalog = () => {
    onChange({
      descripcion: searchTerm,
      producto_id: null,
      producto_codigo: null,
      stock_disponible: null,
    })
  }

  return (
    <div className="space-y-1 relative">
      <div className="relative">
        {/* Backdrop para cerrar desplegable */}
        {showDropdown && (
          <div
            className="fixed inset-0 z-10 bg-transparent"
            onClick={() => setShowDropdown(false)}
          />
        )}

        <div className="relative z-20">
          <Input
            value={searchTerm}
            onChange={(e) => {
              const val = e.target.value
              setSearchTerm(val)
              setShowDropdown(true)
              onChange({
                descripcion: val,
                ...(productoId && val !== value ? { producto_id: null, producto_codigo: null, stock_disponible: null } : {}),
              })
            }}
            onFocus={() => setShowDropdown(true)}
            placeholder="ej. Papel Resma Carta 75g (busque en catálogo o escriba texto libre)"
            className={`h-9 text-xs rounded-md bg-background border-input ${
              error ? 'border-destructive focus-visible:ring-destructive' : ''
            }`}
          />
          <div className="absolute right-2 top-2.5 flex items-center gap-1 z-30 pointer-events-none">
            {isSearching ? (
              <RefreshCw className="size-3.5 animate-spin text-muted-foreground" />
            ) : productoId ? (
              <Badge
                variant="outline"
                className="text-[9px] px-1.5 py-0 bg-emerald-50 text-emerald-700 border-emerald-300 font-bold dark:bg-emerald-950/50 dark:text-emerald-300"
              >
                Catálogo
              </Badge>
            ) : null}
          </div>
        </div>

        {/* Menú Desplegable Predictivo */}
        {showDropdown && (
          <div className="absolute z-30 top-full left-0 right-0 mt-1 max-h-56 overflow-y-auto rounded-lg border border-border bg-popover p-1 shadow-xl text-xs">
            {results.length === 0 ? (
              <div className="p-3 text-muted-foreground text-center text-[11px] space-y-1">
                <p>{isSearching ? 'Buscando en catálogo...' : 'No se encontraron coincidencias en inventario.'}</p>
                <p className="text-[10px] text-muted-foreground/70">Puede mantener el texto escrito como ítem o servicio libre.</p>
              </div>
            ) : (
              results.map((p) => (
                <div
                  key={p.id}
                  onClick={() => handleSelectProducto(p)}
                  className="p-2.5 hover:bg-accent rounded-md cursor-pointer flex items-center justify-between border-b border-border/30 last:border-0 gap-2 transition-colors"
                >
                  <div className="space-y-0.5 min-w-0 flex-1">
                    <div className="flex items-center gap-2 flex-wrap">
                      <span className="font-mono font-bold text-primary text-[11px]">
                        [{p.codigo}]
                      </span>
                      <span className="font-semibold text-foreground text-xs truncate">
                        {p.nombre}
                      </span>
                      {p.categoria?.nombre && (
                        <Badge variant="outline" className="text-[9px] px-1.5 py-0">
                          {p.categoria.nombre}
                        </Badge>
                      )}
                    </div>
                  </div>

                  <div className="flex items-center gap-3 shrink-0 text-right">
                    <span className="text-[10px] font-medium text-muted-foreground">
                      Stock: <strong className="text-foreground font-mono">{p.existencias} {p.unidad_medida}</strong>
                    </span>
                    <span className="text-[11px] font-mono font-bold text-emerald-600 dark:text-emerald-400">
                      Bs. {(p.precio > 0 ? p.precio : p.costo).toLocaleString('es-VE', { minimumFractionDigits: 2 })}
                    </span>
                  </div>
                </div>
              ))
            )}
          </div>
        )}
      </div>

      {/* Indicador visual de vinculación con el catálogo */}
      {productoId && (
        <div className="flex items-center justify-between text-[10px] font-medium text-emerald-700 dark:text-emerald-300 bg-emerald-50 dark:bg-emerald-950/40 px-2 py-0.5 rounded border border-emerald-200 dark:border-emerald-800">
          <span className="flex items-center gap-1.5">
            <Package className="size-3 text-emerald-600 shrink-0" />
            <span>📦 En Catálogo (<strong>{productoCodigo || `#${productoId}`}</strong> — Stock: <strong>{stockDisponible ?? 0}</strong>)</span>
          </span>
          <button
            type="button"
            onClick={handleUnlinkCatalog}
            className="text-muted-foreground hover:text-destructive underline text-[9px] cursor-pointer"
            title="Desvincular del catálogo y mantener como texto libre"
          >
            Desvincular
          </button>
        </div>
      )}
    </div>
  )
}

/* ---------------- PASO 3: ÍTEMS & SERVICIOS ---------------- */
function StepItems() {
  const { data, updateData, errors, clearError } = useFormWizard()

  const addItem = () => {
    clearError('items')
    const defaultUnidad = data.tipoRequisicion === 'servicio' ? 'SERVIC' : 'UNID'
    updateData({
      items: [
        ...data.items,
        { descripcion: '', unidad: defaultUnidad, cantidad: 1, precio: 0, impuesto: 16 },
      ],
    })
  }

  const removeItem = (index: number) => {
    if (data.items.length <= 1) return
    const nextItems = data.items.filter((_, i) => i !== index)
    updateData({ items: nextItems })
  }

  const updateItem = (index: number, fields: Partial<RequisicionItem>) => {
    clearError(`item_${index}_descripcion`)
    clearError(`item_${index}_cantidad`)
    const nextItems = [...data.items]
    nextItems[index] = { ...nextItems[index], ...fields }
    updateData({ items: nextItems })
  }

  const totalRequisicion = data.items.reduce((acc, item) => {
    const subtotal = item.cantidad * item.precio
    const imp = subtotal * (item.impuesto / 100)
    return acc + subtotal + imp
  }, 0)

  return (
    <Card className="border-border/60 shadow-xs overflow-hidden">
      <CardHeader className="pb-3 border-b border-border/40 bg-muted/20 flex flex-row items-center justify-between space-y-0">
        <CardTitle className="text-sm font-bold flex items-center gap-2 text-foreground">
          <Package className="size-4 text-primary" /> Detalle de Productos / Servicios ({data.items.length})
        </CardTitle>
        <Button onClick={addItem} size="sm" className="gap-1.5 text-xs font-semibold shadow-xs">
          <Plus className="size-3.5" />
          Agregar Ítem
        </Button>
      </CardHeader>

      <CardContent className="p-4 sm:p-6 space-y-3">
        {errors['items'] && (
          <p className="text-xs font-semibold text-destructive flex items-center gap-1 mb-2">
            <AlertCircle className="size-3.5 shrink-0" /> {errors['items']}
          </p>
        )}

        {/* CABECERA TABULAR DE COLUMNAS (Para vista Desktop) */}
        <div className="hidden lg:grid grid-cols-12 gap-3 px-3 py-1.5 text-[10px] uppercase tracking-wider text-muted-foreground font-bold border-b border-border/40 bg-muted/10 rounded-md">
          <div className="col-span-4">Descripción / Producto *</div>
          <div className="col-span-1 text-center">Unidad</div>
          <div className="col-span-1 text-right">Cant *</div>
          <div className="col-span-2 text-right">Precio Est.</div>
          <div className="col-span-1 text-right">IVA %</div>
          <div className="col-span-2 text-right">Subtotal Línea</div>
          <div className="col-span-1 text-center">Acción</div>
        </div>

        {/* RENGLONES HORIZONTALES DE ÍTEMS (ESTRICTAMENTE EN UNA SOLA FILA) */}
        {data.items.map((item, index) => {
          const subtotal = item.cantidad * item.precio
          const montoImpuesto = subtotal * (item.impuesto / 100)
          const totalLinea = subtotal + montoImpuesto
          const descError = errors[`item_${index}_descripcion`]
          const cantError = errors[`item_${index}_cantidad`]

          return (
            <div
              key={index}
              className="p-3 lg:px-3 lg:py-2.5 rounded-xl lg:rounded-lg border border-border/60 bg-muted/20 hover:bg-muted/40 transition-colors space-y-2 lg:space-y-0"
            >
              {/* RENGLÓN EN UNA SOLA FILA (12 COLUMNAS EN DESKTOP) */}
              <div className="grid grid-cols-12 gap-2 lg:gap-3 items-center">
                {/* 1. DESCRIPCIÓN / PRODUCTO PREDICTIVO (col-span-4) */}
                <div className="col-span-12 lg:col-span-4 space-y-1 lg:space-y-0">
                  <span className="lg:hidden text-[10px] uppercase font-bold text-muted-foreground block">
                    Ítem #{index + 1} - Descripción / Producto *
                  </span>
                  <ProductoPredictiveInput
                    value={item.descripcion}
                    productoId={item.producto_id}
                    productoCodigo={item.producto_codigo}
                    stockDisponible={item.stock_disponible}
                    error={descError}
                    onChange={(fields) => {
                      updateItem(index, {
                        descripcion: fields.descripcion,
                        ...(fields.producto_id !== undefined ? { producto_id: fields.producto_id } : {}),
                        ...(fields.producto_codigo !== undefined ? { producto_codigo: fields.producto_codigo } : {}),
                        ...(fields.stock_disponible !== undefined ? { stock_disponible: fields.stock_disponible } : {}),
                        ...(fields.unidad !== undefined ? { unidad: fields.unidad } : {}),
                        ...(fields.precio !== undefined ? { precio: fields.precio } : {}),
                      })
                    }}
                  />
                  {descError && (
                    <p className="text-[10px] font-semibold text-destructive mt-0.5">{descError}</p>
                  )}
                </div>

                {/* 2. UNIDAD DE MEDIDA (col-span-1 - Selector Estándar) */}
                <div className="col-span-4 lg:col-span-1 space-y-1 lg:space-y-0">
                  <span className="lg:hidden text-[10px] uppercase font-bold text-muted-foreground block text-center">
                    Unidad
                  </span>
                  <select
                    value={item.unidad}
                    onChange={(e) => updateItem(index, { unidad: e.target.value })}
                    className="w-full h-9 rounded-md border border-input bg-background px-1.5 text-xs font-semibold focus:ring-2 focus:ring-primary focus:outline-none transition-colors cursor-pointer shadow-2xs"
                  >
                    <option value="UNID">UNID - Unidad/Pieza</option>
                    <option value="CAJA">CAJA - Caja</option>
                    <option value="KG">KG - Kilogramo</option>
                    <option value="LT">LT - Litro</option>
                    <option value="MTR">MTR - Metro</option>
                    <option value="PAQ">PAQ - Paquete</option>
                    <option value="SERVIC">SERVIC - Otro / Servicio</option>
                    <option value="GLOBAL">GLOBAL - Monto Global</option>
                    {item.unidad &&
                      !['UNID', 'CAJA', 'KG', 'LT', 'MTR', 'PAQ', 'SERVIC', 'GLOBAL'].includes(item.unidad) && (
                        <option value={item.unidad}>{item.unidad}</option>
                      )}
                  </select>
                </div>

                {/* 3. CANTIDAD (col-span-1) */}
                <div className="col-span-4 lg:col-span-1 space-y-1 lg:space-y-0">
                  <span className="lg:hidden text-[10px] uppercase font-bold text-muted-foreground block text-right">
                    Cant *
                  </span>
                  <Input
                    type="number"
                    min="1"
                    value={item.cantidad}
                    onChange={(e) => updateItem(index, { cantidad: parseFloat(e.target.value) || 0 })}
                    className={`h-9 text-xs rounded-md bg-background border-input text-right font-mono ${
                      cantError ? 'border-destructive focus-visible:ring-destructive' : ''
                    }`}
                  />
                  {cantError && (
                    <p className="text-[10px] font-semibold text-destructive mt-0.5">{cantError}</p>
                  )}
                </div>

                {/* 4. PRECIO UNITARIO (col-span-2) */}
                <div className="col-span-4 lg:col-span-2 space-y-1 lg:space-y-0">
                  <span className="lg:hidden text-[10px] uppercase font-bold text-muted-foreground block text-right">
                    Precio Est.
                  </span>
                  <Input
                    type="number"
                    step="0.01"
                    value={item.precio}
                    onChange={(e) => updateItem(index, { precio: parseFloat(e.target.value) || 0 })}
                    className="h-9 text-xs rounded-md bg-background border-input text-right font-mono"
                  />
                </div>

                {/* 5. IVA (%) (col-span-1) */}
                <div className="col-span-4 lg:col-span-1 space-y-1 lg:space-y-0">
                  <span className="lg:hidden text-[10px] uppercase font-bold text-muted-foreground block text-right">
                    IVA %
                  </span>
                  <Input
                    type="number"
                    value={item.impuesto}
                    onChange={(e) => updateItem(index, { impuesto: parseFloat(e.target.value) || 0 })}
                    className="h-9 text-xs rounded-md bg-background border-input text-right font-mono"
                  />
                </div>

                {/* 6. SUBTOTAL LÍNEA (col-span-2) */}
                <div className="col-span-5 lg:col-span-2 text-right">
                  <span className="lg:hidden text-[10px] uppercase font-bold text-muted-foreground block">
                    Subtotal Línea
                  </span>
                  <span className="text-xs font-bold text-foreground font-mono inline-block">
                    {totalLinea.toLocaleString('es-VE', { minimumFractionDigits: 2 })} {data.moneda}
                  </span>
                </div>

                {/* 7. ACCIÓN ELIMINAR (col-span-1) */}
                <div className="col-span-3 lg:col-span-1 flex justify-center items-center">
                  {data.items.length > 1 ? (
                    <Button
                      variant="ghost"
                      size="icon"
                      onClick={() => removeItem(index)}
                      className="h-8 w-8 text-destructive hover:bg-destructive/10 rounded-md"
                      title="Eliminar ítem"
                    >
                      <Trash2 className="size-4" />
                    </Button>
                  ) : (
                    <span className="text-[10px] text-muted-foreground font-semibold">—</span>
                  )}
                </div>
              </div>
            </div>
          )
        })}
      </CardContent>

      {/* BLOQUE DE TOTAL GENERAL INFERIOR (FOOTER FINANCIERO) */}
      <div className="bg-muted/40 p-4 border-t border-border/60 flex flex-col sm:flex-row items-center justify-between gap-3">
        <span className="text-xs text-muted-foreground font-medium">
          Monto total estimado sujeto a validación presupuestaria al momento de la aprobación.
        </span>
        <div className="flex items-center gap-3 shrink-0">
          <span className="text-xs font-bold text-muted-foreground uppercase tracking-wider">
            Total Estimado Requisición:
          </span>
          <span className="text-lg font-extrabold text-primary font-mono bg-background px-3 py-1 rounded-md border border-border/60 shadow-2xs">
            {totalRequisicion.toLocaleString('es-VE', { minimumFractionDigits: 2 })} {data.moneda}
          </span>
        </div>
      </div>
    </Card>
  )
}

/* ---------------- PASO 4: RESUMEN & CONFIRMACIÓN ---------------- */
function StepResumen() {
  const { data } = useFormWizard()
  const total = data.items.reduce((acc, item) => {
    const subtotal = item.cantidad * item.precio
    return acc + subtotal + subtotal * (item.impuesto / 100)
  }, 0)

  return (
    <Card className="border-border/60 shadow-xs">
      <CardContent className="p-6 space-y-6">
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 p-3.5 rounded-xl bg-muted/40 border border-border/40">
          <div>
            <span className="text-[10px] font-bold text-muted-foreground uppercase">Prioridad</span>
            <div className="mt-0.5">
              <Badge variant="outline" className="uppercase text-[10px] font-bold">{data.prioridad}</Badge>
            </div>
          </div>
          <div>
            <span className="text-[10px] font-bold text-muted-foreground uppercase">Tipo</span>
            <p className="text-xs font-semibold capitalize">{data.tipoRequisicion}</p>
          </div>
          <div>
            <span className="text-[10px] font-bold text-muted-foreground uppercase">Moneda</span>
            <p className="text-xs font-semibold">{data.moneda}</p>
          </div>
          <div>
            <span className="text-[10px] font-bold text-muted-foreground uppercase">Monto Total</span>
            <p className="text-xs font-bold text-emerald-600 dark:text-emerald-400">
              {total.toLocaleString('es-VE', { minimumFractionDigits: 2 })} {data.moneda}
            </p>
          </div>
        </div>

        <div className="space-y-2">
          <h4 className="text-xs font-bold text-foreground">Proveedor Asignado</h4>
          <div className="p-3 rounded-lg border border-border/60 bg-background text-xs space-y-1">
            <p><span className="font-semibold">Razón Social:</span> {data.proveedor.nombre}</p>
            <p><span className="font-semibold">RIF:</span> {data.proveedor.rif}</p>
            <p><span className="font-semibold">Contacto:</span> {data.proveedor.telefono || 'Sin teléfono'} - {data.proveedor.email || 'Sin correo'}</p>
          </div>
        </div>

        <div className="space-y-2">
          <h4 className="text-xs font-bold text-foreground">Ítems a Solicitar ({data.items.length})</h4>
          <div className="rounded-lg border border-border/60 overflow-hidden">
            <table className="w-full text-xs">
              <thead className="bg-muted/50 text-muted-foreground font-semibold border-b border-border/40">
                <tr>
                  <th className="p-2.5 text-left">#</th>
                  <th className="p-2.5 text-left">Descripción</th>
                  <th className="p-2.5 text-center">Cant.</th>
                  <th className="p-2.5 text-right">P. Unit.</th>
                  <th className="p-2.5 text-right">Total</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-border/40">
                {data.items.map((item, i) => {
                  const lineTotal = item.cantidad * item.precio * (1 + item.impuesto / 100)
                  return (
                    <tr key={i}>
                      <td className="p-2.5 font-bold text-muted-foreground">{i + 1}</td>
                      <td className="p-2.5 font-medium">{item.descripcion}</td>
                      <td className="p-2.5 text-center">{item.cantidad} {item.unidad}</td>
                      <td className="p-2.5 text-right">{item.precio.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                      <td className="p-2.5 text-right font-bold">{lineTotal.toLocaleString('es-VE', { minimumFractionDigits: 2 })}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}
