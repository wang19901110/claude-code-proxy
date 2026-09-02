# Claude Code Desktop Free Model Gateway

基于 PHP 8.3 和 Workerman 的本地 Anthropic Messages 网关。它只向 Claude Code Desktop 暴露经过免费校验的 B.AI 与 Flatkey 模型，并提供自动路由 `claude-free-auto`。

## 特性

- 原生转发 Anthropic Messages，不经过 OpenAI Chat Completions 转换。
- `GET /v1/models` 为 Claude Code 提供具体免费模型与自动路由发现。
- B.AI 使用静态免费允许清单与账号模型目录的交集。
- Flatkey 仅允许其目录中存在的 `deepseek-v4-flash` 免费模型。
- 自动路由支持请求能力过滤、两小时会话粘性、被动成功率/延迟统计和失败冷却。
- SSE 支持 thinking、tool use、ping、任意分片及提交前 fallback。
- 本地入口不验证 Claude Code 的 API Key；服务强制只监听 `127.0.0.1`。
- 上游 Key 不会写入日志，出站提示词中的已知 Key 值也会被替换。

## 安装与启动

项目已经包含 Windows PHP 运行时。首次安装依赖：

```powershell
composer install
```

双击 `start.bat` 时会自动准备根配置，并把旧版根 `.env` 或 `keys.txt` 中的 Key 迁移到对应平台目录。也可以手动执行：

```powershell
.\php8.3.2nts\php.exe .\bin\migrate-keys.php
```

迁移不会删除 `keys.txt`。旧版 B.AI 密钥会迁移至 `providers/bai/.env`；其它平台在各自目录的 `.env` 中单独配置，不会写入根 `.env`。

启动：

```powershell
.\start.bat
```

也可以前台启动：

```powershell
.\php8.3.2nts\php.exe .\proxy.php start
```

## Claude Code Desktop 配置

在第三方推理服务中填写：

```text
URL: http://127.0.0.1:8787
认证方式: x-api-key
API Key: local（任意非空占位值，网关不会验证）
```

开启 Gateway/第三方模型发现。若使用环境变量方式：

```powershell
$env:ANTHROPIC_BASE_URL = "http://127.0.0.1:8787"
$env:ANTHROPIC_API_KEY = "local"
$env:CLAUDE_CODE_ENABLE_GATEWAY_MODEL_DISCOVERY = "1"
```

启动 Claude Code 后，在 `/model` 中选择列表第一项 `Auto · All Providers` 或具体模型。

## 配置

根 `.env` 只保存网关公共配置：

```dotenv
GATEWAY_HOST=127.0.0.1
GATEWAY_PORT=8787
LOG_ENABLED=true
```

B.AI 配置位于 `providers/bai/.env`：

```dotenv
API_KEY=...
BASE_URL=https://api.b.ai/v1
CONCURRENCY=2
```

Flatkey 配置位于 `providers/flatkey/.env`：

```dotenv
API_KEY=...
BASE_URL=https://router.flatkey.ai/v1
CONCURRENCY=2
```

所有平台 `.env` 均已加入忽略规则。

## 接口

- `HEAD/GET /api/hello`
- `GET /health`
- `GET /v1/models?limit=1000`
- `POST /v1/messages`
- `POST /v1/messages/count_tokens`

`count_tokens` 是不访问上游的保守估算，避免为计数消耗免费请求额度。

## 路由行为

- 具体模型 ID 永远只调用对应模型。
- `claude-free-auto` 每次最多尝试四个免费候选。
- 具体模型使用 `claude-sonnet-x-y` 别名：`x` 是平台编号，`y` 是该平台内稳定自增的模型编号。
- 401 禁用整个 Provider；403/404、429、5xx、超时和空响应分别进入不同冷却策略。
- 自动路由只在下游尚未收到模型内容时切换候选；内容已提交后只返回 Anthropic SSE error，不拼接另一模型的回答。

运行状态保存在 `runtime/catalog.json`，结构化元数据日志位于 `log/`；两者均不包含请求正文或 API Key。

## 扩展 Provider

每个平台是 `providers/` 下的独立包：

```text
providers/{平台 ID}/
├─ provider.json   # 唯一平台编号
├─ bootstrap.php   # 返回 Provider 工厂
├─ Provider.php    # 平台适配器
├─ .env            # 本平台密钥与设置，不提交
└─ .env.example    # 无密钥配置模板
```

新增平台时创建一个新目录，并在其中实现 `FreeGateway\Provider\ProviderAdapter`：

- `discover()`：返回经过免费证据验证的模型目录。
- `prepare()`：替换上游模型和认证信息，保留 Anthropic 请求结构。
- `classify()`：将上游错误映射为统一失败类型。

`ProviderRegistry` 会在启动时自动扫描所有不以 `_` 开头、包含 `bootstrap.php` 的平台目录。无需修改 `GatewayServer`、Composer autoload、协议、SSE、Catalog 或 Router。目录名必须是小写字母、数字与连字符组成的稳定平台 ID，且应与 `ProviderAdapter::name()` 一致。

平台环境变量也可以覆盖目录 `.env`，命名规则为 `{平台 ID}_{配置名}`。例如 `BAI_API_KEY` 会覆盖 `providers/bai/.env` 中的 `API_KEY`。

## 测试

```powershell
.\php8.3.2nts\php.exe .\vendor\bin\phpunit --testdox
```

测试覆盖免费价格判定、模型别名、能力过滤、会话粘性、冷却、密钥脱敏、精确 model 重写、队列和 SSE 分片/工具调用/thinking。
