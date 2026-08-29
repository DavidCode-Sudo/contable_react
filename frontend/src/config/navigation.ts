import type { LucideIcon } from 'lucide-react'
import {
  Activity,
  ArrowLeftRight,
  BookOpen,
  CalendarDays,
  Calculator,
  ChartArea,
  ClipboardList,
  CreditCard,
  Factory,
  FileBarChart,
  FileText,
  FolderKanban,
  Home,
  LineChart,
  PackageSearch,
  PanelsTopLeft,
  Receipt,
  ScrollText,
  Settings,
  ShieldCheck,
  ShoppingCart,
  UserRound,
  Users,
  Warehouse,
} from 'lucide-react'

export type NavigationItem = {
  id: string
  label: string
  path: string
  icon: LucideIcon
  description?: string
  badge?: string
}

export type NavigationSection = {
  id: string
  label: string
  icon: LucideIcon
  items: NavigationItem[]
}

export const navigationSections: NavigationSection[] = [
  {
    id: 'principal',
    label: 'Principal',
    icon: Home,
    items: [
      {
        id: 'dashboard',
        label: 'Dashboard',
        path: '/dashboard',
        icon: PanelsTopLeft,
        description: 'Resumen general con métricas clave del negocio.',
      },
    ],
  },
  {
    id: 'inventario',
    label: 'Inventario y Compras',
    icon: Warehouse,
    items: [
      {
        id: 'solicitudes-internas',
        label: 'Mis Solicitudes',
        path: '/inventario/solicitudes-internas',
        icon: FileText,
        description: 'Gestione solicitudes internas de servicio e insumos de oficina.',
      },
      {
        id: 'requisiciones',
        label: 'Requisiciones',
        path: '/inventario/requisiciones',
        icon: ClipboardList,
        description:
          'Gestione las requisiciones internas y haga seguimiento a cada solicitud.',
      },
      {
        id: 'inventario',
        label: 'Inventario',
        path: '/inventario/gestion',
        icon: PackageSearch,
        description:
          'Administre inventarios, existencias y movimientos de productos.',
      },
      {
        id: 'ordenes-entrega',
        label: 'Órdenes de entrega',
        path: '/inventario/ordenes-entrega',
        icon: ShoppingCart,
        description: 'Controle las órdenes de entrega y su estado de despacho.',
      },
      {
        id: 'necesidades-compras',
        label: 'Cola de Procura',
        path: '/inventario/necesidades-compras',
        icon: ShoppingCart,
        description: 'Consolidado de demandas insatisfechas para adquisiciones.',
      },
    ],
  },
  {
    id: 'contabilidad',
    label: 'Contabilidad',
    icon: Calculator,
    items: [
      {
        id: 'catalogo',
        label: 'Catálogo de partidas',
        path: '/contabilidad/catalogo',
        icon: Factory,
        description: 'Administre el catálogo contable y la estructura de cuentas.',
      },
      {
        id: 'matriz-conversion',
        label: 'Matriz de conversión',
        path: '/contabilidad/matriz',
        icon: ArrowLeftRight,
        description: 'Definición de reglas de imputación entre ONAPRE y asientos.',
      },
      {
        id: 'cuentas-bancarias',
        label: 'Cuentas bancarias',
        path: '/tesoreria/cuentas-bancarias',
        icon: CreditCard,
        description: 'Registre y concilie las cuentas bancarias del sistema.',
      },
      {
        id: 'asientos',
        label: 'Asientos contables',
        path: '/contabilidad/asientos',
        icon: ScrollText,
        description: 'Registre, revise y apruebe asientos contables.',
        badge: 'CON CONTROL',
      },
      {
        id: 'libros-contables',
        label: 'Libros Contables',
        path: '/contabilidad/libros',
        icon: BookOpen,
        description: 'Libro Diario, Libro Mayor y Balance de Comprobación consolidado.',
      },
      {
        id: 'cierre',
        label: 'Cierre contable',
        path: '/contabilidad/cierre',
        icon: ShieldCheck,
        description: 'Ejecución de cierres contables mensuales y anuales.',
      },
      {
        id: 'periodos',
        label: 'Periodos contables',
        path: '/contabilidad/periodos',
        icon: Activity,
        description: 'Defina y supervise los periodos contables del ejercicio.',
      },
    ],
  },
  {
    id: 'operaciones',
    label: 'Operaciones',
    icon: ClipboardList,
    items: [
      {
        id: 'recibos-pago',
        label: 'Recibos de pagos',
        path: '/operaciones/recibos-pago',
        icon: Receipt,
        description: 'Gestione la facturación y recibos de pago a clientes.',
      },
      {
        id: 'servicios',
        label: 'Servicios',
        path: '/operaciones/servicios',
        icon: FolderKanban,
        description:
          'Administre el catálogo de servicios y su configuración contable.',
      },
    ],
  },
  {
    id: 'presupuestos',
    label: 'Control presupuestario',
    icon: LineChart,
    items: [
      {
        id: 'dashboard-presupuesto',
        label: 'Dashboard presupuestario',
        path: '/presupuestos/dashboard',
        icon: ChartArea,
        description:
          'Compare escenarios y evolución de la ejecución presupuestaria.',
      },
      {
        id: 'estado-ejecucion',
        label: 'Estado de ejecución',
        path: '/presupuestos/estado-ejecucion',
        icon: FileBarChart,
        description:
          'Evalúe la ejecución presupuestaria frente a las proyecciones.',
      },
      {
        id: 'gestion-presupuestos',
        label: 'Gestión de presupuestos',
        path: '/presupuestos/gestion',
        icon: Calculator,
        description:
          'Cree y ajuste presupuestos anuales, trimestrales o mensuales.',
      },
      {
        id: 'periodos-presupuestos',
        label: 'Periodos presupuestarios',
        path: '/presupuestos/periodos',
        icon: CalendarDays,
        description: 'Configure los periodos y calendarios del presupuesto.',
      },
      {
        id: 'admin-presupuestos',
        label: 'Administración',
        path: '/presupuestos/administracion',
        icon: Settings,
        description: 'Herramientas avanzadas para la configuración presupuestaria.',
      },
    ],
  },
  {
    id: 'rrhh',
    label: 'Recursos humanos',
    icon: Users,
    items: [
      {
        id: 'departamentos',
        label: 'Departamentos',
        path: '/rrhh/departamentos',
        icon: Factory,
        description: 'Estructura organizacional y unidades de negocio.',
      },
      {
        id: 'empleados',
        label: 'Empleados',
        path: '/rrhh/empleados',
        icon: Users,
        description: 'Gestione legajos, datos y credenciales del personal.',
      },
      {
        id: 'reglas-nomina',
        label: 'Reglas de nómina',
        path: '/rrhh/reglas-nomina',
        icon: ScrollText,
        description: 'Configure conceptos y reglas de cálculo de nómina.',
      },
      {
        id: 'config-empleado',
        label: 'Configuración por empleado',
        path: '/rrhh/configuracion-empleado',
        icon: Settings,
        description: 'Asigne conceptos y beneficios a nivel individual.',
      },
      {
        id: 'config-cargo',
        label: 'Configuración por cargo',
        path: '/rrhh/configuracion-cargo',
        icon: ClipboardList,
        description: 'Defina estructuras de compensación por cargo.',
      },
      {
        id: 'nominas',
        label: 'Nóminas',
        path: '/nominas/gestion',
        icon: Receipt,
        description: 'Procese y distribuya nóminas periódicas.',
      },
      {
        id: 'periodos-nomina',
        label: 'Períodos de nómina',
        path: '/nominas/periodos',
        icon: CalendarDays,
        description: 'Controle las fechas y ciclos de pago del personal.',
      },
      {
        id: 'impacto-nominas',
        label: 'Impacto de nóminas',
        path: '/nominas/impacto',
        icon: LineChart,
        description: 'Analice el impacto de nóminas sobre el presupuesto.',
      },
    ],
  },
  {
    id: 'relaciones',
    label: 'Relaciones',
    icon: Users,
    items: [
      {
        id: 'clientes',
        label: 'Clientes',
        path: '/relaciones/clientes',
        icon: UserRound,
        description: 'Administre fichas de clientes y sus cuentas corrientes.',
      },
      {
        id: 'proveedores',
        label: 'Proveedores',
        path: '/relaciones/proveedores',
        icon: Factory,
        description: 'Gestione proveedores, contratos y condiciones de pago.',
      },
    ],
  },
  {
    id: 'reportes',
    label: 'Reportes',
    icon: FileBarChart,
    items: [
      {
        id: 'reportes-financieros',
        label: 'Reportes financieros',
        path: '/reportes/financieros',
        icon: LineChart,
        description: 'Informes transversales para análisis financiero.',
      },
      {
        id: 'estados-financieros',
        label: 'Estados financieros',
        path: '/reportes/estados',
        icon: FileText,
        description:
          'Estados financieros oficiales y estados de situación.',
      },
      {
        id: 'estados-cuentas',
        label: 'Estados de cuentas',
        path: '/reportes/estados-cuentas',
        icon: ClipboardList,
        description:
          'Resumen por cliente o proveedor con métricas de saldo.',
      },
      {
        id: 'conciliacion',
        label: 'Conciliación bancaria',
        path: '/reportes/conciliacion-bancaria',
        icon: CreditCard,
        description: 'Conciliación detallada de cuentas bancarias.',
      },
      {
        id: 'cxc',
        label: 'Cuentas por cobrar',
        path: '/reportes/cuentas-por-cobrar',
        icon: FileBarChart,
        description:
          'Análisis de vencimientos y cartera de clientes por cobrar.',
      },
      {
        id: 'cxp',
        label: 'Cuentas por pagar',
        path: '/reportes/cuentas-por-pagar',
        icon: FileBarChart,
        description: 'Gestión de obligaciones y pago a proveedores.',
      },
    ],
  },
  {
    id: 'sistema',
    label: 'Sistema',
    icon: Settings,
    items: [
      {
        id: 'usuarios',
        label: 'Usuarios',
        path: '/sistema/usuarios',
        icon: Users,
        description: 'Gestione usuarios internos y sus accesos.',
      },
      {
        id: 'roles',
        label: 'Roles y permisos',
        path: '/sistema/roles',
        icon: ShieldCheck,
        description: 'Administre roles, permisos y accesos.',
      },
      {
        id: 'auditoria',
        label: 'Auditoría del sistema',
        path: '/sistema/auditoria',
        icon: ScrollText,
        description: 'Registros de auditoría y trazabilidad.',
      },
    ],
  },
]

