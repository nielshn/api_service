<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoleResource;
use App\Services\RoleService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

class RoleController extends Controller implements HasMiddleware
{
    protected $roleService;

    public function __construct(RoleService $roleService)
    {
        $this->roleService = $roleService;
    }

    public static function middleware(): array
    {
        return [
            'auth:api',
            new Middleware('permission:view_role', only: ['index', 'show']),
            new Middleware('permission:create_role', only: ['store']),
            new Middleware('permission:update_role', only: ['update']),
            new Middleware('permission:delete_role', only: ['destroy']),
            new Middleware('permission:restore_role', only: ['restore']),
        ];
    }

    public function index(Request $request)
    {
        $withTrashed = $request->query('with_trashed') === 'true';
        $roles = $this->roleService->getAll($withTrashed);
        return RoleResource::collection($roles);
    }

    public function store(Request $request)
    {
        try {
            $role = $this->roleService->create($request->all());
            return response()->json([
                'message' => 'Role berhasil disimpan',
                'data' => new RoleResource($role),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Gagal menyimpan role', 'error' => $e->getMessage()], 400);
        }
    }

    public function show($id)
    {
        $role = $this->roleService->getById($id, true);
        return $role
            ? new RoleResource($role)
            : response()->json(['message' => 'Role tidak ditemukan.'], 404);
    }

    public function update(Request $request, $id)
    {
        try {
            $updated = $this->roleService->update($id, $request->all());
            return $updated
                ? response()->json(['message' => 'Role berhasil diperbarui', 'data' => new RoleResource($updated)])
                : response()->json(['message' => 'Role tidak ditemukan'], 404);
        } catch (ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        }
    }

    public function destroy($id)
    {
        return $this->roleService->delete($id)
            ? response()->json(['message' => 'Role berhasil dihapus.'])
            : response()->json(['message' => 'Role tidak ditemukan.'], 404);
    }

    public function restore($id)
    {
        return $this->roleService->restore($id)
            ? response()->json(['message' => 'Role berhasil dipulihkan.'])
            : response()->json(['message' => 'Role tidak ditemukan atau sudah aktif.'], 404);
    }
}
