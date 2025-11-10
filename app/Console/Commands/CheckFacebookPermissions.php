<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class CheckFacebookPermissions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'facebook:check-permissions';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verificar qué permisos tiene tu token actual de Facebook';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Verificando permisos del token de Facebook...');
        $this->newLine();

        $token = config('services.facebook.access_token');

        if (!$token || $token === 'your-facebook-access-token') {
            $this->error('❌ Token no configurado');
            return 1;
        }

        try {
            // Obtener información del token
            $response = Http::get('https://graph.facebook.com/v18.0/me/permissions', [
                'access_token' => $token,
            ]);

            if (!$response->successful()) {
                $this->error('❌ Error al verificar token:');
                $this->line($response->body());
                return 1;
            }

            $permissions = $response->json()['data'] ?? [];

            // Permisos requeridos para sincronización
            $requiredPermissions = [
                'pages_show_list' => 'Listar páginas',
                'pages_read_engagement' => 'Leer interacciones (comentarios, likes)',
                'pages_read_user_content' => 'Leer contenido de usuarios',
                // 'public_profile' => 'Perfil público',
            ];

            $this->info('📋 Permisos actuales del token:');
            $this->newLine();

            $grantedPerms = [];
            $missingPerms = [];

            foreach ($permissions as $perm) {
                $name = $perm['permission'];
                $status = $perm['status'];

                if ($status === 'granted') {
                    $grantedPerms[] = $name;
                    $icon = '✅';
                } else {
                    $icon = '❌';
                }

                $this->line("  {$icon} {$name} ({$status})");
            }

            $this->newLine();
            $this->info('📝 Permisos REQUERIDOS para sincronización:');
            $this->newLine();

            foreach ($requiredPermissions as $perm => $description) {
                if (in_array($perm, $grantedPerms)) {
                    $this->line("  ✅ {$perm} - {$description}");
                } else {
                    $this->line("  ❌ {$perm} - {$description} (FALTANTE)");
                    $missingPerms[] = $perm;
                }
            }

            $this->newLine();

            if (count($missingPerms) > 0) {
                $this->warn('⚠️  FALTAN PERMISOS!');
                $this->newLine();
                $this->error('Permisos faltantes: ' . implode(', ', $missingPerms));
                $this->newLine();
                $this->info('🔧 SOLUCIÓN:');
                $this->line('1. Ve a: https://developers.facebook.com/tools/explorer/');
                $this->line('2. Selecciona tu app (ID: ' . config('services.facebook.app_id') . ')');
                $this->line('3. Haz clic en "Generate Access Token"');
                $this->line('4. Selecciona los siguientes permisos:');
                foreach ($requiredPermissions as $perm => $desc) {
                    $this->line("   • {$perm}");
                }
                $this->line('5. Copia el nuevo token y actualiza .env');
                $this->line('6. Ejecuta: php artisan config:clear');
                $this->newLine();
                $this->line('O usa el script automático:');
                $this->line('   .\setup-facebook-token.ps1');

                return 1;
            }

            $this->info('✅ Todos los permisos necesarios están otorgados!');
            $this->info('🎯 Puedes sincronizar campañas sin problemas.');

            return 0;

        } catch (\Exception $e) {
            $this->error('❌ Error al verificar permisos:');
            $this->line($e->getMessage());
            return 1;
        }
    }
}
