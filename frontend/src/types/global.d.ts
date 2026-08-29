declare global {
  interface Window {
    __APP_CONTEXT__?: {
      baseUrl?: string
      user?: {
        name?: string
        initials?: string
        roles?: string[]
      }
    }
  }
}

export {}

