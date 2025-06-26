<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class WebResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->web_id,
            'web_nama' => $this->web_nama,
            'web_logo' => is_string($this->web_logo) ? asset('storage/' . $this->web_logo) : null,
            'web_deskripsi' => $this->web_deskripsi,
            'user' => [
                'id' => $this->user?->id,
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ],
        ];
    }
}
