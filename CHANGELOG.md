# 更新日志

本项目遵循语义化版本（SemVer），兼容 Typecho 1.3.0+。

## 1.1.4 - 2026-10-23

### 安全修复

- 头像地址增加协议白名单：仅允许 `http` / `https`，`javascript:` 等非法协议按空值处理（新增 `sanitizeUrl()`）。
- 输出头像 URL 的 `json_encode()` 增加 `JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` 转义标志，防止前端脚本注入。
- 读取后台配置时 `unserialize()` 显式设置 `allowed_classes => false`，禁止反序列化任何对象。

### 工程规范

- 新增 `CHANGELOG.md` 与 `.gitattributes`（统一 LF 换行）。

## 1.1.3 - 2026-10-13

### 新增功能

- 首个正式版本：用 URL 图片替换 Gravatar 头像，覆盖评论头像与后台个人设置头像。
- 支持个人头像 URL 与全局默认头像地址配置，内置默认头像资源。
- 插件自包含，不依赖任何主题，可单独安装使用。
