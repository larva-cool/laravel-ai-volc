<?php

/**
 * This file is part of the larva/laravel-ai-volc package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace Larva\Ai\Volc\Gateways;

use Illuminate\Http\Client\Response;
use Laravel\Ai\Contracts\Gateway\AudioGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\Meta;
use Larva\Ai\Volc\Gateways\Concerns\CreatesVolcClient;

/**
 * 火山方舟 TTS 网关
 *
 * 对接火山方舟 OpenAI 兼容的 /audio/speech 端点：
 *  - voice 使用方舟音色 ID（如 zh_female_gaolengyujie_uranus_bigtts）；
 *    SDK 占位音色 default-female / default-male 映射为 models.audio 中配置的默认音色
 *  - 方舟不支持 OpenAI 的 instructions 参数，情绪 / 语气通过音色与提示文本控制，该参数会被忽略
 *
 * @author Tongle Xu <xutongle@gmail.com>
 */
class VolcAudioGateway implements AudioGateway
{
    use CreatesVolcClient;
    use HandlesFailoverErrors;

    /**
     * Generate audio from the given text.
     */
    public function generateAudio(
        AudioProvider $provider,
        string $model,
        string $text,
        string $voice,
        ?string $instructions = null,
        int $timeout = 300,
    ): AudioResponse {
        /** @var array{voice?: string, male_voice?: string, response_format?: string, speed?: float} $config */
        $config = $provider->additionalConfiguration()['models']['audio'] ?? [];

        $format = (string) ($config['response_format'] ?? 'mp3');

        $response = $this->withErrorHandling(
            $provider->name(),
            fn (): Response => $this->client($provider, $timeout)->post('audio/speech', [
                'model' => $model,
                'input' => $text,
                'voice' => $this->resolveVoice($voice, $config),
                'response_format' => $format,
                'speed' => (float) ($config['speed'] ?? 1.0),
            ]),
        );

        return new AudioResponse(
            base64_encode($response->body()),
            new Meta($provider->name(), $model),
            $this->mimeType($format),
        );
    }

    /**
     * 将 SDK 占位音色映射为方舟音色 ID
     *
     * @param  array{voice?: string, male_voice?: string}  $config
     */
    protected function resolveVoice(string $voice, array $config): string
    {
        return match ($voice) {
            'default-female' => (string) ($config['voice'] ?? 'zh_female_gaolengyujie_uranus_bigtts'),
            'default-male' => (string) ($config['male_voice'] ?? 'zh_male_qingshuangnanda_mars_bigtts'),
            default => $voice,
        };
    }

    /**
     * 输出格式对应的 MIME 类型
     */
    protected function mimeType(string $format): string
    {
        return match ($format) {
            'wav' => 'audio/wav',
            'pcm' => 'audio/pcm',
            'ogg_opus', 'opus' => 'audio/ogg',
            default => 'audio/mpeg',
        };
    }
}
