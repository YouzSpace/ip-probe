# IP 探针系统

> 轻量级 PHP 链接式网络信息采集工具

## 简介

IP 探针系统是一个纯 PHP 编写的轻量级采集工具。管理员创建采集链接，用户点击后页面静默采集其网络与设备信息，采集完成后自动跳转到预设页面。无需数据库，数据以 JSON 文件存储。

## 功能特性

- **静默采集**：用户访问链接后全程无感知，仅显示极简 loading 动画
- **多维度采集**：公网 IP（IPv4/IPv6）、内网 IP（WebRTC）、地理位置、操作系统、浏览器、屏幕分辨率、电池状态、网络类型
- **代理检测**：多维度综合判断用户是否使用代理/VPN
- **管理后台**：SPA 单页面后台，支持查看统计图表、管理采集链接、查看/搜索/删除采集记录
- **零依赖**：纯 PHP 8.x，无框架、无 Composer 依赖，直接部署

## 技术栈

| 层级 | 技术 |
|---|---|
| 后端 | PHP 8.x（纯 PHP，无框架） |
| 存储 | JSON 文件（`data/` 目录，`flock` 并发安全） |
| 前端采集 | 原生 JS（兼容 ES5，无框架） |
| 管理后台 | 原生 JS + Chart.js 4.x + Lucide Icons |
| UI 风格 | iOS 轻质感 + 毛玻璃 + 波点纹理背景 |

## 文件结构

```
ip-probe/
├── index.php                  # 管理后台入口（SPA）
├── collect.php                # 采集端页面（极简 loading）
├── api.php                    # 统一 API 入口（action 路由）
├── config.php                 # 全局配置常量
├── includes/
│   ├── auth.php               # 认证模块（Session + password_hash）
│   ├── ip.php                 # IP 获取 + 归属地查询 + 代理检测
│   ├── storage.php            # JSON 存储引擎（flock 文件锁）
│   ├── records.php            # 采集记录 CRUD + 统计
│   └── links.php              # 采集链接 CRUD
├── data/
│   ├── .htaccess              # 禁止直接访问
│   ├── records.json           # 采集记录存储
│   └── links.json             # 采集链接存储
├── assets/
│   ├── admin.css              # 后台样式（CSS 变量 + 毛玻璃）
│   ├── admin.js               # 后台交互逻辑
│   └── collect.js             # 前端采集脚本
└── README.md                  # 本文档
```

## 部署步骤

1. 将 `ip-probe/` 目录上传到支持 PHP 8.x 的 Web 服务器
2. 确保 `data/` 目录可写（PHP 会自动创建，或手动 `chmod 755 data/`）
3. 修改 `config.php` 中的 `SITE_URL`（自动检测，通常无需修改）
4. 访问 `http://your-domain.com/ip-probe/` 进入后台
5. 默认密码：`admin123`（登录后可在 `config.php` 中修改 `DEFAULT_PASSWORD` 并删除 `data/.password_hash` 让系统重新生成）

## API 接口说明

### 公开接口（无需登录）

| Action | 方法 | 说明 |
|---|---|---|
| `get_ip` | GET | 获取访问者公网 IP + 归属地 + 代理检测结果 |
| `save` | POST | 接收前端采集数据并保存 |
| `collect_info` | GET | 获取采集链接的跳转目标 URL |

### 管理接口（需登录）

| Action | 方法 | 说明 |
|---|---|---|
| `login` | POST | 管理员登录（`{ password }`） |
| `logout` | POST | 退出登录 |
| `check_auth` | GET | 检查当前登录状态 |
| `get_stats` | GET | 获取仪表盘统计数据 |
| `get_links` | GET | 获取采集链接列表 |
| `create_link` | POST | 创建采集链接（`{ redirect_url, remark }`） |
| `delete_link` | POST | 删除采集链接及关联记录 |
| `get_records` | GET | 获取采集记录列表（支持分页 + 搜索） |
| `get_record` | GET | 获取单条记录详情 |
| `delete_records` | POST | 批量删除采集记录 |

## 注意事项

- **ip-api.com 限流**：免费版限制 45 次/分钟，高并发场景建议升级付费版或替换为其他 IP 归属地 API
- **浏览器兼容性**：WebRTC 内网 IP 采集在部分浏览器（Safari 无痕模式、某些移动浏览器）中可能失败，系统会优雅降级
- **ipify.org**：用于获取公网 IP，如无法访问请修改 `config.php` 中的 `IP_API_URL`
- **数据持久化**：JSON 文件存储在 `data/` 目录，定期备份 `records.json` 和 `links.json`
- **安全**：`data/` 和 `includes/` 目录已通过 `.htaccess` 保护（Apache），Nginx 需手动配置 `deny all`

## 许可证

MIT License
