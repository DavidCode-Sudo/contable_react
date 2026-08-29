<?php
/**
 * Generador de contraseñas hasheadas
 * Sistema de Inscripción y Evaluación
 * 
 * USO: Ejecutar desde navegador o consola para generar passwords hasheados
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generador de Contraseñas - Sistema de Evaluación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-key"></i> Generador de Contraseñas Hasheadas
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php
                        $hash = '';
                        $password_input = '';
                        
                        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
                            $password_input = $_POST['password'];
                            if (!empty($password_input)) {
                                // Generar hash con bcrypt (cost 10)
                                $hash = password_hash($password_input, PASSWORD_BCRYPT, ['cost' => 10]);
                            }
                        }
                        ?>
                        
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock"></i> Ingrese la contraseña a hashear:
                                </label>
                                <input type="text" 
                                       class="form-control form-control-lg" 
                                       id="password" 
                                       name="password" 
                                       value="<?php echo htmlspecialchars($password_input); ?>"
                                       placeholder="Ej: mipassword123"
                                       required>
                                <small class="form-text text-muted">
                                    Mínimo 6 caracteres recomendado
                                </small>
                            </div>
                            
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-cogs"></i> Generar Hash
                            </button>
                        </form>
                        
                        <?php if (!empty($hash)): ?>
                            <hr class="my-4">
                            
                            <div class="alert alert-success">
                                <h5 class="alert-heading">
                                    <i class="fas fa-check-circle"></i> Hash generado exitosamente
                                </h5>
                                
                                <div class="mb-3">
                                    <label class="form-label"><strong>Contraseña original:</strong></label>
                                    <div class="alert alert-info mb-2">
                                        <code><?php echo htmlspecialchars($password_input); ?></code>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label"><strong>Hash generado (copiar para BD):</strong></label>
                                    <textarea class="form-control" 
                                              rows="3" 
                                              id="hashOutput"
                                              readonly><?php echo $hash; ?></textarea>
                                    <button class="btn btn-sm btn-secondary mt-2" onclick="copiarHash()">
                                        <i class="fas fa-copy"></i> Copiar Hash
                                    </button>
                                </div>
                                
                                <div class="alert alert-warning">
                                    <strong><i class="fas fa-info-circle"></i> Cómo usar:</strong>
                                    <ol class="mb-0 mt-2">
                                        <li>Copia el hash generado</li>
                                        <li>Inserta en la base de datos en el campo <code>password</code> de la tabla <code>usuarios</code></li>
                                        <li>El usuario podrá iniciar sesión con la contraseña original</li>
                                    </ol>
                                </div>
                                
                                <div class="alert alert-info mb-0">
                                    <strong>SQL de ejemplo:</strong>
                                    <pre class="mb-0 mt-2" style="background: white; padding: 10px; border-radius: 5px;">
INSERT INTO usuarios (username, password, nombres, apellidos, email, rol) 
VALUES ('usuario', '<?php echo $hash; ?>', 'Nombre', 'Apellido', 'email@example.com', 'Docente');</pre>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <hr class="my-4">
                        
                        <div class="alert alert-light">
                            <h6><i class="fas fa-shield-alt"></i> Seguridad:</h6>
                            <ul class="mb-0">
                                <li>Se utiliza <strong>bcrypt</strong> con cost factor 10</li>
                                <li>Cada hash es único debido al salt automático</li>
                                <li>El hash no puede ser revertido a la contraseña original</li>
                                <li>La misma contraseña generará diferentes hashes cada vez</li>
                            </ul>
                        </div>
                        
                        <div class="text-center mt-3">
                            <a href="../login.php" class="btn btn-outline-primary">
                                <i class="fas fa-arrow-left"></i> Volver al Login
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    function copiarHash() {
        const textarea = document.getElementById('hashOutput');
        textarea.select();
        document.execCommand('copy');
        alert('Hash copiado al portapapeles!');
    }
    </script>
</body>
</html>

