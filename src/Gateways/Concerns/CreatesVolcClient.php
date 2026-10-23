<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Larva\Ai\Volc\Gateways\Concerns;

use Illuminate\Http\Client\PendingRequest;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Providers\Provider;
use Laravel\Ai\Gateway\Concerns\CreatesClient;

/**
 * 火山方舟网关共用的 HTTP 客户端构建
 *
 * 依据 Provider 配置（url / key / headers）创建 OpenAI 兼容端点客户端。
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
trait CreatesVolcClient
{
    use CreatesClient;

    /**
     * 火山方舟 OpenAI 兼容接口 HTTP 客户端
     */
    protected function client(Provider $provider, ?int $timeout = null): PendingRequest
    {
        $config = $provider->additionalConfiguration();

        $url = $config['url'] ?? null;

        if (blank($url)) {
            throw new InvalidArgumentException("The [{$provider->name()}] provider requires a 'url' to be configured.");
        }

        $key = $provider->providerCredentials()['key'] ?? null;

        return $this->createClient(
            rtrim((string) $url, '/'),
            filled($key) ? ['Authorization' => 'Bearer '.$key] : [],
            $config['headers'] ?? [],
            $timeout ?? 300,
        );
    }
}
