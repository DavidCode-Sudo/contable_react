import * as React from 'react'
import { cn } from '@/lib/utils'

export interface SelectProps extends React.SelectHTMLAttributes<HTMLSelectElement> {
  onValueChange?: (value: string) => void;
  children?: React.ReactNode;
}

interface SelectContextType {
  value?: string;
  onValueChange?: (value: string) => void;
  getOptionLabel: (value: string) => string | undefined;
  registerOption: (value: string, label: string) => void;
}

const SelectContext = React.createContext<SelectContextType>({
  getOptionLabel: () => undefined,
  registerOption: () => {},
})

const Select: React.FC<
  React.HTMLAttributes<HTMLDivElement> & {
    value?: string;
    onValueChange?: (value: string) => void;
    children: React.ReactNode;
  }
> = ({ value, onValueChange, children, className, ...props }) => {
  const optionsMapRef = React.useRef<Map<string, string>>(new Map())
  const [, forceUpdate] = React.useReducer((x) => x + 1, 0)

  const registerOption = React.useCallback(
    (val: string, label: string) => {
      const prev = optionsMapRef.current.get(val)
      if (prev !== label) {
        optionsMapRef.current.set(val, label)
        if (value !== undefined && String(val) === String(value)) {
          queueMicrotask(() => forceUpdate())
        }
      }
    },
    [value]
  )

  const getOptionLabel = React.useCallback((val: string) => {
    return optionsMapRef.current.get(val)
  }, [])

  return (
    <SelectContext.Provider value={{ value, onValueChange, getOptionLabel, registerOption }}>
      <div className={cn('relative inline-block w-full', className)} {...props}>
        {children}
      </div>
    </SelectContext.Provider>
  )
}

const SelectTrigger = React.forwardRef<
  HTMLDivElement,
  React.HTMLAttributes<HTMLDivElement>
>(({ className, children, ...props }, ref) => {
  return (
    <div
      ref={ref}
      className={cn(
        'relative flex h-9 w-full items-center justify-between rounded-md border border-input bg-background px-3 py-1.5 text-xs text-foreground shadow-xs cursor-pointer focus:outline-none focus:ring-1 focus:ring-primary',
        className
      )}
      {...props}
    >
      {children}
    </div>
  )
})
SelectTrigger.displayName = 'SelectTrigger'

const SelectValue: React.FC<{ placeholder?: string }> = ({ placeholder }) => {
  const ctx = React.useContext(SelectContext)
  const displayLabel = ctx.value !== undefined && ctx.value !== '' ? (ctx.getOptionLabel(String(ctx.value)) ?? ctx.value) : undefined
  return <span className="truncate block font-medium">{displayLabel || placeholder || 'Seleccione...'}</span>
}

const SelectContent: React.FC<React.HTMLAttributes<HTMLSelectElement>> = ({
  children,
  className,
}) => {
  const ctx = React.useContext(SelectContext)

  return (
    <select
      value={ctx.value || ''}
      onChange={(e) => ctx.onValueChange?.(e.target.value)}
      className={cn(
        'absolute inset-0 w-full h-full opacity-0 cursor-pointer text-xs bg-background text-foreground',
        className
      )}
    >
      {children}
    </select>
  )
}

const SelectItem = React.forwardRef<
  HTMLOptionElement,
  React.OptionHTMLAttributes<HTMLOptionElement> & { textValue?: string }
>(({ className, children, value, textValue, ...props }, ref) => {
  const ctx = React.useContext(SelectContext)

  const label = React.useMemo(() => {
    if (textValue) return textValue
    if (typeof children === 'string') return children
    if (typeof children === 'number') return String(children)
    if (Array.isArray(children)) {
      const extracted = children
        .map((child) => {
          if (typeof child === 'string' || typeof child === 'number') return child
          if (child && typeof child === 'object' && 'props' in child) {
            const childProps = (child as any).props
            if (childProps && childProps.children) {
              return typeof childProps.children === 'string' ? childProps.children : ''
            }
          }
          return ''
        })
        .join('')
      if (extracted.trim()) return extracted
    }
    return String(children ?? '')
  }, [children, textValue])

  React.useEffect(() => {
    if (value !== undefined && label) {
      ctx.registerOption(String(value), label)
    }
  }, [value, label, ctx])

  return (
    <option ref={ref} value={value} className={cn('text-foreground bg-background text-xs py-1', className)} {...props}>
      {label}
    </option>
  )
})
SelectItem.displayName = 'SelectItem'

export { Select, SelectTrigger, SelectValue, SelectContent, SelectItem }
