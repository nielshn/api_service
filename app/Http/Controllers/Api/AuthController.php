<?php

    namespace App\Http\Controllers\Api;

    use App\Http\Controllers\Controller;
    use App\Models\User;
    use App\Services\AuthService;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Auth;

    class AuthController extends Controller
    {
        protected $authService;

        public function __construct(AuthService $authService)
        {
            $this->authService = $authService;
        }

        public function login(Request $request): JsonResponse
        {
            $credentials = $request->validate([
                'name' => 'required|string',
                'password' => 'required|string',
            ]);

            $result = $this->authService->login($credentials);

            return response()->json($result, $result['response_code']);
        }


        public function logout(Request $request): JsonResponse
        {
            $user = Auth::user();
            $result = $this->authService->logout($user);

            return response()->json($result, $result['response_code']);
        }


    public function userInfo(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
            ], 401);
        }

        // ambil ulang dari database (misal termasuk relasi)
        $user = User::with(['roles', 'permissions'])->find($user->id);

        $permissions = $this->authService->getUserPermissions($user);

        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone_number,
            'avatar' => $user->avatar ? asset('storage/' . $user->avatar) : null,
            'created_at' => $user->created_at,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $permissions,
        ];

        return response()->json([
            'status' => 'success',
            'data' => $userData,
        ], 200);
    }
    }
