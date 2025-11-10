<?php

namespace App\Console\Commands;

use App\Services\FacebookService;
use Illuminate\Console\Command;

class TestFacebookConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'facebook:test {post_id?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Probar la conexión con Facebook API y verificar el access token';

    protected $facebookService;

    /**
     * Create a new command instance.
     */
    public function __construct(FacebookService $facebookService)
    {
        parent::__construct();
        $this->facebookService = $facebookService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 Probando conexión con Facebook API...');
        $this->newLine();

        // Paso 1: Verificar configuración
        $this->info('📋 Verificando configuración:');
        $appId = config('services.facebook.app_id');
        $token = config('services.facebook.access_token');
        $version = config('services.facebook.api_version');

        $this->line("  App ID: " . ($appId ?: '<no configurado>'));
        $this->line("  API Version: {$version}");

        if ($token && $token !== 'your-facebook-access-token') {
            $this->line("  Token: " . substr($token, 0, 20) . '...' . substr($token, -10));
        } else {
            $this->error("  Token: ❌ No configurado o es placeholder");
            $this->newLine();
            $this->warn('⚠️  Necesitas configurar FACEBOOK_ACCESS_TOKEN en tu .env');
            $this->info('📖 Consulta FACEBOOK_TOKEN_SETUP.md para instrucciones completas');
            $this->newLine();
            $this->info('🚀 Opción rápida:');
            $this->line('   1. Ve a: https://developers.facebook.com/tools/explorer/');
            $this->line('   2. Genera un User Access Token con permisos de lectura');
            $this->line('   3. Cópialo a .env → FACEBOOK_ACCESS_TOKEN=TU_TOKEN');
            $this->line('   4. Ejecuta: php artisan config:clear');
            return 1;
        }

        $this->newLine();

        // Paso 2: Test de conexión básica
        try {
            $this->info('🔗 Probando conexión con Facebook...');
            $result = $this->facebookService->testConnection();

            if ($result['success']) {
                $this->info('✅ Conexión exitosa!');
                $this->line("   Usuario/Página: {$result['data']['name']}");
                $this->line("   ID: {$result['data']['id']}");
            } else {
                $this->error('❌ Error al conectar:');
                $this->line($result['error']);
                return 1;
            }
        } catch (\Exception $e) {
            $this->error('❌ Error al conectar con Facebook:');
            $this->line($e->getMessage());
            return 1;
        }

        $this->newLine();

        // Paso 3: Test de obtener datos de post (si se proporciona)
        $postId = $this->argument('post_id');

        if ($postId) {
            $this->info("📄 Probando obtener datos del post: {$postId}");

            try {
                $data = $this->facebookService->getPostEngagement($postId);

                $this->info('✅ Datos obtenidos exitosamente!');
                $this->newLine();

                $commentsCount = count($data['comments'] ?? []);
                $likesCount = count($data['likes'] ?? []);
                $sharesCount = count($data['shares'] ?? []);

                $this->table(
                    ['Tipo', 'Cantidad'],
                    [
                        ['Comentarios', $commentsCount],
                        ['Likes', $likesCount],
                        ['Shares', $sharesCount],
                    ]
                );

                if ($commentsCount > 0) {
                    $this->newLine();
                    $this->info('📝 Primeros 3 comentarios:');
                    foreach (array_slice($data['comments'], 0, 3) as $index => $comment) {
                        $this->line("  " . ($index + 1) . ". {$comment['username']}: " . substr($comment['message'], 0, 50) . '...');
                    }
                }

            } catch (\Exception $e) {
                $this->error('❌ Error al obtener datos del post:');
                $this->line($e->getMessage());
                $this->newLine();
                $this->warn('💡 Posibles causas:');
                $this->line('   • El post_id es incorrecto');
                $this->line('   • El post no es público o no tienes acceso');
                $this->line('   • Faltan permisos en el token');
                $this->newLine();
                $this->info('🔍 Formato correcto del post_id:');
                $this->line('   • {page-id}_{post-id} (ej: 123456789_987654321)');
                $this->line('   • O solo el ID numérico del post');
                return 1;
            }
        } else {
            $this->newLine();
            $this->info('💡 Para probar con un post específico:');
            $this->line('   php artisan facebook:test {post_id}');
            $this->line('   Ejemplo: php artisan facebook:test 123456789_987654321');
        }

        $this->newLine();
        $this->info('✨ Prueba completada exitosamente!');
        $this->info('🎯 Puedes proceder a sincronizar campañas con POST /api/social-media/campaigns/{id}/sync/facebook');

        return 0;
    }
}
