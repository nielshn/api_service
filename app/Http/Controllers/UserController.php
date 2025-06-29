<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Notifications\CustomVerifyEmail;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class UserController extends Controller implements HasMiddleware
{
    protected $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public static function middleware(): array
    {
        return [
            'auth:api',
            new Middleware('permission:view_user', only: ['index', 'show']),
            new Middleware('permission:create_user', only: ['store']),
            new Middleware('permission:update_user', only: ['updateUserByAdmin']),
            new Middleware('permission:delete_user', only: ['destroy', 'deleteAvatar']),
        ];
    }

    public function index()
    {
        return UserResource::collection($this->userService->getAll());
    }

    public function getOperators()
    {
        $operators = $this->userService->getOperators();
        return UserResource::collection($operators);
    }

    public function show($id)
    {
        try {
            $user = $this->userService->getById($id);
            if (!$user) {
                return response()->json(['message' => 'User tidak ditemukan'], 404);
            }

            return new UserResource($user);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = $this->userService->create($request->all());

            return response()->json([
                'message' => 'User berhasil dibuat',
                'data' => new UserResource($user)
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function update(Request $request)
    {
        try {
            $allowedFields = $request->only(['name', 'phone_number']);
            $user = $this->userService->update($allowedFields);

            return response()->json([
                'message' => 'Profil berhasil diperbarui',
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            Log::error("Error updating user: " . $e->getMessage());

            return response()->json([
                'message' => 'Terjadi kesalahan saat memperbarui profil',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function updateAvatar(Request $request)
    {
        try {
            $request->validate([
                'avatar' => 'required|string'
            ]);

            $user = $this->userService->updateAvatar($request->avatar);

            return response()->json([
                'message' => 'Avatar berhasil diperbarui',
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            Log::error("Error updating avatar: " . $e->getMessage());

            return response()->json([
                'message' => 'Terjadi kesalahan saat memperbarui avatar',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteAvatar()
    {
        try {
            $user = $this->userService->deleteAvatar();

            return response()->json([
                'message' => 'Avatar berhasil dihapus',
                'data' => new UserResource($user)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function destroy($id)
    {
        try {
            $this->userService->delete($id);

            return response()->json(['message' => 'Pengguna berhasil dihapus'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }


    public function updateUserByAdmin(Request $request, $id)
    {
       try {
        $user = $this->userService->updateUserByAdmin($id, $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Data pengguna berhasil diperbarui oleh admin.',
            'data' => new UserResource($user)
        ], 200);
    } catch (ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Validasi gagal.',
            'errors' => $e->errors()
        ], 422);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Gagal memperbarui data pengguna.',
            'error' => $e->getMessage()
        ], 422);
    }
    }
    public function updateEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email|email:rfc,dns',
        ]);

        $user = auth()->user();

        $cooldownKey = 'email_update_cooldown_' . $user->id;
        if (Cache::has($cooldownKey)) {
            $remaining = Cache::get($cooldownKey) - time();
            return response()->json([
                'message' => "Harap tunggu $remaining detik sebelum mencoba lagi."
            ], 429);
        }

        if ($request->email === $user->email) {
            return response()->json(['message' => 'Email yang dimasukkan sama dengan email saat ini.'], 400);
        }

        if (\App\Models\User::where('email', $request->email)->exists()) {
            return response()->json(['message' => 'Email sudah digunakan.'], 400);
        }

        // Simpan email baru yang akan diverifikasi ke cache
        Cache::put('pending_email_' . $user->id, $request->email, now()->addHours(1));

        // Cooldown
        Cache::put($cooldownKey, time() + 60, now()->addSeconds(60));

        try {
            // Kirim notifikasi verifikasi dengan email BARU
            $user->notify(new CustomVerifyEmail($request->email));
            return response()->json([
                'message' => 'Email berhasil diperbarui. Link verifikasi telah dikirim ke email baru.'
            ]);
        } catch (\Exception $e) {
            Log::error('Gagal kirim email verifikasi: ' . $e->getMessage());
            return response()->json([
                'message' => 'Gagal mengirim email.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function changePassword(Request $request)
    {
        try {
            $user = $this->userService->changePasswordByLoginUser($request->all());

            return response()->json([
                'message' => 'Password berhasil diubah.',
                'data' => new UserResource($user)
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validasi gagal.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
