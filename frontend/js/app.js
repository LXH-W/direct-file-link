/**
 * 文件上传前端逻辑
 *
 * 实现分片上传：
 *   - 将文件切成 5MB 分片逐个上传至 backend/upload.php
 *   - 全部分片上传完成后调用 action=finish 合并
 *   - 支持取消上传（action=cancel 清理已传分片）
 *   - 展示上传进度、结果与复制直链
 *
 * 暴露 FileUploader.cancelUpload 供 HTML 内联 onclick 调用。
 */
const FileUploader = (function() {
    // 单个分片大小：5MB
    const CHUNK_SIZE = 5 * 1024 * 1024;
    
    // 当前上传状态
    let currentFile = null;       // 待上传的 File 对象
    let currentChunk = 0;          // 当前分片序号
    let totalChunks = 0;           // 总分片数
    let fileId = '';               // 本次上传的文件ID
    let currentXhr = null;         // 当前进行中的 XMLHttpRequest
    let isCancelled = false;       // 是否已取消

    // DOM 节点缓存
    const elements = {
        fileInput: document.getElementById('fileInput'),
        uploadArea: document.getElementById('uploadArea'),
        progressContainer: document.getElementById('progressContainer'),
        progressFill: document.getElementById('progressFill'),
        progressText: document.getElementById('progressText'),
        result: document.getElementById('result'),
        uploadedInfo: document.getElementById('uploadedInfo'),
        fileNameSpan: document.getElementById('fileName'),
        fileSizeSpan: document.getElementById('fileSize')
    };

    /** 初始化：绑定事件。 */
    function init() {
        bindEvents();
    }

    /** 绑定上传区域与文件输入相关事件。 */
    function bindEvents() {
        elements.uploadArea.addEventListener('click', handleUploadAreaClick);
        elements.uploadArea.addEventListener('dragover', handleDragOver);
        elements.uploadArea.addEventListener('dragleave', handleDragLeave);
        elements.uploadArea.addEventListener('drop', handleDrop);
        elements.fileInput.addEventListener('change', handleFileInputChange);
        // 委托：结果区内“复制链接”按钮点击
        document.addEventListener('click', handleGlobalClick);
    }

    /** 点击上传区域 → 触发文件选择。 */
    function handleUploadAreaClick() {
        elements.fileInput.click();
    }

    /** 拖拽悬停：阻止默认行为并高亮。 */
    function handleDragOver(e) {
        e.preventDefault();
        elements.uploadArea.classList.add('dragover');
    }

    /** 拖拽离开：取消高亮。 */
    function handleDragLeave() {
        elements.uploadArea.classList.remove('dragover');
    }

    /** 放下文件：取第一个文件开始上传。 */
    function handleDrop(e) {
        e.preventDefault();
        elements.uploadArea.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files && files.length > 0) {
            handleFile(files[0]);
        }
    }

    /** 文件选择框变化：取第一个文件开始上传。 */
    function handleFileInputChange(e) {
        const files = e.target.files;
        if (files && files.length > 0) {
            handleFile(files[0]);
        }
    }

    /** 字节数 → 人类可读体积文本。 */
    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(2) + ' KB';
        if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
        return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
    }

    /** 生成 12 位随机 fileId（前端生成，后端按需校验）。 */
    function generateFileId() {
        const characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let result = '';
        for (let i = 0; i < 12; i++) {
            result += characters.charAt(Math.floor(Math.random() * characters.length));
        }
        return result;
    }

    /** 处理选中的文件：前置校验后进入分片上传流程。 */
    function handleFile(file) {
        // 文件名校验
        if (file.name.length > 120) {
            showResult('error', '文件名超过限制（最多120个字符）');
            return;
        }

        // 体积上限校验（与后端 MAX_FILE_SIZE 保持一致）
        if (file.size > 10 * 1024 * 1024 * 1024) {
            showResult('error', '文件大小超过限制（最大10GB）');
            return;
        }

        resetUploadState();
        
        currentFile = file;
        elements.fileNameSpan.textContent = file.name;
        // title 属性：鼠标悬停时浏览器原生显示完整文件名
        elements.fileNameSpan.title = file.name;
        elements.fileSizeSpan.textContent = formatFileSize(file.size);
        elements.uploadedInfo.style.display = 'block';

        // 准备进度条
        elements.progressContainer.style.display = 'block';
        elements.progressFill.style.width = '0%';
        elements.result.style.display = 'none';

        currentChunk = 0;
        totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        fileId = generateFileId();
        isCancelled = false;

        uploadChunk();
    }

    /** 重置上传状态，并中止进行中的请求。 */
    function resetUploadState() {
        if (currentXhr) {
            currentXhr.abort();
            currentXhr = null;
        }
        isCancelled = false;
        currentFile = null;
        currentChunk = 0;
        totalChunks = 0;
        fileId = '';
    }

    /** 上传当前分片。 */
    function uploadChunk() {
        // 终止条件：已取消或全部分片完成
        if (!currentFile || currentChunk >= totalChunks || isCancelled) {
            return;
        }

        // 计算当前分片的字节范围
        const start = currentChunk * CHUNK_SIZE;
        const end = Math.min(start + CHUNK_SIZE, currentFile.size);
        const chunk = currentFile.slice(start, end);

        // 组装表单数据
        const formData = new FormData();
        formData.append('file', chunk);
        formData.append('chunk', currentChunk);
        formData.append('chunks', totalChunks);
        formData.append('fileId', fileId);
        formData.append('fileName', currentFile.name);
        formData.append('fileSize', currentFile.size);

        currentXhr = new XMLHttpRequest();
        currentXhr.open('POST', '../backend/upload.php', true);

        currentXhr.onload = function() {
            if (isCancelled) return;
            
            if (currentXhr.status === 200) {
                try {
                    const response = JSON.parse(currentXhr.responseText);
                    if (response.status === 'success') {
                        currentChunk++;
                        // 更新进度条
                        const percent = (currentChunk / totalChunks) * 100;
                        elements.progressFill.style.width = percent + '%';
                        elements.progressText.innerHTML = '上传中: ' + percent.toFixed(1) + '%' +
                            ' <button class="cancel-btn" onclick="FileUploader.cancelUpload()">取消上传</button>';

                        if (currentChunk >= totalChunks) {
                            // 全部分片完成 → 请求合并
                            finishUpload();
                        } else {
                            // 间隔 100ms 继续下一片，避免压满服务器
                            setTimeout(uploadChunk, 100);
                        }
                    } else {
                        showResult('error', response.message || '分片上传失败');
                    }
                } catch (e) {
                    showResult('error', '服务器响应解析失败');
                }
            } else {
                showResult('error', '上传失败，服务器错误 (状态码: ' + currentXhr.status + ')');
            }
        };

        currentXhr.onerror = function() {
            if (!isCancelled) {
                showResult('error', '网络错误，上传失败');
            }
        };

        currentXhr.send(formData);
    }

    /** 全部分片上传完成后，请求后端合并并入库。 */
    function finishUpload() {
        if (isCancelled) return;

        currentXhr = new XMLHttpRequest();
        currentXhr.open('POST', '../backend/upload.php', true);
        
        const formData = new FormData();
        formData.append('action', 'finish');
        formData.append('fileId', fileId);
        formData.append('fileName', currentFile.name);
        formData.append('fileSize', currentFile.size);

        currentXhr.onload = function() {
            elements.progressContainer.style.display = 'none';
            if (isCancelled) return;
            
            if (currentXhr.status === 200) {
                try {
                    const response = JSON.parse(currentXhr.responseText);
                    if (response.status === 'success') {
                        showResult('success', response.message, response);
                    } else {
                        showResult('error', response.message || '文件合并失败');
                    }
                } catch (e) {
                    showResult('error', '服务器响应解析失败: ' + currentXhr.responseText);
                }
            } else {
                showResult('error', '文件合并失败，服务器错误 (状态码: ' + currentXhr.status + ')');
            }
        };

        currentXhr.onerror = function() {
            elements.progressContainer.style.display = 'none';
            if (!isCancelled) {
                showResult('error', '网络错误，文件合并失败');
            }
        };

        currentXhr.send(formData);
    }

    /**
     * 展示上传结果。
     * @param type 'success' | 'error'
     * @param message 提示文案
     * @param data 成功时携带后端返回的数据（含 download_url）
     */
    function showResult(type, message, data) {
        elements.result.className = 'result ' + type;
        let html = '<div class="result-title">' + 
            (type === 'success' ? '<span class="icon-check"></span>上传成功' : '<span class="icon-error">!</span>上传失败') + 
            '</div>';
        // 成功时上方"上传成功"已足够；失败时仍显示具体原因方便排查
        if (type === 'error') html += '<p>' + message + '</p>';
        
        // 成功时附带直链下载地址与复制按钮
        if (data && data.download_url) {
            html += '<div class="download-url" id="downloadUrl">' + data.download_url + '</div>';
            html += '<button class="copy-btn" data-url="' + data.download_url + '">复制链接</button>';
        }
        
        elements.result.innerHTML = html;
        elements.result.style.display = 'block';
    }

    /** 全局点击委托：处理结果区内“复制链接”按钮。 */
    function handleGlobalClick(e) {
        const target = e.target;
        if (target.classList.contains('copy-btn')) {
            const url = target.getAttribute('data-url');
            if (!url) return;
            
            // 降级复制方案：临时 textarea + execCommand
            const textArea = document.createElement('textarea');
            textArea.value = url;
            textArea.style.position = 'fixed';
            textArea.style.left = '-99999px';
            textArea.style.top = '-99999px';
            document.body.appendChild(textArea);
            textArea.select();
            
            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    target.textContent = '已复制';
                    target.classList.add('copied');
                    setTimeout(function() {
                        target.textContent = '复制链接';
                        target.classList.remove('copied');
                    }, 2000);
                } else {
                    alert('复制失败，请手动复制链接');
                }
            } catch (err) {
                alert('复制失败，请手动复制链接');
            }
            
            document.body.removeChild(textArea);
        }
    }

    /** 取消上传：中止请求并通知后端清理已传分片。 */
    function cancelUpload() {
        isCancelled = true;
        if (currentXhr) {
            currentXhr.abort();
            currentXhr = null;
        }
        elements.progressContainer.style.display = 'none';
        
        if (fileId) {
            // 通知后端清理已上传的分片
            const formData = new FormData();
            formData.append('action', 'cancel');
            formData.append('fileId', fileId);
            
            const cancelXhr = new XMLHttpRequest();
            cancelXhr.open('POST', '../backend/upload.php', true);
            
            cancelXhr.onload = function() {
                if (cancelXhr.status === 200) {
                    try {
                        const response = JSON.parse(cancelXhr.responseText);
                        console.log('取消上传:', response.message, '删除分片:', response.deleted_chunks, '删除文件:', response.deleted_files);
                    } catch (e) {
                        console.error('解析取消上传响应失败:', e);
                    }
                }
            };
            
            cancelXhr.onerror = function() {
                console.error('取消上传请求失败');
            };
            
            cancelXhr.send(formData);
        }
        
        showResult('error', '上传已取消');
        resetUploadState();
    }

    // 暴露给外部：HTML 内联 onclick 调用 cancelUpload
    return {
        init: init,
        cancelUpload: cancelUpload
    };
})();

document.addEventListener('DOMContentLoaded', function() {
    FileUploader.init();
});
