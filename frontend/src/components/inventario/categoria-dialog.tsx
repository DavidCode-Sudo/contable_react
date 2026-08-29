import { useEffect, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { FolderPlus, Save } from 'lucide-react'
import { toast } from 'sonner'
import { apiClient } from '@/lib/apiClient'
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
import {
  inventarioKeys,
  saveCategoria,
  type CategoriaProducto,
} from '@/services/inventario'
import { catalogoCuentasService } from '@/services/catalogoCuentas'
import { Select2Cuenta } from '@/components/ui/select2-cuenta'

interface CategoriaDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  categoriaEditar?: CategoriaProducto | null
}

export function CategoriaDialog({ open, onOpenChange, categoriaEditar }: CategoriaDialogProps) {
  const queryClient = useQueryClient()

  const [nombre, setNombre] = useState('')
  const [descripcion, setDescripcion] = useState('')
  const [cuentaContableId, setCuentaContableId] = useState<number | null>(null)
  const [estado, setEstado] = useState<'activo' | 'inactivo'>('activo')
  const [cuentasContables, setCuentasContables] = useState<Array<{ id: number; codigo: string; nombre: string }>>([])
  const [errorLocal, setErrorLocal] = useState<string | null>(null)

  useEffect(() => {
    const fetchCuentas = async () => {
      try {
        const res = await catalogoCuentasService.getAll({ imputable: 1 })
        const list = Array.isArray(res)
          ? res
          : Array.isArray(res?.cuentas)
          ? res.cuentas
          : Array.isArray((res as any)?.data)
          ? (res as any).data
          : []
        setCuentasContables(list)
      } catch (e) {
        console.error('Error al cargar cuentas contables:', e)
      }
    }
    if (open) {
      fetchCuentas()
    }
  }, [open])

  useEffect(() => {
    if (categoriaEditar) {
      setNombre(categoriaEditar.nombre || '')
      setDescripcion(categoriaEditar.descripcion || '')
      setCuentaContableId(categoriaEditar.cuenta_contable_id ? Number(categoriaEditar.cuenta_contable_id) : null)
      setEstado(categoriaEditar.estado || 'activo')
    } else {
      setNombre('')
      setDescripcion('')
      setCuentaContableId(null)
      setEstado('activo')
    }
    setErrorLocal(null)
  }, [categoriaEditar, open])

  const mutation = useMutation({
    mutationFn: (payload: { id?: number | null; nombre: string; descripcion?: string; cuenta_contable_id?: number | null; estado: 'activo' | 'inactivo' }) =>
      saveCategoria(payload),
    onSuccess: (data) => {
      queryClient.invalidateQueries({ queryKey: inventarioKeys.all })
      toast.success(data.message || 'Categoría guardada exitosamente.')
      onOpenChange(false)
    },
    onError: (err: Error) => {
      setErrorLocal(err.message)
      toast.error(err.message)
    },
  })

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    setErrorLocal(null)

    if (!nombre.trim()) {
      setErrorLocal('El nombre de la categoría es obligatorio.')
      return
    }

    mutation.mutate({
      id: categoriaEditar?.id ?? null,
      nombre: nombre.trim(),
      descripcion: descripcion.trim(),
      cuenta_contable_id: cuentaContableId,
      estado,
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-lg font-bold">
              <FolderPlus className="size-5 text-primary" />
              {categoriaEditar ? `Editar Categoría: ${categoriaEditar.nombre}` : 'Nueva Categoría de Inventario'}
            </DialogTitle>
            <DialogDescription className="text-xs">
              Organice los productos de almacén y asigne su Cuenta Contable Imputable del Plan Único de Cuentas.
            </DialogDescription>
          </DialogHeader>

          {errorLocal && (
            <div className="my-3 p-3 rounded-lg bg-rose-50 border border-rose-200 text-xs font-semibold text-rose-700">
              {errorLocal}
            </div>
          )}

          <div className="space-y-4 py-3">
            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Nombre de la Categoría *</label>
              <Input
                required
                placeholder="Ej: Material de Limpieza, Artículos de Oficina..."
                value={nombre}
                disabled={mutation.isPending}
                onChange={(e) => setNombre(e.target.value)}
                className="text-xs font-medium"
              />
            </div>

            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Descripción</label>
              <textarea
                rows={2}
                placeholder="Breve descripción del grupo de productos..."
                value={descripcion}
                disabled={mutation.isPending}
                onChange={(e) => setDescripcion(e.target.value)}
                className="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:ring-2 focus:ring-primary focus:outline-none"
              />
            </div>

            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">
                Cuenta Contable Imputable (Plan Único de Cuentas) *
              </label>
              <Select2Cuenta
                value={cuentaContableId}
                onChange={(id) => setCuentaContableId(id)}
                cuentas={cuentasContables}
                disabled={mutation.isPending}
                placeholder="-- Buscar por código o nombre de cuenta... --"
              />
              <p className="text-[11px] text-muted-foreground mt-1">
                Cuenta de Activo Realizable usada para inyectar automáticamente los asientos en el Libro Diario.
              </p>
            </div>

            <div className="space-y-1">
              <label className="text-xs font-semibold text-foreground">Estado</label>
              <select
                value={estado}
                disabled={mutation.isPending}
                onChange={(e) => setEstado(e.target.value as 'activo' | 'inactivo')}
                className="w-full rounded-md border border-input bg-background p-2.5 text-xs focus:ring-2 focus:ring-primary focus:outline-none"
              >
                <option value="activo">Activo</option>
                <option value="inactivo">Inactivo</option>
              </select>
            </div>
          </div>

          <DialogFooter className="gap-2">
            <Button variant="outline" size="sm" type="button" onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button size="sm" type="submit" disabled={mutation.isPending} className="gap-2 font-bold">
              <Save className="size-4" />
              {mutation.isPending ? 'Guardando...' : 'Guardar Categoría'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
