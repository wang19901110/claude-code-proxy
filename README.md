# Claude Code Desktop · B.AI 本地代理

## 使用步骤

1. 下载完整项目 ZIP：
   [下载 GitHub ZIP](https://github.com/wang19901110/claude-code-proxy/archive/refs/heads/main.zip)

2. 解压 ZIP 文件。

3. 从 [B.AI](https://chat.b.ai/key) 获取 API Key。

4. 将项目中的 [.env](https://github.com/wang19901110/claude-code-proxy/blob/main/.env.example) 复制一份，并重命名为 `.env`。

5. 打开 `.env`，填写你的 B.AI API Key：

   ```dotenv
   BAI_API_KEY=你的_BAI_API_KEY
   BAI_MODELS_FILE=models.json
   ```

6. 双击 `start.bat` 启动代理。启动窗口需要保持打开。

7. 打开 Claude Code Desktop，进入开发者模式，配置第三方推理服务：

   ```text
   URL: http://127.0.0.1:8787
   认证方式: x-api-key
   API Key: local
   ```

   API Key 填任意非空文本即可，因为本地代理会使用 `.env` 中的 B.AI API Key 访问上游。

8. 开启“模型发现”开关，保存配置即可使用。

模型列表和参数位于 `models.json`，代理会按照文件中的顺序自动生成 `claude-sonnet-1-1`、`claude-sonnet-1-2` 等模型别名。

## 注意事项

- `.env` 包含真实 API Key，请勿上传到 GitHub。
- 使用结束后再关闭 `start.bat` 窗口。
- 如果修改了 `.env` 或 `models.json`，需要重启 `start.bat`。
