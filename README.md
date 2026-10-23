# Laravel AI · 火山方舟（Volcengine Ark）驱动

为 [Laravel AI](https://github.com/laravel/ai) 提供火山引擎方舟平台（Volcengine Ark）接入驱动，在官方 `openai-compatible` 驱动的文本对话 / 向量能力之上，补齐方舟平台的多模态扩展能力：

- 💬 **文本对话 / 向量嵌入**：继承官方 OpenAI 兼容驱动（豆包大模型）
- 🔊 **TTS 语音合成**：对接 OpenAI 兼容的 `/audio/speech` 端点（doubao-seed-tts）
- 🎨 **Seedream 图像生成**：对接 OpenAI 兼容的 `/images/generations` 端点（doubao-seedream）
- 🎬 **Seedance 视频生成**：异步任务模式（创建任务 → 轮询结果）

[![License](https://poser.pugx.org/larva/laravel-ai-volc/license.svg)](https://packagist.org/packages/larva/laravel-ai-volc)
[![Latest Stable Version](https://poser.pugx.org/larva/laravel-ai-volc/v/stable.png)](https://packagist.org/packages/larva/laravel-ai-volc)
[![Total Downloads](https://poser.pugx.org/larva/laravel-ai-volc/downloads.png)](https://packagist.org/packages/larva/laravel-ai-volc)

## 环境需求

- PHP `^8.4`
- Laravel `^12.0` / `^13.0`
- `laravel/ai` `^0.10.3`
- 火山方舟 API Key（[控制台获取](https://console.volcengine.com/ark)）

## 安装

```bash
composer require larva/laravel-ai-volc
```

包通过 Laravel Package Discovery 自动注册 `volc` 驱动，无需手动添加 ServiceProvider。

## 配置

在 `config/ai.php` 的 `providers` 中新增火山方舟接入点：

```php
'providers' => [
    // ...

    'volc' => [
        'driver' => 'volc',
        'key' => env('VOLC_ARK_KEY'),
        'url' => env('VOLC_ARK_URL', 'https://ark.cn-beijing.volces.com/api/v3'),

        // 可选：默认模型与网关参数
        'models' => [
            'text' => [
                'default' => env('VOLC_TEXT_MODEL', 'doubao-seed-2-1-lite-260915'), // 默认文本模型
            ],
            'embeddings' => [
                'default' => env('VOLC_EMBEDDINGS_MODEL', 'doubao-embedding-vision-251215'), // 默认向量模型
                'dimensions' => env('VOLC_EMBEDDINGS_DIMENSIONS', 2048), // 向量维度：支持传入dimensions参数降维1024使用
            ],
            'audio' => [
                'default' => env('VOLC_AUDIO_MODEL', 'doubao-seed-tts-2.0'),   // 默认 TTS 模型
                'voice' => env('VOLC_AUDIO_VOICE', 'zh_female_gaolengyujie_uranus_bigtts'), // 默认女声
                'male_voice' => env('VOLC_AUDIO_MALE_VOICE', 'zh_male_qingshuangnanda_mars_bigtts'), // 默认男声
                'response_format' => env('VOLC_AUDIO_RESPONSE_FORMAT', 'mp3'), // mp3 / wav / pcm / ogg_opus
                'speed' => env('VOLC_AUDIO_SPEED', 1.0), // 语速 0.5 ~ 2.0
            ],
            'image' => [
                'default' => env('VOLC_IMAGE_MODEL', 'doubao-seedream-5-0-pro-260628'), // 默认图像模型
                'watermark' => false, // 是否添加水印
            ],
            'video' => [
                'default' => env('VOLC_VIDEO_MODEL', 'doubao-seedance-2-5-260628'), // 默认视频模型
            ],
        ],
    ],
],
```

`.env`：

```dotenv
VOLC_ARK_KEY=your-ark-api-key
VOLC_ARK_URL=https://ark.cn-beijing.volces.com/api/v3
```

如需将火山方舟设为默认提供方：

```dotenv
AI_DEFAULT_PROVIDER=volc
```

## 使用

### 文本对话 / 向量嵌入

文本与向量能力继承自官方 OpenAI 兼容驱动，用法与 [Laravel AI 官方文档](https://github.com/laravel/ai) 一致，将提供方指定为 `volc` 即可：

```php
use Laravel\Ai\Ai;

$embeddings = Ai::embeddingProvider('volc')
    ->embeddings(['火山引擎是字节跳动旗下的云服务平台'], model: 'doubao-embedding-large');
```

### TTS 语音合成

```php
use Laravel\Ai\Ai;

$audio = Ai::audioProvider('volc')->audio('你好，欢迎来到火山方舟。');

// 保存为 mp3
$audio->store('greeting.mp3', 'public');
```

SDK 占位音色 `default-female` / `default-male` 会映射为配置中的 `voice` / `male_voice`；也可以直接传入方舟音色 ID：

```php
$audio = Ai::audioProvider('volc')->audio(
    '今天天气真不错。',
    voice: 'zh_female_wanwanxiaohe_moon_bigtts',
);
```

> 方舟不支持 OpenAI 的 `instructions` 参数，情绪 / 语气通过音色与提示文本控制。

### Seedream 图像生成

```php
use Laravel\Ai\Ai;

$image = Ai::imageProvider('volc')->image(
    '一只在草地上奔跑的柴犬，阳光明媚，摄影风格',
    size: '3:2',
);

$image->store('shiba.png', 'public');
```

尺寸与质量映射规则：显式 `size`（支持 `1:1` / `2:3` / `3:2` 比例或 `1536x1024` 显式像素）优先；未指定时 `quality` 映射为方舟分辨率档位（`low` → 1K、`medium` → 2K、`high` → 4K）。

```php
use Laravel\Ai\Files\RemoteImage;

// 图生图 / 多图参考：支持 RemoteImage / LocalImage / StoredImage / Base64Image
$image = Ai::imageProvider('volc')->image(
    '参考这张图的构图重新绘制',
    [new RemoteImage('https://example.com/ref.png')],
);
```

### Seedance 视频生成

视频生成为方舟异步任务接口（SDK 暂无 VideoProvider 契约），由 `VolcProvider` 直接提供：

```php
use Laravel\Ai\Ai;

/** @var \Larva\Ai\Volc\Providers\VolcProvider $provider */
$provider = Ai::instance('volc');

// 1. 创建任务（文生视频）
$task = $provider->createVideoTask('一只猫在草地上奔跑，镜头缓慢推进', [
    'duration' => 5,          // 时长（秒）
    'resolution' => '1080p',  // 480p / 720p / 1080p
    'ratio' => '16:9',        // 16:9 / 9:16 / 1:1 / 4:3 / 3:4 / 21:9
    'watermark' => false,
]);

// 图生视频：首帧 / 尾帧 / 多模态参考图
$task = $provider->createVideoTask('让画面动起来', [
    'images' => [
        ['url' => 'https://example.com/first.png', 'role' => 'first_frame'],
        ['url' => 'https://example.com/last.png', 'role' => 'last_frame'],
    ],
]);

// 2. 轮询任务状态
$result = $provider->getVideoResult($task['id']);

// status：queued / running / succeeded / failed / expired
if ($result['status'] === 'succeeded') {
    $videoUrl = $result['content']['video_url']; // URL 有效期 24 小时
}
```

> 任务 ID 仅保存 7 天，生成完成后请及时下载视频。

## 异常处理

网关统一使用 Laravel AI 的异常体系，可在调用处捕获：

- `Laravel\Ai\Exceptions\RateLimitedException`：触发限流
- `Laravel\Ai\Exceptions\InsufficientCreditsException`：余额不足
- `Laravel\Ai\Exceptions\ProviderOverloadedException`：服务过载
- `Illuminate\Http\Client\RequestException`：其它 HTTP 错误

## 开发与测试

```bash
composer install
composer test          # 运行单元测试
composer check-style   # 代码风格检查
composer fix-style     # 自动修复代码风格
```

## 贡献

欢迎提交 Issue 与 Pull Request。提交前请：

1. 通过 `composer test` 全部测试
2. 通过 `composer check-style` 风格检查
3. 在 PR 中描述清楚变更背景与影响范围

## 安全

如发现安全漏洞，请联系：<xutongle@msn.com>，请勿在公开 Issue 中披露。

## 许可证

本项目基于 [MIT](LICENSE) 协议发布。
