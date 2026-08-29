<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database/database.php';
require_once __DIR__ . '/../config/session_config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json');

if (!isUserAuthenticated()) {
    echo json_encode(['success' => false, 'error' => 'No autenticado']);
    exit;
}

$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

$action = $_GET['action']
    ?? $_POST['action']
    ?? ($jsonData['action'] ?? 'list');

$conn = getConnection();

// Función para verificar si la tasa de cambio necesita actualización
function necesitaActualizarTasa($conn) {
    try {
        $horaActual = (int) date('H');
        $minutoActual = (int) date('i');
        $horaCompleta = $horaActual * 100 + $minutoActual; // Formato HHMM para comparación
        
        // Verificar si estamos en las franjas horarias específicas (8am o 1pm)
        $enFranja8am = ($horaActual == 8);
        $enFranja1pm = ($horaActual == 13);
        
        // Si no estamos en las franjas horarias, no mostrar modal
        if (!$enFranja8am && !$enFranja1pm) {
            return false;
        }
        
        // Verificar si hay tasas de cambio de hoy en la franja horaria correspondiente
        $horaInicio = $horaActual . ':00:00';
        $horaFin = $horaActual . ':59:59';
        
        $stmt = $conn->prepare("
            SELECT COUNT(*) 
            FROM tasas_cambio 
            WHERE DATE(fecha) = CURDATE() 
            AND TIME(fecha) BETWEEN ? AND ?
        ");
        $stmt->execute([$horaInicio, $horaFin]);
        $count = $stmt->fetchColumn();
        
        // Si no hay tasas en esta franja horaria, necesita actualización
        if ($count == 0) {
            $usuarioId = $_SESSION['usuario_id'] ?? null;
            if (!$usuarioId) {
                return true; // sin usuario no podemos generar notificación, pero seguimos indicando alerta
            }

            $slot = $enFranja8am ? '08:00 AM' : '01:00 PM';
            $titulo = $enFranja8am 
                ? 'Recordatorio: Actualizar Tasa de Cambio (08:00 AM)'
                : 'Recordatorio: Actualizar Tasa de Cambio (01:00 PM)';
            $mensaje = $enFranja8am
                ? 'Es hora de actualizar la tasa de cambio al inicio de la jornada (08:00 AM).'
                : 'Es hora de actualizar la tasa de cambio al mediodía (01:00 PM).';

            // Verificar si ya se creó la notificación para este usuario y franja horaria en el día actual
            $stmt = $conn->prepare("
                SELECT id FROM notificaciones 
                WHERE usuario_id = ?
                  AND tipo = 'warning'
                  AND titulo = ?
                  AND DATE(created_at) = CURDATE()
                LIMIT 1
            ");
            $stmt->execute([$usuarioId, $titulo]);
            $notif_exists = $stmt->fetchColumn();
            
            // Si no existe la notificación, crearla
            if (!$notif_exists) {
                $stmt = $conn->prepare("
                    INSERT INTO notificaciones (usuario_id, titulo, mensaje, tipo, leida, created_at)
                    VALUES (?, ?, ?, 'warning', 0, NOW())
                ");
                $stmt->execute([$usuarioId, $titulo, $mensaje]);
            }
            
            return true;
        }
        
        return false;
    } catch (Exception $e) {
        error_log("Error verificando tasa de cambio: " . $e->getMessage());
        return false;
    }
}

switch ($action) {
    case 'count':
        // Devolver solo el contador de notificaciones no leídas
        try {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM notificaciones 
                WHERE usuario_id = ? AND leida = 0
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'count' => (int)$result['count']
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'list_all':
        // Listar todas las notificaciones (leídas y no leídas)
        try {
            // Primero verificar tasa de cambio
            necesitaActualizarTasa($conn);
            
            $stmt = $conn->prepare("
                SELECT id, titulo, mensaje, tipo, leida, created_at,
                       DATE_FORMAT(created_at, '%d/%m/%Y %h:%i %p') as fecha
                FROM notificaciones 
                WHERE usuario_id = ? 
                ORDER BY leida ASC, created_at DESC
                LIMIT 50
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'notificaciones' => $notificaciones
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'check_tasa':
        // Verificar si necesita actualización de tasa de cambio
        $necesita = necesitaActualizarTasa($conn);
        $horaActual = (int) date('H');
        $enFranja8am = ($horaActual == 8);
        $enFranja1pm = ($horaActual == 13);
        $mostrarModal = $necesita && ($enFranja8am || $enFranja1pm);
        
        $mensajeModal = '';
        if ($mostrarModal) {
            $mensajeModal = $enFranja8am
                ? 'Es hora de actualizar la tasa de cambio al inicio de la jornada (08:00 AM).'
                : 'Es hora de actualizar la tasa de cambio al mediodía (01:00 PM).';
        }
        
        echo json_encode([
            'success' => true,
            'necesita_actualizacion' => $necesita,
            'mostrar_modal' => $mostrarModal,
            'mensaje_modal' => $mensajeModal,
            'hora' => $horaActual
        ]);
        break;
        
    case 'marcar_leida':
        // Marcar una notificación como leída
        try {
            $id = $jsonData['id'] ?? 0;
            
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE notificaciones SET leida = 1, read_at = NOW() WHERE id = ? AND usuario_id = ?");
                $stmt->execute([$id, $_SESSION['usuario_id']]);
            }
            
            echo json_encode([
                'success' => true,
                'mensaje' => 'Notificación marcada como leída'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'marcar_todas_leidas':
        // Marcar todas las notificaciones como leídas
        try {
            $stmt = $conn->prepare("UPDATE notificaciones SET leida = 1, read_at = NOW() WHERE usuario_id = ? AND leida = 0");
            $stmt->execute([$_SESSION['usuario_id']]);
            
            echo json_encode([
                'success' => true,
                'mensaje' => 'Todas las notificaciones han sido marcadas como leídas'
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
        
    case 'list':
    default:
        // Listar notificaciones no leídas
        try {
            // Primero verificar tasa de cambio
            necesitaActualizarTasa($conn);
            
            $stmt = $conn->prepare("
                SELECT id, titulo, mensaje, tipo, leida, created_at,
                       DATE_FORMAT(created_at, '%d/%m/%Y %h:%i %p') as fecha
                FROM notificaciones 
                WHERE usuario_id = ? 
                ORDER BY created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$_SESSION['usuario_id']]);
            $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'notificaciones' => $notificaciones
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
}
?>
