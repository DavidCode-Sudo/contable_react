import { test, expect } from '@playwright/test';

test.describe('Autenticación y Carga del Sistema Contable', () => {
  test('debe cargar la pantalla de Acceso al Sistema (Login)', async ({ page }) => {
    // Navegar a la raíz / login
    await page.goto('/');

    // Verificar que el título "ACCESO AL SISTEMA" o el formulario de Login esté visible
    const loginHeading = page.locator('h1', { hasText: 'ACCESO AL SISTEMA' });
    await expect(loginHeading).toBeVisible();

    // Verificar que los campos de usuario/correo y contraseña existan
    const emailInput = page.locator('input[type="email"]');
    const passwordInput = page.locator('input[type="password"]');

    await expect(emailInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
  });

  test('debe permitir escribir en el formulario de inicio de sesión', async ({ page }) => {
    await page.goto('/');

    const emailInput = page.locator('input[type="email"]');
    const passwordInput = page.locator('input[type="password"]');

    // Limpiar y llenar campos de prueba
    await emailInput.fill('admi.osmc@gmail.com');
    await passwordInput.fill('Simon1756*');

    await expect(emailInput).toHaveValue('admi.osmc@gmail.com');
    await expect(passwordInput).toHaveValue('Simon1756*');
  });
});
