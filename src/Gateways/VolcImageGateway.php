<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Larva\Ai\Volc\Gateways;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Base64Image;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\LocalImage;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\ImageResponse;
use Larva\Ai\Volc\Gateways\Concerns\CreatesVolcClient;

/**
 * 火山方舟图像生成网关（Seedream）
 *
 * 对接火山方舟 OpenAI 兼容的 /images/generations 端点：
 *  - 参考图（图生图 / 多图生图）经 image 参数传入：远端 URL 直接透传，本地 / 存储盘文件统一转为 Base64 Data URI
 *  - 固定以 b64_json 返回（ImageResponse 以 Base64 存储图像）
 *  - 显式 size 优先；未指定时 quality 映射为方舟分辨率档位：low → 1K、medium → 2K（模型默认）、high → 4K
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcImageGateway implements ImageGateway
{
    use CreatesVolcClient;
    use HandlesFailoverErrors;

    /**
     * Generate an image.
     *
     * @param  ImageProvider  $provider
     * @param  string  $model
     * @param  string  $prompt
     * @param  array<Image>  $attachments
     * @param  string|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     * @param  int|null  $timeout
     * @return ImageResponse
     * @throws InsufficientCreditsException
     * @throws ProviderOverloadedException
     * @throws RateLimitedException
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
    ): ImageResponse {
        /** @var array{watermark?: bool} $config */
        $config = $provider->additionalConfiguration()['models']['image'] ?? [];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn (): Response => $this->client($provider, $timeout ?? 120)->post('images/generations', array_filter([
                'model' => $model,
                'prompt' => $prompt,
                ...$provider->defaultImageOptions($size, $quality),
                'image' => $this->mapAttachments($attachments),
                'response_format' => 'b64_json',
                'watermark' => $config['watermark'] ?? null,
            ], fn (mixed $value): bool => $value !== null)),
        );

        $data = $response->json();

        return new ImageResponse(
            collect($data['data'] ?? [])->map(fn (array $image): GeneratedImage => new GeneratedImage(
                $image['b64_json'] ?? '',
                'image/png',
            )),
            new Usage(
                (int) ($data['usage']['prompt_tokens'] ?? $data['usage']['input_tokens'] ?? 0),
                (int) ($data['usage']['completion_tokens'] ?? $data['usage']['output_tokens'] ?? 0),
            ),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * 参考图映射为方舟 image 参数（无附件返回 null，单图返回字符串，多图返回数组）
     *
     * @param  array<Image>  $attachments
     */
    protected function mapAttachments(array $attachments): string|array|null
    {
        $images = collect($attachments)->map(fn (Image $attachment): string => $this->mapAttachment($attachment));

        return match ($images->count()) {
            0 => null,
            1 => $images->first(),
            default => $images->all(),
        };
    }

    /**
     * 单个参考图映射为公网 URL 或 Base64 Data URI
     */
    protected function mapAttachment(Image $attachment): string
    {
        return match (true) {
            $attachment instanceof RemoteImage => $attachment->url,
            $attachment instanceof Base64Image => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.$attachment->base64,
            $attachment instanceof LocalImage => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.base64_encode((string) file_get_contents($attachment->path)),
            $attachment instanceof StoredImage => 'data:'.($attachment->mimeType() ?? 'image/png').';base64,'.base64_encode(
                (string) Storage::disk($attachment->disk)->get($attachment->path)
            ),
            default => throw new InvalidArgumentException('Unsupported image attachment type ['.$attachment::class.']'),
        };
    }
}
