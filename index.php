<?php
/**
 * IP 探针系统 — 管理后台入口
 *
 * 后台 SPA：侧边栏 + 主内容区，通过 JS 切换页面。
 * 登录状态由 PHP Session 判断，未登录只显示登录面板。
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';

init_session();
$isLoggedIn = is_logged_in();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IP 探针系统</title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body>

<!-- ====== 登录面板 ====== -->
<div id="login-panel" class="login-panel" style="<?php echo $isLoggedIn ? 'display:none' : ''; ?>">
    <div class="login-card">
        <h2>IP 探针系统</h2>
        <p class="text-secondary">请输入管理密码</p>
        <div class="form-group">
            <input type="password" id="login-password" class="form-input" placeholder="密码" autocomplete="off">
        </div>
        <div id="login-error" style="display:none;color:var(--color-danger);font-size:13px;margin-bottom:12px;"></div>
        <button class="btn btn-primary btn-lg" id="login-btn">登 录</button>
    </div>
</div>

<!-- ====== 后台主体 ====== -->
<div id="admin-panel" class="admin-wrapper" style="<?php echo $isLoggedIn ? '' : 'display:none'; ?>">

    <!-- 侧边栏 -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo">
                <!-- Lucide: Activity -->
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                <span>IP 探针</span>
            </div>
        </div>

        <nav class="sidebar-nav">
            <button class="nav-item active" data-page="dashboard">
                <!-- Lucide: LayoutDashboard -->
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                <span>仪表盘</span>
            </button>
            <button class="nav-item" data-page="links">
                <!-- Lucide: Link -->
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <span>链接管理</span>
            </button>
            <button class="nav-item" data-page="records">
                <!-- Lucide: FileText -->
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                <span>采集记录</span>
            </button>
            <button class="nav-item" data-page="about">
                <!-- Lucide: Info -->
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span>关于</span>
            </button>
        </nav>

        <div class="sidebar-footer">
            <button class="nav-item danger" id="logout-btn">
                <!-- Lucide: LogOut -->
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>退出登录</span>
            </button>
        </div>
    </aside>

    <!-- 主内容区 -->
    <main class="main-content">

        <!-- ====== 仪表盘页面 ====== -->
        <section id="page-dashboard" class="page-section active">
            <h1 class="page-title">仪表盘</h1>
            <p class="page-desc">采集数据概览与趋势分析</p>

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">总采集数</div>
                    <div class="stat-value" id="stat-total">--</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">今日采集</div>
                    <div class="stat-value" id="stat-today">--</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">链接总数</div>
                    <div class="stat-value" id="stat-links">--</div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">本周采集</div>
                    <div class="stat-value" id="stat-week">--</div>
                </div>
            </div>

            <div class="chart-grid">
                <div class="chart-box wide">
                    <canvas id="chart-trend"></canvas>
                </div>
                <div class="chart-box">
                    <canvas id="chart-os"></canvas>
                </div>
                <div class="chart-box">
                    <canvas id="chart-browser"></canvas>
                </div>
                <div class="chart-box">
                    <canvas id="chart-city"></canvas>
                </div>
            </div>
        </section>

        <!-- ====== 链接管理页面 ====== -->
        <section id="page-links" class="page-section">
            <h1 class="page-title">链接管理</h1>
            <p class="page-desc">创建和管理采集链接</p>

            <!-- 创建链接 -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-title">创建采集链接</div>
                <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
                    <div class="form-group" style="flex:1;min-width:200px;margin-bottom:0;">
                        <label class="form-label">跳转目标 URL</label>
                        <input type="text" id="link-url" class="form-input" placeholder="https://example.com">
                    </div>
                    <div class="form-group" style="flex:1;min-width:150px;margin-bottom:0;">
                        <label class="form-label">备注（可选）</label>
                        <input type="text" id="link-remark" class="form-input" placeholder="备注名称">
                    </div>
                    <button class="btn btn-primary" id="create-link-btn" style="height:fit-content;">
                        <!-- Lucide: Plus -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        创建链接
                    </button>
                </div>
                <!-- 创建成功后的链接展示 -->
                <div id="link-created" style="display:none;margin-top:16px;padding:12px 16px;background:var(--color-surface-input);border-radius:var(--radius-sm);font-size:14px;">
                    <span style="color:var(--color-text-secondary);">链接已创建：</span>
                    <code id="link-created-url" style="user-select:all;color:var(--color-primary);"></code>
                    <button class="btn btn-ghost btn-sm" id="copy-link-btn" style="margin-left:8px;">
                        <!-- Lucide: Copy -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        复制
                    </button>
                </div>
            </div>

            <!-- 链接列表 -->
            <div id="links-list" class="link-list">
                <!-- JS 动态填充 -->
            </div>
            <div id="links-empty" class="empty-state" style="display:none;">
                <!-- Lucide: Link (空状态) -->
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                <p>暂无采集链接，点击上方「创建链接」开始</p>
            </div>
        </section>

        <!-- ====== 采集记录页面 ====== -->
        <section id="page-records" class="page-section">
            <h1 class="page-title">采集记录</h1>
            <p class="page-desc">查看和管理所有采集数据</p>

            <!-- 搜索 + 批量操作 -->
            <div class="toolbar">
                <div class="search-bar" style="flex:1;max-width:400px;">
                    <div class="search-input-wrapper" style="width:100%;">
                        <!-- Lucide: Search -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" id="search-input" class="form-input" placeholder="搜索 IP、User-Agent、城市...">
                    </div>
                </div>
                <div class="toolbar-right">
                    <button class="btn btn-danger" id="batch-delete-btn" style="display:none;">
                        <!-- Lucide: Trash2 -->
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                        删除选中 (<span id="selected-count">0</span>)
                    </button>
                </div>
            </div>

            <!-- 记录表格 -->
            <div class="card" style="padding:0;overflow:hidden;">
                <div class="table-wrapper">
                    <table class="data-table" id="records-table">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="select-all"></th>
                                <th>IPv4</th>
                                <th>IPv6</th>
                                <th>内网 IP</th>
                                <th>操作系统</th>
                                <th>浏览器</th>
                                <th>城市</th>
                                <th>采集时间</th>
                            </tr>
                        </thead>
                        <tbody id="records-tbody">
                            <!-- JS 动态填充 -->
                        </tbody>
                    </table>
                </div>
                <!-- 空状态 -->
                <div id="records-empty" class="empty-state" style="display:none;">
                    <!-- Lucide: FileText -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p>暂无采集记录</p>
                </div>
            </div>

            <!-- 分页 -->
            <div class="pagination" id="records-pagination">
                <button class="btn btn-ghost btn-sm" id="page-prev">
                    <!-- Lucide: ChevronLeft -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                </button>
                <span class="page-info" id="page-info">第 1 页</span>
                <button class="btn btn-ghost btn-sm" id="page-next">
                    <!-- Lucide: ChevronRight -->
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
            </div>
        </section>

        <!-- ====== 关于页面 ====== -->
        <section id="page-about" class="page-section">
            <h1 class="page-title">关于</h1>
            <p class="page-desc">系统信息与技术说明</p>

            <!-- 系统信息 -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-title">系统信息</div>
                <div class="group-list">
                    <div class="group-list-item"><span class="group-list-label">项目名称</span><span class="group-list-value">IP 探针系统</span></div>
                    <div class="group-list-item"><span class="group-list-label">版本</span><span class="group-list-value">v1.0.0</span></div>
                    <div class="group-list-item"><span class="group-list-label">运行环境</span><span class="group-list-value">PHP + JSON 文件存储（无数据库）</span></div>
                    <div class="group-list-item"><span class="group-list-label">开发者</span><span class="group-list-value">数字柚子 · 小柚子</span></div>
                </div>
            </div>

            <!-- 采集能力 -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-title">采集能力</div>
                <div class="group-list">
                    <div class="group-list-item"><span class="group-list-label">WebRTC 内网 IP</span><span class="group-list-value">通过 WebRTC 探测局域网真实 IP</span></div>
                    <div class="group-list-item"><span class="group-list-label">公网 IPv4 / IPv6</span><span class="group-list-value">通过 ipify 获取公网双栈地址</span></div>
                    <div class="group-list-item"><span class="group-list-label">地理位置</span><span class="group-list-value">精确到区县级（ip9.com.cn，免费免 Key）</span></div>
                    <div class="group-list-item"><span class="group-list-label">操作系统 / 浏览器</span><span class="group-list-value">User-Agent 解析</span></div>
                    <div class="group-list-item"><span class="group-list-label">屏幕分辨率</span><span class="group-list-value">逻辑分辨率 + 设备像素比</span></div>
                    <div class="group-list-item"><span class="group-list-label">系统语言</span><span class="group-list-value">navigator.language</span></div>
                    <div class="group-list-item"><span class="group-list-label">电池状态</span><span class="group-list-value">电量 + 充电状态（Battery API）</span></div>
                    <div class="group-list-item"><span class="group-list-label">VPN / 代理检测</span><span class="group-list-value">基于 WebRTC 泄漏与 IP 归属地比对</span></div>
                </div>
            </div>

            <!-- IP 归属地数据源 -->
            <div class="card" style="margin-bottom:20px;">
                <div class="card-title">IP 归属地数据源（优先级顺序）</div>
                <div class="group-list">
                    <div class="group-list-item">
                        <span class="group-list-label">① ip9.com.cn</span>
                        <span class="group-list-value">免费免 Key · 区县级 · IPv6 精准 · 60次/分钟</span>
                    </div>
                    <div class="group-list-item">
                        <span class="group-list-label">② ip-api.com</span>
                        <span class="group-list-value">免费 · 城市级 · 无日限额 · 45次/分钟</span>
                    </div>
                    <div class="group-list-item">
                        <span class="group-list-label">③ ipapi.co</span>
                        <span class="group-list-value">免费 · 城市级 · 1000次/天</span>
                    </div>
                </div>
            </div>

            <!-- 文件结构 -->
            <div class="card">
                <div class="card-title">数据存储结构</div>
                <div class="group-list">
                    <div class="group-list-item"><span class="group-list-label">records.json</span><span class="group-list-value">采集记录，追加写入</span></div>
                    <div class="group-list-item"><span class="group-list-label">links.json</span><span class="group-list-value">采集链接配置</span></div>
                    <div class="group-list-item"><span class="group-list-label">.password_hash</span><span class="group-list-value">管理员密码哈希（bcrypt）</span></div>
                    <div class="group-list-item"><span class="group-list-label">.htaccess</span><span class="group-list-value">阻止 data/ 目录直接访问</span></div>
                </div>
            </div>
        </section>

    </main>
</div>

<!-- ====== 记录详情弹窗 ====== -->
<div id="detail-modal" class="modal-overlay" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <div class="modal-title">记录详情</div>
            <button class="modal-close" id="modal-close">
                <!-- Lucide: X -->
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body" id="modal-body">
            <!-- JS 动态填充 -->
        </div>
    </div>
</div>

<!-- ====== 确认弹窗（通用） ====== -->
<div id="confirm-overlay" class="confirm-overlay" style="display:none;">
    <div class="confirm-dialog">
        <p id="confirm-msg">确定要执行此操作吗？</p>
        <div class="confirm-actions">
            <button class="btn btn-ghost" id="confirm-cancel">取消</button>
            <button class="btn btn-danger" id="confirm-ok">确定</button>
        </div>
    </div>
</div>

<!-- Toast 容器 -->
<div class="toast-container" id="toast-container"></div>

<!-- 第三方脚本 -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
    // Lucide 图标初始化（如果 Lucide 可用）
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>
<!-- 输出站点基础 URL，供 JS 拼接完整链接 -->
<script>
    window.SITE_URL = '<?php
        $proto = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host  = $_SERVER["HTTP_HOST"] ?? "localhost";
        $basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\");
        echo $proto . "://" . $host . $basePath;
    ?>';
</script>
<script src="assets/admin.js"></script>

</body>
</html>
