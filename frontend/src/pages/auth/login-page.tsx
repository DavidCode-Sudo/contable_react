import { useState, type FormEvent } from 'react'
import { useLogin } from '@/hooks/use-login'
import { AlertCircle, Eye, EyeOff, LogIn, ShieldCheck } from 'lucide-react'

export default function LoginPage() {
  const [correo, setCorreo] = useState('admi.osmc@gmail.com')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)

  const { login, isLoading, isError, error } = useLogin()

  const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!correo.trim() || !password) return
    login({ correo: correo.trim(), password })
  }

  return (
    <div className="min-h-screen w-full bg-[#0a0a0c] text-white flex items-center justify-center p-4 relative overflow-hidden select-none font-sans">
      
      {/* ── Glow de Fondo & Profundidad ── */}
      <div className="absolute inset-0 bg-[radial-gradient(ellipse_at_center,_var(--tw-gradient-stops))] from-zinc-800/25 via-[#0a0a0c]/90 to-[#0a0a0c] pointer-events-none" />
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[550px] h-[550px] bg-white/[0.02] rounded-full blur-3xl pointer-events-none" />

      {/* ── Tarjeta Principal Glassmorphism Dark ── */}
      <div className="relative z-10 w-full max-w-[410px] bg-[#141416]/95 border border-white/10 rounded-2xl shadow-2xl shadow-black/90 backdrop-blur-2xl p-7 sm:p-9 space-y-6">

        {/* Línea luminosa superior */}
        <div className="absolute -top-px left-12 right-12 h-px bg-gradient-to-r from-transparent via-white/30 to-transparent" />

        {/* ── 1. Branding: Logo Oficial OSMC ── */}
        <div className="flex flex-col items-center justify-center pt-1 space-y-4 text-center">
          
          {/* Logo vectorial Orquesta Sinfónica Municipal de Caracas */}
          <div className="flex items-center justify-center gap-3 py-1">
            <svg className="h-10 w-auto text-white fill-current shrink-0" viewBox="0 0 160 50">
              {/* Isotipo OSMC */}
              <path d="M22 10C14.268 10 8 16.268 8 24c0 7.732 6.268 14 14 14 5.253 0 9.805-2.887 12.234-7.168L29.35 28.18C27.91 30.45 25.13 32 22 32c-4.418 0-8-3.582-8-8s3.582-8 8-8c3.13 0 5.91 1.55 7.35 3.82l4.884-2.652C31.805 12.887 27.253 10 22 10z" />
              <circle cx="22" cy="24" r="3.5" />
              <path d="M38 12h5.5l7 14.5 7-14.5H63v24h-5.5V20.5L50.5 35h-2L41.5 20.5V36H38V12z" />
              <path d="M66 30c0 3.5 2.5 6 6.5 6s6.5-2.5 6.5-6v-2h-5.5v2c0 1-.8 1.8-1.8 1.8s-1.8-.8-1.8-1.8v-12c0-1 .8-1.8 1.8-1.8s1.8.8 1.8 1.8v2H79v-2c0-3.5-2.5-6-6.5-6S66 14.5 66 18v12z" />
            </svg>
            <div className="h-8 w-px bg-white/20" />
            <div className="text-left leading-none space-y-0.5">
              <span className="text-[12px] font-black tracking-wider text-white uppercase block">
                Orquesta Sinfónica
              </span>
              <span className="text-[9.5px] font-bold tracking-widest text-zinc-400 uppercase block">
                Municipal de Caracas
              </span>
            </div>
          </div>

          {/* Divisor de acento */}
          <div className="w-20 h-px bg-gradient-to-r from-transparent via-white/20 to-transparent" />

          {/* Títulos */}
          <div className="space-y-1 pt-1">
            <h1 className="text-lg sm:text-xl font-bold tracking-[0.15em] text-white uppercase leading-tight">
              ACCESO AL SISTEMA
            </h1>
            <p className="text-[10.5px] font-semibold tracking-[0.2em] text-zinc-400 uppercase">
              ACCESO CORPORATIVO SEGURO
            </p>
          </div>
        </div>

        {/* ── 2. Notificación de Error ── */}
        {isError && (
          <div className="flex items-start gap-2.5 p-3.5 rounded-xl bg-rose-950/70 border border-rose-800/60 text-rose-200 text-xs animate-in fade-in slide-in-from-top-2 duration-200">
            <AlertCircle className="size-4 mt-0.5 shrink-0 text-rose-400" />
            <p className="leading-relaxed font-medium">
              {error instanceof Error ? error.message : 'Credenciales incorrectas. Verifique e intente nuevamente.'}
            </p>
          </div>
        )}

        {/* ── 3. Formulario ── */}
        <form onSubmit={handleSubmit} className="space-y-5">
          
          {/* Correo Electrónico */}
          <div className="space-y-1.5">
            <label htmlFor="correo" className="text-xs font-semibold text-zinc-300 block tracking-wide">
              Correo Electrónico
            </label>
            <div className="relative">
              <input
                id="correo"
                type="email"
                placeholder="admi.osmc@gmail.com"
                value={correo}
                onChange={(e) => setCorreo(e.target.value)}
                required
                disabled={isLoading}
                autoComplete="email"
                className="
                  w-full h-11 px-4 rounded-xl text-sm font-medium
                  bg-[#222226] border border-[#33333a]
                  text-white placeholder:text-zinc-500
                  focus:outline-none focus:border-zinc-400 focus:ring-1 focus:ring-zinc-400
                  disabled:opacity-50 transition-all duration-200 shadow-inner
                "
              />
            </div>
          </div>

          {/* Contraseña */}
          <div className="space-y-1.5">
            <label htmlFor="password" className="text-xs font-semibold text-zinc-300 block tracking-wide">
              Contraseña
            </label>
            <div className="relative">
              <input
                id="password"
                type={showPassword ? 'text' : 'password'}
                placeholder="••••••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                required
                disabled={isLoading}
                autoComplete="current-password"
                className="
                  w-full h-11 pl-4 pr-11 rounded-xl text-sm font-medium
                  bg-[#222226] border border-[#33333a]
                  text-white placeholder:text-zinc-500
                  focus:outline-none focus:border-zinc-400 focus:ring-1 focus:ring-zinc-400
                  disabled:opacity-50 transition-all duration-200 shadow-inner
                "
              />
              <button
                type="button"
                tabIndex={-1}
                onClick={() => setShowPassword(!showPassword)}
                className="absolute right-0 top-0 h-11 w-11 flex items-center justify-center text-zinc-400 hover:text-white transition-colors"
                title={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              >
                {showPassword ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
              </button>
            </div>
          </div>

          {/* Botón Acceder */}
          <div className="pt-2">
            <button
              type="submit"
              disabled={isLoading}
              className="
                w-full h-11 rounded-xl text-xs font-bold uppercase tracking-wider
                bg-[#2a2a30] hover:bg-[#36363e] active:bg-[#202024]
                border border-[#42424d] text-white shadow-lg shadow-black/40
                disabled:opacity-50 disabled:cursor-not-allowed
                transition-all duration-200
                focus:outline-none focus:ring-2 focus:ring-zinc-400 focus:ring-offset-2 focus:ring-offset-[#141416]
                flex items-center justify-center gap-2.5 group cursor-pointer
              "
            >
              {isLoading ? (
                <>
                  <span className="size-4 rounded-full border-2 border-white/30 border-t-white animate-spin" />
                  <span>Verificando...</span>
                </>
              ) : (
                <>
                  <LogIn className="size-4 text-zinc-300 group-hover:text-white transition-colors" />
                  <span>Acceder</span>
                </>
              )}
            </button>
          </div>
        </form>

        {/* ── 4. Divisor de Tarjeta ── */}
        <div className="w-full h-px bg-white/10" />

        {/* ── 5. Badge de Seguridad ── */}
        <div className="flex items-center justify-center gap-2 pt-1 text-[11px] font-bold tracking-widest text-emerald-400 uppercase">
          <ShieldCheck className="size-4 text-emerald-400 shrink-0" />
          <span>Conexión Segura y Cifrada</span>
        </div>

      </div>
    </div>
  )
}