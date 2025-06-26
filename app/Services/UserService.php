<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UserService
{
    protected $userRepository;

    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    public function getAll()
    {
        return $this->userRepository->getAll();
    }

    public function getOperators()
    {
        return $this->userRepository->getOperators();
    }

    public function getById($id)
    {
        return $this->userRepository->getById($id);
    }

    public function create(array $data)
    {
        // Cek user terhapus dengan nama yang sama
        $deletedUser = User::onlyTrashed()->where('name', $data['name'])->first();

        if ($deletedUser) {
            // Restore jika nama sama
            $deletedUser->restore();

            // Update data yang baru dikirim
            $deletedUser->update([
                'password' => Hash::make($data['password']),
                'email' => $data['email'] ?? $deletedUser->email,
                'phone_number' => $data['phone_number'] ?? $deletedUser->phone_number,
            ]);

            $deletedUser->syncRoles($data['roles']);
            return $deletedUser->fresh();
        }

        // Validasi input
        $validator = Validator::make($data, [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'name')->whereNull('deleted_at'),
            ],
            'password' => [
                'required',
                'string',
                Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
                'confirmed'
            ],
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ], [
            'name.required' => 'Nama wajib diisi.',
            'name.unique' => 'Nama sudah digunakan.',
            'password.required' => 'Password wajib diisi.',
            'password.min' => 'Password harus minimal 8 karakter.',
            'password.mixedCase' => 'Password harus mengandung huruf besar dan kecil.',
            'password.letters' => 'Password harus mengandung huruf.',
            'password.numbers' => 'Password harus mengandung angka.',
            'password.symbols' => 'Password harus mengandung simbol.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'roles.required' => 'Peran wajib diisi.',
            'roles.*.exists' => 'Peran yang dipilih tidak valid.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $data['password'] = Hash::make($data['password']);

        $role = \Spatie\Permission\Models\Role::where('name', $data['roles'][0])->first();
        $data['role_id'] = $role ? $role->id : null;

        $user = $this->userRepository->create($data);
        $user->syncRoles($data['roles']);

        return $user;
    }

    public function update(array $data)
    {
        $user = auth()->user();

        if (isset($data['name']) && User::where('name', $data['name'])->where('id', '!=', $user->id)->exists()) {
            throw new \Exception('Nama pengguna sudah terdaftar.');
        }

        $validator = Validator::make($data, [
            'name' => 'sometimes|string|max:255',
            'phone_number' => 'nullable|digits_between:10,15|unique:users,phone_number,' . $user->id,
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $this->userRepository->update($user, $data);
        return $user->fresh();
    }

    public function updateAvatar($base64Avatar)
    {
        $user = auth()->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $avatarPath = uploadBase64Image($base64Avatar, 'img/profil');
        $this->userRepository->update($user, ['avatar' => $avatarPath]);

        return $user->fresh();
    }

    public function deleteAvatar()
    {
        $user = auth()->user();

        if ($user->avatar) {
            Storage::disk('public')->delete($user->avatar);
        }

        $this->userRepository->update($user, ['avatar' => null]);
        return $user->fresh();
    }

    public function changePasswordByLoginUser(array $data)
    {
        $user = auth()->user();

        $messages = [
            'current_password.required' => 'Password lama wajib diisi.',
            'new_password.required' => 'Password baru wajib diisi.',
            'new_password.min' => 'Password baru minimal 8 karakter.',
            'new_password.regex' => 'Password baru harus mengandung huruf besar, huruf kecil, dan simbol.',
            'new_password.confirmed' => 'Konfirmasi password baru tidak cocok.',
        ];

        $validator = Validator::make($data, [
            'current_password' => 'required',
            'new_password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*[\W_]).+$/',
                'confirmed'
            ],
        ], $messages);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (!Hash::check($data['current_password'], $user->password)) {
            throw new \Exception('Password lama salah.');
        }

        $this->userRepository->update($user, [
            'password' => Hash::make($data['new_password'])
        ]);

        return $user->fresh();
    }

    public function delete($id)
    {
        $user = $this->userRepository->getById($id);

        if (!$user) {
            throw new \Exception('User tidak ditemukan');
        }

        if (auth()->id() == $user->id) {
            throw new \Exception('Anda tidak dapat menghapus akun Anda sendiri.');
        }

        return $user->delete(); // Soft delete
    }

    public function updateUserByAdmin($id, array $data)
    {
        $id = (int) $id;
        $user = $this->userRepository->getById($id);

        if (!$user) {
            throw new \Exception('Pengguna tidak ditemukan.');
        }

        if (isset($data['roles']) && !is_array($data['roles'])) {
            $data['roles'] = [$data['roles']];
        }

        $validator = Validator::make($data, [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('users', 'name')->ignore($id)->whereNull('deleted_at'),
            ],
            'password' => [
                'nullable',
                'string',
                Password::min(8)->mixedCase()->letters()->numbers()->symbols(),
                'confirmed',
            ],
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ], [
            'name.required' => 'Nama wajib diisi.',
            'name.unique' => 'Nama pengguna sudah terdaftar.',
            'name.max' => 'Nama maksimal 255 karakter.',
            'password.min' => 'Password harus minimal 8 karakter.',
            'password.mixedCase' => 'Password harus mengandung huruf besar dan kecil.',
            'password.letters' => 'Password harus mengandung huruf.',
            'password.numbers' => 'Password harus mengandung angka.',
            'password.symbols' => 'Password harus mengandung simbol.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'roles.required' => 'Peran wajib dipilih.',
            'roles.*.exists' => 'Peran yang dipilih tidak valid.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (!empty($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        } else {
            unset($data['password']);
        }

        $this->userRepository->update($user, $data);
        $user->syncRoles($data['roles']);

        return $user->fresh();
    }
}
