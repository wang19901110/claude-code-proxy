# B.AI Provider

这个 Provider 将 Claude Code Desktop 的 Anthropic Messages 请求转发到 B.AI。

## 注册与 API Key

- 注册/登录：https://chat.b.ai/
- 创建 API Key：https://chat.b.ai/key

B.AI 当前免费模型和活动规则可能变化，请以 Key 页面显示为准；本项目不会读取或保存平台登录密码。

## 配置

复制 `.env.example` 为 `.env`，然后填写：

```dotenv
BAI_API_KEY=你的_BAI_API_KEY
UPSTREAM_TIMEOUT=180
```

`.env` 只在本机使用，不会提交到 Git。

## 模型

| Claude 模型别名 | B.AI 上游模型 | max_tokens |
| --- | --- | ---: |
| `claude-sonnet-1-1` | `deepseek-v4-flash` | 32000 |
| `claude-sonnet-1-2` | `qwen3.8-flash` | 64000 |
| `claude-sonnet-1-3` | `hy3` | 32000 |

旧 Claude 别名仍可用于请求，但不会出现在模型发现列表中。

Hy3 偶尔遗漏 Anthropic SSE 结束事件；该平台实现会按需补齐结束事件。
