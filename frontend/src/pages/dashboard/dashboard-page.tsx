import {
  ArrowDownRight,
  ArrowUpRight,
  CalendarRange,
  CreditCard,
  FileSpreadsheet,
  Users,
} from 'lucide-react'

import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'

const metrics = [
  {
    id: 'ingresos',
    label: 'Ingresos del mes',
    value: '$ 128,450',
    trend: '+12.4%',
    trendLabel: 'vs. mes anterior',
    trendPositive: true,
    icon: ArrowUpRight,
  },
  {
    id: 'gastos',
    label: 'Gastos operativos',
    value: '$ 89,210',
    trend: '+4.8%',
    trendLabel: 'vs. mes anterior',
    trendPositive: false,
    icon: ArrowDownRight,
  },
  {
    id: 'clientes-activos',
    label: 'Clientes activos',
    value: '342',
    trend: '+18 nuevos',
    trendLabel: 'últimos 30 días',
    trendPositive: true,
    icon: Users,
  },
  {
    id: 'ordenes',
    label: 'Órdenes pendientes',
    value: '27',
    trend: '5 con prioridad alta',
    trendLabel: 'Inventario y compras',
    trendPositive: false,
    icon: FileSpreadsheet,
  },
]

const upcomingEvents = [
  {
    id: 'nomina',
    title: 'Proceso de nómina',
    description: 'Cierre del ciclo quincenal y aprobación de incidencias.',
    due: '12 Nov 2025',
    responsible: 'RRHH',
    status: 'Programado',
  },
  {
    id: 'cierres',
    title: 'Cierre contable mensual',
    description: 'Conciliación bancaria y revisión de asientos.',
    due: '20 Nov 2025',
    responsible: 'Contabilidad',
    status: 'En preparación',
  },
  {
    id: 'presupuesto',
    title: 'Ajustes presupuestarios 2026',
    description: 'Actualización de proyecciones trimestrales.',
    due: '5 Dic 2025',
    responsible: 'Dirección financiera',
    status: 'Planificado',
  },
]

const financialHighlights = [
  {
    id: 'ventas',
    label: 'Ventas netas acumuladas',
    value: '$ 1,894,320',
    variation: '+8.2%',
    variationLabel: 'vs. periodo anterior',
    positive: true,
  },
  {
    id: 'margen',
    label: 'Margen operativo',
    value: '32.5%',
    variation: '-1.3 pts',
    variationLabel: 'vs. periodo anterior',
    positive: false,
  },
  {
    id: 'cartera',
    label: 'Cartera por cobrar',
    value: '$ 243,870',
    variation: '+12.5%',
    variationLabel: 'en los últimos 30 días',
    positive: false,
  },
  {
    id: 'pagos',
    label: 'Pagos programados',
    value: '$ 178,900',
    variation: 'Próximas 2 semanas',
    variationLabel: '',
    positive: false,
  },
]

export default function DashboardPage() {
  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h2 className="text-2xl font-semibold tracking-tight">
            Estado general
          </h2>
          <p className="text-sm text-muted-foreground">
            Visión integrada de operaciones, finanzas y cumplimiento en tiempo
            real.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="outline" className="gap-2">
            <CalendarRange className="size-4" />
            Últimos 30 días
          </Button>
          <Button className="gap-2">
            <CreditCard className="size-4" />
            Generar reporte
          </Button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        {metrics.map((metric) => (
          <Card key={metric.id} className="border-border/60">
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
              <CardTitle className="text-sm font-medium">
                {metric.label}
              </CardTitle>
              <metric.icon className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-semibold">{metric.value}</div>
              <p
                className={cn(
                  'text-xs font-medium',
                  metric.trendPositive
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-red-600 dark:text-red-400',
                )}
              >
                {metric.trend}{' '}
                <span className="text-muted-foreground">
                  {metric.trendLabel}
                </span>
              </p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="flex items-center justify-between text-base">
              Próximas actividades
              <Button variant="ghost" size="sm">
                Ver agenda
              </Button>
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {upcomingEvents.map((event, index) => (
              <div key={event.id} className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p className="text-sm font-semibold">{event.title}</p>
                    <p className="text-xs text-muted-foreground">
                      {event.description}
                    </p>
                  </div>
                  <Badge variant="secondary">{event.status}</Badge>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                  <span className="font-medium">Responsable:</span>
                  <span>{event.responsible}</span>
                  <Separator orientation="vertical" className="h-3" />
                  <span className="font-medium">Vencimiento</span>
                  <span>{event.due}</span>
                </div>
                {index < upcomingEvents.length - 1 && <Separator />}
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Alertas recientes</CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm dark:border-amber-900/50 dark:bg-amber-900/30">
              <p className="font-semibold text-amber-900 dark:text-amber-100">
                Tasa de cambio pendiente
              </p>
              <p className="text-amber-900/80 dark:text-amber-200/90">
                La tasa de cambio de las 13:00 aún no se ha actualizado.
              </p>
            </div>
            <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-900/50 dark:bg-rose-900/40">
              <p className="font-semibold text-rose-900 dark:text-rose-100">
                Asiento por aprobar
              </p>
              <p className="text-rose-900/80 dark:text-rose-200/90">
                Existen 3 asientos con control interno pendientes de aprobación.
              </p>
            </div>
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-800 dark:bg-slate-900/40">
              <p className="font-semibold text-slate-900 dark:text-slate-100">
                Reportes automáticos
              </p>
              <p className="text-slate-600 dark:text-slate-300">
                Se programó la generación automática de estados financieros para
                el viernes.
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">
            Indicadores financieros destacados
          </CardTitle>
        </CardHeader>
        <CardContent>
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            {financialHighlights.map((item) => (
              <div
                key={item.id}
                className="rounded-lg border border-border/60 bg-card p-4 shadow-sm"
              >
                <p className="text-xs text-muted-foreground">{item.label}</p>
                <p className="mt-2 text-lg font-semibold">{item.value}</p>
                <p
                  className={cn(
                    'text-xs font-medium',
                    item.positive
                      ? 'text-emerald-600 dark:text-emerald-400'
                      : 'text-red-600 dark:text-red-400',
                  )}
                >
                  {item.variation}
                </p>
                {item.variationLabel ? (
                  <p className="text-xs text-muted-foreground">
                    {item.variationLabel}
                  </p>
                ) : null}
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

