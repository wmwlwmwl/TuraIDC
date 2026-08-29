<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * 把真实前端地址以 window.__APP_CONFIG__ 注入各前端 index.html
 *（兼容 frontend-x/dist/index.html 与扁平化发行包的 frontend-x/index.html），
 * 配合 vite define 的运行时覆盖，使前端无需为换域名而重建。
 *
 * 同源逻辑被安装向导（InstallService）与 turaidc:inject-frontend-config
 * 命令共用，保证行为一致。可重复执行（幂等）。
 */
class FrontendConfigInjector
{
    /** 需要注入的前端目录（与仓库前端布局一致） */
    private const FRONTENDS = [
        'frontend-user-v3-www',
        'frontend-user-v4-console',
        'frontend-admin-v3',
    ];

    /**
     * @param  array{app_url:string,frontend_url:string,client_console_url:string,admin_url:string}  $addresses
     * @return array<string,string> 每个目录的处理结果（injected / missing-dist / missing-index）
     */
    public function inject(array $addresses): array
    {
        $apiOrigin = rtrim((string) $addresses['app_url'], '/').'/api';

        $runtime = [
            'VITE_API_BASE_URL' => $apiOrigin,
            'VITE_PUBLIC_SITE_URL' => (string) $addresses['frontend_url'],
            'VITE_CONSOLE_SITE_URL' => (string) $addresses['client_console_url'],
            'VITE_BASE_URL' => '/',
            'VITE_SESSION_COOKIE_DOMAIN' => '',
        ];

        $replacements = [
            'https://api.example.com/api' => $apiOrigin,
            'https://www.example.com' => (string) $addresses['frontend_url'],
            'https://console.example.com' => (string) $addresses['client_console_url'],
            'https://admin.example.com' => (string) $addresses['admin_url'],
        ];

        $script = '<script>window.__APP_CONFIG__='.json_encode($runtime, JSON_UNESCAPED_SLASHES).';</script>';

        $results = [];
        foreach (self::FRONTENDS as $dir) {
            // 兼容两种布局：源码构建产物在 frontend-x/dist/index.html；
            // 扁平化发行包则直接是 frontend-x/index.html（无 dist 子目录）。
            // 优先 dist（避免误伤源码根目录的 Vite 入口 index.html），否则回落目录根。
            $distIndex = base_path('..'.DIRECTORY_SEPARATOR.$dir.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'index.html');
            $rootIndex = base_path('..'.DIRECTORY_SEPARATOR.$dir.DIRECTORY_SEPARATOR.'index.html');

            if (is_file($distIndex)) {
                $index = $distIndex;
            } elseif (is_file($rootIndex)) {
                $index = $rootIndex;
            } else {
                $results[$dir] = 'missing-index';

                continue;
            }

            $html = (string) file_get_contents($index);

            // 先清掉旧注入（重跑/换域名场景），避免重复 <script>。
            $html = preg_replace('#<script>window\.__APP_CONFIG__=.*?</script>#', '', $html);

            if (! str_contains($html, 'window.__APP_CONFIG__')) {
                $html = str_replace('</head>', $script.'</head>', $html, $count);
                if ($count === 0) {
                    $html = $script.$html;
                }
            }

            // 替换首页内联脚本里烤死的占位地址。
            $html = str_replace(array_keys($replacements), array_values($replacements), $html);

            file_put_contents($index, $html);
            $results[$dir] = 'injected';
        }

        return $results;
    }
}
