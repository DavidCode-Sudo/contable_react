import { MoonStar, Sun } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { useTheme } from '@/app/providers/theme-provider'

export function ThemeToggle() {
  const { theme, toggleTheme } = useTheme()

  return (
    <Button
      variant="outline"
      size="icon"
      className="h-9 w-9"
      onClick={toggleTheme}
      aria-label={
        theme === 'dark'
          ? 'Cambiar a modo claro'
          : 'Cambiar a modo oscuro'
      }
    >
      {theme === 'dark' ? (
        <MoonStar className="size-4" />
      ) : (
        <Sun className="size-4" />
      )}
    </Button>
  )
}

