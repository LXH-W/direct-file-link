/**
 * 文件管理页前端逻辑
 *
 * 职责：
 *   - 从 backend/files.php 拉取分页文件列表并渲染表格
 *   - 处理 分页、删除、复制分享链接 等交互
 *   - 所有数据交互均为 JSON，不再依赖服务端渲染页面
 */
(function () {
    'use strict';

    // 后端接口地址（相对于当前页面 frontend/manage.html）
    var API_URL = '../backend/files.php';

    // DOM 节点缓存
    var els = {
        statTotal: document.getElementById('statTotal'),
        fileListBody: document.getElementById('fileListBody'),
        pagination: document.getElementById('pagination'),
        toast: document.getElementById('toast')
    };

    // 当前页码，默认第 1 页
    var currentPage = 1;

    // MD5 计算中轮询定时器（有文件 md5 为 null 时开启，全部就绪后停止）
    var md5PollTimer = null;
    var md5PollAttempts = 0;  // 当前已轮询次数（用于上限判断）

    document.addEventListener('DOMContentLoaded', function () {
        // 首次加载第 1 页
        loadPage(1);

        // 事件委托：分页栏与列表内按钮统一在 body 处理
        document.body.addEventListener('click', function (e) {
            var target = e.target;

            // 分页按钮（带 data-page 属性）
            var pageBtn = target.closest('[data-page]');
            if (pageBtn) {
                e.preventDefault();
                loadPage(parseInt(pageBtn.getAttribute('data-page'), 10) || 1);
                return;
            }

            // 删除按钮
            if (target.classList.contains('delete')) {
                e.preventDefault();
                handleDelete(target);
                return;
            }

            // 分享（复制链接）按钮
            if (target.classList.contains('share')) {
                e.preventDefault();
                handleShare(target);
                return;
            }

            // MD5 点击复制
            if (target.classList.contains('file-md5') && !target.classList.contains('calculating')) {
                e.preventDefault();
                handleCopyMd5(target);
                return;
            }
        });
    });

    /**
     * 拉取指定页数据并触发渲染。
     */
    function loadPage(page) {
        currentPage = page;

        // 清除上一轮的 MD5 轮询，避免翻页后旧定时器继续跑
        stopMd5Polling();

        // 先展示加载态，避免界面空白造成"卡死"错觉
        els.fileListBody.innerHTML = '<div class="loading">加载中...</div>';
        els.pagination.innerHTML = '';

        fetch(API_URL + '?page=' + page)
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    renderList(data);
                } else {
                    els.fileListBody.innerHTML = emptyHtml('加载失败：' + (data.message || '未知错误'));
                }
            })
            .catch(function () {
                els.fileListBody.innerHTML = emptyHtml('网络错误，加载失败');
            });
    }

    /**
     * 渲染整张表：统计、文件行、分页栏。
     */
    function renderList(data) {
        // 文件总数
        els.statTotal.textContent = data.total;

        // 文件行（空数据时显示空状态）
        if (!data.files || data.files.length === 0) {
            els.fileListBody.innerHTML = emptyHtml('暂无上传的文件', '点击右上角"返回上传"上传文件');
        } else {
            els.fileListBody.innerHTML = data.files.map(renderRow).join('');

            // 有未计算 MD5 的文件 → 触发后端计算 + 启动轮询
            kickOffPendingMd5Calculation(data.files);
        }

        // 分页栏（仅多于 1 页时渲染）
        if (data.totalPages > 1) {
            els.pagination.innerHTML = renderPagination(data);
        } else {
            els.pagination.innerHTML = '';
        }
    }

    /**
     * 渲染单行文件记录。
     * download_url 为相对路径，下载按钮直接用作 href。
     */
    function renderRow(file) {
        var md5Cell;
        if (file.md5) {
            md5Cell = '<div class="file-md5" data-md5="' + escapeHtml(file.md5) + '">' + escapeHtml(file.md5) + '</div>';
        } else {
            md5Cell = '<div class="file-md5 calculating" data-id="' + escapeHtml(file.file_id) + '">计算中...</div>';
        }

        return '' +
            '<div class="file-item">' +
                '<div class="file-id">' + escapeHtml(file.file_id) + '</div>' +
                '<div class="file-name" title="' + escapeHtml(file.original_name) + '">' + escapeHtml(file.original_name) + '</div>' +
                '<div class="file-size">' + escapeHtml(file.size_text) + '</div>' +
                md5Cell +
                '<div class="file-count">' + file.download_count + '</div>' +
                '<div class="file-time">' + escapeHtml(file.upload_time) + '</div>' +
                '<div class="file-actions">' +
                    '<a class="action-btn download" href="' + escapeHtml(file.download_url) + '">下载</a>' +
                    '<button class="action-btn share" data-url="' + escapeHtml(file.download_url) + '">分享</button>' +
                    '<button class="action-btn delete" data-id="' + escapeHtml(file.file_id) + '">删除</button>' +
                '</div>' +
            '</div>';
    }

    /**
     * 渲染分页栏：首页 / 上一页 / 页码 / 下一页 / 末页。
     * 边界页用 disabled 禁用点击。
     */
    function renderPagination(data) {
        var cur = data.currentPage;
        var total = data.totalPages;
        var html = '';

        html += pageLink(1, '首页', cur === 1);
        html += pageLink(cur - 1, '上一页', cur === 1);

        // 当前页附近最多显示 5 个页码
        var start = Math.max(1, cur - 2);
        var end = Math.min(total, cur + 2);
        for (var i = start; i <= end; i++) {
            html += pageLink(i, String(i), false, i === cur);
        }

        html += pageLink(cur + 1, '下一页', cur === total);
        html += pageLink(total, '末页', cur === total);
        return html;
    }

    /**
     * 生成分页按钮 <a>。
     * disabled=true 表示不可点击（不附带 data-page），active=true 表示当前页。
     */
    function pageLink(page, text, disabled, active) {
        var cls = '';
        if (disabled) cls += ' disabled';
        if (active) cls += ' active';
        // disabled 时不附带 data-page，避免被事件委托误触发
        var dataAttr = disabled ? '' : (' data-page="' + page + '"');
        return '<a class="' + cls.trim() + '"' + dataAttr + '>' + text + '</a>';
    }

    /**
     * 处理删除：二次确认后 POST 到后端，成功后刷新当前页。
     */
    function handleDelete(btn) {
        var fileId = btn.getAttribute('data-id');
        if (!fileId) return;
        if (!confirm('确定要删除此文件吗？')) return;

        // 用表单字段提交，与后端 handleDelete 读取 $_POST['id'] 对应
        var formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', fileId);

        fetch(API_URL, { method: 'POST', body: formData })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status === 'success') {
                    // 删除后刷新当前页
                    loadPage(currentPage);
                } else {
                    alert(data.message || '删除失败');
                }
            })
            .catch(function () {
                alert('网络错误，删除失败');
            });
    }

    /**
     * 处理分享：复制完整下载链接到剪贴板。
     * 优先用 Clipboard API，失败时降级用 execCommand。
     */
    function handleShare(btn) {
        var url = btn.getAttribute('data-url');
        if (!url) return;
        // download_url 为相对路径，拼上 origin 得到完整链接
        var fullUrl = window.location.origin + url;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(fullUrl).then(function () {
                onCopied(btn);
            }).catch(function () {
                fallbackCopy(fullUrl, btn);
            });
        } else {
            fallbackCopy(fullUrl, btn);
        }
    }

    /**
     * 复制成功后的按钮反馈与 toast 提示。
     */
    function onCopied(btn) {
        showToast();
        btn.textContent = '已复制';
        btn.classList.add('copied');
        setTimeout(function () {
            btn.textContent = '分享';
            btn.classList.remove('copied');
        }, 2000);
    }

    /**
     * 降级复制方案：临时插入 textarea 并 execCommand('copy')。
     */
    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-99999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        if (ok) {
            onCopied(btn);
        } else {
            alert('复制失败，请手动复制链接: ' + text);
        }
    }

    /**
     * 显示右下角 toast 提示（2 秒后自动隐藏）。
     * 可选传入自定义消息，默认显示 "链接已复制到剪贴板"。
     */
    function showToast(message) {
        if (message) {
            els.toast.textContent = message;
        } else {
            els.toast.textContent = '链接已复制到剪贴板';
        }
        els.toast.classList.add('show');
        setTimeout(function () {
            els.toast.classList.remove('show');
        }, 2000);
    }

    /**
     * 生成空状态 HTML。
     */
    function emptyHtml(msg, sub) {
        var html = '<div class="empty-state">' +
            '<div class="icon">📭</div>' +
            '<p>' + escapeHtml(msg) + '</p>';
        if (sub) html += '<p style="margin-top: 8px; font-size: 12px;">' + escapeHtml(sub) + '</p>';
        html += '</div>';
        return html;
    }

    /**
     * 简单 HTML 转义，防止文件名中含特殊字符破坏结构。
     */
    function escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * MD5 轮询最大重试次数（20 次 × 3 秒 = 60 秒上限）。
     * 防止服务端计算失败时无限轮询。
     */
    var MD5_POLL_MAX_ATTEMPTS = 20;

    /**
     * 检查当前页是否有 md5 为 null 的文件，触发后端计算并启动轮询。
     */
    function kickOffPendingMd5Calculation(files) {
        var pending = [];
        for (var i = 0; i < files.length; i++) {
            if (!files[i].md5) {
                pending.push(files[i].file_id);
            }
        }

        if (pending.length === 0) {
            // 全部已有 MD5，确保轮询已停止
            stopMd5Polling();
            return;
        }

        // 对每个待计算的文件触发后端（异步，不阻塞 UI）
        pending.forEach(function (fileId) {
            fetch(API_URL + '?action=calcMd5&id=' + fileId).catch(function () {});
        });

        // 重置重试计数，启动轻量级轮询
        md5PollAttempts = 0;
        startMd5Polling();
    }

    /**
     * 启动轻量级 MD5 轮询（3 秒一次，只查 md5 字段，不重绘列表）。
     * 如果已有定时器在跑则跳过。
     */
    function startMd5Polling() {
        if (md5PollTimer !== null) return;
        md5PollTimer = setInterval(pollMd5Status, 3000);
    }

    /**
     * 轻量级轮询：收集 DOM 中所有"计算中..."的 fileId，批量查 md5，
     * 只更新对应的 DOM 单元格，避免重绘整个列表造成闪烁。
     */
    function pollMd5Status() {
        // 1. 从 DOM 里收集当前所有"计算中..."的文件 ID
        var pendingIds = [];
        var calculatingEls = els.fileListBody.querySelectorAll('.file-md5.calculating');
        for (var i = 0; i < calculatingEls.length; i++) {
            var id = calculatingEls[i].getAttribute('data-id');
            if (id) pendingIds.push(id);
        }

        // 没有待计算的了 → 停止轮询
        if (pendingIds.length === 0) {
            stopMd5Polling();
            return;
        }

        // 2. 超过最大重试次数 → 放弃轮询
        md5PollAttempts++;
        if (md5PollAttempts >= MD5_POLL_MAX_ATTEMPTS) {
            stopMd5Polling();
            return;
        }

        // 3. 批量查询 MD5 状态
        fetch(API_URL + '?action=getMd5Status&ids=' + pendingIds.join(','))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || data.status !== 'success' || !data.md5_map) return;

                var stillPending = false;
                for (var fileId in data.md5_map) {
                    if (!data.md5_map.hasOwnProperty(fileId)) continue;
                    var md5 = data.md5_map[fileId];
                    if (md5) {
                        // 找到了 → 更新 DOM 单元格
                        updateMd5Cell(fileId, md5);
                    } else {
                        stillPending = true;
                    }
                }

                // 全部算完 → 停止轮询
                if (!stillPending) {
                    stopMd5Polling();
                }
            })
            .catch(function () { /* 网络错误静默忽略，下次轮询再试 */ });
    }

    /**
     * 查找并更新指定 fileId 对应的 MD5 DOM 单元格（计算中 → 具体哈希）。
     */
    function updateMd5Cell(fileId, md5) {
        var cell = els.fileListBody.querySelector('.file-md5.calculating[data-id="' + fileId + '"]');
        if (!cell) return;
        cell.classList.remove('calculating');
        cell.removeAttribute('data-id');
        cell.setAttribute('data-md5', md5);
        cell.textContent = md5;
    }

    /**
     * 停止 MD5 轮询定时器并重置重试计数。
     */
    function stopMd5Polling() {
        if (md5PollTimer !== null) {
            clearInterval(md5PollTimer);
            md5PollTimer = null;
        }
        md5PollAttempts = 0;
    }

    /**
     * 点击 MD5 文本 → 复制到剪贴板。
     */
    function handleCopyMd5(el) {
        var md5 = el.getAttribute('data-md5');
        if (!md5) return;

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(md5).then(function () {
                showToast('MD5 已复制');
            }).catch(function () {
                fallbackCopyMd5(md5, el);
            });
        } else {
            fallbackCopyMd5(md5, el);
        }
    }

    /**
     * MD5 复制降级方案。
     */
    function fallbackCopyMd5(text, el) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.left = '-99999px';
        document.body.appendChild(ta);
        ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
        document.body.removeChild(ta);
        if (ok) {
            showToast('MD5 已复制');
        }
    }
})();
