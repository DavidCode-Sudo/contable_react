import { useState, useMemo } from 'react'
import {
  Bell,
  ChevronRight,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  Search,
  UserRound,
  X,
} from 'lucide-react'
import {
  Link,
  NavLink,
  Outlet,
  useLocation,
  useMatches,
  type UIMatch,
} from 'react-router-dom'

import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip'
import { navigationSections } from '@/config/navigation'
import { ThemeToggle } from '@/components/theme/theme-toggle'
import { useAuth } from '@/context/AuthContext'

type RouteHandle = {
  title: string
  description?: string
}

export function AppShell() {
  const [isOpen, setIsOpen] = useState(true)
  const [mobileOpen, setMobileOpen] = useState(false)
  const [showMobileSearch, setShowMobileSearch] = useState(false)

  const matches = useMatches() as Array<UIMatch<unknown, RouteHandle>>
  const location = useLocation()
  const { user, logout } = useAuth()

  const name = user?.nombre ?? 'Usuario'
  const initials = name.slice(0, 2).toUpperCase()

  // Formato seguro para roles evitando [object Object]
  const rolesText = useMemo(() => {
    if (!user) return 'Usuario'
    if (typeof user.rol === 'string' && user.rol.trim()) return user.rol
    if (Array.isArray((user as unknown as { roles?: unknown[] }).roles)) {
      const list = (user as unknown as { roles: unknown[] }).roles
        .map((r) => (typeof r === 'string' ? r : (r as { nombre?: string; name?: string })?.nombre || (r as { name?: string })?.name || ''))
        .filter(Boolean)
      if (list.length > 0) return list.join(', ')
    }
    return 'Administrador'
  }, [user])

  const breadcrumbs = useMemo(() => {
    const crumbs = matches
      .map((match) => {
        const handle = match.handle
        if (!handle?.title || !match.pathname) return null
        return {
          label: handle.title,
          path: match.pathname,
        }
      })
      .filter(Boolean) as Array<{ label: string; path: string }>

    return crumbs.length > 0
      ? crumbs
      : [
          {
            label: 'Dashboard',
            path: '/dashboard',
          },
        ]
  }, [matches])

  const toggleSidebar = () => setIsOpen((prev) => !prev)

  return (
    <TooltipProvider delayDuration={100}>
      {/* CONTENEDOR RAÍZ FLEXBOX */}
      <div className="flex h-screen w-full overflow-hidden bg-background antialiased text-foreground">
        
        {/* SIDEBAR HERMANO ANCLADO EN DESKTOP */}
        <aside
          className={`relative hidden flex-shrink-0 border-r border-border/60 bg-sidebar transition-all duration-300 ease-in-out md:flex flex-col ${
            isOpen ? 'w-64' : 'w-20'
          }`}
        >
          {/* Header del Sidebar */}
          <div className="flex h-16 flex-shrink-0 items-center justify-between border-b border-sidebar-border/60 px-4">
            <Link to="/dashboard" className="flex items-center gap-3 overflow-hidden">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary text-sm font-bold text-primary-foreground shadow-sm">
                SC
              </div>
              {isOpen && (
                <div className="flex flex-col truncate transition-opacity duration-200">
                  <span className="truncate text-sm font-bold tracking-tight text-sidebar-foreground">
                    Sistema Contable
                  </span>
                  <span className="truncate text-[11px] font-medium text-muted-foreground">
                    ERP Corporativo
                  </span>
                </div>
              )}
            </Link>
          </div>

          {/* Ficha de Usuario Activo */}
          <div className="p-3">
            <div className={`rounded-xl border border-sidebar-border/70 bg-sidebar-accent/50 p-2.5 shadow-xs flex items-center ${isOpen ? 'gap-3' : 'justify-center'}`}>
              <Avatar className="h-9 w-9 shrink-0 border border-border/40">
                <AvatarFallback className="bg-primary/10 text-primary font-bold text-xs">
                  {initials}
                </AvatarFallback>
              </Avatar>
              {isOpen && (
                <div className="flex flex-col truncate">
                  <span className="truncate text-xs font-bold text-sidebar-foreground">{name}</span>
                  <span className="truncate text-[11px] text-muted-foreground">{rolesText}</span>
                </div>
              )}
            </div>
          </div>

          {/* Menú de Navegación por Secciones */}
          <ScrollArea className="flex-1 px-3">
            <div className="flex flex-col gap-4 py-2 pb-6">
              {navigationSections.map((section) => (
                <div key={section.id} className="flex flex-col gap-1">
                  {isOpen ? (
                    <div className="flex items-center px-2 py-1 text-[10px] font-bold uppercase tracking-wider text-muted-foreground/70">
                      <section.icon className="mr-2 size-3.5" />
                      <span className="truncate">{section.label}</span>
                    </div>
                  ) : (
                    <div className="h-px bg-border/40 my-1" />
                  )}
                  {section.items.map((item) => {
                    const isActive = item.path === '/' ? location.pathname === '/' : location.pathname.startsWith(item.path)

                    const buttonContent = (
                      <NavLink
                        to={item.path}
                        className={`flex items-center gap-3 rounded-lg px-3 py-2 text-xs font-medium transition-colors ${
                          isActive
                            ? 'bg-primary text-primary-foreground font-semibold shadow-xs'
                            : 'text-sidebar-foreground hover:bg-sidebar-accent/70 hover:text-sidebar-accent-foreground'
                        } ${!isOpen ? 'justify-center px-0' : ''}`}
                      >
                        <item.icon className="size-4 shrink-0" />
                        {isOpen && <span className="truncate flex-1">{item.label}</span>}
                        {isOpen && item.badge ? (
                          <Badge className="bg-primary-foreground/20 text-primary-foreground text-[10px] px-1.5 py-0.5">
                            {item.badge}
                          </Badge>
                        ) : null}
                      </NavLink>
                    )

                    return !isOpen ? (
                      <Tooltip key={item.id}>
                        <TooltipTrigger asChild>{buttonContent}</TooltipTrigger>
                        <TooltipContent side="right" className="font-semibold text-xs">
                          {item.label}
                        </TooltipContent>
                      </Tooltip>
                    ) : (
                      <div key={item.id}>{buttonContent}</div>
                    )
                  })}
                </div>
              ))}
            </div>
          </ScrollArea>

          {/* Footer con Botón de Logout */}
          <div className="border-t border-sidebar-border/60 p-3">
            <Button
              variant="ghost"
              size="sm"
              className={`w-full gap-2.5 text-xs font-semibold text-destructive hover:bg-destructive/10 hover:text-destructive transition-colors ${
                isOpen ? 'justify-start' : 'justify-center px-0'
              }`}
              onClick={logout}
            >
              <LogOut className="size-4 shrink-0" />
              {isOpen && <span>Cerrar sesión</span>}
            </Button>
          </div>
        </aside>

        {/* SIDEBAR MÓVIL */}
        {mobileOpen && (
          <div className="fixed inset-0 z-50 flex md:hidden">
            <div className="fixed inset-0 bg-black/60 backdrop-blur-xs" onClick={() => setMobileOpen(false)} />
            <aside className="relative flex w-72 flex-col bg-sidebar border-r border-sidebar-border z-50">
              <div className="flex h-16 items-center justify-between border-b px-4">
                <span className="font-bold text-sidebar-foreground text-sm">Sistema Contable Pro</span>
                <Button size="icon" variant="ghost" onClick={() => setMobileOpen(false)}>
                  <PanelLeftClose className="size-5" />
                </Button>
              </div>
              <ScrollArea className="flex-1 p-4">
                {navigationSections.map((section) => (
                  <div key={section.id} className="mb-4">
                    <div className="text-[11px] font-bold uppercase text-muted-foreground mb-2 flex items-center gap-2">
                      <section.icon className="size-3.5" />
                      {section.label}
                    </div>
                    {section.items.map((item) => (
                      <NavLink
                        key={item.id}
                        to={item.path}
                        onClick={() => setMobileOpen(false)}
                        className="flex items-center gap-3 px-3 py-2 text-xs rounded-lg font-medium hover:bg-sidebar-accent mb-1"
                      >
                        <item.icon className="size-4" />
                        {item.label}
                      </NavLink>
                    ))}
                  </div>
                ))}
              </ScrollArea>
            </aside>
          </div>
        )}

        {/* ÁREA DE CONTENIDO HERMANA (Header + Main) */}
        <div className="flex flex-col flex-1 min-w-0 h-screen overflow-hidden bg-background">
          
          {/* BARRA SUPERIOR (HEADER RESPONSIVO) */}
          <header className="flex h-16 flex-shrink-0 items-center justify-between border-b border-border/60 bg-background/95 px-4 sm:px-6 backdrop-blur supports-[backdrop-filter]:bg-background/80">
            {showMobileSearch ? (
              /* MODO BUSCADOR DESPLEGADO EN MÓVIL */
              <div className="flex flex-1 items-center gap-2 sm:hidden animate-in fade-in duration-200">
                <div className="flex flex-1 items-center gap-2 rounded-lg border border-primary bg-background px-3 py-1.5 text-xs shadow-xs">
                  <Search className="size-3.5 shrink-0 text-primary" />
                  <input
                    type="text"
                    autoFocus
                    placeholder="Buscar módulos, partidas..."
                    className="w-full bg-transparent text-xs text-foreground placeholder:text-muted-foreground outline-none"
                  />
                </div>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8 shrink-0"
                  onClick={() => setShowMobileSearch(false)}
                >
                  <X className="size-4 text-muted-foreground" />
                </Button>
              </div>
            ) : (
              /* ESTADO NORMAL DEL HEADER */
              <>
                <div className="flex items-center gap-2 sm:gap-3">
                  <Button
                    variant="outline"
                    size="icon"
                    className="hidden md:flex h-9 w-9 border-border/60 shadow-xs"
                    onClick={toggleSidebar}
                    aria-label="Alternar menú lateral"
                  >
                    {isOpen ? <PanelLeftClose className="size-4" /> : <PanelLeftOpen className="size-4" />}
                  </Button>

                  <Button
                    variant="outline"
                    size="icon"
                    className="flex md:hidden h-9 w-9 border-border/60 shadow-xs"
                    onClick={() => setMobileOpen(true)}
                  >
                    <Menu className="size-4" />
                  </Button>

                  <div className="hidden items-center gap-2 md:flex text-xs text-muted-foreground font-medium">
                    {breadcrumbs.map((crumb, index) => (
                      <div key={crumb.path} className="flex items-center gap-2">
                        {index > 0 && <ChevronRight className="size-3 text-muted-foreground/60" />}
                        {index === breadcrumbs.length - 1 ? (
                          <span className="font-bold text-foreground">{crumb.label}</span>
                        ) : (
                          <Link to={crumb.path} className="hover:text-foreground transition-colors">
                            {crumb.label}
                          </Link>
                        )}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Buscador Interactivo en Escritorio (hidden sm:flex) */}
                <div className="hidden sm:flex flex-1 items-center justify-center px-4 max-w-md">
                  <div className="flex w-full items-center justify-between gap-2 rounded-lg border border-border/60 bg-muted/40 px-3 py-1.5 text-xs text-muted-foreground shadow-xs transition-colors focus-within:border-primary focus-within:bg-background">
                    <div className="flex items-center gap-2 flex-1 min-w-0">
                      <Search className="size-3.5 shrink-0 text-muted-foreground" />
                      <input
                        type="text"
                        placeholder="Buscar módulos, partidas o comprobantes…"
                        className="w-full bg-transparent text-xs text-foreground placeholder:text-muted-foreground outline-none"
                      />
                    </div>
                    <kbd className="hidden lg:inline-flex items-center gap-0.5 rounded border border-border bg-muted px-1.5 text-[10px] font-semibold text-muted-foreground">
                      ⌘K
                    </kbd>
                  </div>
                </div>

                {/* Acciones del Header */}
                <div className="flex items-center gap-1.5 sm:gap-2">
                  {/* Botón Lupa en Móvil (flex sm:hidden) */}
                  <Button
                    variant="outline"
                    size="icon"
                    className="flex sm:hidden h-9 w-9 border-border/60 shadow-xs"
                    onClick={() => setShowMobileSearch(true)}
                    aria-label="Buscar"
                  >
                    <Search className="size-4 text-muted-foreground" />
                  </Button>

                  <Button
                    variant="outline"
                    size="icon"
                    className="relative h-9 w-9 border-border/60 shadow-xs"
                    aria-label="Ver notificaciones"
                  >
                    <Bell className="size-4 text-muted-foreground" />
                    <Badge className="absolute -right-1 -top-1 h-4 min-w-4 px-1 text-[9px] bg-primary text-primary-foreground">
                      0
                    </Badge>
                  </Button>
                  <ThemeToggle />
                  <Button
                    variant="outline"
                    size="icon"
                    className="hidden h-9 w-9 border-border/60 shadow-xs md:inline-flex"
                    aria-label="Perfil de usuario"
                  >
                    <UserRound className="size-4 text-muted-foreground" />
                  </Button>
                </div>
              </>
            )}
          </header>

          {/* VISTA DE CONTENIDO CON SCROLL AISLADO */}
          <div className="flex flex-col flex-1 overflow-hidden bg-slate-50/60 dark:bg-slate-950/40">
            <main className="flex-1 overflow-y-auto p-4 sm:p-6">
              <div className="mx-auto w-full max-w-7xl space-y-6">
                <Outlet />
              </div>
            </main>
          </div>
        </div>
      </div>
    </TooltipProvider>
  )
}
