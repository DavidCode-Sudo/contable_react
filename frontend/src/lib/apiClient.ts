declare global {
  interface Window {
    __APP_CONTEXT__?: {
      baseUrl?: string
    }
  }
}

const API_BASE =
  (import.meta.env?.VITE_API_BASE as string | undefined) ??
  (window.__APP_CONTEXT__?.baseUrl ?? '')

export interface ApiResponse<T = unknown> {
  success: boolean
  data?: T
  message?: string
  errors?: Record<string, string[]> | string[]
}

export function getApiUrl(endpoint: string): string {
  if (endpoint.startsWith('http')) return endpoint;
  const base = API_BASE.replace(/\/$/, '');
  const path = endpoint.replace(/^\//, '');
  return base ? `${base}/${path}` : `/${path}`;
}

/**
 * Cliente HTTP centralizado sobre fetch nativo.
 * Maneja credenciales, tipado genérico <T>, parseo estricto de JSON y manejo desacoplado de 401.
 */
export async function apiClient<T = unknown>(
  endpoint: string,
  options: RequestInit = {}
): Promise<T> {
  const url = getApiUrl(endpoint);

  const isFormData = options.body instanceof FormData

  const headersObj: Record<string, string> = {
    'Accept': 'application/json',
  }

  if (!isFormData) {
    headersObj['Content-Type'] = 'application/json'
  }

  if (options.headers) {
    Object.assign(headersObj, options.headers)
  }

  if (isFormData) {
    delete headersObj['Content-Type']
    delete headersObj['content-type']
  }

  const config: RequestInit = {
    ...options,
    credentials: 'include', // Imprescindible para enviar/recibir cookies de sesión PHP
    headers: headersObj,
  }

  let response: Response
  try {
    response = await fetch(url, config)
  } catch (error) {
    const message = error instanceof Error ? error.message : 'Error de conexión de red'
    console.error(`[apiClient] Error de red al intentar consultar ${url}:`, message)
    throw new Error(`Error de conexión a ${url}: ${message}`)
  }

  // Interceptar estado 401 Unauthorized y despachar evento global (excepto en chequeos de sesión iniciales)
  if (response.status === 401) {
    const isCheckAuthEndpoint = endpoint.includes('usuarios/me.php') || (options as any)?.skipAuthRedirect
    if (!isCheckAuthEndpoint) {
      console.warn('[apiClient] Petición no autorizada (401). Despachando auth:unauthorized.')
      window.dispatchEvent(new CustomEvent('auth:unauthorized'))
    }

    let errorMsg = 'Sesión expirada o no autorizada'
    try {
      const payload = (await response.json()) as ApiResponse
      if (payload && payload.message) {
        errorMsg = payload.message
      }
    } catch {
      // Ignorar
    }
    throw new Error(errorMsg)
  }

  // Clone de respuesta para capturar el texto bruto si falla el parseo JSON
  const responseClone = response.clone()
  let payload: ApiResponse<T> | null = null

  try {
    payload = (await response.json()) as ApiResponse<T>
  } catch (parseError) {
    const rawText = await responseClone.text()
    console.error(`[apiClient] Error al parsear JSON recibido de ${url}:`, parseError)
    console.error(`[apiClient] Respuesta RAW recibida del servidor:\n`, rawText)
    throw new Error('El servidor no devolvió una respuesta JSON válida.')
  }

  if (!response.ok || (payload && payload.success === false)) {
    const message =
      payload?.message ||
      `Error ${response.status}: ${response.statusText}`
    throw new Error(message)
  }

  // Extraer el objeto 'data' solo si existe y NO es null ni undefined
  if (
    payload &&
    typeof payload === 'object' &&
    'data' in payload &&
    payload.data !== null &&
    payload.data !== undefined
  ) {
    return payload.data
  }

  return payload as T
}
