<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Larva\Ai\Volc\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Providers\Concerns\GeneratesAudio;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\HasAudioGateway;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;
use Larva\Ai\Volc\Gateways\VolcAudioGateway;
use Larva\Ai\Volc\Gateways\VolcImageGateway;

/**
 * 火山方舟 Provider
 *
 * 继承官方 openai-compatible 驱动（文本对话 / 向量），并补齐火山方舟平台扩展能力：
 *  - TTS 语音合成：对接 OpenAI 兼容的 /audio/speech 端点（doubao-seed-tts）
 *  - Seedream 图像生成：对接 OpenAI 兼容的 /images/generations 端点（doubao-seedream）
 *  - Seedance 视频生成：异步任务模式（创建任务 → 轮询结果），SDK 暂无 VideoProvider 契约，由本类直接提供方法
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcProvider extends OpenAiCompatibleProvider implements AudioProvider, ImageProvider
{
    use GeneratesAudio;
    use GeneratesImages;
    use HandlesFailoverErrors;
    use HasAudioGateway;
    use HasImageGateway;

    /**
     * Get the provider's audio gateway.
     */
    #[\Override]
    public function audioGateway(): AudioGateway
    {
        return $this->audioGateway ??= new VolcAudioGateway;
    }

    /**
     * Get the provider's image gateway.
     */
    #[\Override]
    public function imageGateway(): ImageGateway
    {
        return $this->imageGateway ??= new VolcImageGateway;
    }

    /**
     * Get the name of the default audio (TTS) model.
     */
    #[\Override]
    public function defaultAudioModel(): string
    {
        return $this->config['models']['audio']['default'] ?? 'doubao-seed-tts-2.0';
    }

    /**
     * Get the name of the default image model.
     */
    #[\Override]
    public function defaultImageModel(): string
    {
        return $this->config['models']['image']['default'] ?? 'doubao-seedream-5-0-pro-260628';
    }

    /**
     * Get the default / normalized image options for the provider.
     *
     * Seedream 支持显式宽高像素（如 1536x1024）与 1K / 2K / 4K 分辨率档位；
     * 显式 size 优先，未指定时 quality 映射为档位：low → 1K、high → 4K，其余由模型默认（2K）。
     *
     * @param  string|null  $size
     * @param  'low'|'medium'|'high'|null  $quality
     * @return array
     */
    #[\Override]
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'size' => match ($size) {
                '1:1' => '1024x1024',
                '2:3' => '1024x1536',
                '3:2' => '1536x1024',
                default => $size,
            } ?? match ($quality) {
                'low' => '1K',
                'high' => '4K',
                default => null,
            },
        ]);
    }

    /**
     * Get the name of the default video generation model.
     */
    public function defaultVideoModel(): string
    {
        return $this->config['models']['video']['default'] ?? 'doubao-seedance-2-5-260628';
    }

    /**
     * 创建 Seedance 视频生成任务
     *
     * 视频生成为异步接口：本方法提交任务并返回任务 ID，通过 {@see getVideoResult()} 轮询任务状态。
     *
     * @param  array{model?: string, images?: array<int, array{url: string, role?: string}>, resolution?: string, ratio?: string, duration?: int, generate_audio?: bool, watermark?: bool, seed?: int, camera_fixed?: bool, return_last_frame?: bool, service_tier?: string, execution_expires_after?: int, callback_url?: string}  $options
     * @return array{id: string} 任务创建结果，id 为任务 ID（仅保存 7 天）
     *
     * @throws RequestException
     * @throws InsufficientCreditsException
     * @throws ProviderOverloadedException
     * @throws RateLimitedException
     */
    public function createVideoTask(string $prompt, array $options = []): array
    {
        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];

        // 图生视频：首帧 / 尾帧（first_frame / last_frame）或多模态参考图（reference_image）
        foreach ($options['images'] ?? [] as $image) {
            $content[] = [
                'type' => 'image_url',
                'image_url' => array_filter([
                    'url' => $image['url'],
                    'role' => $image['role'] ?? null,
                ]),
            ];
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultVideoModel(),
            'content' => $content,
            ...Arr::only($options, [
                'resolution', 'ratio', 'duration', 'generate_audio', 'watermark',
                'seed', 'camera_fixed', 'return_last_frame', 'service_tier',
                'execution_expires_after', 'callback_url',
            ]),
        ];

        return $this->withErrorHandling(
            $this->name(),
            fn (): array => $this->client()->post('contents/generations/tasks', $payload)->json()
        );
    }

    /**
     * 查询视频生成任务状态（仅支持查询最近 7 天的任务，视频 URL 有效期 24 小时）
     *
     * @return array{id: string, status: string, content?: array{video_url?: string}} status 取值：queued（排队中）/ running（运行中）/ succeeded（成功）/ failed（失败）/ expired（超时）
     *
     * @throws InsufficientCreditsException
     * @throws ProviderOverloadedException
     * @throws RateLimitedException
     * @throws RequestException
     */
    public function getVideoResult(string $taskId): array
    {
        return $this->withErrorHandling(
            $this->name(),
            fn (): array => $this->client()->get("contents/generations/tasks/{$taskId}")->json()
        );
    }

    /**
     * 火山方舟平台扩展接口共用的 HTTP 客户端
     */
    protected function client(?int $timeout = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->config['key'])
            ->timeout($timeout ?? 300)
            ->throw();
    }

    /**
     * 平台基础地址（配置缺省时抛出明确异常）
     */
    protected function baseUrl(): string
    {
        $url = $this->config['url'] ?? null;

        if (blank($url)) {
            throw new InvalidArgumentException("The [{$this->name()}] provider requires a 'url' to be configured.");
        }

        return rtrim((string) $url, '/');
    }
}
