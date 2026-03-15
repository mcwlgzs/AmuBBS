/**
 * TinyMCE editor initializer
 */

(function() {
    'use strict';

    window.initTinyMCE = function(selector, options) {
        options = options || {};
        var mode = options.mode || 'full';
        var minHeight = options.minHeight || 300;
        var onChange = options.onChange || function() {};
        var onInit = options.onInit || function() {};

        var editorConfig = {
            selector: selector,
            language: 'zh-Hans',
            language_url: '/assets/tinymce/langs/zh-Hans.js',
            height: minHeight,
            menubar: false,
            plugins: getPlugins(mode),
            toolbar: getToolbar(mode),
            placeholder: '输入内容...',
            branding: false,
            resize: true,
            statusbar: true,
            convert_urls: false,
            remove_script_host: false,
            relative_urls: false,
            automatic_uploads: true,
            paste_data_images: false,
            images_upload_handler: function(blobInfo, progressOrSuccess, maybeFailure) {
                var uploadPromise = uploadImageToServer(blobInfo, progressOrSuccess);

                // Backward-compatible handler style: (blobInfo, success, failure)
                if (typeof progressOrSuccess === 'function') {
                    uploadPromise.then(progressOrSuccess).catch(function(errMsg) {
                        if (typeof maybeFailure === 'function') {
                            maybeFailure(errMsg);
                        }
                    });
                    return;
                }

                // Promise handler style used by newer TinyMCE
                return uploadPromise;
            },
            setup: function(editor) {
                editor.on('change', function() {
                    onChange(editor.getContent());
                });
                editor.on('init', function() {
                    onInit(editor);
                });
            }
        };

        if (mode === 'simple') {
            editorConfig.plugins = [
                'lists', 'link', 'image', 'code', 'emoticons'
            ];
            editorConfig.toolbar = [
                'bold italic underline | bullist numlist | link image emoticons | code'
            ].join(' ');
        }

        tinymce.init(editorConfig);
    };

    function getPlugins(mode) {
        // 简化模式：只加载必要插件
        if (mode === 'simple') {
            return ['autolink', 'autoresize', 'link', 'lists', 'emoticons', 'code', 'xiunoimgup'];
        }
        // 完整模式：按需加载常用插件
        var fullPlugins = [
            'advlist', 'autolink', 'autoresize', 'link', 'lists',
            'emoticons', 'code', 'codesample', 'image', 'xiunoimgup',
            'table', 'fullscreen', 'preview', 'searchreplace', 'wordcount'
        ];
        return fullPlugins;
    }

    function getToolbar(mode) {
        if (mode === 'simple') {
            return 'bold italic underline | bullist numlist | link image emoticons | code';
        }
        return 'undo redo | blocks | bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | link image emoticons codesample table | preview fullscreen | help';
    }

    function uploadImageToServer(blobInfo, progressCallback) {
        return new Promise(function(resolve, reject) {
            var blob = blobInfo.blob();
            if (!blob) {
                reject('图片数据无效');
                return;
            }

            if (blob.size > 5 * 1024 * 1024) {
                reject('图片大小不能超过 5MB');
                return;
            }

            var fileName = (typeof blobInfo.filename === 'function' && blobInfo.filename()) || ('image-' + Date.now() + '.png');
            var formData = new FormData();
            formData.append('image', blob, fileName);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/thread/upload-image', true);
            xhr.responseType = 'json';

            var csrfTokenMeta = document.querySelector('meta[name="csrf-token"]');
            if (csrfTokenMeta && csrfTokenMeta.content) {
                xhr.setRequestHeader('X-CSRF-TOKEN', csrfTokenMeta.content);
            }
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.upload.onprogress = function(e) {
                if (typeof progressCallback === 'function' && e.lengthComputable && e.total > 0) {
                    progressCallback((e.loaded / e.total) * 100);
                }
            };

            xhr.onerror = function() {
                reject('上传失败，请检查网络');
            };

            xhr.onload = function() {
                var data = xhr.response;
                if (!data && xhr.responseText) {
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch (err) {
                        data = null;
                    }
                }

                if (xhr.status < 200 || xhr.status >= 300) {
                    reject((data && (data.message || data.msg)) || '上传失败');
                    return;
                }

                if (!data || !data.success || !data.data || !data.data.url) {
                    reject((data && (data.message || data.msg)) || '上传失败');
                    return;
                }

                if (/^data:/i.test(data.data.url)) {
                    reject('服务端返回了无效图片地址');
                    return;
                }

                resolve(data.data.url);
            };

            xhr.send(formData);
        });
    }
})();
