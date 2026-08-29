import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { type PropsWithChildren, useState } from 'react'
import { Toaster } from 'sonner'
import { ThemeProvider } from './theme-provider'

export function AppProviders({ children }: PropsWithChildren) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            refetchOnWindowFocus: false,
            staleTime: 1000 * 30,
            retry: (failureCount, error) => {
              // Prevenir reintentos inútiles cuando la sesión expira
              if (
                error instanceof Error &&
                (error.message.includes('expirada') || error.message.includes('autorizada'))
              ) {
                return false
              }
              return failureCount < 2
            },
          },
          mutations: {
            retry: 0,
          },
        },
      }),
  )

  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        {children}
        <Toaster richColors position="top-right" closeButton duration={2000} />
      </QueryClientProvider>
    </ThemeProvider>
  )
}
