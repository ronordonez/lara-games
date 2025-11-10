<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Autenticación Facebook Exitosa</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.5s ease-out;
        }
        .success-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
        }
        .user-info {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
        }
        .user-info h3 {
            color: #333;
            margin-bottom: 15px;
            font-size: 18px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .info-row:last-child {
            border-bottom: none;
        }
        .info-label {
            color: #666;
            font-weight: 600;
        }
        .info-value {
            color: #333;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        .token-container {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            position: relative;
        }
        .token-label {
            color: #666;
            font-weight: 600;
            margin-bottom: 10px;
            display: block;
        }
        .token-value {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 10px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            word-break: break-all;
            color: #333;
            max-height: 100px;
            overflow-y: auto;
        }
        .copy-btn {
            margin-top: 10px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: transform 0.2s;
        }
        .copy-btn:hover {
            transform: translateY(-2px);
        }
        .copy-btn:active {
            transform: translateY(0);
        }
        .instructions {
            background: #e8f4f8;
            border-left: 4px solid #1da1f2;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
            border-radius: 5px;
        }
        .instructions h4 {
            color: #1da1f2;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .instructions p {
            color: #555;
            line-height: 1.6;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .instructions code {
            background: rgba(29, 161, 242, 0.1);
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .close-btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            margin-top: 20px;
            transition: all 0.3s;
        }
        .close-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
        </div>

        <h1>¡Autenticación Exitosa!</h1>
        <p class="subtitle">Te has conectado exitosamente con Facebook</p>

        <div class="user-info">
            <h3>Información del Usuario</h3>
            <div class="info-row">
                <span class="info-label">Nombre:</span>
                <span class="info-value">{{ $user->name }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value">{{ $user->email }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Expira en:</span>
                <span class="info-value">{{ $expires_in }} segundos</span>
            </div>
        </div>

        <div class="token-container">
            <span class="token-label">Token de Acceso (JWT):</span>
            <div class="token-value" id="jwtToken">{{ $token }}</div>
            <button class="copy-btn" onclick="copyToken('jwtToken')">📋 Copiar JWT Token</button>
        </div>

        <div class="token-container">
            <span class="token-label">Token de Facebook:</span>
            <div class="token-value" id="fbToken">{{ $facebook_token }}</div>
            <button class="copy-btn" onclick="copyToken('fbToken')">📋 Copiar Facebook Token</button>
        </div>

        <div class="instructions">
            <h4>📌 Próximos pasos:</h4>
            <p>1. Copia el <strong>JWT Token</strong> para autenticarte en la API</p>
            <p>2. Usa el header: <code>Authorization: Bearer [tu-token]</code></p>
            <p>3. Ahora puedes acceder a tus páginas de Facebook con <code>GET /api/facebook/pages</code></p>
            <p>4. Obtén publicaciones con <code>GET /api/facebook/pages/{pageId}/posts</code></p>
            <p>5. Crea sources y sincroniza participantes para tus sorteos</p>
        </div>

        <button class="close-btn" onclick="window.close()">Cerrar Ventana</button>
    </div>

    <script>
        function copyToken(elementId) {
            const tokenElement = document.getElementById(elementId);
            const token = tokenElement.textContent;

            navigator.clipboard.writeText(token).then(() => {
                const btn = event.target;
                const originalText = btn.textContent;
                btn.textContent = '✓ Copiado!';
                btn.style.background = '#10b981';

                setTimeout(() => {
                    btn.textContent = originalText;
                    btn.style.background = '';
                }, 2000);
            }).catch(err => {
                alert('Error al copiar: ' + err);
            });
        }

        // Enviar mensaje al parent si está en iframe o popup
        if (window.opener || window.parent !== window) {
            const data = {
                success: true,
                token: '{{ $token }}',
                facebook_token: '{{ $facebook_token }}',
                user: {
                    name: '{{ $user->name }}',
                    email: '{{ $user->email }}'
                }
            };

            if (window.opener) {
                window.opener.postMessage(data, '*');
            } else {
                window.parent.postMessage(data, '*');
            }
        }
    </script>
</body>
</html>
