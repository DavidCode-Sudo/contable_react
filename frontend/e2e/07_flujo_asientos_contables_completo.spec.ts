import { test, expect } from '@playwright/test';

test.describe('Módulo de Asientos Contables, Libros Oficiales y Cierre Fiscal', () => {
  test.beforeEach(async ({ page }) => {
    // 1. Iniciar sesión institucional
    await page.goto('http://localhost:5173/login');
    await page.fill('input[type="email"], input[name="email"], input[name="username"]', 'admin@admin.com');
    await page.fill('input[type="password"]', 'admin123');
    await page.click('button[type="submit"]');

    // Esperar redirección al Dashboard
    await page.waitForURL('**/dashboard', { timeout: 15000 });
  });

  test('Flujo E2E Completo: Creación en Borrador, Confirmación Legal AS-YYYY, Libro Diario, Mayor O(1) y Anulación con Reversión', async ({ page }) => {
    console.log('--- ETAPA 1: Navegación al Libro Diario de Asientos Contables ---');
    await page.goto('http://localhost:5173/contabilidad/asientos');
    await page.waitForLoadState('networkidle');

    expect(page.url()).toContain('/contabilidad/asientos');
    await expect(page.locator('h1')).toContainText('Libro Diario y Asientos Contables');

    console.log('--- ETAPA 2: Apertura de Modal y Registro de Comprobante en Borrador ---');
    await page.click('button:has-text("Nuevo Comprobante")');
    await expect(page.locator('[role="dialog"]')).toBeVisible();

    const conceptoTest = `Asiento de prueba E2E Playwright - ${Date.now()}`;
    await page.fill('#concepto', conceptoTest);

    // Rellenar montos en partida doble cuadrada de 5.000,00 VES
    const inputsDebe = page.locator('input.text-emerald-600');
    const inputsHaber = page.locator('input.text-blue-600');

    await inputsDebe.nth(0).fill('5000.00');
    await inputsHaber.nth(1).fill('5000.00');

    // Verificar badge CUADRADO
    await expect(page.locator('text=🟩 CUADRADO')).toBeVisible();

    // Guardar borrador
    await page.click('[role="dialog"] button[type="submit"]');
    await page.waitForLoadState('networkidle');

    console.log('--- ETAPA 3: Verificación del Borrador TMP-YYYY-XXXXXX ---');
    await expect(page.locator(`td:has-text("${conceptoTest}")`)).toBeVisible();
    await expect(page.locator('tr', { hasText: conceptoTest }).locator('text=BORRADOR')).toBeVisible();

    console.log('--- ETAPA 4: Confirmación del Comprobante y Estampa de Secuencia Oficial ---');
    page.on('dialog', (dialog) => dialog.accept());
    
    const responseConfirmarPromise = page.waitForResponse(
      (res) => res.url().includes('/confirmar') && res.status() === 200
    );
    await page.locator('tr', { hasText: conceptoTest }).locator('button:has-text("Confirmar")').click();
    await responseConfirmarPromise;

    // Verificar que el estado cambió a CONFIRMADO
    await expect(page.locator('tr', { hasText: conceptoTest }).locator('text=CONFIRMADO')).toBeVisible();
    
    // Verificar que el correlativo oficial AS-YYYY-XXXXXX fue estampado
    const numeroOficial = await page.locator('tr', { hasText: conceptoTest }).locator('td').first().textContent();
    console.log(`Correlativo Oficial Estampado: ${numeroOficial}`);
    expect(numeroOficial).toMatch(/^AS-\d{4}-\d{6}$/);

    console.log('--- ETAPA 5: Verificación en el Libro Diario Oficial ---');
    await page.goto('http://localhost:5173/contabilidad/libro-diario');
    await page.waitForLoadState('networkidle');

    await expect(page.locator(`text=${numeroOficial}`)).toBeVisible();
    await expect(page.locator(`text=${conceptoTest}`)).toBeVisible();

    console.log('--- ETAPA 6: Verificación en la Tarjeta de Libro Mayor O(1) ---');
    await page.goto('http://localhost:5173/contabilidad/libro-mayor');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('h1')).toContainText('Libro Mayor');

    console.log('--- ETAPA 7: Anulación de Comprobante con Contra-Asiento de Reversión ---');
    await page.goto('http://localhost:5173/contabilidad/asientos');
    await page.waitForLoadState('networkidle');

    await page.locator('tr', { hasText: conceptoTest }).locator('button:has-text("Anular")').click();
    await expect(page.locator('text=Anular Comprobante')).toBeVisible();

    const responseAnularPromise = page.waitForResponse(
      (res) => res.url().includes('/anular') && res.status() === 200
    );
    await page.click('button:has-text("Confirmar Anulación")');
    await responseAnularPromise;

    // Verificar que el comprobante original pasó a ANULADO
    await expect(page.locator('tr', { hasText: conceptoTest }).locator('text=ANULADO')).toBeVisible();

    // Verificar que se generó el contra-asiento automático de reversión
    await expect(page.locator(`text=Reversión contable automática de comprobante N° ${numeroOficial}`)).toBeVisible();

    console.log('✅ SUITE E2E COMPLETA FINALIZADA CON ÉXITO ABSOLUTO');
  });
});
