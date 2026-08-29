import { Navigate, Outlet, createBrowserRouter } from 'react-router-dom'

import { AppShell } from '@/components/layout/app-shell'
import { ProtectedRoute } from '@/components/auth/ProtectedRoute'
import { PublicRoute } from '@/components/auth/PublicRoute'
import { navigationSections } from '@/config/navigation'
import DashboardPage from '@/pages/dashboard/dashboard-page'
import { PlaceholderPage } from '@/pages/placeholder-page'
import { RequisicionesListPage } from '@/pages/requisiciones/requisiciones-list-page'
import { RequisicionDetailPage } from '@/pages/requisiciones/requisicion-detail-page'
import { RequisicionFormPage } from '@/pages/requisiciones/requisicion-form-page'
import { InventarioListPage } from '@/pages/inventario/inventario-list-page'
import { OrdenesEntregaListPage } from '@/pages/inventario/ordenes-entrega-list-page'
import { OrdenEntregaDetailPage } from '@/pages/inventario/orden-entrega-detail-page'
import { SolicitudesInternasListPage } from '@/pages/inventario/solicitudes-internas-list-page'
import { SolicitudInternaDetailPage } from '@/pages/inventario/solicitud-interna-detail-page'
import { NecesidadesComprasPage } from '@/pages/inventario/necesidades-compras-page'
import { CatalogoListPage } from '@/pages/inventario/catalogo-list-page'
import { MatrizListPage } from '@/pages/contabilidad/matriz-list-page'
import { ConfiguracionCuentasPage } from '@/pages/contabilidad/configuracion-cuentas-page'
import { CuentasBancariasPage } from '@/pages/tesoreria/cuentas-bancarias-page'
import { AsientosListPage } from '@/pages/contabilidad/asientos-list-page'
import { LibrosContablesPage } from '@/pages/contabilidad/libros-contables-page'
import { LibroDiarioPage } from '@/pages/contabilidad/libro-diario-page'
import { LibroMayorPage } from '@/pages/contabilidad/libro-mayor-page'
import { BalanceComprobacionPage } from '@/pages/contabilidad/balance-comprobacion-page'

import { AuthProvider } from '@/context/AuthContext'
import LoginPage from '@/pages/auth/login-page'

export type RouteHandle = {
  title: string
  description?: string
}

const placeholderRoutes = navigationSections
  .flatMap((section) => section.items)
  .filter(
    (item) =>
      item.path !== '/dashboard' &&
      item.path !== '/inventario/requisiciones' &&
      item.path !== '/inventario/gestion' &&
      item.path !== '/inventario/ordenes-entrega' &&
      item.path !== '/inventario/solicitudes-internas' &&
      item.path !== '/inventario/necesidades-compras' &&
      item.path !== '/contabilidad/catalogo' &&
      item.path !== '/contabilidad/matriz' &&
      item.path !== '/contabilidad/configuracion-cuentas' &&
      item.path !== '/tesoreria/cuentas-bancarias' &&
      item.path !== '/contabilidad/bancos' &&
      item.path !== '/contabilidad/asientos' &&
      item.path !== '/contabilidad/diario' &&
      item.path !== '/contabilidad/libro-diario' &&
      item.path !== '/contabilidad/libro-mayor' &&
      item.path !== '/contabilidad/mayor' &&
      item.path !== '/contabilidad/balance-comprobacion' &&
      item.path !== '/contabilidad/balance',
  )

/**
 * RootLayout mantiene viva una única instancia de AuthProvider a nivel raíz
 * evitando la destrucción de estado en RAM durante transiciones entre páginas.
 */
const RootLayout = () => {
  return (
    <AuthProvider>
      <Outlet />
    </AuthProvider>
  )
}

export const router = createBrowserRouter([
  {
    element: <RootLayout />,
    children: [
      {
        element: <PublicRoute />,
        children: [
          {
            path: '/login',
            element: <LoginPage />,
          },
        ],
      },
      {
        element: <ProtectedRoute />,
        children: [
          {
            path: '/',
            element: <AppShell />,
            children: [
              {
                index: true,
                element: <Navigate to="/dashboard" replace />,
                handle: {
                  title: 'Inicio',
                  description: 'Redireccionando al dashboard principal.',
                } satisfies RouteHandle,
              },
              {
                path: 'dashboard',
                element: <DashboardPage />,
                handle: {
                  title: 'Dashboard',
                  description:
                    'Tablero general con indicadores clave, alertas y accesos rápidos.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/requisiciones',
                element: <RequisicionesListPage />,
                handle: {
                  title: 'Requisiciones',
                  description:
                    'Listado principal de requisiciones con indicadores y filtros avanzados.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/requisiciones/nueva',
                element: <RequisicionFormPage />,
                handle: {
                  title: 'Nueva requisición',
                  description:
                    'Formulario para crear una nueva requisición de compra o servicio.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/requisiciones/:id/editar',
                element: <RequisicionFormPage />,
                handle: {
                  title: 'Editar requisición',
                  description:
                    'Actualiza los datos de una requisición en borrador antes de enviarla.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/requisiciones/:id',
                element: <RequisicionDetailPage />,
                handle: {
                  title: 'Detalle de requisición',
                  description:
                    'Información completa de la requisición seleccionada, incluyendo ítems y bitácora.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/gestion',
                element: <InventarioListPage />,
                handle: {
                  title: 'Gestión de inventario',
                  description:
                    'Administre existencias de almacén, productos, categorías y movimientos de stock.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/ordenes-entrega',
                element: <OrdenesEntregaListPage />,
                handle: {
                  title: 'Órdenes de entrega',
                  description:
                    'Gestión y control de despachos institucionales de almacén a departamentos.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/ordenes-entrega/:id',
                element: <OrdenEntregaDetailPage />,
                handle: {
                  title: 'Detalle de orden de entrega',
                  description:
                    'Información detallada, acta de entrega e ítems despachados.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/solicitudes-internas',
                element: <SolicitudesInternasListPage />,
                handle: {
                  title: 'Mis Solicitudes / Solicitudes Internas',
                  description:
                    'Gestión y seguimiento institucional de requerimientos de insumos y servicios.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/solicitudes-internas/:id',
                element: <SolicitudInternaDetailPage />,
                handle: {
                  title: 'Detalle de solicitud interna',
                  description:
                    'Información completa del expediente, ítems requeridos y trazabilidad.',
                } satisfies RouteHandle,
              },
              {
                path: 'inventario/necesidades-compras',
                element: <NecesidadesComprasPage />,
                handle: {
                  title: 'Cola de Procura / Compras',
                  description:
                    'Consolidado de demandas insatisfechas para la generación de requisiciones globales.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/catalogo',
                element: <CatalogoListPage />,
                handle: {
                  title: 'Catálogo de Cuentas y Partidas',
                  description:
                    'Gestión del clasificador presupuestario ONAPRE y plan de cuentas patrimonial.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/matriz',
                element: <MatrizListPage />,
                handle: {
                  title: 'Matriz de Conversión',
                  description:
                    'Definición de reglas de imputación automática entre clasificadores ONAPRE y asientos.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/configuracion-cuentas',
                element: <ConfiguracionCuentasPage />,
                handle: {
                  title: 'Configuración de Cuentas del Sistema',
                  description:
                    'Mapeo de cuentas por defecto del sistema y auto-sanación ONCOP.',
                } satisfies RouteHandle,
              },
              {
                path: 'tesoreria/cuentas-bancarias',
                element: <CuentasBancariasPage />,
                handle: {
                  title: 'Cuentas Bancarias y Tesorería',
                  description:
                    'Gestión de cuentas institucionales, disponibilidad ONAPRE y transferencias.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/bancos',
                element: <CuentasBancariasPage />,
                handle: {
                  title: 'Cuentas Bancarias y Tesorería',
                  description:
                    'Gestión de cuentas institucionales, disponibilidad ONAPRE y transferencias.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/asientos',
                element: <AsientosListPage />,
                handle: {
                  title: 'Asientos Contables y Libro Diario',
                  description:
                    'Gestión de comprobantes contables con correlativos oficiales inmutables.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/diario',
                element: <AsientosListPage />,
                handle: {
                  title: 'Asientos Contables y Libro Diario',
                  description:
                    'Gestión de comprobantes contables con correlativos oficiales inmutables.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/libros',
                element: <LibrosContablesPage />,
                handle: {
                  title: 'Libros Contables',
                  description:
                    'Módulo consolidado de Libro Diario, Libro Mayor y Balance de Comprobación.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/libro-diario',
                element: <LibrosContablesPage />,
                handle: {
                  title: 'Libro Diario Oficial',
                  description:
                    'Reporte oficial foliado de comprobantes contables confirmados.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/libro-mayor',
                element: <LibrosContablesPage />,
                handle: {
                  title: 'Libro Mayor (Tarjeta O(1))',
                  description:
                    'Consulta atómica bimonetaria de tarjetas de Mayor en 5ms.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/mayor',
                element: <LibrosContablesPage />,
                handle: {
                  title: 'Libro Mayor (Tarjeta O(1))',
                  description:
                    'Consulta atómica bimonetaria de tarjetas de Mayor en 5ms.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/balance-comprobacion',
                element: <LibrosContablesPage />,
                handle: {
                  title: 'Balance de Comprobación O(1)',
                  description:
                    'Resumen bimonetario consolidado con arrastre interanual.',
                } satisfies RouteHandle,
              },
              {
                path: 'contabilidad/balance',
                element: <LibrosContablesPage />,
                handle: {
                  title: 'Balance de Comprobación O(1)',
                  description:
                    'Resumen bimonetario consolidado con arrastre interanual.',
                } satisfies RouteHandle,
              },
              ...placeholderRoutes.map((item) => ({
                path: normalizePath(item.path),
                element: (
                  <PlaceholderPage title={item.label} description={item.description} />
                ),
                handle: {
                  title: item.label,
                  description: item.description,
                } satisfies RouteHandle,
              })),
              {
                path: '*',
                element: (
                  <PlaceholderPage
                    title="Página no encontrada"
                    description="La ruta solicitada no existe en el nuevo frontend aún. Puedes volver al dashboard o utilizar la versión anterior del sistema."
                  />
                ),
                handle: {
                  title: 'No encontrado',
                  description: 'La ruta solicitada no está disponible.',
                } satisfies RouteHandle,
              },
            ],
          },
        ],
      },
    ],
  },
])

function normalizePath(path: string) {
  return path.startsWith('/') ? path.slice(1) : path
}
