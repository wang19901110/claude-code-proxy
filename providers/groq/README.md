# Groq Provider

## 注册与 API Key

- 注册/登录：https://console.groq.com/
- 创建 API Key：https://console.groq.com/keys
- 官方免费层限制：https://console.groq.com/docs/rate-limits

Groq 提供无需绑定信用卡的 Free Plan。免费层仍有正常的 RPM、RPD 和 TPM 公平使用限制；本项目不接入需要绑定信用卡才能启用的 Developer Tier。

复制 `.env.example` 为 `.env`：

```dotenv
GROQ_API_KEY=你的_GROQ_API_KEY
UPSTREAM_TIMEOUT=180
```

## 模型

| 本地模型别名 | Groq 上游模型 | max_tokens |
| --- | --- | ---: |
| `claude-sonnet-3-1` | `qwen/qwen3.8-27b` | 16384 |
| `claude-sonnet-3-2` | `openai/gpt-oss-120b` | 65536 |

旧别名 `groq-qwen3.8-27b` 和 `groq-gpt-oss-120b` 仍可用于请求，但不会出现在模型发现列表中。

Groq 使用 OpenAI Chat Completions；代理负责转换 Anthropic Messages、工具调用和 SSE 流。
