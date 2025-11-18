<?php
/**
 * 抖音视频嵌入插件
 *
 * @package DouyinVideo
 * @author 湘铭呀
 * @version 1.0.0
 * @link https://xiangming.site/
 */
class DouyinVideo_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array('DouyinVideo_Plugin', 'parse');
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array('DouyinVideo_Plugin', 'addButton');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array('DouyinVideo_Plugin', 'addButton');
        Typecho_Plugin::factory('Widget_Archive')->header = array('DouyinVideo_Plugin', 'addFrontendCSS');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate(){}

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $apiDomain = new Typecho_Widget_Helper_Form_Element_Text('apiDomain', NULL, 'https://open.douyin.com',
        _t('抖音开放平台域名'), _t('抖音开放平台API域名，默认为 https://open.douyin.com'));
        $form->addInput($apiDomain);

        $customCSS = new Typecho_Widget_Helper_Form_Element_Textarea('customCSS', NULL, '',
        _t('自定义CSS样式'), _t('在这里输入自定义的CSS样式代码，将会添加到页面中。例如：<br/>
.douyin-video-container { margin: 30px auto; }<br/>
.douyin-video-container iframe { border-radius: 12px; }<br/>
留空则使用默认样式。'));
        $form->addInput($customCSS);
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 插件实现方法
     *
     * @access public
     * @return void
     */
    public static function render(){}

    /**
     * 解析短代码
     *
     * @access public
     * @param string $text 待解析文本
     * @return string
     */
    public static function parse($text)
    {
        $pattern = '/\[VideoID=([a-zA-Z0-9]+)\]/';

        return preg_replace_callback($pattern, function($matches) {
            $videoId = $matches[1];
            $options = Typecho_Widget::widget('Widget_Options');
            $plugin = $options->plugin('DouyinVideo');

            $apiUrl = $plugin->apiDomain . '/api/douyin/v1/video/get_iframe_by_video?video_id=' . $videoId;

            $iframe = self::getIframeCode($apiUrl, $videoId, $plugin);

            return $iframe;
        }, $text);
    }

    /**
     * 获取抖音视频iframe代码
     *
     * @access private
     * @param string $apiUrl API地址
     * @param string $videoId 视频ID
     * @param object $plugin 插件配置
     * @return string
     */
    private static function getIframeCode($apiUrl, $videoId, $plugin)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            return '<div class="douyin-video-error">抖音视频加载失败，请检查视频ID是否正确</div>';
        }

        $data = json_decode($response, true);

        if (!$data || $data['err_no'] !== 0) {
            $errorMsg = isset($data['err_msg']) ? $data['err_msg'] : '未知错误';
            return '<div class="douyin-video-error">抖音视频加载失败：' . htmlspecialchars($errorMsg) . '</div>';
        }

        if (!isset($data['data']['iframe_code'])) {
            return '<div class="douyin-video-error">抖音视频数据格式错误</div>';
        }

        $iframeCode = $data['data']['iframe_code'];

        // Enforce container dimensions and remove any inline styles from the API
        $iframeCode = preg_replace('/width="[^"]*"/', '', $iframeCode); // Remove width attribute
        $iframeCode = preg_replace('/height="[^"]*"/', '', $iframeCode); // Remove height attribute
        $iframeCode = preg_replace('/style="[^"]*"/', '', $iframeCode); // Remove inline styles

        // 添加必要的 iframe 属性
        $iframeCode = str_replace(
            '<iframe',
            '<iframe width="100%" height="100%" scrolling="no" frameborder="0" allow="fullscreen"',
            $iframeCode
        );

        return '<div class="douyin-video-container douyin-video-wrapper" data-type="dy">' . $iframeCode . '</div>';
    }

    /**
     * 在前端页面添加CSS样式
     *
     * @access public
     * @return void
     */
    public static function addFrontendCSS()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $cssUrl = $options->pluginUrl . '/DouyinVideo/assets/style.css?v=' . time();
        echo '<link rel="stylesheet" href="' . $cssUrl . '" type="text/css" />';
    }

    /**
     * 在编辑器页面添加抖音按钮
     *
     * @access public
     * @return void
     */
    public static function addButton()
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $plugin = $options->plugin('DouyinVideo');
        $cssUrl = $options->pluginUrl . '/DouyinVideo/assets/style.css?v=' . time();

        echo <<<EOF
<link rel="stylesheet" href="{$cssUrl}" type="text/css" />
<script type="text/javascript">
(function() {
    // 创建抖音视频弹窗
    function showDouyinDialog() {
        // 移除已存在的弹窗和遮罩
        var existingDialog = document.querySelector('.douyin-prompt-dialog');
        var existingBackground = document.querySelector('.douyin-prompt-background');
        if (existingDialog) existingDialog.remove();
        if (existingBackground) existingBackground.remove();

        // 创建遮罩层
        var background = document.createElement('div');
        background.className = 'wmd-prompt-background douyin-prompt-background';
        background.style.cssText = 'position: absolute; top: 0px; z-index: 1000; opacity: 0.5; left: 0px; width: 100%; height: ' + document.body.scrollHeight + 'px;';
        document.body.appendChild(background);

        // 创建弹窗 HTML
        var dialog = document.createElement('div');
        dialog.className = 'wmd-prompt-dialog douyin-prompt-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.innerHTML = `
            <div>
                <p><b>插入抖音视频</b></p>
                <p>请在下方的输入框内输入抖音视频ID</p>
                <p>视频ID可以从抖音分享链接中获取</p>
            </div>
            <form>
                <input type="text" id="douyin-video-id-input" placeholder="请输入视频ID">
                <button type="button" class="btn btn-s primary douyin-confirm">确定</button>
                <button type="button" class="btn btn-s douyin-cancel">取消</button>
            </form>
        `;

        document.body.appendChild(dialog);

        // 获取输入框和按钮
        var input = dialog.querySelector('#douyin-video-id-input');
        var confirmBtn = dialog.querySelector('.douyin-confirm');
        var cancelBtn = dialog.querySelector('.douyin-cancel');

        // 聚焦输入框
        setTimeout(function() {
            input.focus();
        }, 100);

        // 关闭弹窗的函数
        function closeDialog() {
            dialog.remove();
            background.remove();
            document.onkeydown = null;
        }

        // 确定按钮点击事件
        confirmBtn.onclick = function() {
            var videoId = input.value.trim();
            if (videoId) {
                insertDouyinVideo(videoId);
                closeDialog();
            } else {
                alert('请输入视频ID');
            }
        };

        // 取消按钮点击事件
        cancelBtn.onclick = function() {
            closeDialog();
        };

        // 点击遮罩层关闭
        background.onclick = function() {
            closeDialog();
        };

        // 回车键确认
        input.onkeypress = function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                confirmBtn.click();
            }
        };

        // ESC键取消
        document.onkeydown = function(e) {
            if (e.key === 'Escape' && dialog.parentNode) {
                closeDialog();
            }
        };
    }

    // 插入抖音视频短代码
    function insertDouyinVideo(videoId) {
        var textarea = document.getElementById('text');
        if (!textarea) return;

        var shortcode = '[VideoID=' + videoId + ']';
        var start = textarea.selectionStart;
        var end = textarea.selectionEnd;
        var text = textarea.value;

        textarea.value = text.substring(0, start) + shortcode + text.substring(end);
        textarea.selectionStart = textarea.selectionEnd = start + shortcode.length;
        textarea.focus();
    }

    // 等待页面加载完成
    function addDouyinButton() {
        var toolbar = document.getElementById('wmd-button-row');
        if (!toolbar) {
            setTimeout(addDouyinButton, 100);
            return;
        }

        // 检查按钮是否已经存在
        if (document.getElementById('wmd-douyin-button')) {
            return;
        }

        // 创建按钮元素
        var button = document.createElement('li');
        button.className = 'wmd-button';
        button.id = 'wmd-douyin-button';
        button.title = '插入抖音视频';

        // 创建按钮内的span元素
        var span = document.createElement('span');
        span.innerHTML = '抖';
        span.style.fontSize = '12px';
        span.style.fontWeight = 'bold';
        span.style.color = '#333';
        button.appendChild(span);

        // 点击事件 - 显示弹窗
        button.onclick = function() {
            showDouyinDialog();
        };

        // 找到合适的插入位置，在图片按钮后面
        var imageButton = document.getElementById('wmd-image-button');
        var spacer2 = document.getElementById('wmd-spacer2');

        if (imageButton && spacer2) {
            // 在图片按钮和第二个分隔符之间插入
            toolbar.insertBefore(button, spacer2);
        } else {
            // 如果找不到特定位置，就添加到最后
            toolbar.appendChild(button);
        }
    }

    // 页面加载完成后执行
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', addDouyinButton);
    } else {
        addDouyinButton();
    }
})();
</script>
EOF;

        // 输出用户自定义CSS
        if (!empty($plugin->customCSS)) {
            echo "\n<style type=\"text/css\">\n/* 用户自定义样式 */\n";
            echo $plugin->customCSS;
            echo "\n</style>\n";
        }
    }
}