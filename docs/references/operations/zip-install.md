# 发行源码 Zip 与 Web 安装向导

面向希望用「下载发行包 → 解压 → 浏览器安装」方式部署的用户。后端通过内置 Web 安装向导（`/install`）完成建库、迁移与初始化，无需手动执行宝塔四站点或 Docker 编排；前端仍需单独构建并部署到独立站点。

> 对齐时间：`2026-08-29`
> 配套文档：
> - [宝塔部署项目指南](bt-panel-deployment.md)
> - [Docker 与 1Panel 部署指南](docker-and-1panel-deployment.md)
> - [前端 Nginx 伪静态配置](frontend-nginx-rules.md)
> - [部署与调度指南](deployment-and-scheduling.md)

## 1. 适用场景与边界

- 适合：单台服务器、希望最小化命令行操作的源码部署；发行包已含 `vendor/`，解压即可跑向导。
- 不适合：需要容器化扩缩容、或由 CI 统一出镜像的团队（请走 Docker 路线）。
- 安装向导**只安装后端**。前端三端仍需 `pnpm build` 后部署到各自站点；官网 SEO 动态渲染依赖 `frontend-user-v3-www/dist/index.html` 存在。

## 2. 获取发行包

发行包由 GitHub Actions 自动构建：`.github/workflows/release-source.yml`。

- 推送版本 tag（`v*`）时，自动发布到对应 GitHub Release，资产名 `turaidc-<版本>.zip`。
- 手动 `workflow_dispatch` 时，作为 7 天保留的构建产物上传。

包内包含：
- `backend/`（含 `composer install --no-dev` 装好的 `vendor/`）
- 前端三端源码
- 一份用占位地址（`https://*.example.com`）预构建的 `dist/`，解压即可预览；正式上线前请用真实地址重新 `pnpm build` 覆盖

包已剔除 `.git`、`node_modules`、`.env`、安装锁与日志缓存。

## 3. 服务器前置

- Web 服务器（Nginx/Apache），文档根指向 `backend/public`，PHP 8.3+
- PHP 扩展：`pdo_mysql`、`redis`、`openssl`、`mbstring`、`fileinfo`、`json`
- MySQL ≥ 5.7.8（推荐 8.0）、Redis 7+（运行期必需）
- 四个域名解析到本机（可选，本地可用 IP + 端口）

## 4. 解压与启用安装向导

```bash
# 解压到站点目录，文档根 = 项目路径/backend/public
unzip turaidc-<版本>.zip -d /www/wwwroot/turaidc
cd /www/wwwroot/turaidc/backend

# 权限（.env 由向导在安装时自动从 .env.example 生成，无需手动创建）
chmod -R 775 storage bootstrap/cache
```

> 默认无需配置 `INSTALL_TOKEN`：解压即可访问 `/install`。若 `backend/.env` 设置了
> `INSTALL_TOKEN`，安装页「安装令牌」输入框需填一致的值；留空则直接安装。安装锁
> 存在后向导即 404，重装需先删除 `storage/app/install.lock`。

## 4.1 域名、站点与部署目录对应

安装向导第 2 步「站点地址」四格与四个站点一一对应；四个地址的 origin 必须互不相同、同协议。

| 配置项 | 填写示例 | 对应站点 / 角色 | 部署根目录 |
| --- | --- | --- | --- |
| `APP_URL` | `https://api.你的域名.com` | 后端 API（Laravel，含安装页 `/install`） | `backend/public` |
| `FRONTEND_URL` | `https://www.你的域名.com` | 官网 / 用户入口 | `frontend-user-v3-www/dist` |
| `CLIENT_CONSOLE_URL` | `https://console.你的域名.com` | 用户控制台 | `frontend-user-v4-console/dist` |
| `ADMIN_URL` | `https://admin.你的域名.com` | 管理端 | `frontend-admin-v3/dist` |

- **安装页访问 `APP_URL` 对应的域名**：`https://api.你的域名.com/install`（安装页由后端提供，其余三个域名是纯前端静态站，不提供安装页）。
- 装完后 `ADMIN_URL` 用于登录管理后台，`WWW`/`CONSOLE` 给用户访问。

## 5. 访问安装页

浏览器打开（即 `APP_URL` 对应的域名）：

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

- **前端无需为换域名重建**：安装向导在完成时已把真实地址以 `window.__APP_CONFIG__` 注入各前端 `dist/index.html`，配合构建期的运行时覆盖，前端直接读取，不必重新 `pnpm build`。发行包自带的占位 `dist` 装完即可指向你的域名。
- **计划任务**：每分钟执行 `php artisan schedule:run`（队列与心跳）。
- **VNC Relay**：常驻 `php artisan vnc:relay`（不使用 VNC 可跳过）；确认 `127.0.0.1:8100` 监听。
- **前端部署**：把三个 `dist/`（装完后已含真实地址）部署到各自站点，伪静态见[前端 Nginx 伪静态配置](frontend-nginx-rules.md)；API 站点加 `/ws/vnc` 转发与 Laravel 回退。如需自行重建，在源码目录 `pnpm install --frozen-lockfile --shamefully-hoist` 后 `pnpm run build:frontends`（读 `backend/.env` 真实地址，会重新生成 `dist/index.html`，覆盖向导注入的脚本，效果一致）。
- **健康检查**：`/api/health` 与 `/api/ready` 应返回 200。

## 7. 常见问题

- 访问 `/install` 直接 404：`.env` 中 `APP_KEY` 已被填写（已安装态）；或配置了 `INSTALL_TOKEN` 但页面输入的令牌不一致。
- 环境检测报 `vendor/` 缺失：发行包异常或解压不完整，重新下载 Release 资产。
- 安装报数据库版本过低：MySQL 需 ≥ 5.7.8；5.7 建议在 `my.cnf` 设 `explicit_defaults_for_timestamp=ON`。
- 官网首页 500（`Could not resolve host: frontends`）：向导已把 `SEO_FRONTEND_SHELL_URL` 写为本机 `file://.../frontend-user-v3-www/dist/index.html`，前端构建产物缺失会导致此错，先完成前端构建。
