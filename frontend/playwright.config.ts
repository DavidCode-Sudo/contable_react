import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright E2E Configuración - Simulación Manual Lenta
 */
export default defineConfig({
  testDir: './e2e',
  testMatch: '**/*.spec.ts',
  /* Ejecución estrictamente secuencial en 1 sola ventana */
  fullyParallel: false,
  workers: 1,
  
  /* Aumentar tiempo límite por prueba a 2 minutos para permitir simulaciones manuales lentas */
  timeout: 120 * 1000,
  
  reporter: 'html',
  
  use: {
    baseURL: 'http://localhost:5173',
    trace: 'on',
    screenshot: 'on',
  },

  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        launchOptions: {
          slowMo: 300, // 300 ms entre cada interacción (más rápido y fluido)
        },
      },
    },
  ],

  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173',
    reuseExistingServer: true,
    timeout: 120 * 1000,
  },
});
