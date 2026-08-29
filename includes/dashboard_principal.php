<?php
require_once __DIR__ . '/../includes/verificar_sesion.php';
require_once __DIR__ . '/../includes/funciones_contables.php';
require_once __DIR__ . '/../config/config.php';

$page_title = 'Dashboard';
// Restringir acceso por permisos
verificarPermisoRedirigir('dashboard', 'ver');
require_once __DIR__ . '/../includes/header_sistema.php';

// Mostrar mensajes de error
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'sin_permisos':
            echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle"></i> No tiene permisos para acceder a esa sección.
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                  </div>';
            break;
    }
}

// Obtener estadísticas
$conn = getConnection();
$usuario_id = $_SESSION['usuario_id'] ?? 0;

// Obtener roles del usuario
$roles_usuario = obtenerRolesUsuario($usuario_id);
$roles_nombres = array_column($roles_usuario, 'nombre');

// Variables para controlar qué mostrar
$mostrar_requisiciones_registro = false;
$mostrar_requisiciones_presupuesto = false;
$mostrar_ordenes_pago = false;
$mostrar_asientos = false;
$mostrar_acciones_rapidas = false;

// Determinar qué mostrar según los roles del usuario
if (in_array('Administrador', $roles_nombres)) {
    // Administrador ve todo
    $mostrar_requisiciones_registro = true;
    $mostrar_requisiciones_presupuesto = true;
    $mostrar_ordenes_pago = true;
    $mostrar_asientos = true;
    $mostrar_acciones_rapidas = true;
} elseif (in_array('Registro y Control', $roles_nombres)) {
    // Registro y Control ve requisiciones pendientes de su nivel y asientos
    $mostrar_requisiciones_registro = true;
    $mostrar_asientos = true;
    $mostrar_acciones_rapidas = true;
} elseif (in_array('Presupuesto', $roles_nombres)) {
    // Presupuesto ve requisiciones pendientes de su nivel
    $mostrar_requisiciones_presupuesto = true;
    $mostrar_acciones_rapidas = true;
} elseif (in_array('Compra', $roles_nombres)) {
    // Compra ve órdenes de pago pendientes
    $mostrar_ordenes_pago = true;
    $mostrar_acciones_rapidas = true;
}

// Consultas condicionales según permisos
$requisiciones_pendientes_registro = 0;
$requisiciones_pendientes_presupuesto = 0;
$requisiciones_pendientes_orden_pago = 0;
$ultimos_asientos = [];

if ($mostrar_requisiciones_registro) {
    $stmt = $conn->query("SELECT COUNT(*) as total FROM requisiciones WHERE estado = 'enviada'");
    $requisiciones_pendientes_registro = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

if ($mostrar_requisiciones_presupuesto) {
    $stmt = $conn->query("SELECT COUNT(*) as total FROM requisiciones WHERE estado = 'pendiente_nivel_2'");
    $requisiciones_pendientes_presupuesto = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

if ($mostrar_ordenes_pago) {
    $stmt = $conn->query("SELECT COUNT(*) as total FROM requisiciones WHERE estado = 'aprobada' AND id NOT IN (SELECT DISTINCT requisicion_id FROM ordenes_pago WHERE requisicion_id IS NOT NULL)");
    $requisiciones_pendientes_orden_pago = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
}

if ($mostrar_asientos) {
    $stmt = $conn->query("SELECT a.*, u.nombre_completo as usuario 
                         FROM asientos a 
                         LEFT JOIN usuarios u ON a.usuario_id = u.id 
                         WHERE a.estado = 'confirmado' 
                         ORDER BY a.fecha DESC 
                         LIMIT 5");
    $ultimos_asientos = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
/* Tema oscuro: tarjetas y textos del dashboard */
html[data-theme='dark'] .card { 
    background-color: var(--card-bg, #0b1220) !important; 
    color: #e5e7eb !important; 
    border-color: var(--table-border-color, #222c3c) !important; 
  background-color: var(--card-bg, #0b1220) !important; 
  color: #e5e7eb !important; 
  border-color: var(--table-border-color, #222c3c) !important; 
}
html[data-theme='dark'] .card-header { 
  background-color: var(--table-header-bg, #1e293b) !important; 
  border-bottom-color: var(--table-border-color, #222c3c) !important; 
}
html[data-theme='dark'] .card-body { 
  background-color: var(--card-bg, #0b1220) !important; 
  color: #e5e7eb !important; 
}
/* Ajuste de colores de texto utilitarios en oscuro */
html[data-theme='dark'] .text-muted { color: #9ca3af !important; }
html[data-theme='dark'] .text-secondary { color: #9ca3af !important; }
/* Ícono dentro del encabezado mantiene buen contraste */
html[data-theme='dark'] .card-header i { filter: none; color: var(--primary-color, #3b82f6) !important; }
</style>

<div class="container-fluid px-4 py-4">
    <!-- Tarjetas de estadísticas -->
    <div class="row g-4 mb-5">
        <?php if ($mostrar_requisiciones_registro): ?>
        <div class="col-xl-4 col-lg-4 col-md-4 col-sm-6">
            <div class="dashboard-stat-card">
                <div class="stat-content">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($requisiciones_pendientes_registro); ?></div>
                        <div class="stat-label">Pendientes Registro y Control</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mostrar_requisiciones_presupuesto): ?>
        <div class="col-xl-4 col-lg-4 col-md-4 col-sm-6">
            <div class="dashboard-stat-card">
                <div class="stat-content">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-calculator"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($requisiciones_pendientes_presupuesto); ?></div>
                        <div class="stat-label">Pendientes Presupuesto</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mostrar_ordenes_pago): ?>
        <div class="col-xl-4 col-lg-4 col-md-4 col-sm-6">
            <div class="dashboard-stat-card">
                <div class="stat-content">
                    <div class="stat-icon-wrapper">
                        <i class="fas fa-file-invoice-dollar"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-number"><?php echo number_format($requisiciones_pendientes_orden_pago); ?></div>
                        <div class="stat-label">Pendientes Orden de Pago</div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Contenido principal -->
    <div class="row g-4">
        <?php if ($mostrar_acciones_rapidas): ?>
        <!-- Acciones Rápidas -->
        <div class="col-xl-6 col-lg-6">
            <div class="dashboard-info-card">
                <div class="info-card-header">
                    <div class="info-card-icon">
                        <i class="fas fa-bolt"></i>
            </div>
                    <h5 class="info-card-title">Acciones Rápidas</h5>
                </div>
                
                <div class="quick-actions-grid">
                    <?php if ($mostrar_requisiciones_registro): ?>
                    <!-- Gestión de Requisiciones Registro y Control -->
                    <a href="../modulos/requisiciones/gestion_requisiciones.php?estado=enviada" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-clipboard-check"></i>
                        </div>
                        <span>Requisiciones Registro y Control</span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar_requisiciones_presupuesto): ?>
                    <!-- Gestión de Requisiciones Presupuesto -->
                    <a href="../modulos/requisiciones/gestion_requisiciones.php?estado=pendiente_nivel_2" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-calculator"></i>
                        </div>
                        <span>Requisiciones Presupuesto</span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar_ordenes_pago): ?>
                    <!-- Gestión de Órdenes de Pago -->
                    <a href="../modulos/requisiciones/gestion_requisiciones.php?estado=aprobada" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <span>Generar Órdenes de Pago</span>
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($mostrar_asientos): ?>
                    <!-- Operaciones Contables -->
                    <a href="../modulos/contabilidad/gestion_asientos_contables.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-plus"></i>
                        </div>
                        <span>Nuevo Asiento</span>
                    </a>
                    
                    <a href="../modulos/facturacion/gestion_recibos_pago.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-receipt"></i>
                        </div>
                        <span>Nueva Factura</span>
                    </a>
                    
                    <!-- Cuentas por Cobrar y Pagar -->
                    <a href="../modulos/reportes/cuentas_por_cobrar.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <span>Cuentas por Cobrar</span>
                    </a>
                    
                    <a href="../modulos/reportes/cuentas_por_pagar.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-credit-card"></i>
                        </div>
                        <span>Cuentas por Pagar</span>
                    </a>
                    
                    <!-- Contabilidad Avanzada -->
                    <a href="../modulos/contabilidad/cierre_contable.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <span>Cierre Contable</span>
                    </a>
                    
                    <a href="../modulos/contabilidad/periodos_contables.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-lock"></i>
                        </div>
                        <span>Periodos Contables</span>
                    </a>
                    
                    <!-- Reportes y Consultas -->
                    <a href="../modulos/contabilidad/libros_contables.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-book-open"></i>
                        </div>
                        <span>Libros Contables</span>
                    </a>
                    
                    <a href="../modulos/reportes/estados_financieros.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-file-invoice-dollar"></i>
                        </div>
                        <span>Estados Financieros</span>
                    </a>
                    
                    <!-- Gestión de Datos -->
                    <a href="../modulos/clientes/gestion_clientes.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <span>Gestión Clientes</span>
                    </a>
                    
                    <a href="../modulos/proveedores/gestion_proveedores.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-truck"></i>
                        </div>
                        <span>Gestión Proveedores</span>
                    </a>
                    
                    <a href="../modulos/contabilidad/catalogo_cuentas.php" class="quick-action-item">
                        <div class="action-icon">
                            <i class="fas fa-list-alt"></i>
                        </div>
                        <span>Catálogo Cuentas</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($mostrar_asientos): ?>
        <!-- Últimos Asientos -->
        <div class="col-xl-6 col-lg-6">
            <div class="dashboard-info-card">
                <div class="info-card-header">
                    <div class="info-card-icon">
                        <i class="fas fa-history"></i>
    </div>
                    <h5 class="info-card-title">Últimos Asientos</h5>
</div>

                <?php if (empty($ultimos_asientos)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <p class="empty-text">No hay asientos recientes</p>
                    </div>
                <?php else: ?>
                    <div class="recent-items">
                    <?php foreach ($ultimos_asientos as $asiento): ?>
                            <div class="recent-item">
                                <div class="item-content">
                                    <div class="item-title"><?php echo limpiarDatos($asiento['descripcion']); ?></div>
                                    <div class="item-meta">
                                        <span class="item-date"><?php echo date('d/m/Y', strtotime($asiento['fecha'])); ?></span>
                                        <span class="item-user"><?php echo limpiarDatos($asiento['usuario'] ?? ''); ?></span>
                                    </div>
                                </div>
                                <div class="item-amount">
                                    <span class="amount-value"><?php echo formatearMonedaBs($asiento['total_debitos']); ?></span>
                            </div>
                            </div>
                        <?php endforeach; ?>
            </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer_sistema.php'; ?> 