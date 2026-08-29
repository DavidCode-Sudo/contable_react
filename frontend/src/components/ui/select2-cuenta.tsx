import React, { useState, useEffect, useRef } from 'react'
import { Check, ChevronsUpDown, Loader2, Search, X } from 'lucide-react'
import { Input } from '@/components/ui/input'

export interface CuentaItem {
  id: number
  codigo: string
  nombre: string
  imputable?: number | boolean
}

interface Select2CuentaProps {
  value?: number | null
  onChange: (id: number | null, item?: CuentaItem) => void
  cuentas: CuentaItem[]
  placeholder?: string
  loading?: boolean
  disabled?: boolean
  className?: string
}

export const Select2Cuenta: React.FC<Select2CuentaProps> = ({
  value,
  onChange,
  cuentas = [],
  placeholder = '-- Seleccionar Cuenta Contable (Ej: 1.1.3...) --',
  loading = false,
  disabled = false,
  className = '',
}) => {
  const [open, setOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const containerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLInputElement>(null)

  const selectedItem = cuentas.find((c) => Number(c.id) === Number(value))

  useEffect(() => {
    if (open) {
      setTimeout(() => inputRef.current?.focus(), 50)
    }
  }, [open])

  // Cierra el menú desplegable si se hace clic fuera del contenedor
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  // Filtrado en tiempo real por código o nombre
  const filteredCuentas = cuentas.filter((c) => {
    const q = searchQuery.toLowerCase().trim()
    if (!q) return true
    return c.codigo.toLowerCase().includes(q) || c.nombre.toLowerCase().includes(q)
  })

  return (
    <div ref={containerRef} className={`relative w-full ${className}`}>
      {/* BOTÓN CAJA SELECT2 */}
      <button
        type="button"
        disabled={disabled}
        onClick={() => {
          if (!disabled) {
            setOpen(!open)
            setSearchQuery('')
          }
        }}
        className={`w-full min-h-[38px] px-3 py-1.5 text-xs font-mono bg-background border rounded-md shadow-xs flex items-center justify-between transition-all focus:outline-none focus:ring-2 focus:ring-primary ${
          open ? 'border-primary ring-2 ring-primary/20' : 'border-input hover:bg-accent/40'
        } ${disabled ? 'opacity-50 cursor-not-allowed bg-muted' : 'cursor-pointer'}`}
      >
        <span className={`truncate text-left mr-2 ${selectedItem ? 'text-foreground font-bold' : 'text-muted-foreground font-sans'}`}>
          {selectedItem ? `${selectedItem.codigo} - ${selectedItem.nombre}` : placeholder}
        </span>
        <div className="flex items-center gap-1 shrink-0 text-muted-foreground">
          {selectedItem && !disabled && (
            <X
              className="size-3.5 hover:text-destructive cursor-pointer mr-0.5"
              onClick={(e) => {
                e.stopPropagation()
                onChange(null)
                setOpen(false)
              }}
            />
          )}
          <ChevronsUpDown className="size-3.5 opacity-60" />
        </div>
      </button>

      {/* MENÚ DESPLEGABLE CON BUSCADOR INTERNO SELECT2 RESPONSIVO */}
      {open && (
        <div className="absolute left-0 right-0 z-50 mt-1 w-full max-h-72 rounded-lg border border-border bg-popover text-popover-foreground shadow-2xl p-1.5 flex flex-col space-y-1.5 animate-in fade-in-50 zoom-in-95">
          {/* BARRA DE BÚSQUEDA SELECT2 */}
          <div className="relative flex items-center px-1 pt-1">
            <Search className="absolute left-3 size-3.5 text-muted-foreground" />
            <Input
              ref={inputRef}
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              placeholder="Escriba código o nombre para buscar..."
              className="h-8 pl-8 text-xs font-mono bg-muted/40 focus-visible:ring-primary"
            />
            {searchQuery && (
              <X
                className="absolute right-3 size-3.5 text-muted-foreground hover:text-foreground cursor-pointer"
                onClick={() => setSearchQuery('')}
              />
            )}
          </div>

          {/* RESULTADOS SCROLLABLES RESPONSIVOS */}
          <div className="overflow-y-auto max-h-56 divide-y divide-border/20 py-1">
            {loading ? (
              <div className="flex items-center justify-center p-4 text-xs text-muted-foreground">
                <Loader2 className="size-4 animate-spin mr-2 text-primary" /> Cargando catálogo de cuentas...
              </div>
            ) : filteredCuentas.length === 0 ? (
              <div className="p-4 text-center text-xs text-muted-foreground">
                No se encontraron cuentas que coincidan con &quot;{searchQuery}&quot;.
              </div>
            ) : (
              filteredCuentas.map((item) => {
                const isSelected = Number(item.id) === Number(value)
                return (
                  <div
                    key={item.id}
                    onClick={() => {
                      onChange(item.id, item)
                      setOpen(false)
                    }}
                    className={`text-xs font-mono cursor-pointer flex items-center justify-between py-2 px-2.5 rounded-md transition-colors ${
                      isSelected
                        ? 'bg-primary/10 text-primary font-bold'
                        : 'hover:bg-accent text-foreground hover:text-accent-foreground'
                    }`}
                  >
                    <div className="truncate mr-2">
                      <span className="font-bold mr-1.5">{item.codigo}</span>
                      <span className="font-sans text-[11px] opacity-90">{item.nombre}</span>
                    </div>
                    {isSelected && <Check className="size-4 text-primary shrink-0" />}
                  </div>
                )
              })
            )}
          </div>
        </div>
      )}
    </div>
  )
}
