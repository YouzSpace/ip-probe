/**
 * IP 探针系统 — 签到采集脚本 v2
 *
 * 三步签到流程：
 * 1. GPS 定位（真实坐标）
 * 2. 拍照（相机拍摄）
 * 3. 上传（照片 + 设备/网络信息）
 *
 * 兼容 ES5 语法
 */
(function () {
    'use strict';

    var CHECKIN_ID = window.CHECKIN_ID || '';
    var gpsData = null;
    var ipData = null;
    var photoBlob = null;
    var cameraStream = null;

    /* ====== 设备信息 — 页面加载时立即同步采集 ====== */

    var uaRaw = navigator.userAgent || '';

    function parseUA(ua) {
        var os = '未知', browser = '未知';
        if (/Windows Phone/.test(ua)) os = 'Windows Phone';
        else if (/Windows NT 10\.0/.test(ua)) os = 'Windows 10';
        else if (/Windows NT 6\.3/.test(ua)) os = 'Windows 8.1';
        else if (/Windows NT 6\.1/.test(ua)) os = 'Windows 7';
        else if (/Windows NT/.test(ua)) os = 'Windows';
        else if (/Android[\s/](\d+[\.\d]*)/.test(ua)) os = 'Android ' + RegExp.$1;
        else if (/iPhone OS (\d+[_\d]*)/.test(ua)) os = 'iOS ' + RegExp.$1.replace(/_/g, '.');
        else if (/iPad.*OS (\d+[_\d]*)/.test(ua)) os = 'iPadOS ' + RegExp.$1.replace(/_/g, '.');
        else if (/Mac OS X (\d+[._]\d+[._\d]*)/.test(ua)) os = 'macOS ' + RegExp.$1.replace(/_/g, '.');
        else if (/CrOS/.test(ua)) os = 'Chrome OS';
        else if (/HarmonyOS/.test(ua)) os = 'HarmonyOS';
        else if (/Linux/.test(ua)) os = 'Linux';

        if (/Edg(?:e|iOS|A)?\/(\d+)/.test(ua)) browser = 'Edge ' + RegExp.$1;
        else if (/OPR\/(\d+)/.test(ua)) browser = 'Opera ' + RegExp.$1;
        else if (/UCBrowser\/(\d+)/.test(ua)) browser = 'UC ' + RegExp.$1;
        else if (/QQBrowser\/(\d+)/.test(ua)) browser = 'QQ浏览器 ' + RegExp.$1;
        else if (/MiuiBrowser\/(\d+)/.test(ua)) browser = '小米浏览器 ' + RegExp.$1;
        else if (/HuaweiBrowser\/(\d+)/.test(ua)) browser = '华为浏览器 ' + RegExp.$1;
        else if (/SamsungBrowser\/(\d+)/.test(ua)) browser = '三星浏览器 ' + RegExp.$1;
        else if (/CriOS\/(\d+)/.test(ua)) browser = 'Chrome(iOS) ' + RegExp.$1;
        else if (/FxiOS\/(\d+)/.test(ua)) browser = 'Firefox(iOS) ' + RegExp.$1;
        else if (/Chrome\/(\d+)/.test(ua)) browser = 'Chrome ' + RegExp.$1;
        else if (/Firefox\/(\d+)/.test(ua)) browser = 'Firefox ' + RegExp.$1;
        else if (/Version\/(\d+).*Safari/.test(ua)) browser = 'Safari ' + RegExp.$1;
        return { os: os, browser: browser };
    }

    var uaInfo = parseUA(uaRaw);
    var deviceData = {
        user_agent_raw: uaRaw,
        os: uaInfo.os,
        browser: uaInfo.browser,
        language: navigator.language || '',
        screen: screen.width + 'x' + screen.height,
        dpr: window.devicePixelRatio || 1
    };

    // 立即启动 IP 归属地查询（异步，不阻塞页面）
    fetch((window.SITE_URL || '') + '/api.php?action=get_ip')
        .then(function (r) { return r.json(); })
        .then(function (data) { ipData = data; })
        .catch(function () { ipData = {}; });

    /* ====== DOM ====== */
    var $ = function (id) { return document.getElementById(id); };

    function showSection(name) {
        var sections = ['location', 'camera', 'uploading', 'success', 'error'];
        sections.forEach(function (s) {
            var el = $('section-' + s);
            if (el) el.classList.toggle('active', s === name);
        });
        var stepMap = { location: 1, camera: 2, uploading: 3, success: 3 };
        var step = stepMap[name] || 0;
        for (var i = 1; i <= 3; i++) {
            var dot = $('dot-step' + i);
            if (!dot) continue;
            dot.className = 'step-dot';
            if (i < step) dot.classList.add('done');
            else if (i === step) dot.classList.add('active');
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /* ====== Step 1: GPS 定位 ====== */

    function initLocationStep() {
        var btn = $('btn-get-location');
        var skipBtn = $('btn-skip-location');
        var statusEl = $('gps-status');
        var errorEl = $('gps-error');

        btn.addEventListener('click', function () {
            if (!navigator.geolocation) {
                errorEl.textContent = '您的浏览器不支持 GPS 定位';
                errorEl.style.display = '';
                skipBtn.style.display = '';
                return;
            }

            btn.disabled = true;
            btn.textContent = '正在定位...';
            statusEl.innerHTML = '正在请求 GPS 位置...<br>请在弹出的权限窗口中点击「允许」';
            errorEl.style.display = 'none';

            navigator.geolocation.getCurrentPosition(
                function (pos) {
                    gpsData = {
                        latitude: pos.coords.latitude,
                        longitude: pos.coords.longitude,
                        accuracy: pos.coords.accuracy,
                        altitude: pos.coords.altitude,
                        heading: pos.coords.heading,
                        speed: pos.coords.speed
                    };

                    statusEl.innerHTML =
                        '<strong>定位成功</strong><br>' +
                        '纬度: ' + gpsData.latitude.toFixed(6) + '<br>' +
                        '经度: ' + gpsData.longitude.toFixed(6) + '<br>' +
                        '精度: ±' + Math.round(gpsData.accuracy) + ' 米';
                    btn.textContent = '定位成功';
                    btn.style.display = 'none';

                    setTimeout(function () {
                        startCameraStep();
                    }, 800);
                },
                function (err) {
                    var msg = '定位失败';
                    if (err.code === 1) msg = '定位权限被拒绝，请在浏览器设置中允许定位';
                    else if (err.code === 2) msg = '无法获取位置信息';
                    else if (err.code === 3) msg = '定位超时，请重试';

                    errorEl.textContent = msg;
                    errorEl.style.display = '';
                    btn.disabled = false;
                    btn.textContent = '重试定位';
                    skipBtn.style.display = '';
                },
                {
                    enableHighAccuracy: true,
                    timeout: 15000,
                    maximumAge: 0
                }
            );
        });

        skipBtn.addEventListener('click', function () {
            gpsData = null;
            startCameraStep();
        });
    }

    /* ====== Step 2: 拍照 ====== */

    function startCameraStep() {
        showSection('camera');

        var video = $('camera-video');
        var canvas = $('camera-canvas');
        var wrapper = $('camera-wrapper');
        var shutterBtn = $('btn-shutter');
        var retakeBtn = $('btn-retake');
        var confirmBtn = $('btn-confirm-photo');
        var shutterArea = $('shutter-area');
        var retakeArea = $('retake-area');
        var errorEl = $('camera-error');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            errorEl.textContent = '您的浏览器不支持相机功能，请使用现代浏览器';
            errorEl.style.display = '';
            shutterBtn.disabled = true;
            return;
        }

        // 优先前置摄像头
        var constraints = {
            video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 960 } },
            audio: false
        };

        navigator.mediaDevices.getUserMedia(constraints)
            .then(function (stream) {
                cameraStream = stream;
                video.srcObject = stream;
                video.play();
            })
            .catch(function () {
                // 前置失败，尝试后置
                constraints.video.facingMode = 'environment';
                return navigator.mediaDevices.getUserMedia(constraints).then(function (stream) {
                    cameraStream = stream;
                    video.srcObject = stream;
                    video.play();
                });
            })
            .catch(function () {
                errorEl.textContent = '无法打开相机，请检查权限设置';
                errorEl.style.display = '';
                shutterBtn.disabled = true;
            });

        // 拍照
        shutterBtn.addEventListener('click', function () {
            if (!cameraStream) return;

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            var ctx = canvas.getContext('2d');
            ctx.translate(canvas.width, 0);
            ctx.scale(-1, 1);
            ctx.drawImage(video, 0, 0);
            ctx.setTransform(1, 0, 0, 1, 0, 0);

            wrapper.classList.add('photo-taken');
            shutterArea.style.display = 'none';
            retakeArea.style.display = '';

            canvas.toBlob(function (blob) {
                photoBlob = blob;
            }, 'image/jpeg', 0.85);
        });

        // 重拍
        retakeBtn.addEventListener('click', function () {
            wrapper.classList.remove('photo-taken');
            shutterArea.style.display = '';
            retakeArea.style.display = 'none';
            photoBlob = null;
        });

        // 确认签到
        confirmBtn.addEventListener('click', function () {
            if (!photoBlob) {
                errorEl.textContent = '请先拍照';
                errorEl.style.display = '';
                return;
            }
            doCheckin();
        });
    }

    /* ====== Step 3: 上传签到 ====== */

    function doCheckin() {
        stopCamera();
        showSection('uploading');

        var formData = new FormData();
        formData.append('photo', photoBlob, 'checkin_' + Date.now() + '.jpg');

        var checkinData = {
            checkin_id: CHECKIN_ID,
            ipv4: (ipData && ipData.ipv4) || '',
            ipv6: (ipData && ipData.ipv6) || '',
            location: (ipData && ipData.location) || {},
            is_proxy: (ipData && ipData.is_proxy) || false,
            proxy_detected_by: (ipData && ipData.proxy_detected_by) || null,
            user_agent_raw: deviceData.user_agent_raw || uaRaw,
            os: deviceData.os || '未知',
            browser: deviceData.browser || '未知',
            language: deviceData.language || '',
            screen: deviceData.screen || '',
            dpr: deviceData.dpr || 1,
            gps: gpsData ? {
                latitude: gpsData.latitude,
                longitude: gpsData.longitude,
                accuracy: gpsData.accuracy,
                altitude: gpsData.altitude,
                heading: gpsData.heading,
                speed: gpsData.speed
            } : null
        };

        formData.append('data', JSON.stringify(checkinData));

        fetch((window.SITE_URL || '') + '/api.php?action=checkin_upload', {
            method: 'POST',
            body: formData
        })
        .then(function (resp) {
            if (!resp.ok) {
                // 服务器返回非 200，尝试解析 JSON，失败则显示通用错误
                return resp.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('服务器错误 (HTTP ' + resp.status + ')，照片可能过大');
                    }
                });
            }
            return resp.json();
        })
        .then(function (result) {
            if (result.success) {
                showSuccess(result);
            } else {
                showError(result.error || '签到失败');
            }
        })
        .catch(function (err) {
            // 显示详细错误帮助调试
            showError('上传失败：' + (err.message || '请稍后重试'));
        });
    }

    function stopCamera() {
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (t) { t.stop(); });
            cameraStream = null;
        }
    }

    /* ====== Step 4: 显示结果 ====== */

    function showSuccess(data) {
        showSection('success');

        var record = data.record || {};

        $('success-time').textContent = record.created_at || '';

        var mainIp = record.ipv4 || record.ipv6 || record.server_ip || '--';
        $('success-ip').textContent = mainIp;

        // 照片
        if (record.photo_url) {
            $('success-photo').src = (window.SITE_URL || '') + record.photo_url;
            $('success-photo-wrap').style.display = '';
        } else {
            $('success-photo-wrap').style.display = 'none';
        }

        // GPS
        var gps = record.gps;
        if (gps && gps.latitude) {
            $('info-gps-coord').textContent = gps.latitude.toFixed(6) + ', ' + gps.longitude.toFixed(6);
            $('info-gps-accuracy').textContent = '±' + Math.round(gps.accuracy) + ' 米';
        } else {
            $('info-gps-coord').textContent = '未获取';
            $('info-gps-accuracy').textContent = '--';
        }

        // 网络
        $('info-ipv4').textContent = record.ipv4 || '--';
        $('info-ipv6').textContent = record.ipv6 || '--';
        var loc = record.location || {};
        var locParts = [loc.country, loc.province, loc.city, loc.district].filter(Boolean);
        $('info-location').textContent = locParts.join(' ') || '--';

        // 设备
        $('info-os').textContent = record.os || '--';
        $('info-browser').textContent = record.browser || '--';
    }

    function showError(msg) {
        showSection('error');
        $('error-msg').textContent = msg;
    }

    /* ====== 初始化 ====== */

    function init() {
        showSection('location');
        initLocationStep();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
