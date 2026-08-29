# Sistema de Notificaciones para Tasa de Cambio

## Descripción
Sistema de notificaciones push que alerta sobre la necesidad de actualizar la tasa de cambio diariamente.

## Características
- Campanita de notificaciones en el encabezado
- Badge con contador de notificaciones pendientes
- Modal con lista de notificaciones
- Verificación automática de tasas de cambio
- Notificaciones push del navegador (con permiso del usuario)
- Actualización automática cada 5 minutos

## Instalación

### 1. Crear tabla de notificaciones
Ejecutar el script SQL:
```bash
mysql -u root -p sistema_contable < database/scripts/crear_tabla_notificaciones.sql
```

### 2. Verificar archivos
Los siguientes archivos ya están creados:
- `includes/header_sistema.php` - Campanita y modal de notificaciones
- `includes/ajax_notificaciones.php` - Endpoint AJAX para manejar notificaciones
- `database/scripts/crear_tabla_notificaciones.sql` - Script de creación de tabla

### 3. Permisos de notificaciones
El navegador solicitará permisos para mostrar notificaciones push. El usuario debe aceptar.

## Funcionamiento

### Verificación de tasa de cambio
- Al cargar cualquier página, se verifica si existe una tasa de cambio para el día actual
- Si no existe, se crea automáticamente una notificación de tipo "warning"
- La notificación solo se crea una vez por día

### Recordatorios
- **8:00 AM**: Al cargar la primera página después de las 8 AM
- **1:00 PM**: Al cargar la primera página después de la 1 PM
- Estas verificaciones se realizan automáticamente cuando el usuario abre el sistema

### Notificaciones Push
Si el usuario aceptó los permisos:
- Se muestra una notificación del navegador
- Incluye icono del sistema
- El mensaje recuerda actualizar la tasa de cambio

## Uso

### Ver notificaciones
1. Hacer clic en la campanita del encabezado
2. Se abrirá un modal con las notificaciones
3. Las notificaciones no leídas aparecen en negrita

### Marcar como leída
- Clic en la "X" de cada notificación
- O usar el botón "Marcar todas como leídas"

### Badge de notificaciones
- El número rojo muestra cuántas notificaciones no leídas hay
- Se actualiza automáticamente cada 5 minutos
- Desaparece cuando no hay notificaciones pendientes

## Personalización

### Cambiar horarios de recordatorio
Editar `includes/ajax_notificaciones.php`:
```php
// Agregar lógica para verificar hora específica
$hora_actual = date('H');
if ($hora_actual >= 8 && $hora_actual < 9) {
    // Lógica de recordatorio 8 AM
}
if ($hora_actual >= 13 && $hora_actual < 14) {
    // Lógica de recordatorio 1 PM
}
```

### Modificar mensajes
Editar `includes/ajax_notificaciones.php`:
```php
$stmt = $conn->prepare("
    INSERT INTO notificaciones (usuario_id, titulo, mensaje, tipo, leida, created_at)
    VALUES (?, 'Tu Título', 'Tu mensaje personalizado.', 'warning', 0, NOW())
");
```

### Cambiar frecuencia de actualización
Editar `includes/header_sistema.php`:
```javascript
setInterval(function() {
    cargarNotificacionesCount();
}, 300000); // Cambiar 300000 (5 min) por el tiempo deseado en milisegundos
```

## Estructura de la base de datos

### Tabla: notificaciones
```sql
- id: ID único
- usuario_id: Usuario que recibe la notificación
- titulo: Título de la notificación
- mensaje: Mensaje completo
- tipo: info, warning, danger, success
- leida: 0 o 1
- created_at: Fecha de creación
- read_at: Fecha de lectura (nullable)
```

## Notas
- Las notificaciones push solo funcionan en navegadores modernos (Chrome, Firefox, Edge)
- Requiere conexión a internet para funcionar
- Las notificaciones se almacenan en la base de datos
- Se mantienen hasta que se marquen como leídas o se borren manualmente
