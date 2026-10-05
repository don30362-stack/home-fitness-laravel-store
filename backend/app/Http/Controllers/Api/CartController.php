<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCartItemRequest;
use App\Http\Resources\CartResource;
use App\Http\Requests\UpdateCartItemRequest;
use App\Http\Requests\MergeCartRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CartController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $cart = $request->user()
            ->cart()
            ->firstOrCreate();

        return $this->cartResponse($cart);
    }

    public function store(StoreCartItemRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $cart = DB::transaction(function () use ($request, $validated) {
            $product = Product::findOrFail($validated['product_id']);

            $variant = $this->findProductVariant(
                $product,
                $validated['product_variant_id'] ?? null
            );

            $cart = $request->user()
                ->cart()
                ->firstOrCreate();

            $this->addItemToCart(
                $cart,
                $product,
                $variant,
                $validated['quantity']
            );

            return $cart;
        });

        return $this->cartResponse(
            $cart,
            '商品已加入購物車。'
        );
    }

    public function update(
        UpdateCartItemRequest $request,
        int $id
    ): JsonResponse {
        $cartItem = $this->findUserCartItem($request, $id);

        $cartItem->load([
            'product.category.parent',
            'productVariant',
        ]);

        $product = $cartItem->product;
        $variant = $cartItem->productVariant;

        if ($product->status !== 'active' || ! $product->hasEffectiveCategory()) {
            throw ValidationException::withMessages([
                'quantity' => '此商品目前無法購買。',
            ]);
        }

        if (
            $cartItem->product_variant_id !== null &&
            ($variant === null || $variant->status !== 'active')
        ) {
            throw ValidationException::withMessages([
                'quantity' => '此商品規格目前無法購買。',
            ]);
        }

        $quantity = $request->validated('quantity');
        $availableStock = $variant?->stock ?? $product->stock;

        if ($quantity > $availableStock) {
            throw ValidationException::withMessages([
                'quantity' => "商品庫存不足，目前可購買數量為 {$availableStock}。",
            ]);
        }

        $cartItem->update([
            'quantity' => $quantity,
        ]);

        return $this->cartResponse(
            $cartItem->cart,
            '購物車商品數量已更新。'
        );
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $cartItem = $this->findUserCartItem($request, $id);
        $cart = $cartItem->cart;

        $cartItem->delete();

        return $this->cartResponse(
            $cart,
            '商品已從購物車移除。'
        );
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $request->user()
            ->cart()
            ->firstOrCreate();

        $cart->items()->delete();

        return $this->cartResponse(
            $cart,
            '購物車已清空。'
        );
    }

    public function merge(
        MergeCartRequest $request
    ): JsonResponse {
        $validated = $request->validated();

        $cart = DB::transaction(function () use ($request, $validated) {
            $cart = $request->user()
                ->cart()
                ->firstOrCreate();

            foreach ($validated['items'] as $guestItem) {
                $product = Product::findOrFail(
                    $guestItem['product_id']
                );

                $variant = $this->findProductVariant(
                    $product,
                    $guestItem['product_variant_id'] ?? null
                );

                $this->addItemToCart(
                    $cart,
                    $product,
                    $variant,
                    $guestItem['quantity'],
                    true
                );
            }

            return $cart;
        });

        return $this->cartResponse(
            $cart,
            '訪客購物車已合併。'
        );
    }

    private function addItemToCart(
        Cart $cart,
        Product $product,
        ?ProductVariant $variant,
        int $quantity,
        bool $useHigherQuantity = false
    ): void {
        if ($product->status !== 'active' || ! $product->hasEffectiveCategory()) {
            throw ValidationException::withMessages([
                'product_id' => '此商品目前無法購買。',
            ]);
        }

        if ($variant !== null && $variant->status !== 'active') {
            throw ValidationException::withMessages([
                'product_variant_id' => '此商品規格目前無法購買。',
            ]);
        }

        $itemQuery = $cart->items()
            ->where('product_id', $product->id);

        if ($variant !== null) {
            $itemQuery->where(
                'product_variant_id',
                $variant->id
            );
        } else {
            $itemQuery->whereNull('product_variant_id');
        }

        $cartItem = $itemQuery->first();

        $currentQuantity = $cartItem?->quantity ?? 0;

        $newQuantity = $useHigherQuantity
            ? max($currentQuantity, $quantity)
            : $currentQuantity + $quantity;

        $availableStock = $variant?->stock ?? $product->stock;

        if ($newQuantity > $availableStock) {
            throw ValidationException::withMessages([
                'quantity' => "商品庫存不足，目前可購買數量為 {$availableStock}。",
            ]);
        }

        if ($cartItem !== null) {
            $cartItem->update([
                'quantity' => $newQuantity,
            ]);

            return;
        }

        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant?->id,
            'quantity' => $quantity,
        ]);
    }

    private function findProductVariant(Product $product, ?int $variantId): ?ProductVariant
    {
        if ($variantId === null) {
            if ($product->variants()->exists()) {
                throw ValidationException::withMessages([
                    'product_variant_id' => '請選擇商品規格。',
                ]);
            }

            return null;
        }

        $variant = ProductVariant::find($variantId);

        if ($variant === null || $variant->product_id !== $product->id) {
            throw ValidationException::withMessages([
                'product_variant_id' => '所選規格不屬於此商品。',
            ]);
        }

        return $variant;
    }

    private function loadCart(Cart $cart): Cart
    {
        return $cart->load([
            'items.product.images',
            'items.product.category.parent',
            'items.productVariant',
        ]);
    }

    private function findUserCartItem(
        Request $request,
        int $id
    ): CartItem {
        return CartItem::query()
            ->whereKey($id)
            ->whereHas('cart', function ($query) use ($request) {
                $query->where(
                    'user_id',
                    $request->user()->id
                );
            })
            ->firstOrFail();
    }

    /**
     * 建立固定為 200 的購物車回應。
     */
    private function cartResponse(
        Cart $cart,
        ?string $message = null
    ): JsonResponse {
        $resource = new CartResource(
            $this->loadCart($cart)
        );

        if ($message !== null) {
            $resource->additional([
                'message' => $message,
            ]);
        }

        return $resource
            ->response()
            ->setStatusCode(200);
    }
}
