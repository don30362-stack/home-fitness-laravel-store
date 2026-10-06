<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    protected $fillable = [
        'title', 'subtitle', 'image_path', 'button_text', 'link_url', 'sort_order', 'status',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
