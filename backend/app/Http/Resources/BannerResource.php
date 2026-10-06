<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BannerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'image_url' => asset('storage/' . $this->image_path),
            'button_text' => $this->button_text,
            'link_url' => $this->link_url,
            'sort_order' => $this->sort_order,
        ];
    }
}
