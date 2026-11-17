<?php
session_start();

if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header('Location: index.php');
    exit;
}

$mensajeError = "";
if (isset($_SESSION['error_message'])) {
    $mensajeError = $_SESSION['error_message'];
    unset($_SESSION['error_message']); 
} elseif (isset($_GET['error']) && $_GET['error'] == 1) {
    $mensajeError = "Usuario o contraseña incorrectos.";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - S.G.R IACTP</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    
    <style>
        :root {
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
            font-size: 1.15rem; 
        }

        /* FONDO */
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

        /* RECUADRO PRINCIPAL GRANDE */
        .login-card {
            width: 100%;
            max-width: 1000px; 
            padding: 0;
            
            background-color: rgba(255, 255, 255, 0.1); 
            backdrop-filter: blur(8px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            
            color: var(--color-texto-claro);
            border-radius: 15px;
            box-shadow: 0 10px 40px 0 rgba(0, 0, 0, 0.7); 
            overflow: hidden; 
        }

        /* PANEL IZQUIERDO (Marca Institucional) */
        .left-panel {
            background: linear-gradient(135deg, rgba(78, 76, 76, 0.9), rgba(223, 122, 115, 0.9));
            border-radius: 15px 0 0 15px; 
            /* Aumentamos padding interno */
            padding: 50px 40px; 
            text-shadow: 1px 1px 3px rgba(0, 0, 0, 0.5);
        }
        .login-logo-new {
            /* TAMAÑO AUMENTADO DEL LOGO */
            max-width: 250px; 
            height: auto;
        }
        .system-title {
            /* TAMAÑO AUMENTADO DEL TÍTULO */
            font-size: 5rem; 
            font-weight: 700;
        }
        .system-subtitle {
             font-size: 2rem;
             opacity: 0.8;
        }

        /* PANEL DERECHO (Formulario - Alto Contraste) */
        .right-panel {
            background-color: rgba(0, 0, 0, 0.5); 
            /* Aumentamos padding interno */
            padding: 50px 40px; 
        }
        .right-panel h3 {
             color: var(--color-texto-claro);
             /* TAMAÑO AUMENTADO DEL TÍTULO DEL FORMULARIO */
             font-size: 1.8rem;
        }
        
        /* ESTILOS DE INPUTS (Claro sobre Oscuro) */
        /* Aseguramos que los inputs también crezcan */
        .input-group-text, .form-control {
            padding-top: .8rem;
            padding-bottom: .8rem;
        }

        .input-group {
            border-radius: 0.5rem; 
            overflow: hidden; 
            border: 1px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
        }
        .input-group:focus-within {
            border-color: var(--color-principal);
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
        }

        .input-group-text {
            border: none;
            color: var(--color-principal); 
            background-color: rgba(255, 255, 255, 0.1); 
        }

        .form-control {
            border: none;
            background-color: rgba(255, 255, 255, 0.15);
            color: var(--color-texto-claro);
        }
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }
        .form-control:focus {
            background-color: rgba(255, 255, 255, 0.2);
            box-shadow: none; 
        }
        
        /* BOTÓN DE LOGIN */
        .btn-login {
            background-color: var(--color-principal);
            border: none;
            font-weight: 600;
            /* TAMAÑO AUMENTADO DEL BOTÓN */
            padding: 15px; 
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
        }
        .btn-login:hover {
            background-color: #0b5ed7;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
        }

        /* ENLACES */
        .right-panel a {
             color: #9ac2ff; 
             text-decoration: none;
        }
        .right-panel a:hover {
             color: var(--color-texto-claro);
             text-decoration: underline;
        }

        /* RESPONSIVIDAD (Aseguramos que siga viéndose bien en móvil) */
        @media (max-width: 767.98px) {
            .login-card {
                max-width: 90%;
            }
            .left-panel {
                display: none !important; 
            }
            .right-panel {
                padding: 30px; /* Reducimos el padding en móvil */
                border-radius: 15px; 
                background-color: rgba(0, 0, 0, 0.6);
            }
             .right-panel h3 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    
    <div class="background-image-container"></div>

    <div class="container d-flex justify-content-center align-items-center h-100">
        <div class="card login-card p-0"> 
            <div class="row g-0">
                
                <div class="col-md-5 d-flex flex-column justify-content-center align-items-center left-panel">
                    <img src="assets/img/logo.jpg" alt="Logo IACTP" class="img-fluid mb-3 login-logo-new">
                    <p class="text-white text-center system-subtitle">SISTEMA DE GESTIÓN INTEGRAL </p>
                </div>

                <div class="col-md-7 right-panel">
                    
                    <h3 class="card-title text-center mb-4">Iniciar Sesión</h3>
                    
                    <?php if (!empty($mensajeError)): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo htmlspecialchars($mensajeError); ?>
                        </div>
                    <?php endif; ?>

                    <form action="validar.php" method="POST">
                        
                        <div class="mb-3">
                            <label for="nombre_usuario" class="form-label">Usuario</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                                <input type="text" class="form-control" id="nombre_usuario" name="nombre_usuario" placeholder="Ingrese su usuario" required autocomplete="username">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="contrasena" class="form-label">Contraseña</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                                <input type="password" class="form-control" id="contrasena" name="contrasena" placeholder="Ingrese su contraseña" required autocomplete="current-password">
                                <span class="input-group-text toggle-password" id="togglePassword" style="cursor: pointer;">
                                    <i class="bi bi-eye-slash-fill"></i>
                                </span>
                            </div>
                        </div>
                        
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-login btn-primary" id="btn-submit">
                                <span id="button-text">Ingresar</span>
                            </button>
                        </div>
                        
                        <div class="text-center">
                            <a href="recuperar_paso1.html">¿Olvidó su contraseña?</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.getElementById('togglePassword');
            const passwordField = document.getElementById('contrasena');
            const form = document.querySelector('form');
            const submitButton = document.getElementById('btn-submit');
            const buttonText = document.getElementById('button-text');

            // Lógica para Mostrar/Ocultar Contraseña
            if (togglePassword) {
                togglePassword.addEventListener('click', function (e) {
                    const type = passwordField.getAttribute('type') === 'password' ? 'text' : 'password';
                    passwordField.setAttribute('type', type);

                    const icon = this.querySelector('i');
                    icon.classList.toggle('bi-eye-fill');
                    icon.classList.toggle('bi-eye-slash-fill');
                });
            }
            
            // Lógica para Estado de Carga del Botón
            form.addEventListener('submit', function() {
                submitButton.disabled = true;
                buttonText.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Validando...';
            });
        });
    </script>
</body>
</html>