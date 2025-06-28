<?php

namespace App\Services;

use App\Repositories\RoleRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RoleService
{
    protected $roleRepository;

    public function __construct(RoleRepository $roleRepository)
    {
        $this->roleRepository = $roleRepository;
    }

    public function getAll($withTrashed = false)
    {
        return $withTrashed
            ? $this->roleRepository->getAllWithTrashed()
            : $this->roleRepository->getAll();
    }

    public function getById($id, $withTrashed = false)
    {
        return $withTrashed
            ? $this->roleRepository->findByIdWithTrashed($id)
            : $this->roleRepository->findById($id);
    }

    public function create(array $data)
    {
        $validator = Validator::make($data, [
            'name' => 'required|string',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Cek apakah sudah pernah dibuat dan terhapus
        $trashed = $this->roleRepository->findTrashedByName($data['name']);
        if ($trashed) {
            $this->roleRepository->restoreByName($data['name']);
            $this->roleRepository->update($trashed, [
                'guard_name' => $data['guard_name'] ?? 'api'
            ]);
            return $trashed->fresh();
        }

        // Cek duplikat aktif
        $existing = $this->roleRepository->getAll()->where('name', $data['name'])->first();
        if ($existing) {
            throw new \Exception('Role dengan nama tersebut sudah ada.');
        }

        return $this->roleRepository->create([
            'name' => $data['name'],
            'guard_name' => $data['guard_name'] ?? 'api',
        ]);
    }

    public function update($id, array $data)
    {
        $role = $this->roleRepository->findByIdWithTrashed($id);
        if (!$role) return null;

        $validator = Validator::make($data, [
            'name' => 'required|string|unique:roles,name,' . $id,
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->roleRepository->update($role, $data);
    }

    public function delete($id)
    {
        $role = $this->roleRepository->findById($id);
        return $role ? $this->roleRepository->delete($role) : false;
    }

    public function restore($id)
    {
        return $this->roleRepository->restore($id);
    }
}
//