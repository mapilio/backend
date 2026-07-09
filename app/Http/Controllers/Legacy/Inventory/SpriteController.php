<?php

namespace App\Http\Controllers\Legacy\Inventory;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class SpriteController extends Controller
{
    public function standard(): JsonResponse
    {
        return response()->json($this->sprites('sprites.json'));
    }

    public function retina(): JsonResponse
    {
        return response()->json($this->sprites('sprites@2x.json'));
    }

    private function sprites(string $file): array
    {
        $path = public_path('sprites/'.$file);
        $json = file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Sprite metadata file is not readable.");
        }

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
