import { useMutation } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { apiClient } from '@/lib/apiClient'
import { useAuth, type User } from '@/context/AuthContext'
import { toast } from 'sonner'

export interface LoginPayload {
  correo: string
  password: string
}

export interface LoginResponse {
  id: number
  nombre: string
  correo: string
}

/**
 * Custom Hook para gestionar el inicio de sesión de forma segura y tipada.
 */
export function useLogin() {
  const navigate = useNavigate()
  const { setUser } = useAuth()

  const mutation = useMutation({
    mutationFn: async (credentials: LoginPayload): Promise<LoginResponse> => {
      return apiClient<LoginResponse>('api/auth/login.php', {
        method: 'POST',
        body: JSON.stringify(credentials),
      })
    },
    onSuccess: (data) => {
      if (!data || typeof data !== 'object') {
        toast.error('Respuesta no válida del servidor de autenticación.')
        return
      }

      const loggedUser: User = {
        id: data.id ?? 0,
        nombre: data.nombre ?? 'Usuario',
        email: data.correo ?? '',
      }

      setUser(loggedUser)
      toast.success(`¡Bienvenido de nuevo, ${loggedUser.nombre}!`)
      navigate('/dashboard', { replace: true })
    },
    onError: (_error: Error) => {
      // El mensaje de error ya se muestra de forma elegante dentro de la tarjeta del formulario
    },
  })

  return {
    login: mutation.mutate,
    isLoading: mutation.isPending,
    isError: mutation.isError,
    error: mutation.error,
  }
}
