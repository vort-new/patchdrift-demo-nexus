<?php

use Illuminate\Support\Facades\Http;
use Malikad778\LaravelNexus\DataTransferObjects\NexusProduct;
use Malikad778\LaravelNexus\Facades\Nexus;

/*
 * 夹具更新说明(随 REST → GraphQL 迁移一同提交,但独立成 commit 以便单独审阅)
 *
 * 原测试用 Http::fake() 桩住 REST 端点 URL。驱动改用 graphql.json 之后,
 * 这些桩不再匹配任何请求 —— 测试不会断言失败,而是让请求逃逸到真实网络
 * (实测收到 Shopify 真实的 401)。也就是说测试停止了对代码的验证。
 *
 * 因此下列改动是必要的,且每一处都对应驱动的一个真实行为:
 *  - 桩改指向 graphql.json,用 Http::sequence() 表达"先查询后变更"的两步调用
 *  - 断言从"REST 请求体字段"改为"GraphQL 变量结构",覆盖 GID 转换
 *  - 新增两个用例覆盖迁移引入的新语义: compare-and-swap 基准值,
 *    以及 userErrors 导致的失败(GraphQL 业务失败仍返回 HTTP 200)
 */

it('can fetch products from shopify', function () {
    config()->set('nexus.drivers.shopify', [
        'shop_url' => 'test-shop.myshopify.com',
        'access_token' => 'test-token',
        'api_version' => '2026-07',
    ]);

    Http::fake([
        'test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
            'data' => [
                'products' => [
                    'nodes' => [
                        [
                            'id' => 'gid://shopify/Product/123456',
                            'title' => 'Test Product',
                            'variants' => [
                                'nodes' => [
                                    [
                                        'id' => 'gid://shopify/ProductVariant/987654',
                                        'sku' => 'TEST-SKU',
                                        'price' => '19.99',
                                        'inventoryQuantity' => 10,
                                        'barcode' => null,
                                        'selectedOptions' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $driver = Nexus::driver('shopify');
    $products = $driver->getProducts(now()->subDay());

    expect($products)->toHaveCount(1);
    expect($products->first())->toBeInstanceOf(NexusProduct::class);
    expect($products->first()->sku)->toBe('TEST-SKU');
    expect($products->first()->name)->toBe('Test Product');
    expect($products->first()->price)->toBe(19.99);
    expect($products->first()->quantity)->toBe(10);
    // GID 转回数字 ID,保持与既有渠道映射数据兼容
    expect($products->first()->id)->toBe('123456');
});

it('can update inventory on shopify', function () {
    config()->set('nexus.drivers.shopify', [
        'shop_url' => 'test-shop.myshopify.com',
        'access_token' => 'test-token',
        'api_version' => '2026-07',
        'location_id' => '888888',
    ]);

    // 迁移后是两次 GraphQL 调用: 先读当前库存(拿 inventoryItem GID 与 CAS 基准值),再写入
    Http::fake([
        'test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['productVariant' => ['inventoryItem' => [
                'id' => 'gid://shopify/InventoryItem/777777',
                'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 42]]],
            ]]]], 200)
            ->push(['data' => ['inventorySetQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => '2026-07-29T00:00:00Z'],
                'userErrors' => [],
            ]]], 200),
    ]);

    $driver = Nexus::driver('shopify');
    $result = $driver->updateInventory('987654', 50);

    expect($result)->toBeTrue();

    // 断言写入请求携带了正确的 GID 与 compare-and-swap 基准值
    Http::assertSent(function ($request) {
        $body = $request->data();

        if (! isset($body['variables']['input'])) {
            return false;
        }

        $input = $body['variables']['input'];
        $quantity = $input['quantities'][0];

        return $request->url() === 'https://test-shop.myshopify.com/admin/api/2026-07/graphql.json'
            && $input['name'] === 'available'
            && $input['reason'] === 'correction'
            && $quantity['inventoryItemId'] === 'gid://shopify/InventoryItem/777777'
            && $quantity['locationId'] === 'gid://shopify/Location/888888'
            && $quantity['quantity'] === 50
            // 基准值来自第一次查询,缺了它 Shopify 自 2026-04 起会拒绝调用
            && $quantity['changeFromQuantity'] === 42;
    });
});

it('fails inventory update when shopify reports a stale compare-and-swap baseline', function () {
    config()->set('nexus.drivers.shopify', [
        'shop_url' => 'test-shop.myshopify.com',
        'access_token' => 'test-token',
        'api_version' => '2026-07',
        'location_id' => '888888',
    ]);

    // GraphQL 业务失败仍返回 HTTP 200,成功与否只能看 userErrors
    Http::fake([
        'test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::sequence()
            ->push(['data' => ['productVariant' => ['inventoryItem' => [
                'id' => 'gid://shopify/InventoryItem/777777',
                'inventoryLevel' => ['quantities' => [['name' => 'available', 'quantity' => 42]]],
            ]]]], 200)
            ->push(['data' => ['inventorySetQuantities' => [
                'inventoryAdjustmentGroup' => null,
                'userErrors' => [[
                    'code' => 'CHANGE_FROM_QUANTITY_STALE',
                    'field' => ['input', 'quantities', '0', 'changeFromQuantity'],
                    'message' => 'The quantity has changed since it was read.',
                ]],
            ]]], 200),
    ]);

    $driver = Nexus::driver('shopify');

    // 这条路径正是防超卖的保护:并发写入时必须失败而不是静默覆盖
    expect($driver->updateInventory('987654', 50))->toBeFalse();
});

it('fails inventory update when the variant has no inventory item', function () {
    config()->set('nexus.drivers.shopify', [
        'shop_url' => 'test-shop.myshopify.com',
        'access_token' => 'test-token',
        'api_version' => '2026-07',
        'location_id' => '888888',
    ]);

    Http::fake([
        'test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(
            ['data' => ['productVariant' => null]], 200
        ),
    ]);

    $driver = Nexus::driver('shopify');

    expect($driver->updateInventory('nonexistent', 50))->toBeFalse();
});

/*
 * 以下用例的响应桩由 Shopify 自己的 2026-07 schema 生成(逐个可空点置 null),
 * 不是人手编的 —— 这样"用自己写的桩验证自己写的代码"的循环论证才被打破。
 * 生成器: tools/mock-response.mjs --variant nulls-each
 */

it('throws a clear error when the product does not exist', function () {
    config()->set('nexus.drivers.shopify', [
        'shop_url' => 'test-shop.myshopify.com',
        'access_token' => 'test-token',
        'api_version' => '2026-07',
    ]);

    // schema 生成的桩: data.product 可空,置 null
    // REST 时代这是 404 + 异常;GraphQL 返回 200 + null,不拦截就会变成 DTO 里的 TypeError
    Http::fake([
        'test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response(
            ['data' => ['product' => null]], 200
        ),
    ]);

    $driver = Nexus::driver('shopify');

    expect(fn () => $driver->fetchProduct('999999'))
        ->toThrow(RuntimeException::class);
});

it('handles null scalars in product variants', function () {
    config()->set('nexus.drivers.shopify', [
        'shop_url' => 'test-shop.myshopify.com',
        'access_token' => 'test-token',
        'api_version' => '2026-07',
    ]);

    // schema 生成的桩: sku / inventoryQuantity / barcode 在合约里都是可空的
    Http::fake([
        'test-shop.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
            'data' => ['products' => ['nodes' => [[
                'id' => 'gid://shopify/Product/1',
                'title' => 'sample-title',
                'variants' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/1',
                    'sku' => null,
                    'price' => '19.99',
                    'inventoryQuantity' => null,
                    'barcode' => null,
                    'selectedOptions' => [],
                ]]],
            ]]]],
        ], 200),
    ]);

    $products = Nexus::driver('shopify')->getProducts(now()->subDay());

    expect($products)->toHaveCount(1);
    expect($products->first()->sku)->toBe('');
    expect($products->first()->quantity)->toBe(0);
    expect($products->first()->barcode)->toBeNull();
});
