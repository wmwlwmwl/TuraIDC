<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Install\FrontendConfigInjector;
use Illuminate\Console\Command;

/**
 * 将 backend/.env 中的真实站点地址注入三个前端 dist/index.html。
 *
 * 安装向导已会在安装成功时自动注入；但以下场景需要手动重跑：
 *   - 安装向导运行后，才把前端 dist 补进/重新构建到目录
 *   - 之后更换了域名，需要重新写入 dist
 * 命令幂等，重复执行只会覆盖旧注入。
 */
class InjectFrontendConfig extends Command
{
    protected $signature = 'turaidc:inject-frontend-config';

    protected $description = '将 backend/.env 的真实站点地址注入三个前端 dist/index.html（可重复执行，幂等）';

    /** @var array<int,string> */
    private const REQUIRED = ['APP_URL', 'FRONTEND_URL', 'CLIENT_CONSOLE_URL', 'ADMIN_URL'];

    public function handle(): int
    {
        $envPath = base_path('.env');
        if (! is_file($envPath)) {
            $this->error('backend/.env 不存在，请先完成安装向导或创建 .env。');

            return self::FAILURE;
        }

        // 直接解析 .env 文件，避免 config:cache 后 env() 取不到值。
        $raw = [];
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = (string) $line;
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $raw[trim($key)] = trim($value);
        }

        $addresses = [];
        foreach (self::REQUIRED as $key) {
            if (empty($raw[$key])) {
                $this->error("backend/.env 缺少 {$key}，请先填写真实地址。");

                return self::FAILURE;
            }
            $addresses[strtolower($key)] = $raw[$key];
        }

        $result = app(FrontendConfigInjector::class)->inject([
            'app_url' => $addresses['app_url'],
            'frontend_url' => $addresses['frontend_url'],
            'client_console_url' => $addresses['client_console_url'],
            'admin_url' => $addresses['admin_url'],
        ]);

        foreach ($result as $dir => $status) {
            $this->line("{$dir}: {$status}");
        }

        $this->info('前端运行时地址注入完成（基于 backend/.env）。');

        return self::SUCCESS;
    }
}
