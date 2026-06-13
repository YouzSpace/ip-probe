/**
 * IP 探针系统 — 后台管理 JS
 *
 * 功能：登录、SPA 路由、仪表盘图表、链接管理、采集记录列表/详情/删除
 * 纯原生 JS，无框架依赖
 */
(function () {
    'use strict';

    /* ====== 全局状态 ====== */
    var currentPage   = '';
    var currentPageNum = 1;
    var selectedIds    = [];
    var charts          = {};   // 持有 Chart 实例引用

    /* ====== 通用工具 ====== */

    /**
     * AJAX 封装 — 统一处理 JSON 请求
     * @param {string} action - api.php?action=xxx
     * @param {object} [options] - fetch 选项
     * @returns {Promise<object>}
     */
    function api(action, options) {
        var url = (window.SITE_URL || '') + '/api.php?action=' + action;
        return fetch(url, options || {})
            .then(function (resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.json();
            })
            .then(function (data) {
                if (data.error) throw new Error(data.error);
                return data;
            });
    }

    /**
     * Toast 通知
     * @param {string} msg    - 消息文本
     * @param {string} [type] - success | danger | warning
     */
    function toast(msg, type) {
        type = type || 'success';
        var container = document.getElementById('toast-container');
        var el = document.createElement('div');
        el.className = 'toast toast-' + type;
        el.textContent = msg;
        container.appendChild(el);

        setTimeout(function () {
            el.style.animation = 'toastOut 0.25s ease forwards';
            setTimeout(function () { el.remove(); }, 250);
        }, 2500);
    }

    /**
     * 确认弹窗（Promise 封装）
     * @param {string} msg
     * @returns {Promise<boolean>}
     */
    function confirmDialog(msg) {
        return new Promise(function (resolve) {
            var overlay = document.getElementById('confirm-overlay');
            var msgEl  = document.getElementById('confirm-msg');
            var okBtn  = document.getElementById('confirm-ok');
            var cancelBtn = document.getElementById('confirm-cancel');

            msgEl.textContent = msg;
            overlay.style.display = 'flex';

            function clean() {
                overlay.style.display = 'none';
                okBtn.onclick    = null;
                cancelBtn.onclick = null;
            }

            okBtn.onclick = function () { clean(); resolve(true); };
            cancelBtn.onclick = function () { clean(); resolve(false); };
        });
    }

    /**
     * 复制文本到剪贴板
     * @param {string} text
     */
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                toast('已复制到剪贴板');
            });
        } else {
            // 降级方案
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
            toast('已复制到剪贴板');
        }
    }

    /**
     * 数字千位逗号格式化
     * @param {number} n
     * @returns {string}
     */
    function formatNumber(n) {
        return Number(n || 0).toLocaleString('zh-CN');
    }

    /**
     * XSS 转义
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ====== 登录 ====== */

    function initLogin() {
        var pwdInput   = document.getElementById('login-password');
        var loginBtn   = document.getElementById('login-btn');
        var errorDiv   = document.getElementById('login-error');
        var stepPwd    = document.getElementById('login-step-password');
        var step2fa    = document.getElementById('login-step-2fa');
        var codeInput  = document.getElementById('login-2fa-code');
        var verifyBtn  = document.getElementById('login-2fa-btn');
        var backBtn    = document.getElementById('login-back-btn');
        var error2fa   = document.getElementById('login-2fa-error');

        // Step 1: 密码验证
        loginBtn.addEventListener('click', function () {
            var pwd = pwdInput.value;
            if (!pwd) { errorDiv.textContent = '请输入密码'; errorDiv.style.display = 'block'; return; }

            loginBtn.disabled = true;
            loginBtn.textContent = '登录中…';
            errorDiv.style.display = 'none';

            api('login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: pwd })
            }).then(function (data) {
                if (data.need_2fa) {
                    // 需要 2FA：切换到验证码输入
                    stepPwd.style.display = 'none';
                    step2fa.style.display = '';
                    codeInput.value = '';
                    codeInput.focus();
                } else {
                    // 直接登录成功
                    onLoginSuccess();
                }
            }).catch(function (err) {
                errorDiv.textContent = err.message || '密码错误';
                errorDiv.style.display = 'block';
            }).finally(function () {
                loginBtn.disabled = false;
                loginBtn.textContent = '登 录';
            });
        });

        // 回车登录
        pwdInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') loginBtn.click();
        });

        // Step 2: 2FA 验证
        verifyBtn.addEventListener('click', function () {
            var code = codeInput.value.trim();
            if (!code || code.length !== 6) { error2fa.textContent = '请输入 6 位验证码'; error2fa.style.display = 'block'; return; }

            verifyBtn.disabled = true;
            verifyBtn.textContent = '验证中…';
            error2fa.style.display = 'none';

            api('login_2fa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code })
            }).then(function () {
                onLoginSuccess();
            }).catch(function (err) {
                error2fa.textContent = err.message || '验证码错误';
                error2fa.style.display = 'block';
                codeInput.value = '';
                codeInput.focus();
            }).finally(function () {
                verifyBtn.disabled = false;
                verifyBtn.textContent = '验 证';
            });
        });

        // 验证码回车
        codeInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') verifyBtn.click();
        });

        // 返回密码输入
        backBtn.addEventListener('click', function () {
            step2fa.style.display = 'none';
            stepPwd.style.display = '';
            pwdInput.value = '';
            pwdInput.focus();
        });

        // 退出登录
        document.getElementById('logout-btn').addEventListener('click', function () {
            api('logout', { method: 'POST' }).then(function () {
                location.reload();
            });
        });
    }

    /** 登录成功后的通用初始化 */
    function onLoginSuccess() {
        document.getElementById('login-panel').style.display = 'none';
        document.getElementById('admin-panel').style.display = '';
        var hash = location.hash.replace('#', '') || 'dashboard';
        navigateTo(hash);
        toast('登录成功', 'success');
    }

    /* ====== SPA 路由 ====== */

    function initRouter() {
        // 侧边栏导航点击
        var navItems = document.querySelectorAll('.sidebar-nav .nav-item');
        navItems.forEach(function (item) {
            item.addEventListener('click', function () {
                var page = item.getAttribute('data-page');
                location.hash = page;
            });
        });

        // hash 变化
        window.addEventListener('hashchange', function () {
            var hash = location.hash.replace('#', '') || 'dashboard';
            navigateTo(hash);
        });
    }

    /**
     * 切换到指定页面
     * @param {string} page - dashboard | links | records | about
     */
    function navigateTo(page) {
        if (page === currentPage) return;
        currentPage = page;

        // 更新侧边栏高亮
        document.querySelectorAll('.sidebar-nav .nav-item').forEach(function (item) {
            item.classList.toggle('active', item.getAttribute('data-page') === page);
        });

        // 切换 section 显示
        document.querySelectorAll('.page-section').forEach(function (sec) {
            sec.classList.toggle('active', sec.id === 'page-' + page);
        });

        // 加载对应数据
        switch (page) {
            case 'dashboard': loadDashboard(); break;
            case 'links':     loadLinks();     break;
            case 'records':   currentPageNum = 1; loadRecords(1); break;
            case 'checkins':  loadCheckins(); break;
            case 'about':     /* 静态页面，无需加载数据 */ break;
            case 'settings':  loadSettings(); break;
        }
    }

    /* ====== 检查更新 ====== */

    /**
     * 检查 GitHub 仓库是否有新版本
     * 由关于页面的按钮 onclick 调用
     */
    window.checkUpdate = function () {
        var statusEl   = document.getElementById('update-status');
        var linkEl     = document.getElementById('update-link');
        var updateBtn  = document.getElementById('update-now-btn');
        var btnEl      = document.getElementById('check-update-btn');

        // 显示加载状态
        statusEl.style.display    = 'block';
        statusEl.style.background = 'var(--color-surface-input)';
        statusEl.style.color      = 'var(--color-text-secondary)';
        statusEl.innerHTML        = '正在检查更新...';
        linkEl.style.display      = 'none';
        updateBtn.style.display   = 'none';
        btnEl.disabled            = true;

        api('check_update').then(function (data) {
            btnEl.disabled = false;

            if (data.type === 'release') {
                if (data.has_update) {
                    statusEl.style.background = '#fff3cd';
                    statusEl.style.color      = '#856404';
                    statusEl.innerHTML =
                        '<div style="font-weight:600;margin-bottom:4px;">发现新版本 ' + escapeHtml(data.latest_version) + '</div>' +
                        '<div style="font-size:13px;">当前：' + escapeHtml(data.current_version) + ' → 最新：' + escapeHtml(data.latest_version) + '</div>' +
                        '<div style="font-size:13px;margin-top:4px;color:var(--color-text-secondary);">发布于 ' + escapeHtml(data.published_at || '--') + '</div>';
                    linkEl.style.display    = 'inline-flex';
                    updateBtn.style.display = 'inline-flex';
                    linkEl.href = data.html_url;
                } else {
                    statusEl.style.background = '#d4edda';
                    statusEl.style.color      = '#155724';
                    statusEl.innerHTML =
                        '<div style="font-weight:600;">已是最新版本</div>' +
                        '<div style="font-size:13px;margin-top:2px;">当前 ' + escapeHtml(data.current_version) + ' 与最新 Release 一致</div>';
                    linkEl.style.display    = 'none';
                    updateBtn.style.display = 'none';
                }
            } else if (data.type === 'commit') {
                statusEl.style.background = 'var(--color-surface-input)';
                statusEl.style.color      = 'var(--color-text-secondary)';
                statusEl.innerHTML =
                    '<div style="font-weight:600;">暂无 Release，显示最新提交</div>' +
                    '<div style="font-size:13px;margin-top:2px;">' + escapeHtml(data.latest_version) + ' · ' + escapeHtml(data.commit_message) + '</div>' +
                    '<div style="font-size:13px;margin-top:2px;">' + escapeHtml(data.commit_date || '--') + '</div>';
                linkEl.style.display    = 'inline-flex';
                updateBtn.style.display = 'none';
                linkEl.href = data.html_url;
            }
        }).catch(function (err) {
            btnEl.disabled = false;
            statusEl.style.background = '#f8d7da';
            statusEl.style.color      = '#721c24';
            statusEl.innerHTML =
                '<div style="font-weight:600;">检查失败</div>' +
                '<div style="font-size:13px;margin-top:2px;">' + escapeHtml(err.message || '网络错误') + '</div>';
            linkEl.style.display    = 'inline-flex';
            updateBtn.style.display = 'none';
            linkEl.href = 'https://github.com/YouzSpace/ip-probe';
        });
    };

    /**
     * 执行 git pull 拉取最新代码
     * 由「立即更新」按钮 onclick 调用
     */
    window.doUpdate = function () {
        var statusEl  = document.getElementById('update-status');
        var btnEl     = document.getElementById('update-now-btn');
        var linkEl    = document.getElementById('update-link');

        if (!confirm('确定要立即更新吗？系统将自动拉取最新代码。')) return;

        statusEl.style.background = 'var(--color-surface-input)';
        statusEl.style.color      = 'var(--color-text-secondary)';
        statusEl.innerHTML        = '正在拉取更新...';
        btnEl.disabled            = true;
        linkEl.style.display      = 'none';

        api('do_update').then(function (data) {
            if (data.success) {
                statusEl.style.background = '#d4edda';
                statusEl.style.color      = '#155724';
                statusEl.innerHTML =
                    '<div style="font-weight:600;">更新成功</div>' +
                    '<div style="font-size:13px;margin-top:4px;">当前版本：' + escapeHtml(data.version) + '</div>';
                btnEl.style.display = 'none';
                toast('更新成功！', 'success');
            } else {
                statusEl.style.background = '#f8d7da';
                statusEl.style.color      = '#721c24';
                statusEl.innerHTML =
                    '<div style="font-weight:600;">更新失败</div>' +
                    '<div style="font-size:13px;margin-top:4px;white-space:pre-wrap;">' + escapeHtml(data.output || '未知错误') + '</div>';
                btnEl.disabled = false;
            }
        }).catch(function (err) {
            statusEl.style.background = '#f8d7da';
            statusEl.style.color      = '#721c24';
            statusEl.innerHTML =
                '<div style="font-weight:600;">更新失败</div>' +
                '<div style="font-size:13px;margin-top:2px;">' + escapeHtml(err.message || '网络错误') + '</div>';
            btnEl.disabled = false;
        });
    };

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ====== 仪表盘 ====== */

    function loadDashboard() {
        api('get_stats').then(function (stats) {
            // 统计卡片
            document.getElementById('stat-total').textContent  = formatNumber(stats.total || 0);
            document.getElementById('stat-today').textContent  = formatNumber(stats.today || 0);
            document.getElementById('stat-links').textContent  = formatNumber(stats.links_count || 0);
            document.getElementById('stat-week').textContent   = formatNumber(stats.week_total || 0);

            renderCharts(stats);
        }).catch(function (err) {
            console.error('加载仪表盘失败：', err);
            // 显示 0 而非 --
            document.getElementById('stat-total').textContent  = '0';
            document.getElementById('stat-today').textContent  = '0';
            document.getElementById('stat-links').textContent  = '0';
            document.getElementById('stat-week').textContent   = '0';
            toast('仪表盘加载失败：' + (err.message || '未知错误'), 'danger');
        });
    }

    /**
     * 渲染所有图表
     * @param {object} stats - get_stats 返回数据
     */
    function renderCharts(stats) {
        if (typeof Chart === 'undefined') {
            console.warn('Chart.js 未加载，跳过图表渲染');
            return;
        }

        // 销毁旧图表
        Object.keys(charts).forEach(function (key) {
            if (charts[key]) { charts[key].destroy(); charts[key] = null; }
        });

        // ===== 1. 近 7 天趋势折线图 =====
        var trend = stats.week_trend || [];
        var trendLabels = trend.map(function (d) { return d.date || ''; });
        var trendData   = trend.map(function (d) { return d.count || 0; });

        charts.trend = new Chart(document.getElementById('chart-trend'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: '采集次数',
                    data: trendData,
                    borderColor: '#007AFF',
                    backgroundColor: 'rgba(0,122,255,0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointBackgroundColor: '#007AFF',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });

        // ===== 2. 操作系统分布饼图 =====
        var osDist = stats.os_dist || {};
        var osKeys  = Object.keys(osDist);
        charts.os = new Chart(document.getElementById('chart-os'), {
            type: 'doughnut',
            data: {
                labels: osKeys,
                datasets: [{
                    data: osKeys.map(function (k) { return osDist[k]; }),
                    backgroundColor: ['#007AFF','#34C759','#FF9500','#FF3B30','#AF52DE','#5856D6','#8E8E93']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { title: { display: true, text: '操作系统分布' } }
            }
        });

        // ===== 3. 浏览器分布饼图 =====
        var brDist = stats.browser_dist || {};
        var brKeys  = Object.keys(brDist);
        charts.browser = new Chart(document.getElementById('chart-browser'), {
            type: 'doughnut',
            data: {
                labels: brKeys,
                datasets: [{
                    data: brKeys.map(function (k) { return brDist[k]; }),
                    backgroundColor: ['#007AFF','#34C759','#FF9500','#FF3B30','#AF52DE','#5856D6','#8E8E93']
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { title: { display: true, text: '浏览器分布' } }
            }
        });

        // ===== 4. 城市分布 Top10 条形图 =====
        var cityDist = stats.city_dist || {};
        var cityKeys = Object.keys(cityDist).slice(0, 10);
        charts.city = new Chart(document.getElementById('chart-city'), {
            type: 'bar',
            data: {
                labels: cityKeys,
                datasets: [{
                    label: '采集次数',
                    data: cityKeys.map(function (k) { return cityDist[k]; }),
                    backgroundColor: 'rgba(0,122,255,0.7)',
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                plugins: { title: { display: true, text: '城市分布 Top 10' }, legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 } }
                }
            }
        });
    }

    /* ====== 链接管理 ====== */

    function loadLinks() {
        api('get_links').then(function (data) {
            var links = data.links || [];
            renderLinks(links);
        }).catch(function (err) {
            console.error('加载链接失败：', err);
        });
    }

    /**
     * 渲染链接列表（iOS Group List 风格）
     * @param {Array} links
     */
    function renderLinks(links) {
        var container  = document.getElementById('links-list');
        var emptyDiv  = document.getElementById('links-empty');

        if (!links.length) {
            container.style.display = 'none';
            emptyDiv.style.display  = '';
            return;
        }

        container.style.display = '';
        emptyDiv.style.display  = 'none';

        var html = '';
        links.forEach(function (link) {
            var url = escapeHtml(link.redirect_url || '');
            var remark = escapeHtml(link.remark || '（无备注）');
            var created = escapeHtml(link.created_at || '');
            var count = formatNumber(link.collect_count || 0);
            var fullUrl = escapeHtml((window.SITE_URL || '') + '/collect.php?id=' + encodeURIComponent(link.id));

            html +=
                '<div class="link-item" data-id="' + escapeHtml(link.id) + '">' +
                    '<div class="link-info">' +
                        '<div class="link-remark">' + remark + '</div>' +
                        '<div class="link-url" title="' + url + '">跳转至：' + url + '</div>' +
                        '<div class="link-meta">采集 ' + count + ' 次 · 创建于 ' + created + '</div>' +
                    '</div>' +
                    '<div class="link-actions">' +
                        '<button class="btn btn-secondary btn-sm btn-copy-link" data-url="' + fullUrl + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                            ' 复制' +
                        '</button>' +
                        '<button class="btn btn-danger btn-sm btn-delete-link" data-id="' + escapeHtml(link.id) + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                            ' 删除' +
                        '</button>' +
                    '</div>' +
                '</div>';
        });

        container.innerHTML = html;

        // 绑定复制按钮
        container.querySelectorAll('.btn-copy-link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                copyText(btn.getAttribute('data-url'));
            });
        });

        // 绑定删除按钮
        container.querySelectorAll('.btn-delete-link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = btn.getAttribute('data-id');
                deleteLink(id);
            });
        });
    }

    /**
     * 创建链接
     */
    function createLink() {
        var urlInput   = document.getElementById('link-url');
        var remarkInput = document.getElementById('link-remark');
        var url         = urlInput.value.trim();
        var remark      = remarkInput.value.trim();

        if (!url) { toast('请输入跳转目标 URL', 'warning'); return; }

        var btn = document.getElementById('create-link-btn');
        btn.disabled = true;

        api('create_link', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ redirect_url: url, remark: remark })
        }).then(function (data) {
            var link = data.link || {};
            var fullUrl = (window.SITE_URL || '') + '/collect.php?id=' + encodeURIComponent(link.id || '');

            document.getElementById('link-created-url').textContent = fullUrl;
            document.getElementById('link-created').style.display = '';

            urlInput.value   = '';
            remarkInput.value = '';

            loadLinks();
            toast('链接创建成功', 'success');
        }).catch(function (err) {
            toast('创建失败：' + err.message, 'danger');
        }).finally(function () {
            btn.disabled = false;
        });
    }

    /**
     * 删除链接（含确认）
     * @param {string} id
     */
    function deleteLink(id) {
        confirmDialog('确定要删除此链接吗？关联的采集记录将一并删除。').then(function (ok) {
            if (!ok) return;
            api('delete_link', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            }).then(function () {
                toast('链接已删除', 'success');
                loadLinks();
            }).catch(function (err) {
                toast('删除失败：' + err.message, 'danger');
            });
        });
    }

    /**
     * 复制链接（创建后快捷按钮）
     */
    function initCopyLinkBtn() {
        var btn = document.getElementById('copy-link-btn');
        if (!btn) return;
        btn.addEventListener('click', function () {
            var url = document.getElementById('link-created-url').textContent;
            copyText(url);
        });
    }

    /* ====== 采集记录 ====== */

    /**
     * 加载记录列表
     * @param {number} page
     * @param {string} [search]
     */
    function loadRecords(page) {
        page = page || 1;
        currentPageNum = page;
        selectedIds = [];

        var search = (document.getElementById('search-input').value || '').trim();
        var url = (window.SITE_URL || '') + '/api.php?action=get_records&page=' + page + '&limit=20';
        if (search) url += '&search=' + encodeURIComponent(search);

        fetch(url).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function (data) {
            if (data.error) throw new Error(data.error);
            renderRecords(data.records || []);
            renderPagination(data.total || 0, page, 20);
            updateBatchDeleteBtn();
        }).catch(function (err) {
            console.error('加载记录失败：', err);
            toast('加载记录失败：' + (err.message || '未知错误'), 'danger');
        });
    }

    /**
     * 渲染记录表格
     * @param {Array} records
     */
    function renderRecords(records) {
        var tbody    = document.getElementById('records-tbody');
        var emptyDiv = document.getElementById('records-empty');
        var table    = document.getElementById('records-table');

        if (!records.length) {
            table.style.display    = 'none';
            emptyDiv.style.display = '';
            tbody.innerHTML = '';
            return;
        }

        table.style.display    = '';
        emptyDiv.style.display = 'none';

        var html = '';
        records.forEach(function (rec) {
            var ipv4      = escapeHtml(rec.ipv4 || '--');
            var ipv6      = escapeHtml((rec.ipv6 || '--').substring(0, 20));
            var webrtc    = (rec.webrtc_ips && rec.webrtc_ips.length) ? escapeHtml(rec.webrtc_ips[0]) : '--';
            var os        = escapeHtml(rec.os || '--');
            var browser   = escapeHtml(rec.browser || '--');
            var city      = escapeHtml((rec.location && rec.location.city) || '--');
            var created   = escapeHtml(rec.created_at || '--');
            var recId     = escapeHtml(rec.id || '');

            html +=
                '<tr data-id="' + recId + '">' +
                    '<td><input type="checkbox" class="record-check" data-id="' + recId + '"></td>' +
                    '<td>' + ipv4 + '</td>' +
                    '<td style="font-size:12px;color:var(--color-text-secondary);">' + ipv6 + '</td>' +
                    '<td>' + webrtc + '</td>' +
                    '<td>' + os + '</td>' +
                    '<td>' + browser + '</td>' +
                    '<td>' + city + '</td>' +
                    '<td style="white-space:nowrap;">' + created + '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;

        // 行点击 → 详情
        tbody.querySelectorAll('tr').forEach(function (row) {
            row.addEventListener('click', function (e) {
                // 点击 checkbox 不触发
                if (e.target.type === 'checkbox') return;
                var id = row.getAttribute('data-id');
                showRecordDetail(id);
            });
        });

        // Checkbox 勾选
        tbody.querySelectorAll('.record-check').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var id = cb.getAttribute('data-id');
                if (cb.checked) {
                    if (selectedIds.indexOf(id) === -1) selectedIds.push(id);
                } else {
                    selectedIds = selectedIds.filter(function (x) { return x !== id; });
                }
                updateBatchDeleteBtn();
            });
        });
    }

    /**
     * 渲染分页控件
     * @param {number} total
     * @param {number} page
     * @param {number} limit
     */
    function renderPagination(total, page, limit) {
        var totalPages = Math.max(1, Math.ceil(total / limit));
        document.getElementById('page-info').textContent = '第 ' + page + ' / ' + totalPages + ' 页（共 ' + formatNumber(total) + ' 条）';

        document.getElementById('page-prev').disabled = (page <= 1);
        document.getElementById('page-next').disabled = (page >= totalPages);
    }

    /**
     * 更新批量删除按钮显示
     */
    function updateBatchDeleteBtn() {
        var btn = document.getElementById('batch-delete-btn');
        if (selectedIds.length > 0) {
            btn.style.display = '';
            btn.querySelector('#selected-count').textContent = selectedIds.length;
        } else {
            btn.style.display = 'none';
        }
    }

    /**
     * 显示记录详情弹窗
     * @param {string} id
     */
    function showRecordDetail(id) {
        var url = (window.SITE_URL || '') + '/api.php?action=get_record&id=' + encodeURIComponent(id);
        fetch(url).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function (data) {
            if (data.error) throw new Error(data.error);
            var rec = data.record;
            if (!rec) throw new Error('记录不存在');
            renderDetailModal(rec);
            document.getElementById('detail-modal').style.display = 'flex';
        }).catch(function (err) {
            toast('加载详情失败：' + err.message, 'danger');
        });
    }

    /**
     * 渲染详情弹窗内容
     * @param {object} rec
     */
    function renderDetailModal(rec) {
        var body = document.getElementById('modal-body');
        var loc = rec.location || {};

        var html = '';

        // 网络信息
        html += '<div class="group-list-header">网络信息</div>';
        html += '<div class="group-list">';
        html += groupItem('公网 IPv4', escapeHtml(rec.ipv4 || '--'));
        html += groupItem('公网 IPv6', escapeHtml(rec.ipv6 || '--'));
        html += groupItem('内网 IP', (rec.webrtc_ips || []).map(function (ip) { return escapeHtml(ip); }).join('、') || '--');
        html += groupItem('ISP', escapeHtml(loc.isp || '--'));
        html += groupItem('服务器 IP', escapeHtml(rec.server_ip || '--'));
        html += '</div>';

        // 地理位置
        html += '<div class="group-list-header">地理位置</div>';
        html += '<div class="group-list">';
        html += groupItem('国家', escapeHtml(loc.country || '--'));
        html += groupItem('省份', escapeHtml(loc.province || '--'));
        html += groupItem('城市', escapeHtml(loc.city || '--'));
        html += groupItem('区县', escapeHtml(loc.district || '--'));
        html += groupItem('数据来源', escapeHtml(loc.source || '--'));
        html += '</div>';

        // 设备信息
        html += '<div class="group-list-header">设备信息</div>';
        html += '<div class="group-list">';
        html += groupItem('操作系统', escapeHtml(rec.os || '--'));
        html += groupItem('浏览器', escapeHtml(rec.browser || '--'));
        html += groupItem('语言', escapeHtml(rec.language || '--'));
        html += groupItem('屏幕分辨率', escapeHtml(rec.screen || '--'));
        html += groupItem('像素比 (DPR)', escapeHtml(String(rec.dpr || '--')));
        html += '</div>';

        // 电池信息
        html += '<div class="group-list-header">电池信息</div>';
        html += '<div class="group-list">';
        html += groupItem('电量', rec.battery_level !== null ? escapeHtml(rec.battery_level + '%') : '--');
        html += groupItem('充电中', rec.battery_charging !== null ? (rec.battery_charging ? '是' : '否') : '--');
        html += '</div>';

        // 网络类型
        html += '<div class="group-list-header">网络类型</div>';
        html += '<div class="group-list">';
        html += groupItem('连接类型', escapeHtml(rec.network_type || '--'));
        html += groupItem('下行速度 (Mbps)', rec.connection_downlink !== null ? escapeHtml(String(rec.connection_downlink)) : '--');
        html += '</div>';

        // 代理检测
        html += '<div class="group-list-header">代理检测</div>';
        html += '<div class="group-list">';
        html += groupItem('是否代理', rec.is_proxy ? '<span class="badge badge-danger">是</span>' : '<span class="badge badge-success">否</span>');
        html += groupItem('检测依据', escapeHtml(rec.proxy_detected_by || '--'));
        html += '</div>';

        // 采集信息
        html += '<div class="group-list-header">采集信息</div>';
        html += '<div class="group-list">';
        html += groupItem('采集时间', escapeHtml(rec.created_at || '--'));
        html += groupItem('关联链接', escapeHtml(rec.link_id || '--'));
        html += groupItem('原始 UA', '<span style="word-break:break-all;font-size:12px;">' + escapeHtml(rec.user_agent_raw || '--') + '</span>');
        html += '</div>';

        body.innerHTML = html;
    }

    /**
     * 生成 iOS Group List 单行 HTML
     * @param {string} label
     * @param {string} value - 允许 HTML
     * @returns {string}
     */
    function groupItem(label, value) {
        return '<div class="group-list-item">' +
            '<span class="group-list-label">' + label + '</span>' +
            '<span class="group-list-value">' + value + '</span>' +
        '</div>';
    }

    /**
     * 搜索记录
     */
    function searchRecords() {
        currentPageNum = 1;
        selectedIds = [];
        loadRecords(1);
    }

    /**
     * 批量删除记录
     */
    function deleteRecords(ids) {
        confirmDialog('确定要删除选中的 ' + ids.length + ' 条记录吗？').then(function (ok) {
            if (!ok) return;
            api('delete_records', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ids: ids })
            }).then(function () {
                toast('已删除 ' + ids.length + ' 条记录', 'success');
                selectedIds = [];
                loadRecords(currentPageNum);
            }).catch(function (err) {
                toast('删除失败：' + err.message, 'danger');
            });
        });
    }

    /* ====== 事件绑定 ====== */

    function bindEvents() {
        // 创建链接按钮
        var createBtn = document.getElementById('create-link-btn');
        if (createBtn) createBtn.addEventListener('click', createLink);

        // 链接 URL 输入框回车
        var urlInput = document.getElementById('link-url');
        if (urlInput) {
            urlInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') createLink();
            });
        }

        // 搜索框
        var searchInput = document.getElementById('search-input');
        if (searchInput) {
            var timer = null;
            searchInput.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(searchRecords, 400);
            });
        }

        // 全选
        var selectAll = document.getElementById('select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                var checked = selectAll.checked;
                document.querySelectorAll('.record-check').forEach(function (cb) {
                    cb.checked = checked;
                    var id = cb.getAttribute('data-id');
                    if (checked && selectedIds.indexOf(id) === -1) selectedIds.push(id);
                    if (!checked) selectedIds = selectedIds.filter(function (x) { return x !== id; });
                });
                updateBatchDeleteBtn();
            });
        }

        // 批量删除
        var batchBtn = document.getElementById('batch-delete-btn');
        if (batchBtn) {
            batchBtn.addEventListener('click', function () {
                if (selectedIds.length === 0) return;
                deleteRecords(selectedIds);
            });
        }

        // 分页
        var prevBtn = document.getElementById('page-prev');
        var nextBtn = document.getElementById('page-next');
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (currentPageNum > 1) loadRecords(currentPageNum - 1);
            });
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                loadRecords(currentPageNum + 1);
            });
        }

        // 详情弹窗关闭
        var modalClose = document.getElementById('modal-close');
        var modalOverlay = document.getElementById('detail-modal');
        if (modalClose) {
            modalClose.addEventListener('click', function () {
                modalOverlay.style.display = 'none';
            });
        }
        // 点击遮罩关闭
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function (e) {
                if (e.target === modalOverlay) modalOverlay.style.display = 'none';
            });
        }

        // 创建链接后复制按钮
        initCopyLinkBtn();

        // 签到管理事件
        initCheckinEvents();
    }

    /* ====== 签到管理 ====== */

    var checkinPageNum = 1;

    /**
     * 加载签到管理页面数据
     */
    function loadCheckins() {
        loadCheckinStats();
        loadCheckinLinks();
        checkinPageNum = 1;
        loadCheckinRecords(1);
    }

    /**
     * 加载签到统计数据
     */
    function loadCheckinStats() {
        api('get_checkin_stats').then(function (stats) {
            document.getElementById('checkin-stat-links').textContent = formatNumber(stats.links_count || 0);
            document.getElementById('checkin-stat-total').textContent = formatNumber(stats.total || 0);
            document.getElementById('checkin-stat-today').textContent = formatNumber(stats.today || 0);
        });
    }

    /**
     * 加载签到链接列表
     */
    function loadCheckinLinks() {
        api('get_checkins').then(function (data) {
            renderCheckinLinks(data.checkins || []);
        });
    }

    /**
     * 渲染签到链接列表
     */
    function renderCheckinLinks(checkins) {
        var container = document.getElementById('checkin-links-list');
        var emptyDiv = document.getElementById('checkin-links-empty');

        if (!checkins.length) {
            container.innerHTML = '';
            emptyDiv.style.display = '';
            return;
        }

        emptyDiv.style.display = 'none';

        var html = '';
        checkins.forEach(function (item) {
            var remark = escapeHtml(item.remark || '（无备注）');
            var created = escapeHtml(item.created_at || '');
            var count = formatNumber(item.checkin_count || 0);
            var fullUrl = escapeHtml((window.SITE_URL || '') + '/checkin.php?id=' + encodeURIComponent(item.id));

            html +=
                '<div class="link-item" data-id="' + escapeHtml(item.id) + '">' +
                    '<div class="link-info">' +
                        '<div class="link-remark">' + remark + '</div>' +
                        '<div class="link-url" title="' + fullUrl + '">' + fullUrl + '</div>' +
                        '<div class="link-meta">签到 ' + count + ' 次 · 创建于 ' + created + '</div>' +
                    '</div>' +
                    '<div class="link-actions">' +
                        '<button class="btn btn-secondary btn-sm btn-copy-checkin" data-url="' + fullUrl + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>' +
                            ' 复制' +
                        '</button>' +
                        '<button class="btn btn-danger btn-sm btn-delete-checkin" data-id="' + escapeHtml(item.id) + '">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>' +
                            ' 删除' +
                        '</button>' +
                    '</div>' +
                '</div>';
        });

        container.innerHTML = html;

        container.querySelectorAll('.btn-copy-checkin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                copyText(btn.getAttribute('data-url'));
            });
        });

        container.querySelectorAll('.btn-delete-checkin').forEach(function (btn) {
            btn.addEventListener('click', function () {
                deleteCheckinLink(btn.getAttribute('data-id'));
            });
        });
    }

    /**
     * 创建签到链接
     */
    function createCheckinLink() {
        var remarkInput = document.getElementById('checkin-remark');
        var remark = remarkInput.value.trim();
        var btn = document.getElementById('create-checkin-btn');
        btn.disabled = true;

        api('create_checkin', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ remark: remark })
        }).then(function (data) {
            var checkin = data.checkin || {};
            var fullUrl = (window.SITE_URL || '') + '/checkin.php?id=' + encodeURIComponent(checkin.id || '');

            document.getElementById('checkin-created-url').textContent = fullUrl;
            document.getElementById('checkin-created').style.display = '';

            remarkInput.value = '';
            loadCheckins();
            toast('签到链接创建成功', 'success');
        }).catch(function (err) {
            toast('创建失败：' + err.message, 'danger');
        }).finally(function () {
            btn.disabled = false;
        });
    }

    /**
     * 删除签到链接
     */
    function deleteCheckinLink(id) {
        confirmDialog('确定要删除此签到链接吗？关联的签到记录将一并删除。').then(function (ok) {
            if (!ok) return;
            api('delete_checkin', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id })
            }).then(function () {
                toast('签到链接已删除', 'success');
                loadCheckins();
            }).catch(function (err) {
                toast('删除失败：' + err.message, 'danger');
            });
        });
    }

    /**
     * 加载签到记录列表
     */
    function loadCheckinRecords(page) {
        page = page || 1;
        checkinPageNum = page;

        var search = (document.getElementById('checkin-search-input').value || '').trim();
        var url = (window.SITE_URL || '') + '/api.php?action=get_checkin_records&page=' + page + '&limit=20';
        if (search) url += '&search=' + encodeURIComponent(search);

        fetch(url).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function (data) {
            if (data.error) throw new Error(data.error);
            renderCheckinRecords(data.records || []);
            renderCheckinPagination(data.total || 0, page, 20);
        }).catch(function (err) {
            console.error('加载签到记录失败：', err);
            toast('加载签到记录失败：' + (err.message || '未知错误'), 'danger');
        });
    }

    /**
     * 渲染签到记录表格
     */
    function renderCheckinRecords(records) {
        var tbody = document.getElementById('checkin-records-tbody');
        var emptyDiv = document.getElementById('checkin-records-empty');
        var table = document.getElementById('checkin-records-table');

        if (!records.length) {
            table.style.display = 'none';
            emptyDiv.style.display = '';
            tbody.innerHTML = '';
            return;
        }

        table.style.display = '';
        emptyDiv.style.display = 'none';

        var html = '';
        records.forEach(function (rec) {
            var ipv4 = escapeHtml(rec.ipv4 || '--');
            var ipv6 = escapeHtml((rec.ipv6 || '--').substring(0, 20));
            var os = escapeHtml(rec.os || '--');
            var browser = escapeHtml(rec.browser || '--');
            var city = escapeHtml((rec.location && rec.location.city) || '--');
            var created = escapeHtml(rec.created_at || '--');
            var recId = escapeHtml(rec.id || '');

            html +=
                '<tr data-id="' + recId + '">' +
                    '<td><input type="checkbox" class="checkin-check" data-id="' + recId + '"></td>' +
                    '<td>' + ipv4 + '</td>' +
                    '<td style="font-size:12px;color:var(--color-text-secondary);">' + ipv6 + '</td>' +
                    '<td>' + os + '</td>' +
                    '<td>' + browser + '</td>' +
                    '<td>' + city + '</td>' +
                    '<td style="white-space:nowrap;">' + created + '</td>' +
                '</tr>';
        });

        tbody.innerHTML = html;

        // 行点击 → 详情
        tbody.querySelectorAll('tr').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.type === 'checkbox') return;
                var id = row.getAttribute('data-id');
                showCheckinRecordDetail(id);
            });
        });

        // Checkbox 勾选
        tbody.querySelectorAll('.checkin-check').forEach(function (cb) {
            cb.addEventListener('change', updateCheckinBatchDeleteBtn);
        });

        updateCheckinBatchDeleteBtn();
    }

    /** 获取已勾选的签到记录 ID */
    function getSelectedCheckinIds() {
        var ids = [];
        document.querySelectorAll('.checkin-check:checked').forEach(function (cb) {
            ids.push(cb.getAttribute('data-id'));
        });
        return ids;
    }

    /** 更新签到批量删除按钮显示 */
    function updateCheckinBatchDeleteBtn() {
        var ids = getSelectedCheckinIds();
        var btn = document.getElementById('checkin-batch-delete-btn');
        var countEl = document.getElementById('checkin-selected-count');
        if (ids.length > 0) {
            btn.style.display = '';
            countEl.textContent = ids.length;
        } else {
            btn.style.display = 'none';
        }
    }

    /**
     * 渲染签到记录分页
     */
    function renderCheckinPagination(total, page, limit) {
        var totalPages = Math.ceil(total / limit) || 1;
        document.getElementById('checkin-page-info').textContent = '第 ' + page + ' / ' + totalPages + ' 页';
        document.getElementById('checkin-page-prev').disabled = (page <= 1);
        document.getElementById('checkin-page-next').disabled = (page >= totalPages);
    }

    /**
     * 显示签到记录详情
     */
    function showCheckinRecordDetail(id) {
        var url = (window.SITE_URL || '') + '/api.php?action=get_checkin_record&id=' + encodeURIComponent(id);
        fetch(url).then(function (resp) {
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            return resp.json();
        }).then(function (data) {
            if (data.error) throw new Error(data.error);
            var record = data.record || {};
            var loc = record.location || {};
            var gps = record.gps || {};

            var locationStr = [loc.country, loc.province, loc.city, loc.district].filter(Boolean).join(' ');

            // GPS 坐标
            var gpsCoord = '--';
            var gpsAccuracy = '--';
            var gpsLink = '';
            if (gps.latitude && gps.longitude) {
                gpsCoord = gps.latitude.toFixed(6) + ', ' + gps.longitude.toFixed(6);
                gpsAccuracy = gps.accuracy ? '±' + Math.round(gps.accuracy) + ' 米' : '--';
                gpsLink = ' <a href="https://uri.amap.com/marker?position=' + gps.longitude + ',' + gps.latitude + '" target="_blank" style="font-size:12px;color:var(--color-primary);">在地图中查看</a>';
            }

            // 照片
            var photoHtml = '';
            if (record.photo_url) {
                photoHtml =
                    '<div style="padding:12px 16px;">' +
                        '<div style="font-size:12px;font-weight:600;color:var(--color-text-secondary);margin-bottom:8px;">签到照片</div>' +
                        '<img src="' + escapeHtml((window.SITE_URL || '') + record.photo_url) + '" ' +
                        'style="max-width:100%;max-height:300px;border-radius:8px;cursor:pointer;" ' +
                        'onclick="window.open(this.src)" alt="签到照片">' +
                    '</div>';
            }

            var html =
                photoHtml +
                '<div class="group-list">' +
                    '<div class="group-list-item"><span class="group-list-label">IPv4</span><span class="group-list-value" style="font-family:var(--font-mono);">' + escapeHtml(record.ipv4 || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">IPv6</span><span class="group-list-value" style="font-family:var(--font-mono);">' + escapeHtml(record.ipv6 || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">服务器 IP</span><span class="group-list-value" style="font-family:var(--font-mono);">' + escapeHtml(record.server_ip || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">GPS 坐标</span><span class="group-list-value" style="font-family:var(--font-mono);">' + gpsCoord + gpsLink + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">GPS 精度</span><span class="group-list-value">' + escapeHtml(gpsAccuracy) + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">IP 位置</span><span class="group-list-value">' + escapeHtml(locationStr || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">运营商</span><span class="group-list-value">' + escapeHtml(loc.isp || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">代理/VPN</span><span class="group-list-value">' + (record.is_proxy ? '是' : '否') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">操作系统</span><span class="group-list-value">' + escapeHtml(record.os || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">浏览器</span><span class="group-list-value">' + escapeHtml(record.browser || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">屏幕</span><span class="group-list-value">' + escapeHtml(record.screen || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">语言</span><span class="group-list-value">' + escapeHtml(record.language || '--') + '</span></div>' +
                    '<div class="group-list-item"><span class="group-list-label">签到时间</span><span class="group-list-value">' + escapeHtml(record.created_at || '--') + '</span></div>' +
                '</div>';

            document.getElementById('modal-body').innerHTML = html;
            document.getElementById('detail-modal').style.display = '';
        }).catch(function (err) {
            toast('获取详情失败：' + err.message, 'danger');
        });
    }

    /**
     * 初始化签到页面事件绑定
     */
    function initCheckinEvents() {
        var createBtn = document.getElementById('create-checkin-btn');
        if (createBtn) {
            createBtn.addEventListener('click', createCheckinLink);
        }

        var copyBtn = document.getElementById('copy-checkin-btn');
        if (copyBtn) {
            copyBtn.addEventListener('click', function () {
                var url = document.getElementById('checkin-created-url').textContent;
                copyText(url);
            });
        }

        var searchInput = document.getElementById('checkin-search-input');
        if (searchInput) {
            var searchTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () {
                    checkinPageNum = 1;
                    loadCheckinRecords(1);
                }, 300);
            });
        }

        var prevBtn = document.getElementById('checkin-page-prev');
        if (prevBtn) {
            prevBtn.addEventListener('click', function () {
                if (checkinPageNum > 1) loadCheckinRecords(checkinPageNum - 1);
            });
        }

        var nextBtn = document.getElementById('checkin-page-next');
        if (nextBtn) {
            nextBtn.addEventListener('click', function () {
                loadCheckinRecords(checkinPageNum + 1);
            });
        }

        // 全选 checkbox
        var selectAll = document.getElementById('checkin-select-all');
        if (selectAll) {
            selectAll.addEventListener('change', function () {
                document.querySelectorAll('.checkin-check').forEach(function (cb) {
                    cb.checked = selectAll.checked;
                });
                updateCheckinBatchDeleteBtn();
            });
        }

        // 批量删除按钮
        var batchDelBtn = document.getElementById('checkin-batch-delete-btn');
        if (batchDelBtn) {
            batchDelBtn.addEventListener('click', function () {
                var ids = getSelectedCheckinIds();
                if (!ids.length) return;
                confirmDialog('确定要删除选中的 ' + ids.length + ' 条签到记录吗？').then(function (ok) {
                    if (!ok) return;
                    api('delete_checkin_records', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ ids: ids })
                    }).then(function () {
                        toast('已删除 ' + ids.length + ' 条记录', 'success');
                        loadCheckinRecords(checkinPageNum);
                        loadCheckinStats();
                    }).catch(function (err) {
                        toast('删除失败：' + err.message, 'danger');
                    });
                });
            });
        }
    }

    /* ====== 个性化设置 ====== */

    var SETTINGS_KEY = 'ip_probe_settings';
    var defaultSettings = { navMode: 'normal', themeColor: '#007AFF' };

    function getSettings() {
        try {
            var saved = localStorage.getItem(SETTINGS_KEY);
            if (saved) {
                var parsed = JSON.parse(saved);
                // 合并默认值，防止缺失字段
                for (var k in defaultSettings) {
                    if (!(k in parsed)) parsed[k] = defaultSettings[k];
                }
                return parsed;
            }
        } catch (e) { /* ignore */ }
        return { navMode: 'normal', themeColor: '#007AFF' };
    }

    function saveSettings(settings) {
        try { localStorage.setItem(SETTINGS_KEY, JSON.stringify(settings)); } catch (e) { /* ignore */ }
    }

    /**
     * 应用主题色
     * @param {string} color - HEX 颜色值
     */
    function applyThemeColor(color) {
        var r = parseInt(color.slice(1, 3), 16) / 255;
        var g = parseInt(color.slice(3, 5), 16) / 255;
        var b = parseInt(color.slice(5, 7), 16) / 255;
        var root = document.documentElement;
        root.style.setProperty('--color-primary', color);
        // hover 略深
        root.style.setProperty('--color-primary-hover', adjustBrightness(color, -15));
        root.style.setProperty('--color-primary-active', adjustBrightness(color, -25));
        // subtle 背景
        root.style.setProperty('--color-primary-subtle', 'rgba(' +
            Math.round(r * 255) + ',' + Math.round(g * 255) + ',' + Math.round(b * 255) + ',0.06)');
    }

    /**
     * 简易亮度调整
     * @param {string} hex
     * @param {number} percent - 正值变亮，负值变暗
     * @returns {string}
     */
    function adjustBrightness(hex, percent) {
        var num = parseInt(hex.slice(1), 16);
        var r = Math.min(255, Math.max(0, (num >> 16) + percent));
        var g = Math.min(255, Math.max(0, ((num >> 8) & 0x00FF) + percent));
        var b = Math.min(255, Math.max(0, (num & 0x0000FF) + percent));
        return '#' + (0x1000000 + (r << 16) + (g << 8) + b).toString(16).slice(1);
    }

    /**
     * 应用导航模式
     * @param {string} mode - 'normal' | 'hamburger'
     */
    function applyNavMode(mode) {
        var wrapper = document.getElementById('admin-panel');
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebar-overlay');

        // 先关闭 sidebar（无论切换到哪个模式）
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        document.body.style.overflow = '';

        if (mode === 'hamburger') {
            wrapper.classList.add('force-hamburger');
        } else {
            wrapper.classList.remove('force-hamburger');
        }
    }

    /**
     * 加载设置页面
     */
    function loadSettings() {
        var settings = getSettings();

        // 导航模式 toggle
        var navToggle = document.getElementById('setting-nav-mode');
        if (navToggle) {
            navToggle.checked = (settings.navMode === 'hamburger');
        }

        // 主题颜色高亮
        updateColorBtnActive(settings.themeColor);

        // 自定义颜色 picker
        var picker = document.getElementById('custom-color-picker');
        if (picker) {
            picker.value = settings.themeColor;
        }
    }

    /**
     * 更新颜色按钮的 active 状态
     * @param {string} color
     */
    function updateColorBtnActive(color) {
        document.querySelectorAll('.theme-color-btn').forEach(function (btn) {
            var btnColor = btn.getAttribute('data-color');
            btn.classList.toggle('active', btnColor === color);
        });
    }

    /* ====== 修改密码 ====== */

    function initChangePassword() {
        var btn     = document.getElementById('change-pwd-btn');
        var area    = document.getElementById('change-pwd-area');
        var oldPwd  = document.getElementById('change-pwd-old');
        var newPwd  = document.getElementById('change-pwd-new');
        var confirm = document.getElementById('change-pwd-confirm');
        var errEl   = document.getElementById('change-pwd-error');
        var submit  = document.getElementById('change-pwd-submit');
        var cancel  = document.getElementById('change-pwd-cancel');

        btn.addEventListener('click', function () {
            oldPwd.value = '';
            newPwd.value = '';
            confirm.value = '';
            errEl.style.display = 'none';
            area.style.display = '';
            oldPwd.focus();
        });

        cancel.addEventListener('click', function () {
            area.style.display = 'none';
        });

        submit.addEventListener('click', function () {
            var oldVal = oldPwd.value;
            var newVal = newPwd.value;
            var confVal = confirm.value;

            if (!oldVal || !newVal || !confVal) {
                errEl.textContent = '请填写所有字段';
                errEl.style.display = '';
                return;
            }
            if (newVal !== confVal) {
                errEl.textContent = '两次输入的新密码不一致';
                errEl.style.display = '';
                return;
            }
            if (newVal.length < 8) {
                errEl.textContent = '新密码至少 8 个字符';
                errEl.style.display = '';
                return;
            }

            errEl.style.display = 'none';
            submit.disabled = true;

            api('change_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ old_password: oldVal, new_password: newVal, confirm_password: confVal })
            }).then(function () {
                toast('密码已修改，请重新登录', 'success');
                setTimeout(function () { location.reload(); }, 1500);
            }).catch(function (err) {
                errEl.textContent = err.message || '修改失败';
                errEl.style.display = '';
            }).finally(function () {
                submit.disabled = false;
            });
        });
    }

    /* ====== 两步验证管理 ====== */

    function init2FA() {
        var statusText  = document.getElementById('twofa-status-text');
        var setupBtn    = document.getElementById('twofa-setup-btn');
        var disableBtn  = document.getElementById('twofa-disable-btn');
        var setupArea   = document.getElementById('twofa-setup-area');
        var disableArea = document.getElementById('twofa-disable-area');

        // 加载 2FA 状态
        function loadStatus() {
            api('get_2fa_status').then(function (data) {
                if (data.enabled) {
                    statusText.textContent = '已启用';
                    statusText.style.color = '#34C759';
                    setupBtn.style.display = 'none';
                    disableBtn.style.display = '';
                } else {
                    statusText.textContent = '未启用';
                    statusText.style.color = '#8E8E93';
                    setupBtn.style.display = '';
                    disableBtn.style.display = 'none';
                }
                setupArea.style.display = 'none';
                disableArea.style.display = 'none';
            });
        }

        loadStatus();

        // 启用按钮 → 生成二维码
        setupBtn.addEventListener('click', function () {
            api('setup_2fa').then(function (data) {
                document.getElementById('twofa-qr').src = data.qr_url;
                document.getElementById('twofa-secret-display').textContent = data.secret;
                document.getElementById('twofa-verify-code').value = '';
                document.getElementById('twofa-verify-error').style.display = 'none';
                setupArea.style.display = '';
                disableArea.style.display = 'none';
                document.getElementById('twofa-verify-code').focus();
            }).catch(function (err) {
                toast('生成密钥失败：' + err.message, 'danger');
            });
        });

        // 确认验证码
        document.getElementById('twofa-verify-btn').addEventListener('click', function () {
            var code = document.getElementById('twofa-verify-code').value.trim();
            var errEl = document.getElementById('twofa-verify-error');
            if (!code || code.length !== 6) {
                errEl.textContent = '请输入 6 位验证码';
                errEl.style.display = '';
                return;
            }

            api('verify_2fa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code: code })
            }).then(function () {
                toast('两步验证已启用', 'success');
                loadStatus();
            }).catch(function (err) {
                errEl.textContent = err.message || '验证码错误';
                errEl.style.display = '';
                document.getElementById('twofa-verify-code').value = '';
                document.getElementById('twofa-verify-code').focus();
            });
        });

        // 取消设置
        document.getElementById('twofa-cancel-btn').addEventListener('click', function () {
            setupArea.style.display = 'none';
        });

        // 关闭按钮 → 显示密码确认
        disableBtn.addEventListener('click', function () {
            document.getElementById('twofa-disable-password').value = '';
            document.getElementById('twofa-disable-error').style.display = 'none';
            disableArea.style.display = '';
            setupArea.style.display = 'none';
            document.getElementById('twofa-disable-password').focus();
        });

        // 确认关闭
        document.getElementById('twofa-confirm-disable-btn').addEventListener('click', function () {
            var pwd = document.getElementById('twofa-disable-password').value;
            var errEl = document.getElementById('twofa-disable-error');
            if (!pwd) {
                errEl.textContent = '请输入密码';
                errEl.style.display = '';
                return;
            }

            api('disable_2fa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password: pwd })
            }).then(function () {
                toast('两步验证已关闭', 'success');
                loadStatus();
            }).catch(function (err) {
                errEl.textContent = err.message || '关闭失败';
                errEl.style.display = '';
            });
        });

        // 取消关闭
        document.getElementById('twofa-cancel-disable-btn').addEventListener('click', function () {
            disableArea.style.display = 'none';
        });
    }

    /**
     * 初始化设置页面事件
     */
    function initSettings() {
        // 修改密码
        initChangePassword();

        // 两步验证
        init2FA();

        // 导航模式切换
        var navToggle = document.getElementById('setting-nav-mode');
        if (navToggle) {
            navToggle.addEventListener('change', function () {
                var mode = navToggle.checked ? 'hamburger' : 'normal';
                var settings = getSettings();
                settings.navMode = mode;
                saveSettings(settings);
                applyNavMode(mode);
                toast(mode === 'hamburger' ? '已切换为汉堡菜单' : '已切换为正常导航栏', 'success');
            });
        }

        // 预设颜色按钮
        document.querySelectorAll('.theme-color-btn[data-color]').forEach(function (btn) {
            var color = btn.getAttribute('data-color');
            if (color === 'custom') return; // 自定义按钮单独处理

            btn.addEventListener('click', function () {
                var settings = getSettings();
                settings.themeColor = color;
                saveSettings(settings);
                applyThemeColor(color);
                updateColorBtnActive(color);
            });
        });

        // 自定义颜色选择器
        var customBtn = document.querySelector('.theme-color-btn[data-color="custom"]');
        var customPicker = document.getElementById('custom-color-picker');
        if (customBtn && customPicker) {
            // 点击按钮触发 picker
            customBtn.addEventListener('click', function (e) {
                if (e.target !== customPicker) {
                    customPicker.click();
                }
            });

            customPicker.addEventListener('input', function () {
                var color = customPicker.value;
                var settings = getSettings();
                settings.themeColor = color;
                saveSettings(settings);
                applyThemeColor(color);
                updateColorBtnActive(color);

                // 更新自定义按钮的 dot 颜色
                var dot = customBtn.querySelector('.theme-color-dot');
                if (dot) dot.style.background = color;
            });

            customPicker.addEventListener('change', function () {
                toast('已应用自定义颜色', 'success');
            });
        }

        // 重置按钮
        var resetBtn = document.getElementById('reset-settings-btn');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                confirmDialog('确定要恢复所有默认设置吗？').then(function (ok) {
                    if (!ok) return;
                    localStorage.removeItem(SETTINGS_KEY);
                    applyThemeColor(defaultSettings.themeColor);
                    applyNavMode(defaultSettings.navMode);
                    loadSettings();

                    // 重置自定义按钮 dot
                    var dot = document.querySelector('.theme-color-btn[data-color="custom"] .theme-color-dot');
                    if (dot) dot.style.background = 'conic-gradient(#f00,#ff0,#0f0,#0ff,#00f,#f0f,#f00)';

                    toast('已恢复默认设置', 'success');
                });
            });
        }
    }

    /**
     * 启动时应用已保存的设置
     */
    function applySavedSettings() {
        var settings = getSettings();
        applyThemeColor(settings.themeColor);
        applyNavMode(settings.navMode);
    }

    /* ====== 汉堡菜单（移动端侧滑栏） ====== */

    function initHamburger() {
        var btn     = document.getElementById('hamburger-btn');
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebar-overlay');

        function openSidebar() {
            sidebar.classList.add('open');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        btn.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) {
                closeSidebar();
            } else {
                openSidebar();
            }
        });

        overlay.addEventListener('click', closeSidebar);

        // 导航点击后自动关闭侧滑栏
        document.querySelectorAll('.sidebar-nav .nav-item, .sidebar-footer .nav-item').forEach(function (item) {
            item.addEventListener('click', closeSidebar);
        });
    }

    /* ====== 初始化 ====== */
    function init() {
        applySavedSettings();
        initLogin();
        initRouter();
        initHamburger();
        initSettings();
        bindEvents();

        // 已登录时根据 hash 加载页面
        api('check_auth').then(function (data) {
            if (data.logged_in) {
                var hash = location.hash.replace('#', '') || 'dashboard';
                navigateTo(hash);
            } else if (data.need_2fa) {
                // 密码已验证，等待 2FA
                document.getElementById('login-step-password').style.display = 'none';
                document.getElementById('login-step-2fa').style.display = '';
                document.getElementById('login-2fa-code').focus();
            }
        }).catch(function () {
            // 未登录，不加载数据
        });
    }

    // DOM Ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
