<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Exitoso - Google OAuth</title>
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
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            padding: 40px;
            max-width: 600px;
            width: 100%;
        }
        .success-icon {
            width: 80px;
            height: 80px;
            background: #10b981;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.5s ease-out;
        }
        .success-icon svg {
            width: 50px;
            height: 50px;
            stroke: white;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        @keyframes scaleIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        h1 {
            text-align: center;
            color: #1f2937;
            margin-bottom: 10px;
            font-size: 28px;
        }
        .subtitle {
            text-align: center;
            color: #6b7280;
            margin-bottom: 30px;
        }
        .user-info {
            background: #f3f4f6;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .user-info h2 {
            color: #374151;
            font-size: 18px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-row {
            display: flex;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .info-label {
            font-weight: 600;
            color: #4b5563;
            width: 120px;
        }
        .info-value {
            color: #1f2937;
            flex: 1;
        }
        .token-section {
            margin-top: 20px;
        }
        .token-label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .token-box {
            background: #1f2937;
            color: #10b981;
            padding: 15px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            word-break: break-all;
            position: relative;
            max-height: 120px;
            overflow-y: auto;
        }
        .copy-button {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin-top: 10px;
            width: 100%;
            font-size: 14px;
            transition: background 0.3s;
        }
        .copy-button:hover {
            background: #5568d3;
        }
        .copy-button:active {
            transform: scale(0.98);
        }
        .copy-success {
            background: #10b981 !important;
        }
        .instructions {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 8px;
            margin-top: 20px;
            font-size: 14px;
            color: #92400e;
        }
        .instructions strong {
            display: block;
            margin-bottom: 8px;
            color: #78350f;
        }
        .instructions code {
            background: #fde68a;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        .emoji {
            font-size: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">
            <svg viewBox="0 0 52 52">
                <polyline points="14 27 22 35 38 19"/>
            </svg>
        </div>

        <h1>¡Autenticación Exitosa! 🎉</h1>
        <p class="subtitle">Has iniciado sesión correctamente con Google</p>

        <div class="user-info">
            <h2><span class="emoji">👤</span> Información del Usuario</h2>
            <div class="info-row">
                <span class="info-label">Nombre:</span>
                <span class="info-value">{{ $user->name }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Email:</span>
                <span class="info-value">{{ $user->email }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">ID:</span>
                <span class="info-value">{{ $user->id }}</span>
            </div>
            <div class="info-row">
                <span class="info-label">Token expira en:</span>
                <span class="info-value">{{ $expires_in }} segundos ({{ round($expires_in / 60) }} minutos)</span>
            </div>
        </div>

        <div class="token-section">
            <div class="token-label">
                <span class="emoji">🔑</span> Tu Token de Acceso (JWT)
            </div>
            <div class="token-box" id="tokenBox">{{ $token }}</div>
            <button class="copy-button" onclick="copyToken()">
                📋 Copiar Token al Portapapeles
            </button>
        </div>

        <div class="instructions">
            <strong>📌 Cómo usar este token:</strong>
            Usa este token en el header <code>Authorization</code> de tus peticiones API:
            <br><br>
            <code>Authorization: Bearer {{ substr($token, 0, 30) }}...</code>
            <br><br>
            Ejemplo en cURL:
            <br>
            <code style="font-size: 11px;">curl -H "Authorization: Bearer TU_TOKEN" http://localhost:8000/api/user</code>
        </div>
    </div>

    <script>
        function copyToken() {
            const tokenText = document.getElementById('tokenBox').textContent;
            const button = document.querySelector('.copy-button');

            navigator.clipboard.writeText(tokenText).then(() => {
                button.textContent = '✅ ¡Token Copiado!';
                button.classList.add('copy-success');

                setTimeout(() => {
                    button.textContent = '📋 Copiar Token al Portapapeles';
                    button.classList.remove('copy-success');
                }, 2000);
            }).catch(err => {
                alert('Error al copiar: ' + err);
            });
        }
    </script>
</body>
</html>
