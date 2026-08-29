# B.AI Claude Code Desktop 本地代理

将 Claude Code Desktop 的 Anthropic Messages 请求转发至 B.AI。代理只监听本机回环地址 `127.0.0.1`，并把 Claude 风格的模型别名映射为 B.AI 的免费模型。

## 当前可用模型

| Claude Code Desktop 中显示的模型别名 | B.AI 上游模型 | 代理设置的 `max_tokens` |
| --- | --- | ---: |
| `claude-sonnet-4-6` | `deepseek-v4-flash` | 32000 |
| `claude-opus-4-6` | `qwen3.8-flash` | 64000 |
| `claude-opus-4-5` | `hy3` | 32000 |

`max_tokens` 由代理按上表固定设置；Claude Code Desktop 传来的普通请求值不会改变该映射。仅当 Desktop 进行网关探测并发送 `1` 或 `2` 时，代理会改为 `16`，以满足 B.AI 的最低要求。

Hy3 上游偶尔缺少 Anthropic SSE 的结束事件。代理会在上游正常关闭但遗漏结束事件时补齐 `content_block_stop`、`message_delta` 与 `message_stop`，防止 Claude Code Desktop 显示内容后又回滚。

## 环境要求

- Windows
- 项目自带 PHP 8.3.2 运行时，无需将 PHP 加入 `PATH`
- B.AI API Key

项目根目录包含 `php8.3.2nts` 运行时和 `vendor` 依赖；代理源码、配置与日志位于 `b.ai`。若 `vendor` 被删除，启动脚本会尝试使用 Composer 重新安装到项目根目录。

## 配置

复制模板并填写 B.AI Key：

```powershell
Copy-Item .env.example .env
```

编辑 `.env`：

```dotenv
BAI_API_KEY=你的_BAI_API_KEY
PROXY_HOST=127.0.0.1
PROXY_PORT=8787
UPSTREAM_TIMEOUT=180
```

不要把 `.env` 提交到版本库，也不要把 API Key 粘贴到日志、截图或聊天内容中。

## 启动与停止

双击 [start-proxy.bat](C:/Users/Administrator/Desktop/claude-code-proxy/b.ai/start-proxy.bat)，或在 `b.ai` 目录运行：

```powershell
.\start-proxy.bat
```

代理地址为：

```text
http://127.0.0.1:8787
```

关闭黑色命令窗口或按 `Ctrl+C` 即可停止代理。

每次代理启动时，`workerman.log` 都会清空并重新开始记录本次运行的日志。

## Claude Code Desktop 对接

在 Claude Code Desktop 的本地网关/自定义推理服务配置中使用：

```text
Base URL: http://127.0.0.1:8787
```

代理提供 Anthropic Messages 兼容接口。模型发现接口 `/v1/models` 只会返回上表中的三个模型；未映射模型会返回 400，不会静默改用其他模型。

如果 Desktop 仍缓存旧模型，请新建会话或重新打开模型选择列表。

## 接口

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET` | `/health` | 查看代理状态与模型映射 |
| `GET` | `/v1/models` | Claude Code Desktop 的模型发现 |
| `POST` | `/v1/messages` | Anthropic Messages 请求 |
| `POST` | `/v1/messages/count_tokens` | 本地 token 估算，不访问上游 |
| `GET` / `POST` | `/api/hello` | Claude Desktop 兼容探测 |

## 日志与排错

启动脚本的黑色窗口会实时输出安全摘要，例如：

```text
[15:59:18] [FORWARD] id=... claude-opus-4-5 -> hy3 SSE max_tokens=32000
[15:59:21] [INFO] id=... model=hy3 status=200 latency=3030ms bytes=3802 repaired_sse=yes
```

完整的 JSON Lines 日志位于 [workerman.log](C:/Users/Administrator/Desktop/claude-code-proxy/b.ai/workerman.log)。日志包含请求 ID、模型、状态码、耗时、流式字节数和错误类型；不会记录 API Key、提示词或回复正文。

常见情况：

- `429`：B.AI 上游限流；等待一段时间后重试。
- `400`：请求模型不在代理映射中，或上游不接受该请求格式。
- `502`：上游连接失败；检查网络、B.AI 服务状态与 `.env` 中的 Key。

## 项目结构

```text
claude-code-proxy/
├─ php8.3.2nts/    # 项目共用 PHP 运行时
├─ vendor/         # 项目共用 Composer 依赖
└─ b.ai/
   ├─ proxy.php          # Workerman 代理、模型映射、SSE 修复和日志
   ├─ start-proxy.bat    # Windows 启动脚本
   ├─ .env               # 本机私密配置，不应提交
   ├─ .env.example       # 配置模板
   └─ workerman.log      # 当前一次运行的 JSON 日志
```
