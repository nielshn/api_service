<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as ModelsRole;

class PermissionController extends Controller
{
    public function togglePermission(Request $request)
    {
        $request->validate([
            'role' => 'required|string',
            'permission' => 'required|string',
            'status' => 'required|boolean'
        ]);

        $role = ModelsRole::where('name', $request->role)->firstOrFail();
        $permission = Permission::where('name', $request->permission)->firstOrFail();

        if ($request->status) {
            // aktifkan permission
            if ($role->hasPermissionTo($permission)) {
                return response()->json(['message' => "Permission {$request->permission} sudah dimiliki oleh {$request->role}"], 409);
            }
            $role->givePermissionTo($permission);
            return response()->json(['message' => "Permission {$request->permission} diberikan ke {$request->role}"]);
        } else {
            //ini mematikan permission
            if (!$role->hasPermissionTo($permission)) {
                return response()->json(['message' => "Permission {$request->permission} sudah dicabut sebelumnya dari {$request->role}"], 409);
            }
            $role->revokePermissionTo($permission);
            return response()->json(['message' => "Permission {$request->permission} dicabut dari {$request->role}"]);
        }
    }
    public function index(Request $request)
    {
        // Validasi agar query param 'role' wajib ada
        $request->validate([
            'role' => 'required|string'
        ]);

        // Ambil dari query param, BUKAN dari user login
        $roleName = strtolower($request->role);

        // Ambil role berdasarkan nama
        $role = \Spatie\Permission\Models\Role::whereRaw('LOWER(name) = ?', [$roleName])->first();

        if (!$role) {
            return response()->json([
                'success' => false,
                'message' => 'Role tidak ditemukan: ' . $request->role
            ], 404);
        }

        // Ambil semua permission
        $permissions = \Spatie\Permission\Models\Permission::all();

        // Ambil permission yang dimiliki role ini dari tabel pivot
        $permissionIds = DB::table('role_has_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->toArray();

        // Buat output permission + status true/false
        $result = $permissions->map(function ($permission) use ($permissionIds) {
            return [
                'id' => $permission->id,
                'name' => $permission->name,
                'status' => in_array($permission->id, $permissionIds)
            ];
        });

        // Return JSON
        return response()->json([
            'success' => true,
            'role' => $role->name,
            'permissions' => $result
        ]);
    }
}
