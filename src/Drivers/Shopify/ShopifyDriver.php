<?php

namespace Malikad778\LaravelNexus\Drivers\Shopify;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Malikad778\LaravelNexus\Contracts\InventoryDriver;
use Malikad778\LaravelNexus\DataTransferObjects\NexusInventoryUpdate;
use Malikad778\LaravelNexus\DataTransferObjects\NexusProduct;
use Malikad778\LaravelNexus\DataTransferObjects\RateLimitConfig;

class ShopifyDriver implements InventoryDriver
{
    /**
     * 从 REST Admin API 迁移到 GraphQL Admin API。
     *
     * 依据(逐条对应 Shopify 官方公告与 2026-07 schema):
     * 1. 公开 App 自 2025-02-01 起必须使用 GraphQL Product API,查询已弃用的 REST 商品资源
     *    将无法通过审核(changelog: public-apps-must-use-new-graphql-product-apis-...)。
     * 2. 库存写入改用 inventorySetQuantities,并传 changeFromQuantity 做 compare-and-swap,
     *    避免并发写入互相覆盖导致超卖。该字段在 schema 中虽可空,但官方明确说明
     *    自 2026-04 起不传会调用失败(changelog: 新的 compare and swap 语法)。
     * 3. GraphQL 即使业务失败也返回 HTTP 200,不能再用 HTTP 状态码判断成功,必须检查 userErrors。
     */
    protected string $apiVersion;

    protected string $graphqlUrl;

    public function __construct(protected array $config)
    {
        $this->apiVersion = $this->config['api_version'] ?? '2026-07';
        $this->graphqlUrl = "https://{$this->config['shop_url']}/admin/api/{$this->apiVersion}/graphql.json";
    }

    public function getProducts(Carbon $since): Collection
    {
        // GraphQL 用 query 字符串筛选,取代 REST 的 updated_at_min 参数
        $data = $this->graphql(<<<'GQL'
            query NexusGetProducts($query: String!) {
              products(first: 250, query: $query) {
                nodes {
                  id
                  title
                  variants(first: 100) {
                    nodes {
                      id
                      sku
                      price
                      inventoryQuantity
                      barcode
                      selectedOptions { value }
                    }
                  }
                }
              }
            }
        GQL, ['query' => 'updated_at:>='.$since->toIso8601String()]);

        return collect($data['products']['nodes'] ?? [])
            ->map(fn (array $node) => NexusProduct::fromShopify($this->normalizeProduct($node)));
    }

    public function fetchProduct(string $remoteId): NexusProduct
    {
        $data = $this->graphql(<<<'GQL'
            query NexusFetchProduct($id: ID!) {
              product(id: $id) {
                id
                title
                variants(first: 100) {
                  nodes {
                    id
                    sku
                    price
                    inventoryQuantity
                    barcode
                    selectedOptions { value }
                  }
                }
              }
            }
        GQL, ['id' => $this->toGid('Product', $remoteId)]);

        return NexusProduct::fromShopify($this->normalizeProduct($data['product'] ?? []));
    }

    public function updateInventory(string $remoteId, int $quantity): bool
    {
        $locationGid = $this->toGid('Location', (string) ($this->config['location_id'] ?? ''));

        // 先读当前库存: 既拿到 inventoryItem 的 GID,也拿到 compare-and-swap 的基准值
        $current = $this->graphql(<<<'GQL'
            query NexusVariantInventory($id: ID!, $locationId: ID!) {
              productVariant(id: $id) {
                inventoryItem {
                  id
                  inventoryLevel(locationId: $locationId) {
                    quantities(names: ["available"]) { name quantity }
                  }
                }
              }
            }
        GQL, ['id' => $this->toGid('ProductVariant', $remoteId), 'locationId' => $locationGid]);

        $inventoryItemId = $current['productVariant']['inventoryItem']['id'] ?? null;

        if (! $inventoryItemId) {
            return false;
        }

        $quantities = $current['productVariant']['inventoryItem']['inventoryLevel']['quantities'] ?? [];
        $changeFrom = collect($quantities)->firstWhere('name', 'available')['quantity'] ?? null;

        $result = $this->graphql(<<<'GQL'
            mutation NexusInventorySet($input: InventorySetQuantitiesInput!) {
              inventorySetQuantities(input: $input) {
                inventoryAdjustmentGroup { createdAt }
                userErrors { code field message }
              }
            }
        GQL, ['input' => [
            'name' => 'available',
            'reason' => 'correction',
            'quantities' => [array_filter([
                'inventoryItemId' => $inventoryItemId,
                'locationId' => $locationGid,
                'quantity' => $quantity,
                // 库存位尚未激活时读不到基准值,此时只能跳过 CAS 检查
                'changeFromQuantity' => $changeFrom,
            ], fn ($value) => $value !== null)],
        ]]);

        // GraphQL 业务错误走 userErrors,不体现在 HTTP 状态码上
        return empty($result['inventorySetQuantities']['userErrors'] ?? []);
    }

    public function pushInventory(NexusInventoryUpdate $update): bool
    {
        $remoteId = $update->remoteId;

        if (! $remoteId) {
            return false;
        }

        return $this->updateInventory($remoteId, $update->quantity);
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return $this->getWebhookVerifier()->verify($request);
    }

    public function extractWebhookTopic(Request $request): string
    {
        return $request->header('X-Shopify-Topic') ?? 'unknown';
    }

    public function getWebhookVerifier(): \Malikad778\LaravelNexus\Contracts\WebhookVerifier
    {
        return new \Malikad778\LaravelNexus\Webhooks\Verifiers\ShopifyWebhookVerifier;
    }

    public function parseWebhookPayload(Request $request): NexusInventoryUpdate
    {
        $payload = $request->json()->all();

        $sku = $payload['sku'] ?? $payload['variants'][0]['sku'] ?? 'unknown';
        $qty = $payload['inventory_quantity'] ?? $payload['variants'][0]['inventory_quantity'] ?? 0;
        $id = (string) ($payload['id'] ?? '');

        return new NexusInventoryUpdate(
            sku: $sku,
            quantity: (int) $qty,
            remoteId: $id,
            meta: $payload
        );
    }

    public function getRateLimitConfig(): RateLimitConfig
    {
        return new RateLimitConfig(
            capacity: 40,
            rate: 2,
            cost: 1
        );
    }

    public function getChannelName(): string
    {
        return 'shopify';
    }

    /**
     * 发送 GraphQL 请求并返回 data 部分。传输层与 GraphQL 顶层错误抛异常,
     * 业务层错误(userErrors)交由调用方判断。
     */
    protected function graphql(string $query, array $variables = []): array
    {
        $response = Http::withHeaders($this->getHeaders())
            ->post($this->graphqlUrl, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if ($response->failed()) {
            $response->throw();
        }

        $body = $response->json();

        if (! empty($body['errors'])) {
            throw new \RuntimeException('Shopify GraphQL error: '.json_encode($body['errors']));
        }

        return $body['data'] ?? [];
    }

    /**
     * 把 GraphQL 商品结构映射回 NexusProduct::fromShopify 期望的 REST 形状,
     * 避免改动被多个渠道共用的 DTO。
     */
    protected function normalizeProduct(array $node): array
    {
        if (empty($node)) {
            return [];
        }

        $variants = collect($node['variants']['nodes'] ?? [])->map(function (array $variant) {
            $options = collect($variant['selectedOptions'] ?? [])->pluck('value')->values();

            return [
                'id' => $this->fromGid($variant['id'] ?? ''),
                'sku' => $variant['sku'] ?? '',
                'price' => $variant['price'] ?? '0',
                'inventory_quantity' => $variant['inventoryQuantity'] ?? 0,
                'barcode' => $variant['barcode'] ?? null,
                'option1' => $options->get(0),
                'option2' => $options->get(1),
                'option3' => $options->get(2),
            ];
        })->all();

        return [
            'id' => $this->fromGid($node['id'] ?? ''),
            'title' => $node['title'] ?? '',
            'variants' => $variants,
        ];
    }

    /** 数字 ID 转 GraphQL 全局 ID;已是 gid:// 形式则原样返回 */
    protected function toGid(string $resource, string $id): string
    {
        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$resource}/{$id}";
    }

    /** 全局 ID 取回数字部分,保持与既有渠道映射数据的兼容 */
    protected function fromGid(string $gid): string
    {
        return str_contains($gid, '/') ? (string) substr($gid, strrpos($gid, '/') + 1) : $gid;
    }

    protected function getHeaders(): array
    {
        return [
            'X-Shopify-Access-Token' => $this->config['access_token'] ?? '',
        ];
    }
}
