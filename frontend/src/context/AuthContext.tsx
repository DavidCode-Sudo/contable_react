import { createContext, useContext, useState, useEffect, useCallback, type ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { apiClient } from '@/lib/apiClient'
import { toast } from 'sonner'

export interface User {
  id: number
  nombre: string
  email?: string
  rol?: string
}

interface AuthContextType {
  user: User | null
  setUser: (user: User | null) => void
  isAuthenticated: boolean
  isLoading: boolean
  logout: () => Promise<void>
  handleUnauthorized: () => void
}

const AuthContext = createContext<AuthContextType | undefined>(undefined)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [isLoading, setIsLoading] = useState<boolean>(true)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  // Envuelto en useCallback para garantizar referencia de memoria estable
  const handleUnauthorized = useCallback(() => {
    setUser(null)
    queryClient.clear()
    navigate('/login', { replace: true })
  }, [navigate, queryClient])

  // ESCUCHA GLOBAL DE ERRORES 401: Desacoplado vía Eventos del DOM (CustomEvent)
  useEffect(() => {
    const listener401 = () => handleUnauthorized()

    window.addEventListener('auth:unauthorized', listener401)

    return () => {
      window.removeEventListener('auth:unauthorized', listener401)
    }
  }, [handleUnauthorized])

  // PREVENCIÓN DE NAVEGACIÓN ATRÁS (bfcache & popstate defense post-logout)
  useEffect(() => {
    const handlePageShow = (event: PageTransitionEvent) => {
      if (event.persisted && !user) {
        window.location.replace('/login')
      }
    }

    const handlePopState = () => {
      if (!user && !isLoading && window.location.pathname !== '/login') {
        navigate('/login', { replace: true })
      }
    }

    window.addEventListener('pageshow', handlePageShow)
    window.addEventListener('popstate', handlePopState)

    return () => {
      window.removeEventListener('pageshow', handlePageShow)
      window.removeEventListener('popstate', handlePopState)
    }
  }, [user, isLoading, navigate])

  // Verificar la sesión activa al cargar la app
  useEffect(() => {
    async function checkAuth() {
      try {
        const userData = await apiClient<User>('api/usuarios/me.php')
        setUser(userData)
      } catch {
        setUser(null)
      } finally {
        setIsLoading(false)
      }
    }
    checkAuth()
  }, [])

  const logout = useCallback(async () => {
    try {
      await apiClient('api/auth/logout.php', { method: 'POST' })
      toast.success('Sesión cerrada exitosamente')
    } catch (error) {
      console.warn('Error durante el cierre de sesión:', error)
      toast.success('Sesión cerrada exitosamente')
    } finally {
      setUser(null)
      queryClient.clear()
      // Reemplaza el historial de navegación para impedir 'Volver Atrás'
      window.history.replaceState(null, '', '/login')
      navigate('/login', { replace: true })
    }
  }, [navigate, queryClient])

  return (
    <AuthContext.Provider
      value={{
        user,
        setUser,
        isAuthenticated: !!user,
        isLoading,
        logout,
        handleUnauthorized,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth debe ser utilizado dentro de un AuthProvider')
  }
  return context
}
