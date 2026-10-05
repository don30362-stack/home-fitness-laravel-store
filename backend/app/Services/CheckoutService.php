<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\District;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    private const SHIPPING_FEE_CENTS = 10_000;

    /**
     * @param array<string, mixed> $data
     */
    public function checkout(
        User $user,
        array $data
    ): Order {
        return DB::transaction(function () use (
            $user,
            $data
        ): Order {
            $cart = $this->findLockedCart($user);

            $cartItems =
                $this->getLockedCartItems($cart);

            $district = $this->findDistrict(
                (int) $data['recipient']['district_id']
            );

            $preparedOrder =
                $this->prepareOrderItems($cartItems);

            $statuses =
                $this->resolvePaymentStatuses(
                    $data['payment_method']
                );

            $order = Order::query()->create(
                $this->buildOrderData(
                    $user,
                    $data,
                    $district,
                    $preparedOrder['subtotal_cents'],
                    $statuses['payment_status'],
                    $statuses['order_status']
                )
            );

            foreach (
                $preparedOrder['items'] as $preparedItem
            ) {
                $order->items()->create(
                    $preparedItem['order_item_data']
                );

                $this->decrementStock(
                    $preparedItem['cart_item'],
                    $preparedItem['product'],
                    $preparedItem['variant']
                );
            }

            foreach ($cartItems as $cartItem) {
                $cartItem->delete();
            }

            return $order->load('items');
        }, 3);
    }

    private function findLockedCart(User $user): Cart
    {
        $cart = Cart::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($cart === null) {
            throw ValidationException::withMessages([
                'cart' => '購物車目前沒有商品，請先加入商品後再結帳。',
            ]);
        }

        return $cart;
    }

    /**
     * @return Collection<int, CartItem>
     */
    private function getLockedCartItems(Cart $cart): Collection
    {
        $items = CartItem::query()
            ->where('cart_id', $cart->id)
            ->orderBy('product_id')
            ->orderBy('product_variant_id')
            ->lockForUpdate()
            ->get();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'cart' => '購物車目前沒有商品，請先加入商品後再結帳。',
            ]);
        }

        return $items;
    }

    private function findLockedProduct(
        CartItem $cartItem
    ): Product {
        $product = Product::query()
            ->whereKey($cartItem->product_id)
            ->lockForUpdate()
            ->first();

        if ($product === null || $product->status !== 'active') {
            throw ValidationException::withMessages([
                'cart' => '購物車內有已下架或不存在的商品。',
            ]);
        }

        if (
            $cartItem->product_variant_id === null
            && $product->variants()->exists()
        ) {
            throw ValidationException::withMessages([
                'cart' => "商品「{$product->name}」需要重新選擇規格。",
            ]);
        }

        // Keep Product first, then child, parent, and finally Variant in prepareOrderItems.
        // Locking reads must recheck the current hierarchy/status, never the cart's cached display.
        $child = Category::query()->whereKey($product->category_id)->lockForUpdate()->first();
        $parent = $child?->parent_id !== null
            ? Category::query()->whereKey($child->parent_id)->lockForUpdate()->first()
            : null;
        if (! Product::categoryIsEffective($child, $parent)) {
            throw ValidationException::withMessages(['cart' => '商品分類目前無法購買']);
        }

        return $product;
    }

    private function findLockedVariant(
        CartItem $cartItem,
        Product $product
    ): ?ProductVariant {
        if ($cartItem->product_variant_id === null) {
            return null;
        }

        $variant = ProductVariant::query()
            ->whereKey($cartItem->product_variant_id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($variant === null || $variant->status !== 'active') {
            throw ValidationException::withMessages([
                'cart' => "商品「{$product->name}」的規格目前無法購買。",
            ]);
        }

        return $variant;
    }

    private function ensureEnoughStock(
        CartItem $cartItem,
        Product $product,
        ?ProductVariant $variant
    ): void {
        $availableStock = $variant !== null
            ? (int) $variant->stock
            : (int) ($product->stock ?? 0);

        if ($cartItem->quantity > $availableStock) {
            throw ValidationException::withMessages([
                'cart' => "商品「{$product->name}」庫存不足，目前可購買數量為 {$availableStock}。",
            ]);
        }
    }

    private function decrementStock(
        CartItem $cartItem,
        Product $product,
        ?ProductVariant $variant
    ): void {
        if ($variant !== null) {
            $variant->stock =
                (int) $variant->stock - $cartItem->quantity;

            $variant->save();

            return;
        }

        $product->stock =
            (int) $product->stock - $cartItem->quantity;

        $product->save();
    }

    private function buildVariantSnapshot(
        ?ProductVariant $variant
    ): ?string {
        if ($variant === null) {
            return null;
        }

        return "{$variant->option_name}：{$variant->option_value}";
    }

    /**
     * @return array{
     *     product_id: int,
     *     product_variant_id: int|null,
     *     product_code_snapshot: string,
     *     product_name_snapshot: string,
     *     variant_snapshot: string|null,
     *     unit_price: string,
     *     quantity: int,
     *     subtotal: string
     * }
     */
    private function buildOrderItemData(
        CartItem $cartItem,
        Product $product,
        ?ProductVariant $variant
    ): array {
        $unitPriceCents = $this->moneyToCents(
            (string) $product->price
        );

        $subtotalCents = $this->calculateItemSubtotalCents(
            $product,
            $cartItem->quantity
        );

        return [
            'product_id' => (int) $product->id,
            'product_variant_id' => $variant !== null
                ? (int) $variant->id
                : null,
            'product_code_snapshot' => $product->product_code,
            'product_name_snapshot' => $product->name,
            'variant_snapshot' => $this->buildVariantSnapshot($variant),
            'unit_price' => $this->formatMoney($unitPriceCents),
            'quantity' => (int) $cartItem->quantity,
            'subtotal' => $this->formatMoney($subtotalCents),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function buildOrderData(
        User $user,
        array $data,
        District $district,
        int $subtotalCents,
        string $paymentStatus,
        string $orderStatus
    ): array {
        $totalAmountCents =
            $subtotalCents + self::SHIPPING_FEE_CENTS;

        return [
            'user_id' => (int) $user->id,
            'order_no' => $this->generateOrderNo(),

            'purchaser_name' => $data['purchaser']['name'],
            'purchaser_phone' => $data['purchaser']['phone'],
            'purchaser_email' => $data['purchaser']['email'],

            'recipient_name' => $data['recipient']['name'],
            'recipient_phone' => $data['recipient']['phone'],
            'postal_code' => $district->postal_code,
            'city' => $district->city->name,
            'district' => $district->name,
            'address' => $data['recipient']['address'],

            'shipping_method' => $data['shipping_method'],
            'shipping_fee' => $this->formatMoney(
                self::SHIPPING_FEE_CENTS
            ),

            'payment_method' => $data['payment_method'],
            'payment_status' => $paymentStatus,
            'order_status' => $orderStatus,

            'subtotal' => $this->formatMoney($subtotalCents),
            'total_amount' => $this->formatMoney(
                $totalAmountCents
            ),
        ];
    }

    /**
     * @return array{
     *     payment_status: string,
     *     order_status: string
     * }
     */
    private function resolvePaymentStatuses(
        string $paymentMethod
    ): array {
        if ($paymentMethod === 'cod') {
            return [
                'payment_status' => 'unpaid',
                'order_status' => 'pending',
            ];
        }

        if ($paymentMethod !== 'mock_credit_card') {
            throw ValidationException::withMessages([
                'payment_method' => '選擇的付款方式不正確。',
            ]);
        }

        if (
            config(
                'services.mock_credit_card.should_fail',
                false
            ) === true
        ) {
            throw ValidationException::withMessages([
                'payment_method' =>
                '模擬信用卡付款失敗，請重新嘗試或更換付款方式。',
            ]);
        }

        return [
            'payment_status' => 'paid',
            'order_status' => 'pending',
        ];
    }

    /**
     * @param Collection<int, CartItem> $cartItems
     *
     * @return array{
     *     items: array<int, array{
     *         cart_item: CartItem,
     *         product: Product,
     *         variant: ProductVariant|null,
     *         order_item_data: array<string, mixed>
     *     }>,
     *     subtotal_cents: int
     * }
     */
    private function prepareOrderItems(
        Collection $cartItems
    ): array {
        $preparedItems = [];
        $subtotalCents = 0;

        foreach ($cartItems as $cartItem) {
            $product = $this->findLockedProduct($cartItem);

            $variant = $this->findLockedVariant(
                $cartItem,
                $product
            );

            $this->ensureEnoughStock(
                $cartItem,
                $product,
                $variant
            );

            $subtotalCents +=
                $this->calculateItemSubtotalCents(
                    $product,
                    $cartItem->quantity
                );

            $preparedItems[] = [
                'cart_item' => $cartItem,
                'product' => $product,
                'variant' => $variant,
                'order_item_data' =>
                $this->buildOrderItemData(
                    $cartItem,
                    $product,
                    $variant
                ),
            ];
        }

        return [
            'items' => $preparedItems,
            'subtotal_cents' => $subtotalCents,
        ];
    }

    private function findDistrict(int $districtId): District
    {
        $district = District::query()
            ->with('city')
            ->find($districtId);

        if ($district === null || $district->city === null) {
            throw ValidationException::withMessages([
                'recipient.district_id' => '選擇的行政區不存在。',
            ]);
        }

        return $district;
    }

    private function generateOrderNo(): string
    {
        return 'HF-' . Str::ulid();
    }

    private function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(
            explode('.', $amount, 2),
            2,
            '0'
        );

        $fraction = str_pad(
            substr($fraction, 0, 2),
            2,
            '0'
        );

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function calculateItemSubtotalCents(
        Product $product,
        int $quantity
    ): int {
        return $this->moneyToCents(
            (string) $product->price
        ) * $quantity;
    }

    private function formatMoney(int $amountInCents): string
    {
        $whole = intdiv($amountInCents, 100);
        $fraction = $amountInCents % 100;

        return $whole . '.' . str_pad(
            (string) $fraction,
            2,
            '0',
            STR_PAD_LEFT
        );
    }
}
