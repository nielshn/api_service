<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WebResource;
use App\Services\WebService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Melihovv\Base64ImageDecoder\Base64ImageDecoder;

class WebController extends Controller
{
    protected $service;

    public function __construct(WebService $service)
    {
        $this->service = $service;
    }

    public function index()
    {
        return response()->json([
            'success' => true,
            'message' => 'Daftar pengaturan web berhasil diambil.',
            'data' => WebResource::collection($this->service->getAll()),
        ], 200);
    }

    public function show($id)
    {
        try {
            $web = $this->service->getById($id);
            return response()->json([
                'success' => true,
                'message' => 'Pengaturan web berhasil diambil.',
                'data' => new WebResource($web),
            ], 200, [
                'Cache-Control' => 'public, max-age=3600',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak ditemukan.',
            ], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'web_nama' => 'sometimes|required|string|max:255',
            'web_deskripsi' => 'nullable|string',
            'web_logo' => 'nullable|string',
        ]);

        try {
            $web = $this->service->getById($id);
            $data = $request->only(['web_nama', 'web_deskripsi']);
            $data['user_id'] = Auth::id();

            if ($request->has('web_logo')) {
                $data['web_logo'] = $this->replaceImage($web->web_logo, $request->web_logo);
            }

            $updatedWeb = $this->service->update($id, $data);
            return response()->json([
                'success' => true,
                'message' => 'Pengaturan web berhasil diperbarui.',
                'data' => new WebResource($updatedWeb),
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function handleImageUpload($base64Image)
    {
        return $base64Image ? uploadBase64Image($base64Image, 'img/web') : 'default_image.png';
    }

    private function replaceImage($oldImage, $newBase64)
    {
        if ($oldImage && $oldImage !== 'default_image.png') {
            Storage::disk('public')->delete($oldImage);
        }
        return uploadBase64Image($newBase64, 'img/web');
    }
}
