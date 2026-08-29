# Claude Code Desktop × B.AI 本地代理使用说明（Windows）

本目录中的 `_bai` 是一个仅监听本机 `127.0.0.1` 的 Workerman 代理。它让 Claude Code Desktop 使用本地 Gateway，同时将 Desktop 请求映射到 B.AI 当前可用的免费模型。

代理保存 B.AI API Key，Claude Desktop 只连接本机代理；不要把 B.AI Key 直接填到 Claude Desktop 的 Gateway API Key 字段。

## 目录结构

```text
proxy/
├─ README.md                         # 本文档
├─ assets/                           # 配置截图
└─ _bai/
   ├─ start_proxy.bat                # Windows 启动入口（双击此文件）
   ├─ .env                           # 本机密钥配置，不应分享
   ├─ .env.example                   # 配置模板
   ├─ proxy.php                      # Workerman 代理程序
   └─ start-proxy.bat                # 兼容启动脚本
```

## 1. 在 B.AI 创建 API Key

1. 打开 [B.AI Chat](https://chat.b.ai/chat)，登录账号。
2. 打开 [API Key 管理页面](https://chat.b.ai/key)。
3. 点击“创建 API key”，选择“官方 / 全量接入”，保存新 Key。

> API Key 只会在创建时完整显示。请立即保存到本机 `.env`，不要粘贴到聊天、截图或公共仓库中。若某个 Key 已经公开过，请删除它并重新创建。

![B.AI API Key 管理页面](./assets/bai-api-key.png)

## 2. 配置本地代理

首次双击启动脚本时，如果 `.env` 不存在，脚本会从 `.env.example` 创建它并自动打开记事本。

打开 [_bai/.env](./_bai/.env)，至少填写：

```ini
BAI_API_KEY=你刚创建的 B.AI API Key
```

本代理不校验 Claude Desktop 传来的 Gateway API Key；B.AI Key 只保存在本机 `_bai/.env` 中。

## 3. 启动代理

在 Windows 资源管理器中直接双击：

[`C:\Users\Administrator\Desktop\proxy\_bai\start_proxy.bat`](./_bai/start_proxy.bat)

首次运行会自动安装缺失的 PHP Composer 依赖。成功后窗口会显示：

```text
Starting B.AI Claude Desktop local proxy at http://127.0.0.1:8787
```

保持此窗口打开；关闭窗口或按 `Ctrl+C` 会停止代理。健康检查地址是 <http://127.0.0.1:8787/health>。

## 4. 在 Claude Code Desktop 配置第三方推理

在 Claude Code Desktop 打开“配置第三方推理”→“连接”，填写以下内容：

| 字段 | 填写内容 |
| --- | --- |
| Gateway 凭据类型 | `Static API key` |
| Gateway 基础 URL | `http://127.0.0.1:8787` |
| Gateway API 密钥 | 任意非空文本，例如 `local`（代理不会校验或转发它） |
| Gateway 认证方案 | `x-api-key` |
| Artifact preview iframe origin | 留空 |
| 自定义推理请求头 | 留空 |

不要把基础 URL 写成 `http://127.0.0.1:8787/v1/messages`；Desktop 会自动添加 `/v1/messages`。

![Claude Desktop Gateway 连接配置](./assets/claude-desktop-gateway.png)

继续向下滚动到“模型”区域：

1. 打开“模型发现”。
2. 点击“测试模型发现”。
3. 保持“Default to 1M context”关闭。
4. 在模型列表选择需要的模型；第一项为默认模型。
5. 点击右下角“应用更改”。

![Claude Desktop 模型发现设置](./assets/claude-desktop-model-discovery.png)

## 模型映射

Claude Desktop 会校验模型 ID，因此代理向 Desktop 返回标准的 Claude 模型 ID；模型选择器的显示名则为实际的 B.AI 模型名。

| Claude Desktop 显示名 | Desktop 内部 ID | B.AI 实际模型 |
| --- | --- | --- |
| `deepseek-v4-flash` | `claude-sonnet-4-6` | `deepseek-v4-flash` |
| `glm-5.3-flash` | `claude-haiku-4-5` | `glm-5.3-flash` |
| `qwen3.8-flash` | `claude-opus-4-6` | `qwen3.8-flash` |
| `hy3` | `claude-opus-4-5` | `hy3` |
| `deepseek-v4-flash-vision-exp` | `claude-sonnet-4-5` | `deepseek-v4-flash-vision-exp` |

这些模型当前来自 B.AI 页面标记的限时免费活动；可用性和优惠策略可能变化，以 B.AI 页面为准。

## 常见问题

| 现象 | 处理方式 |
| --- | --- |
| 模型发现显示 `found 0 models` | 重启 `start_proxy.bat`，确认 Gateway 基础 URL 是 `http://127.0.0.1:8787`，再点击“测试模型发现”。 |
| Inference 超时 | 确认代理的命令窗口仍开着，并访问 <http://127.0.0.1:8787/health>。 |
| B.AI 返回 `403 access_denied` | 所选上游模型可能已不再免费、活动已结束，或 Key 没有对应权限；到 [B.AI API 页面](https://chat.b.ai/key) 检查。 |
| B.AI 返回 `max_tokens must be greater than 2` | 请重启代理以加载最新版 `proxy.php`；代理已自动修正 Claude Desktop 的探测请求。 |
| Claude Desktop 显示旧模型名 | 关闭并重新打开 Desktop，再点击“测试模型发现”。 |

## 安全提示

- 代理强制监听 `127.0.0.1`，不会暴露给局域网。
- `.env` 中有 B.AI Key，绝不要上传、同步或分享它。
- Claude Desktop 的 Gateway API Key 只是界面的必填占位文本；B.AI Key 只留在 `_bai/.env`。
- 发现 Key 泄露时，立即在 [B.AI API Key 管理页面](https://chat.b.ai/key) 删除旧 Key 并重新创建。
