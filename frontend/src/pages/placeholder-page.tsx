import { Lightbulb } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Separator } from '@/components/ui/separator'

type PlaceholderPageProps = {
  title: string
  description?: string
}

export function PlaceholderPage({ title, description }: PlaceholderPageProps) {
  return (
    <div className="flex h-full items-center justify-center py-12">
      <Card className="max-w-xl border-dashed">
        <CardHeader className="space-y-3 text-center">
          <div className="mx-auto flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
            <Lightbulb className="size-5" />
          </div>
          <CardTitle className="text-2xl font-semibold">
            {title} en construcción
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-6 text-center text-sm text-muted-foreground">
          <p>
            Estamos migrando esta sección al nuevo frontend en React. Mientras
            tanto, puedes seguir utilizando la versión actual en PHP sin
            interrupciones.
          </p>
          {description ? (
            <>
              <Separator />
              <p className="text-xs text-muted-foreground/90">{description}</p>
            </>
          ) : null}
          <div className="flex flex-wrap items-center justify-center gap-3 pt-4">
            <Button
              variant="default"
              onClick={() => window.history.back()}
              className="min-w-40"
            >
              Volver atrás
            </Button>
            <Button
              variant="outline"
              className="min-w-40"
              onClick={() => (window.location.href = '/')}
            >
              Ir al dashboard
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}

