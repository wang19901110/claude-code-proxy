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
| `siliconflow-qwen3-8b` | `Qwen/Qwen3-8B` | 16384 |
| `siliconflow-qwen2.5-7b` | `Qwen/Qwen2.5-7B-Instruct` | 16384 |

SiliconFlow 原生提供 Anthropic Messages、流式响应和工具调用接口，代理只负责模型映射及响应模型名改写。
