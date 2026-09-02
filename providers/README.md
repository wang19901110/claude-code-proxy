# Provider package contract

每个直接子目录表示一个平台。启动时，`ProviderRegistry` 自动加载目录中的 `bootstrap.php`。

平台目录必须拥有：

- `provider.json`：声明唯一、稳定的正整数 `platform_index`。
- `bootstrap.php`：引入本目录实现文件，并返回签名为 `fn (ProviderConfig $config, SecretGuard $guard, ModelAliasRegistry $aliases): ProviderAdapter` 的工厂。
- `Provider.php`：实现 `FreeGateway\Provider\ProviderAdapter`。
- `.env.example`：列出该平台的非敏感配置模板。
- `.env`：保存该平台 API Key 与实际设置；该文件已被 Git 忽略。

目录名只能使用小写字母、数字和连字符，并且必须等于 `ProviderAdapter::name()`。各平台的 `platform_index` 不得重复。以下划线开头的目录会被忽略，可用于保存模板。

模型别名必须通过注入的 `ModelAliasRegistry` 分配。平台 `x` 的模型会得到稳定的 `claude-sonnet-x-y` 别名；映射保存在 `runtime/provider-aliases/`，已分配的序号不会因模型目录刷新而改变或复用。

Provider 必须在 `discover()` 中完成免费证据验证。价格缺失、价格无法解析或免费状态不确定时必须返回空目录，禁止猜测或降级到付费模型。
