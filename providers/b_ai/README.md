# B.AI Provider

这个 Provider 将 Claude Code Desktop 的 Anthropic Messages 请求转发到 B.AI。

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
| `claude-sonnet-4-6` | `deepseek-v4-flash` | 32000 |
| `claude-opus-4-6` | `qwen3.8-flash` | 64000 |
| `claude-opus-4-5` | `hy3` | 32000 |

Hy3 偶尔遗漏 Anthropic SSE 结束事件；该平台实现会按需补齐结束事件。
