<div align="center">

# Paid for Typecho

**文章付费与订阅插件，支持单篇付费、订阅计划、多支付通道、可定制主题卡片。**

[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb3?logo=php&logoColor=white)](https://www.php.net/)
[![Typecho](https://img.shields.io/badge/Typecho-1.2%2B-4a90d9)](https://typecho.org/)
[![GitHub release](https://img.shields.io/github/v/release/lhl77/TypechoPaid?color=brightgreen&logo=github)](https://github.com/lhl77/TypechoPaid/releases)
[![License](https://img.shields.io/github/license/lhl77/TypechoPaid?color=blue)](LICENSE)
[![Stars](https://img.shields.io/github/stars/lhl77/TypechoPaid?style=flat&logo=github)](https://github.com/lhl77/TypechoPaid/stargazers)

</div>
<p align="center">
  快捷链接：
  <a href="https://blog.lhl.one/artical/1309.html">文档</a> | 
  <a href="https://github.com/lhl77/TypechoPaid/issues">问题反馈</a>
</p>

---

## 功能特性

- **单篇付费** — 通过自定义字段控制单篇文章的付费开关、价格和可用支付渠道
- **订阅计划** — 支持月/季/年订阅，有效期内可访问所有归属该计划的文章
- **多支付通道** — 内置支付宝当面付、微信支付、易支付驱动，可扩展自定义通道
- **可定制主题** — 5 套内置主题卡片，支持 CSS 变量自定义、亮暗双色自动适配
- **安全验证** — 集成 Cloudflare Turnstile 人机验证，防止高频刷单
- **购买通知** — 支持 SMTP 邮件通知，支付成功后自动发送订单详情
- **RSS 保护** — 付费文章在 RSS 订阅中仅显示摘要，防止内容泄露
- **管理面板** — 后台可视化批量管理文章定价、订单和订阅状态

## 支付通道

| 驱动 | 标识 | 说明 |
|------|------|------|
| **支付宝当面付** | `alipay_face` | 支付宝扫码支付，MD5 签名验证 |
| **微信支付** | `wechat` | 微信扫码支付，MD5 签名验证 |
| **易支付** | `epay` | 通用易支付网关，兼容 V1 接口 |

## 内置主题

| 主题 | 变量数 | 说明 |
|------|--------|------|
| **default** | 9 | 简洁蓝调，亮暗双模式 |
| **default2** | 13 | 清新绿色，胶囊按钮风格 |
| **default3** | 13 | 暖灰蓝色调，柔和圆角 |
| **default4** | 14 | 苔绿色调，左右分栏布局 |
| **default5** | 15 | 暖珊瑚色，变量最丰富 |

> 所有主题均支持通过 CSS 变量覆盖配色，可全局或按文章单独设置。

## 服务器要求

| 项目 | 最低要求 | 说明 |
|------|---------|------|
| PHP | 7.4+ | 推荐 8.0+，需开启 `curl`、`json`、`mbstring` 扩展 |
| Typecho | 1.2+ | 支持命名空间版本 |
| MySQL | 5.7+ | 或 MariaDB 10.2+、SQLite 3、PostgreSQL |

## 安装

### 方式一：AB-Store 一键安装（推荐）

安装 [AdminBeautify](https://github.com/lhl77/Typecho-Plugin-AdminBeautify) 插件后，进入后台 **AB-Store** 应用商店，搜索 **TypechoPaid** 即可一键安装并获取后续更新。

### 方式二：手动安装

1. 下载最新 [Release](https://github.com/lhl77/TypechoPaid/releases) 压缩包
2. 解压为 `TypechoPaid` 文件夹
3. 上传至 Typecho 的 `usr/plugins/` 目录
4. 登录后台 → **控制台** → **插件管理** → 启用 **TypechoPaid**

### 方式三：Git 克隆

```bash
cd /path/to/typecho/usr/plugins/
git clone https://github.com/lhl77/TypechoPaid.git TypechoPaid
```

---

## 支付通道配置

支付通道配置详见 [文档](https://blog.lhl.one/artical/1309.html) 中「支付通道」章节。

---

## 文章自定义字段

| 字段 | 类型 | 说明 | 示例 |
|------|------|------|------|
| `paid_enable` | 整数 | 是否启用付费，`1` 开启，`0` 关闭 | `1` |
| `paid_price` | 小数 | 文章价格 | `9.90` |
| `paid_methods` | 字符串 | 可用支付渠道，逗号分隔，留空使用全部 | `alipay_face,epay` |
| `paid_theme` | 字符串 | 主题 ID，留空使用默认主题 | `default3` |
| `paid_desc` | 字符串 | 购买提示文案，留空使用默认文案 | `购买后可永久查看` |
| `paid_plan` | 字符串 | 订阅计划标识，留空则为单篇付费 | `monthly` |
| `paid_theme_options` | 字符串 | 主题变量覆盖，格式 `字段:值` 每行一个 | `primary:#e74c3c` |

## 订阅计划配置

在插件设置的「订阅计划」中，每行一个计划：

```text
plan_key:名称:价格:有效天数
```

```
monthly:月度订阅:29.90:30
quarterly:季度订阅:79.90:90
yearly:年度订阅:259.90:365
```

配置后在文章 `paid_plan` 字段填入 `plan_key` 即可将该文章归入订阅计划。

## 主题开发

主题存放在 `usr/plugins/TypechoPaid/themes/` 目录，每个主题一个子目录，必须包含：

```
themes/mytheme/
├── index.html    # 卡片 HTML 模板
├── theme.css     # 主题样式
└── main.js       # 交互逻辑
```

支持的模板变量请参考 [文档](https://blog.lhl.one/artical/1309.html) 中「主题与设置」章节。

## SDK 支付驱动扩展

```text
sdk/
├── PaymentInterface.php    # 驱动接口
├── PaymentFactory.php      # 驱动注册器
└── Drivers/
    ├── AlipayFace.php      # 支付宝当面付
    ├── Wechat.php          # 微信支付
    ├── Epay.php            # 易支付
    └── Other.php           # 其他支付方式
```

新增驱动步骤：

1. 在 `sdk/Drivers/` 新建驱动类，实现 `TypechoPaid_PaymentInterface`
2. 在 `sdk/PaymentFactory.php` 注册驱动映射
3. 在「支付通道定义」中使用该驱动名

```php
interface TypechoPaid_PaymentInterface
{
    public function createPayment(array $order, array $channel, $notifyUrl);
    public function verifyNotify(array $request, array $channel);
}
```

---

## 捐助

| <img src="https://i.see.you/2026/03/05/yc0T/4ca32aa36972b03bd14c1e480972db55.jpg" width="50%" /> |
| ------------------------------------------------------------ |
| <p align="center">微信赞赏码</p>                             |
备注：[TypechoPaid]+[昵称]+[博客地址/Github地址]可将您的捐赠记录显示下方。

## 许可证

[GPL-3.0](LICENSE) © LHL
