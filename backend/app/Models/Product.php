<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_code',
        'category_id',
        'name',
        'price',
        'short_description',
        'description',
        'stock',
        'low_stock_threshold',
        'status',
    ];

    public function scopeSellable(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('products.status', 'active')->whereHas('category', fn ($child) => $child
            ->where('status', 'active')->whereNotNull('parent_id')
            ->whereHas('parent', fn ($parent) => $parent->whereNull('parent_id')->where('status', 'active')));
    }

    public function hasEffectiveCategory(): bool
    {
        return self::categoryIsEffective($this->category, $this->category?->parent);
    }

    public static function categoryIsEffective(?Category $child, ?Category $parent): bool
    {
        return $child !== null && $parent !== null
            && $child->parent_id !== null && (int) $child->parent_id === (int) $parent->id
            && $parent->parent_id === null && $child->status === 'active' && $parent->status === 'active';
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
