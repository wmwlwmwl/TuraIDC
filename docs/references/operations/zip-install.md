# 发行源码 Zip 与 Web 安装向导

面向希望用「下载发行包 → 解压 → 浏览器安装」方式部署的用户。后端通过内置 Web 安装向导（`/install`）完成建库、迁移与初始化，无需手动执行宝塔四站点或 Docker 编排；前端三端仍需部署到独立站点（发行包已带占位 `dist`，无需重新构建）。

> 对齐时间：`2026-08-29`
> 配套文档：
> - [宝塔部署项目指南](bt-panel-deployment.md)
> - [Docker 与 1Panel 部署指南](docker-and-1panel-deployment.md)
> - [前端 Nginx 伪静态配置](frontend-nginx-rules.md)
> - [部署与调度指南](deployment-and-scheduling.md)

## 1. 适用场景与边界

- 适合：单台服务器、希望最小化命令行操作的源码部署；发行包已含 `vendor/`，解压即可跑向导。
- 不适合：需要容器化扩缩容、或由 CI 统一出镜像的团队（请走 Docker 路线）。
- 安装向导**只安装后端**。前端三端部署到各自独立站点；官网 SEO 动态渲染依赖 `frontend-user-v3-www/index.html` 存在。

## 2. 获取发行包

发行包由 GitHub Actions 自动构建：`.github/workflows/release-source.yml`。

- 推送版本 tag（`v*`）时，自动发布到对应 GitHub Release，资产名 `turaidc-<版本>.zip`。
- 手动 `workflow_dispatch` 时，作为 7 天保留的构建产物上传。

包内包含（已剔除前端源码、docs、.github 等冗余）：

- `backend/`（完整 Laravel 应用，含 `composer install --no-dev` 装好的 `vendor/`；web 文档根 = `backend/public`）
- 前端三端的构建产物（官网 / 控制台 / 管理端各一份，位于各自目录根；含占位地址；安装向导会把真实地址注入，无需重新 `pnpm build`）
- 运行时目录（`storage/*`、`bootstrap/cache`）

> 发布时除总包 `turaidc-<版本>.zip` 外，还会额外产出 4 个独立站点包，适合分目录 / 分机器部署：
> - `turaidc-api-<版本>.zip`：完整 `backend/`（站点根 = `backend/public`）
> - `turaidc-www-<版本>.zip`：官网 `dist/` 内容（解压即站点根）
> - `turaidc-console-<版本>.zip`：控制台 `dist/` 内容
> - `turaidc-admin-<版本>.zip`：管理端 `dist/` 内容
>
> 独立站点包与总包内容一致（均为占位地址，需注入真实地址），只是拆分方式不同。

包已剔除 `.git`、`node_modules`、`.env`、安装锁与日志缓存；各站点 `.htaccess`（Apache 伪静态）已随包提供，Nginx 环境自动忽略。

## 3. 服务器前置

- Web 服务器（Nginx/Apache），文档根指向 `backend/public`，PHP 8.3+
- PHP 扩展：`pdo_mysql`、`openssl`、`mbstring`、`fileinfo`、`json`
- Redis **可选**：装了 `redis` 扩展就保持默认 `CACHE_STORE=redis`；没装就把 `CACHE_STORE` 设为 `file`（详见 §4）
- MySQL ≥ 5.7.8（推荐 8.0）
- 四个域名/地址解析到本机（可选，本地可用 IP + 端口区分，例如 `:8080`/`:8181`/`:8082`/`:8081`）

## 4. 解压与启用安装向导

### 4.1 解压与初始化

```bash
# 解压到站点目录，文档根 = 项目路径/backend/public
unzip turaidc-<版本>.zip -d /www/wwwroot/turaidc
cd /www/wwwroot/turaidc/backend

# 1) 生成 .env 与 APP_KEY（Web 安装向导需要 APP_KEY 才能启动）
php artisan key:generate

# 2) 权限（storage / bootstrap/cache 需可写）
chmod -R 775 storage bootstrap/cache
```

> 若 PHP 未装 `redis` 扩展，运行期会报 `Class "Redis" not found`。编辑 `backend/.env` 把缓存驱动改成本地文件即可：
>
> ```ini
> CACHE_STORE=file
> ```
>
> 装好扩展后想换回 Redis，把该项改回 `redis` 并清缓存即可。

> 默认无需配置 `INSTALL_TOKEN`：解压即可访问 `/install`。若 `backend/.env` 设置了 `INSTALL_TOKEN`，安装页「安装令牌」输入框需填一致的值；留空则直接安装。安装锁存在后向导即 404，重装需先删除 `storage/app/install.lock`。

### 4.2 API 站点（后端）Web 服务器配置 —— 安装前必须

`/install` 与所有 API 路由都由 Laravel 处理，**必须在访问安装页之前配好伪静态**，否则直接 404。

文档根：`backend/public`；选择 PHP 8.3（宝塔会自带 PHP 处理器）。再添加 Laravel 路由回退：

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

Apache 等价（填「伪静态」或放入 `.htaccess`）：

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]
```

发行包 `backend/public` 已内置该 `.htaccess`，Apache 站点根指向 `backend/public` 且 `AllowOverride` 开启即生效，无需手填。更完整规则（含 VNC 转发）见 [前端伪静态配置 · Apache 章节](frontend-nginx-rules.md)。

`/ws/vnc` 转发**仅 VNC 中继需要**（不装/不用 VNC 可不加）：

```nginx
location /ws/vnc {
    proxy_pass http://127.0.0.1:8100;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
}
```

### 4.3 域名、站点与部署目录对应

安装向导第 2 步「站点地址」四格与四个站点一一对应；四个地址的 origin 必须互不相同、同协议。

| 配置项 | 填写示例 | 对应站点 / 角色 | 部署根目录 |
| --- | --- | --- | --- |
| `APP_URL` | `https://api.你的域名.com` | 后端 API（Laravel，含安装页 `/install`） | `backend/public` |
| `FRONTEND_URL` | `https://www.你的域名.com` | 官网 / 用户入口 | `frontend-user-v3-www` |
| `CLIENT_CONSOLE_URL` | `https://console.你的域名.com` | 用户控制台 | `frontend-user-v4-console` |
| `ADMIN_URL` | `https://admin.你的域名.com` | 管理端 | `frontend-admin-v3` |

- **安装页访问 `APP_URL` 对应的域名**：`https://api.你的域名.com/install`（安装页由后端提供，其余三个域名是纯前端静态站，不提供安装页）。
- 装完后 `ADMIN_URL` 用于登录管理后台，`WWW`/`CONSOLE` 给用户访问。

## 5. 访问安装页

在 §4.2 配好 API 伪静态后，浏览器打开 `APP_URL` 对应的域名：

```text
https://api.你的域名.com/install
```

页面内依次：

1. （可选）若 `.env` 已配置 `INSTALL_TOKEN`，在「安装令牌」输入框粘贴后点「检测环境」；否则直接点「检测环境」。
2. 环境检测：PHP 版本、扩展、`vendor/`、`storage/` 可写等需全部通过。
3. 连接测试：填写数据库与 Redis 连接，点击测试。
4. 安装：填写四个地址（`APP_URL`/`FRONTEND_URL`/`CLIENT_CONSOLE_URL`/`ADMIN_URL`，需为完整 URL 且 origin 互不相同）、管理员用户名（字母开头 3-32 位）、邮箱、密码（≥12 位），点「开始安装」。

向导自动完成：建库 → 导入 schema baseline → 增量迁移 → 写入默认配置与通知模板 → 创建管理员 → 写安装锁 `storage/app/install.lock`。管理员账号为用户在表单填写的用户名。

## 6. 装后补齐

### 6.1 前端三站点部署（纯静态站 + SPA 伪静态）

把三个前端目录（装完后已含真实地址）部署到各自站点根目录：

- 官网：`frontend-user-v3-www`
- 用户控制台：`frontend-user-v4-console`
- 管理端：`frontend-admin-v3`

宝塔里**不要**用「Node 项目」，用「网站 → 添加站点」选**纯静态**，根目录直接指到各自的前端目录（如 `frontend-user-v3-www`）。每个站点都要加 **SPA 伪静态**，否则刷新子路由（如 `/client/login`、`/admin/*`）会 404：

```nginx
try_files $uri $uri/ /index.html;
```

Apache 等价（以官网为例，控制台 / 管理端同理）：

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.html [L]
```

发行包三个前端目录已内置该 `.htaccess`（Apache SPA 回退，Nginx 忽略）。官网还需把 SEO 公开路径反代到 API，见 [前端伪静态配置 · Apache 章节](frontend-nginx-rules.md)。

### 6.2 重注入前端地址

安装向导已把真实地址以 `window.__APP_CONFIG__` 注入各前端 `index.html`；但以下场景需要重跑，否则前端会退回占位 `example.com` 或旧地址：安装向导之后才补入/重建前端、之后更换了域名。项目内提供幂等命令，读 `backend/.env` 真实地址覆盖写入：

```bash
cd backend && php artisan turaidc:inject-frontend-config
```

如需自行重建前端：`pnpm install --frozen-lockfile --shamefully-hoist` 后 `pnpm run build:frontends`（读 `backend/.env` 真实地址重新生成 `dist/index.html`，效果一致，但建议重建后也跑一次上面的命令兜底）。

### 6.3 计划任务

每分钟执行 `php artisan schedule:run`（队列与心跳）。

### 6.4 VNC Relay（可选）

常驻 `php artisan vnc:relay`（不使用 VNC 可跳过）；确认 `127.0.0.1:8100` 监听。启用时需在 API 站点配 §4.2 的 `/ws/vnc` 转发。

### 6.5 健康检查

`/api/health` 与 `/api/ready` 应返回 200。

## 7. 常见问题

- 访问 `/install` 直接 404：API 站点未配 Laravel 伪静态（§4.2）；或 `.env` 中 `APP_KEY` 已填（已安装态）；或配置了 `INSTALL_TOKEN` 但页面输入的令牌不一致。
- 环境检测报 `vendor/` 缺失：发行包异常或解压不完整，重新下载 Release 资产。
- 安装报数据库版本过低：MySQL 需 ≥ 5.7.8；5.7 建议在 `my.cnf` 设 `explicit_defaults_for_timestamp=ON`。
- 官网首页 500（`Could not resolve host: frontends`）：向导已把 `SEO_FRONTEND_SHELL_URL` 写为本机 `file://.../frontend-user-v3-www/index.html`，前端构建产物缺失会导致此错，先完成前端部署（§6.1）。
- 前端登录跳 `example.com`：对应前端 `index.html` 未注入真实地址，跑一次 `php artisan turaidc:inject-frontend-config`（§6.2）。

## 8. 版本升级

发行包不含 `.env` 与 `storage/` 里的用户数据，因此升级比容器化简单；但**不能整目录无脑覆盖**——必须保留线上 `.env`（数据库密码、`APP_KEY`、四个真实地址）与 `storage/`（安装锁 `install.lock`、上传附件、日志），并执行数据库迁移。

### 8.1 备份（保险）

```bash
cd /www/wwwroot/turaidc
cp backend/.env /tmp/turaidc-env.bak
tar czf /tmp/turaidc-storage.bak.tgz backend/storage
```

### 8.2 覆盖代码，保留配置与数据

解压新包到临时目录，再选择性覆盖（不要碰 `.env` 与 `storage/`）：

```bash
unzip turaidc-<新版本>.zip -d /tmp/newpkg

# 后端代码 + 依赖整体覆盖（vendor 可能随版本变化，必须一起换）
cp -a /tmp/newpkg/backend/app       backend/
cp -a /tmp/newpkg/backend/bootstrap backend/
cp -a /tmp/newpkg/backend/config    backend/
cp -a /tmp/newpkg/backend/routes    backend/
cp -a /tmp/newpkg/backend/vendor    backend/

# 三个前端：直接覆盖各自站点根目录的内容（静态、无状态）
cp -a /tmp/newpkg/frontend-user-v3-www/.     <官网站点根>/
cp -a /tmp/newpkg/frontend-user-v4-console/. <控制台站点根>/
cp -a /tmp/newpkg/frontend-admin-v3/.        <管理端站点根>/

# 关键：保持原 backend/.env 与 backend/storage 不变
```

> 更省事的做法：先把线上 `backend/.env` 与 `backend/storage` 移到别处，用新包整体替换 `backend/`，再把这俩挪回；任何方式只要保证 `.env` 与 `storage` 不被新包覆盖即可。

### 8.3 跑迁移与清缓存

覆盖后端代码后必须执行（新版本可能带数据库迁移或配置缓存）：

```bash
cd backend
php artisan migrate --force
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan cache:clear
```

### 8.4 重新注入前端真实地址

新包前端是占位地址，覆盖后需把真实地址写回（读 `backend/.env`）：

```bash
php artisan turaidc:inject-frontend-config
```

### 8.5 收尾

- 重启 PHP-FPM（清 OPcache）：宝塔对应 PHP 版本「重启」即可。
- 看本次发版说明：是否有破坏性变更、是否新增 env 变量（新增项手动并到 `backend/.env`）。
- 跑迁移前先备份数据库。
