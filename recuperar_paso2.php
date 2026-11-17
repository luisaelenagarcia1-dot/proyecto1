<?php
// --- INICIO DE LA LÓGICA PHP ---
$pregunta = "";
$usuario_form = "";
$error_message = ""; 

// 1. Verificar que se llega por POST y no por acceso directo
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 2. Verificar que el usuario no esté vacío
    if (!empty($_POST['usuario'])) {
        
        $usuario_form = $_POST['usuario'];
        
        require 'conexion.php'; 

        // 3. Buscar la pregunta de seguridad adaptado a PostgreSQL ($1 en lugar de ?)
        $sql = "SELECT pregunta_seguridad FROM usuarios WHERE nombre_usuario = $1";
        $resultado = pg_query_params($conexion, $sql, array($usuario_form));

        if ($resultado && pg_num_rows($resultado) === 1) {
            $datos_usuario = pg_fetch_assoc($resultado);
            
            // Verificamos si tiene una pregunta de seguridad
            if (!empty($datos_usuario['pregunta_seguridad'])) {
                 $pregunta = htmlspecialchars($datos_usuario['pregunta_seguridad']);
            } else {
                $error_message = "Este usuario no tiene una pregunta de seguridad configurada. Contacte al administrador.";
            }
            
        } else {
            $error_message = "El nombre de usuario no fue encontrado. Verifique e intente de nuevo.";
        }
        
        // Liberamos memoria y cerramos conexión
        if ($resultado) {
            pg_free_result($resultado);
        }
        pg_close($conexion);

    } else {
        $error_message = "No se proporcionó un nombre de usuario.";
    }

} else {
    // Si no es POST, es acceso directo. Redirigir al paso 1.
    header('Location: recuperar_paso1.html');
    exit();
}
// --- FIN DE LA LÓGICA PHP ---
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar Contraseña - Paso 2</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
            /* Colores heredados de tu login final */
            --color-principal: #6c7b91ff; 
            --color-texto-claro: #f8f9fa;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
            overflow: hidden;
            background-color: #212529; 
            font-size: 1.15rem; /* Tamaño base consistente */
        }

        /* FONDO CONSISTENTE */
        .background-image-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background-image: url('assets/img/iactp.jpg');
            background-size: cover; 
            background-position: center center;
            background-repeat: no-repeat;
            filter: brightness(0.4) blur(8px); 
        }

        /* TARJETA ÚNICA Y CENTRADA (Coherente con el right-panel del login) */
        .recovery-card {
            width: 100%;
            max-width: 550px; /* Un poco más ancha para los campos */
            padding: 40px;
            
            background-color: rgba(0, 0, 0, 0.7); 
            backdrop-filter: blur(5px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            
            color: var(--color-texto-claro);
            border-radius: 15px;
            box-shadow: 0 10px 40px 0 rgba(0, 0, 0, 0.7); 
        }
        
        .login-logo {
            max-width: 120px;
            margin-bottom: 1.5rem;
        }
        
        .recovery-card h3 {
            font-size: 1.8rem;
            font-weight: 600;
        }
        
        /* ESTILO PARA LA PREGUNTA */
        .pregunta-box {
            background-color: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 8px;
            border-left: 5px solid var(--color-principal);
            margin-bottom: 1.5rem;
        }
        .pregunta-box p {
            font-style: italic;
            font-size: 1.2rem;
            margin: 0;
        }

        /* ESTILOS DE INPUTS */
        .input-group {
            border-radius: 0.5rem; 
            overflow: hidden; 
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
        .input-group-text {
            border: none;
            color: var(--color-principal); 
            background-color: rgba(255, 255, 255, 0.1); 
            padding: .8rem .8rem;
        }
        .form-control {
            border: none;
            background-color: rgba(255, 255, 255, 0.15);
            color: var(--color-texto-claro);
            padding: .8rem 1rem;
        }
        .form-control::placeholder {
             color: rgba(255, 255, 255, 0.6);
        }
        .form-control:focus {
            background-color: rgba(255, 255, 255, 0.2);
            border-color: var(--color-principal);
            box-shadow: 0 0 0 0.25rem rgba(108, 123, 145, 0.4); 
        }
        .toggle-password {
             cursor: pointer;
        }

        /* BOTÓN CONSISTENTE */
        .btn-action {
            background-color: var(--color-principal);
            border: none;
            font-weight: 600;
            padding: 15px; 
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .btn-action:hover {
            background-color: #5d7092; 
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        /* ENLACES Y TEXTOS */
        .recovery-card a {
             color: #9ac2ff;
             text-decoration: none;
             font-size: 1rem;
        }
        .recovery-card a:hover {
             color: var(--color-texto-claro);
             text-decoration: underline;
        }
        .text-instructions {
            opacity: 0.8;
            font-size: 1.1rem;
        }

    </style>
</head>
<body>
    <div class="background-image-container"></div>

    <div class="container d-flex justify-content-center align-items-center h-100">
        <div class="card recovery-card">
            <div class="card-body p-0">
                
                <div class="text-center mb-4">
                    <img src="assets/img/logo.jpg" alt="Logo IACTP" class="login-logo mb-2">
                    <h3 class="card-title text-white">Recuperar Contraseña</h3>
                </div>
                
                <?php if (!empty($error_message)): ?>
                    <h3 class="card-title text-center text-danger mb-3">Error</h3>
                    <div class="alert alert-danger" role="alert">
                         <i class="bi bi-exclamation-triangle-fill me-2"></i>
                         <?php echo $error_message; ?>
                    </div>
                    <div class="d-grid mt-3">
                        <a href="recuperar_paso1.html" class="btn btn-action">Volver a Intentar</a>
                    </div>
                    
                <?php else: ?>
                    <p class="text-center text-instructions mb-4">Paso 2 de 2: Responda su pregunta de seguridad para restablecer la contraseña.</p>

                    <form action="actualizar_contrasena.php" method="POST">
                        
                        <input type="hidden" name="usuario" value="<?php echo htmlspecialchars($usuario_form); ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold text-white">Pregunta de Seguridad:</label>
                            <div class="pregunta-box">
                                <p class="text-white">"<?php echo $pregunta; ?>"</p>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="respuesta" class="form-label text-white">Tu Respuesta</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-shield-lock-fill"></i></span>
                                <input type="text" class="form-control" id="respuesta" name="respuesta" required placeholder="Ingrese la respuesta secreta">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="nueva_contrasena" class="form-label text-white">Nueva Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" class="form-control" id="nueva_contrasena" name="nueva_contrasena" required placeholder="Mínimo 8 caracteres">
                                <span class="input-group-text toggle-password" id="togglePasswordNew"><i class="bi bi-eye-slash-fill"></i></span>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="confirmar_contrasena" class="form-label text-white">Confirmar Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" class="form-control" id="confirmar_contrasena" name="confirmar_contrasena" required placeholder="Repita la nueva contraseña">
                                <span class="input-group-text toggle-password" id="togglePasswordConfirm"><i class="bi bi-eye-slash-fill"></i></span>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-action">Restablecer Contraseña</button>
                        </div>
                    </form>
                    
                <?php endif; ?>
                
                <div class="text-center mt-3">
                    <a href="login.php">← Volver a Inicio de Sesión</a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            function setupPasswordToggle(toggleId, inputId) {
                const toggle = document.getElementById(toggleId);
                const input = document.getElementById(inputId);

                if (toggle && input) {
                    toggle.addEventListener('click', function() {
                        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                        input.setAttribute('type', type);

                        const icon = this.querySelector('i');
                        icon.classList.toggle('bi-eye-fill');
                        icon.classList.toggle('bi-eye-slash-fill');
                    });
                }
            }

            // Aplicar el toggle a los dos campos de contraseña
            setupPasswordToggle('togglePasswordNew', 'nueva_contrasena');
            setupPasswordToggle('togglePasswordConfirm', 'confirmar_contrasena');
        });
    </script>
</body>
</html>