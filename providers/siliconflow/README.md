# SiliconFlow Provider

## 注册与 API Key

- 注册/登录：https://cloud.siliconflow.cn/
- 创建 API Key：https://cloud.siliconflow.cn/account/ak
- 官方免费模型规则：https://docs.siliconflow.cn/cn/userguide/rate-limits/rate-limit-and-upgradation

SiliconFlow 的免费模型无需绑定信用卡，但平台可能要求完成账号实名认证。只使用模型广场中价格为 0、且名称不带 `Pro/` 的免费模型。

复制 `.env.example` 为 `.env`：

```dotenv
SILICONFLOW_API_KEY=你的_SILICONFLOW_API_KEY
UPSTREAM_TIMEOUT=180
```

## 模型

| 本地模型别名 | SiliconFlow 上游模型 | max_tokens |
| --- | --- | ---: |
| `claude-sonnet-2-1` | `Qwen/Qwen3-8B` | 16384 |
| `claude-sonnet-2-2` | `Qwen/Qwen3.5-4B` | 16384 |

旧别名 `siliconflow-qwen3-8b` 仍可用于请求。Qwen3.5 4B 也可通过 `siliconflow-qwen3.5-4b` 请求；这些兼容别名不会出现在模型发现列表中。

SiliconFlow 原生提供 Anthropic Messages、流式响应和工具调用接口，代理只负责模型映射及响应模型名改写。
