# Claude Code Desktop · B.AI 本地代理

这是一个只针对 B.AI 上游平台的 Claude Code Desktop 本地代理。


## 文件结构

```text
proxy.php     # B.AI Provider、协议适配和代理服务
models.json   # B.AI 模型及其协议参数
.env          # 本机 B.AI API Key，请勿提交 Git
start.bat     # Windows 启动脚本
README.md     # 使用说明
log/          # 每次下游消息请求的独立日志
```

双击 `start.bat` 或运行 `.\start.bat` 即可启动代理。

## 配置

复制 `.env.example` 为 `.env`，再在项目根目录配置真实的 B.AI API Key：

```dotenv
BAI_API_KEY=你的_BAI_API_KEY
BAI_MODELS_FILE=models.json
UPSTREAM_TIMEOUT=180
BAI_INITIAL_CONCURRENCY=2
BAI_MAX_CONCURRENCY=8
BAI_QUEUE_CAPACITY=64
BAI_QUEUE_MAX_WAIT=120
BAI_QUEUE_MAX_BYTES=67108864
BAI_RETRY_MAX=3
BAI_RETRY_BASE_MS=1000
BAI_RETRY_MAX_DELAY=30
BAI_LOG_ENABLED=1
BAI_LOG_BODY=1
BAI_LOG_MAX_BYTES=65536
```

`BAI_INITIAL_CONCURRENCY` 是启动时同时发往 B.AI 的请求数；`BAI_MAX_CONCURRENCY` 是自动调整的上限。发生 429 时，代理会读取 `Retry-After`（若有），全局暂停新请求、降低实际并发，并在队列中重试；不会立刻把 429 交给 Claude Code。请求最多重试 `BAI_RETRY_MAX` 次，并且不能超过 `BAI_QUEUE_MAX_WAIT` 秒。

流式请求会等 B.AI 返回成功响应头后才开始向 Claude Code 发送 SSE。因此，首个上游响应为 429 时可以安全重试；开始输出流内容后绝不会重试，以免回复重复。

## 调试日志

`BAI_LOG_ENABLED=1` 时，代理会将日志写入根目录的 `log/` 文件夹；设为 `0` 后不会创建或写入任何会话日志。健康检查、模型发现和连通性探测不会生成日志文件。

每次下游 `POST /v1/messages` 都会生成一个独立文件，文件名使用该请求到达代理时的 UTC 时间，例如：

```text
20260901T143522.123456Z-r0000000042-a7c91e4b.log
```

时间后附带请求序号和随机后缀，避免并发请求及代理重启后重名。一次请求发生 429 并重试时仍写入原文件，不会拆分日志。每条事件是缩进的 JSON 区块，并以分隔线隔开；认证信息不会写入日志。

`BAI_LOG_BODY=1` 会记录请求与响应正文，单条最多 `BAI_LOG_MAX_BYTES` 字节，适合本次排错。正文可能包含敏感内容，测试结束后请关闭正文记录（设为 `0`）并安全删除 `log/` 中不再需要的日志。

旧版本生成的根目录 `proxy.log` 不会被程序继续写入，也不会自动删除。

## 代码结构

实现仍集中在 `proxy.php`，按职责划分为：

- `ProxyConfig`：读取并校验环境配置。
- `ModelCatalog`：维护 B.AI 模型映射和 `/v1/models` 数据。
- `BaiProtocol`：处理 Anthropic JSON、SSE、错误和模型名转换。
- `SessionLogFactory` / `RequestLog`：创建并写入每个请求的独立日志。
- `RequestSession`：保存一次请求在排队、重试和流式转发期间的状态。
- `BaiProxyServer`：处理 HTTP 路由、自适应并发、队列及 429 重试。

B.AI API Key：

- 注册/登录：https://chat.b.ai/
- 创建 API Key：https://chat.b.ai/key

不要把 Key 粘贴到日志、截图或聊天内容中。

## 模型

模型配置在 `models.json` 中。代理按 JSON 数组顺序自动生成 `claude-sonnet-1-1`、`claude-sonnet-1-2` 等别名；每个模型可配置 `upstream`、`display_name`、`max_tokens` 和可选的 `repair_sse` 字段。数组顺序就是 Claude alias 的编号顺序。

B.AI 价格和活动可能变动，请以 B.AI 控制台为准。代理只接受此表和 `/v1/models` 返回的模型 ID。代理会保留请求的 `max_tokens`，仅在它超出此表上限时裁剪；探测请求的 `max_tokens` 为 `1` 或 `2` 时会改为 `16`。Hy3 缺少 SSE 结束事件时，代理会按需补齐。

## 流式行为

代理始终调用同一个 B.AI Anthropic Messages 端点：`POST /v1/messages`。它保留 Claude Code 传入的 `stream` 值：`false` 时返回完整 JSON，`true` 时转发 SSE。这样两个模式共享模型映射、请求队列、429 冷却和重试逻辑，同时维持 Claude Code 所要求的协议语义。

流式连接建立前的 B.AI 错误会作为普通 JSON 响应原样返回，包括 B.AI 的错误 `code`、`message`、`Retry-After` 和请求 ID；建立 SSE 后出现的错误则按 Anthropic SSE error event 返回。

## 启动

双击 `start.bat`，或运行：

```powershell
.\start.bat
```

代理地址：

```text
http://127.0.0.1:8787
```

在 Claude Code Desktop 的第三方推理服务中配置：

```text
Base URL: http://127.0.0.1:8787
API Key: 任意非空文本，例如 local
认证方式: x-api-key
```

## 接口

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET` | `/health` | B.AI 状态、队列、实际并发及 429 计数 |
| `GET` | `/v1/models` | B.AI 模型发现 |
| `POST` | `/v1/messages` | Anthropic Messages 请求 |
| `POST` | `/v1/messages/count_tokens` | 本地 token 估算 |
| `GET/POST/HEAD` | `/api/hello` | Desktop 连通性探测 |

模型别名不属于上述 B.AI 映射时返回 400，不会自动切换到其他平台。
