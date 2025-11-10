<?php

namespace App\Traits;

use App\Models\Notification;
use Illuminate\Support\Str;

trait Helper
{
    public function normalizeString($string)
    {
        $string = mb_strtolower($string, 'UTF-8');
        $string = preg_replace('/\p{M}/u', '', $string); // Eliminar marcas de acento
        return $string;
    }

    public function store_files($files, $directory)
    {
        $paths = [];

        if ($files) {
            foreach ($files as $file) {
                if ($file->isValid() && $this->isValidImage($file)) {
                    $uniqueName = $this->generateUniqueFileName($file);
                    $paths[] = $file->storeAs($directory, $uniqueName, 'public');
                } else {
                    return response()->json(['error' => 'Uno o más archivos subidos no son imágenes válidas.'], 400);
                }
            }
        }

        return $paths;
    }


    public function store_file($file, $directory)
    {
        if ($file && $file->isValid() && $this->isValidImage($file)) {
            $uniqueName = $this->generateUniqueFileName($file);
            return $file->storeAs($directory, $uniqueName, 'public');
        } else {
            return response()->json(['error' => 'El archivo subido no es una imagen válida.'], 400);
        }
    }


    private function isValidImage($file)
    {
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        return in_array($file->getClientMimeType(), $allowedMimeTypes);
    }

    private function generateUniqueFileName($file)
    {
        $uuid = Str::uuid()->toString(); // Generar UUID
        $extension = $file->getClientOriginalExtension(); // Obtener la extensión del archivo
        return $uuid . '.' . $extension;
    }


    private function createNotification($user_id, $title , $message, $notification_type)
    {
        $notification = Notification::create([
            'user_id' => $user_id,
            'notification_type' => $notification_type,
            'title' => $title,
            'message' => $message,
            'datetime' => now(),
            'is_active' => true,
        ]);

        return $notification;
    }

    // public function deactivateAlert(CaseStatus $caseStatus)
    // {
    //     $updated = Alert::where('case_id', $caseStatus->case_id)
    //         ->where('alert_type', 'Cambio de estado')
    //         ->where('is_active', true)
    //         ->update(['is_active' => false]);

    //     if ($updated) {
    //         return response()->json(['message' => 'Alerta desactivada'], 200);
    //     }

    //     return response()->json(['message' => 'No se encontraron alertas activas'], 404);
    // }

}
