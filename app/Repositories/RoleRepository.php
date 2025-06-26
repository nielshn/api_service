<?php

namespace App\Repositories;

use App\Models\Role;

class RoleRepository
{
    public function getAll()
    {
        return Role::all();
    }

    public function getAllWithTrashed()
    {
        return Role::withTrashed()->get();
    }

    public function findById($id)
    {
        return Role::find($id);
    }

    public function findByIdWithTrashed($id)
    {
        return Role::withTrashed()->find($id);
    }

    public function findTrashedByName($name)
    {
        return Role::onlyTrashed()->where('name', $name)->first();
    }

    public function create(array $data)
    {
        return Role::create($data);
    }

    public function update(Role $role, array $data)
    {
        $role->update($data);
        return $role;
    }

    public function delete(Role $role)
    {
        return $role->delete();
    }

    public function restore($id)
    {
        $role = Role::withTrashed()->find($id);
        return $role ? $role->restore() : false;
    }

    public function restoreByName($name)
    {
        $role = $this->findTrashedByName($name);
        return $role ? $role->restore() : false;
    }
}
