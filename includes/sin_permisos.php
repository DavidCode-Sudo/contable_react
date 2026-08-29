<?php
require_once __DIR__ . '/verificar_sesion.php';
require_once __DIR__ . '/funciones_contables.php';
require_once __DIR__ . '/../config/config.php';

$page_title = 'Acceso restringido';
$modulo_intentado = $_GET['modulo'] ?? '';
$accion_intentada = $_GET['accion'] ?? '';

// Obtener permisos del usuario y agrupar por módulo
$usuario_id = $_SESSION['usuario_id'] ?? 0;
$permisos_usuario = obtenerPermisosUsuario($usuario_id);
$permisos_por_modulo = [];
$modulos_con_ver = [];
foreach ($permisos_usuario as $p) {
    $permisos_por_modulo[$p['modulo']][] = $p['accion'];
    if ($p['accion'] === 'ver') { $modulos_con_ver[$p['modulo']] = true; }
}

// Título legible del módulo/acción intentados
$modulo_legible = $modulo_intentado ? obtenerDescripcionModulo($modulo_intentado) : 'Sección';
$accion_legible = $accion_intentada ? obtenerDescripcionAccion($accion_intentada) : 'acceder';

require_once __DIR__ . '/header_sistema.php';
?>

<style>
.sin-permisos-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 10px; box-shadow: var(--shadow-sm); }
.sin-permisos-card .card-header { background: var(--background-secondary); border-bottom: 1px solid var(--border-color); }
.recomendaciones-list li { margin-bottom: .35rem; }
.permiso-badge { font-size: .75rem; }
.modulo-item { border: 1px solid var(--border-color); border-radius: 8px; padding: .75rem .9rem; background: var(--card-bg); }
</style>

<div class="container-fluid px-4">
  <div class="row justify-content-center mt-4">
    <div class="col-lg-8">
      <div class="card sin-permisos-card">
        <div class="card-header d-flex align-items-center">
          <i class="fas fa-shield-alt text-warning me-2"></i>
          <h5 class="mb-0">Acceso restringido</h5>
        </div>
        <div class="card-body">
          <div class="alert alert-warning d-flex align-items-start" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <div>
              No cuentas con el permiso <strong>"<?php echo htmlspecialchars($accion_legible); ?>"</strong> en el módulo <strong>"<?php echo htmlspecialchars($modulo_legible); ?>"</strong>.
            </div>
          </div>

          <p class="mb-3">Puedes continuar con cualquiera de las secciones a las que ya tienes acceso:</p>

          <?php if (!empty($modulos_con_ver)): ?>
          <div class="row g-3 mb-3">
            <?php foreach ($modulos_con_ver as $modulo => $_): ?>
              <?php 
                $nombre = obtenerDescripcionModulo($modulo);
                $url_base = function_exists('getModuloUrl') ? getModuloUrl($modulo) : BASE_URL;
                // Ruta por defecto según módulo
                $ruta = $url_base;
                switch ($modulo) {
                  case 'catalogo': $ruta .= 'catalogo_cuentas.php'; break;
                  case 'asientos': $ruta .= 'gestion_asientos.php'; break;
                  case 'libros': $ruta .= 'libros_contables.php'; break;
                  case 'cierre_contable': $ruta .= 'cierre_contable.php'; break;
                  case 'periodos_contables': $ruta .= 'periodos_contables.php'; break;
                  case 'facturacion':
                  case 'facturas': $ruta .= 'gestion_recibos_pago.php'; break;
                  case 'inventario': $ruta .= 'gestion_inventario.php'; break;
                  case 'clientes': $ruta .= 'gestion_clientes.php'; break;
                  case 'proveedores': $ruta .= 'gestion_proveedores.php'; break;
                  case 'reportes': $ruta .= 'reportes_financieros.php'; break;
                  case 'estados_financieros': $ruta = (function_exists('getModuloUrl') ? getModuloUrl('reportes') : BASE_URL) . 'estados_financieros.php'; break;
                  case 'cxc': $ruta = (function_exists('getModuloUrl') ? getModuloUrl('reportes') : BASE_URL) . 'cuentas_por_cobrar.php'; break;
                  case 'cxp': $ruta = (function_exists('getModuloUrl') ? getModuloUrl('reportes') : BASE_URL) . 'cuentas_por_pagar.php'; break;
                  case 'auditoria': $ruta = (function_exists('getModuloUrl') ? getModuloUrl('auditoria') : BASE_URL) . 'auditoria_sistema.php'; break;
                  case 'usuarios': $ruta = (function_exists('getModuloUrl') ? getModuloUrl('usuarios') : BASE_URL) . 'gestion_usuarios.php'; break;
                  case 'roles': $ruta = (function_exists('getModuloUrl') ? getModuloUrl('usuarios') : BASE_URL) . 'gestion_roles_permisos.php'; break;
                  case 'dashboard': $ruta = BASE_URL . 'includes/dashboard_principal.php'; break;
                  default: $ruta = BASE_URL . 'includes/dashboard_principal.php'; break;
                }
              ?>
              <div class="col-sm-6 col-md-4">
                <a class="text-decoration-none" href="<?php echo $ruta; ?>">
                  <div class="modulo-item d-flex align-items-center justify-content-between">
                    <span class="fw-semibold"><?php echo htmlspecialchars($nombre); ?></span>
                    <i class="fas fa-arrow-right"></i>
                  </div>
                </a>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
            <div class="alert alert-info"><i class="fas fa-info-circle me-2"></i> Aún no tienes módulos asignados para visualizar.</div>
          <?php endif; ?>

          <hr>
          <h6 class="fw-bold mb-2">Permisos asignados a tu usuario</h6>
          <?php if (!empty($permisos_por_modulo)): ?>
          <div class="row g-2">
            <?php foreach ($permisos_por_modulo as $mod => $acciones): ?>
              <div class="col-md-6">
                <div class="p-2 border rounded">
                  <div class="fw-semibold mb-1"><?php echo htmlspecialchars(obtenerDescripcionModulo($mod)); ?></div>
                  <?php foreach (array_unique($acciones) as $acc): ?>
                    <span class="badge bg-primary permiso-badge me-1 mb-1"><?php echo htmlspecialchars(obtenerDescripcionAccion($acc)); ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
            <p class="text-muted">No se encontraron permisos asignados.</p>
          <?php endif; ?>

          <div class="mt-4 d-flex flex-wrap gap-2">
            <?php if (verificarPermiso('dashboard','ver')): ?>
            <a href="<?php echo BASE_URL; ?>includes/dashboard_principal.php" class="btn btn-primary"><i class="fas fa-home"></i> Ir al Dashboard</a>
            <?php endif; ?>
            <?php if (verificarPermiso('usuarios','ver')): ?>
            <a href="<?php echo getModuloUrl('usuarios'); ?>gestion_usuarios.php" class="btn btn-outline-primary"><i class="fas fa-users"></i> Gestión de Usuarios</a>
            <?php endif; ?>
          </div>

          <div class="mt-3">
            <small class="text-muted">Si consideras que esto es un error, por favor contacta al administrador del sistema.</small>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/footer_sistema.php'; ?>
