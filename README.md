# Claude Code Desktop 免费本地代理

这是一个面向 Claude Code Desktop 的可扩展本地代理。它在本机提供 Anthropic Messages 兼容接口，并通过独立 Provider 将请求转发到可用的免费模型平台。

代理只监听 `127.0.0.1`，不会记录 API Key、提示词或回复正文。

## 当前 Provider

| Provider | Claude 模型别名 | 上游模型 | max_tokens |
| --- | --- | --- | ---: |
| B.AI | `claude-sonnet-1-1` | `deepseek-v4-flash` | 32000 |
| B.AI | `claude-sonnet-1-2` | `qwen3.8-flash` | 64000 |
| B.AI | `claude-sonnet-1-3` | `hy3` | 32000 |
| SiliconFlow | `claude-sonnet-2-1` | `Qwen/Qwen3-8B` | 16384 |
| SiliconFlow | `claude-sonnet-2-2` | `Qwen/Qwen3.5-4B` | 16384 |
| Groq | `claude-sonnet-3-1` | `qwen/qwen3.8-27b` | 16384 |
| Groq | `claude-sonnet-3-2` | `openai/gpt-oss-120b` | 65536 |

普通请求的 `max_tokens` 由 Provider 按上表设置。Claude Code Desktop 的探测请求传入 `1` 或 `2` 时，各 Provider 会改为 `16`。

Groq 使用无需绑定信用卡的 Free Plan；SiliconFlow 仅配置价格为 0、名称不带 `Pro/` 的免费模型。两者仍受平台正常的公平使用速率限制，但不依赖付费套餐或短期试用额度。

## 配置 Provider

每个平台在自己的目录中保存注册说明、Key 地址和独立 `.env`：

| Provider | 配置文档 |
| --- | --- |
| B.AI | [providers/b_ai/README.md](providers/b_ai/README.md) |
| Groq | [providers/groq/README.md](providers/groq/README.md) |
| SiliconFlow | [providers/siliconflow/README.md](providers/siliconflow/README.md) |

以 B.AI 为例，复制配置模板：

```powershell
Copy-Item .\providers\b_ai\.env.example .\providers\b_ai\.env
```

编辑 `providers\b_ai\.env`：

```dotenv
BAI_API_KEY=你的_BAI_API_KEY
UPSTREAM_TIMEOUT=180
```

真实 `.env` 已被 Git 忽略。不要把 Key 粘贴到日志、截图或聊天内容中。

## 启动

双击 `start.bat`，或运行：

```powershell
.\start.bat
```

项目包含 PHP 8.3.2 和已安装的 Workerman 依赖。代理默认地址：

```text
http://127.0.0.1:8787
```

每次启动都会清空根目录的 `workerman.log`。黑色启动窗口会实时显示不含正文和 Key 的请求/响应摘要。

## Claude Code Desktop

在第三方推理服务中配置：

```text
Base URL: http://127.0.0.1:8787
API Key: 任意非空文本，例如 local
认证方式: x-api-key
```

模型发现接口只返回已配置 Provider 的模型。未映射模型返回 400，不会自动切换到其他平台。

为兼容 Claude Code Desktop 的模型过滤规则，模型发现 ID 统一使用 `claude-sonnet-<平台编号>-<模型编号>`。旧别名仍可用于请求，但不会出现在发现列表中。

## 接口

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET` | `/health` | Provider 状态和模型数量 |
| `GET` | `/v1/models` | 模型发现 |
| `POST` | `/v1/messages` | Anthropic Messages 请求 |
| `POST` | `/v1/messages/count_tokens` | 本地 token 估算 |
| `GET/POST/HEAD` | `/api/hello` | Desktop 连通性探测 |

## 新增 Provider

在 `providers/<provider_id>/` 下创建：

```text
ExampleProvider.php
.env.example
README.md
```

`ExampleProvider.php` 必须返回一个实现 `ClaudeCodeProxy\ProviderInterface` 的实例。代理会自动扫描 `providers/*/*Provider.php`，无需修改 HTTP 入口。

Provider 需要声明：

- 小写 snake_case 平台 ID。
- 符合 `claude-sonnet-<平台编号>-<模型编号>` 的唯一发现别名、上游模型和 `max_tokens`。
- 配置状态和安全提示。
- 上游地址、认证头和请求转换。
- JSON/SSE 响应适配器。

重复平台 ID 或重复模型别名会使代理拒绝启动，避免静默覆盖路由。

## 项目结构

```text
proxy.php              # 通用启动入口
config.php             # 非敏感代理配置
start.bat              # Windows 启动脚本
src/                   # 通用路由、协议、日志与 Provider 注册
providers/b_ai/        # B.AI 实现与独立配置
providers/groq/        # Groq 实现与独立配置
providers/siliconflow/ # SiliconFlow 实现与独立配置
php8.3.2nts/           # PHP 运行时
vendor/                # Composer 依赖
tests/run.php          # 无额外依赖的回归测试
```

运行测试：

```powershell
.\php8.3.2nts\php.exe .\tests\run.php
```

常见错误：

- 没有可用模型：检查对应 Provider 目录中的 `.env`。
- `429`：上游平台限流，稍后重试。
- `502`：上游连接失败，检查网络和平台状态。
