<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * TypechoPaid 付费阅读插件
 *
 * @package TypechoPaid
 * @author LHL
 * @version 1.0.4
 * @link https://github.com/lhl77/TypechoPaid
 */
class TypechoPaid_Plugin implements Typecho_Plugin_Interface
{
    const ROUTE_NAME = 'typechopaid_action';
    const ROUTE_PATH = '/typechopaid/[do:string]';
    const MENU_NAME = 'TypechoPaid';
    const COOKIE_NAME = 'typechopaid_unlock';
    const SPONSOR_URL = 'https://see.lhl.one/sponsor';

    public static function activate()
    {
        try {
            self::installTable();
        } catch (\Throwable $e) {
            // 表已存在或数据库操作失败不应阻止插件激活
        }

        Typecho_Plugin::factory('Widget_Abstract_Contents')->contentEx = array(__CLASS__, 'filterContent');
        Typecho_Plugin::factory('admin/write-post.php')->bottom = array(__CLASS__, 'injectEditorHelper');
        Typecho_Plugin::factory('admin/write-page.php')->bottom = array(__CLASS__, 'injectEditorHelper');
        Typecho_Plugin::factory(\Widget\Archive::class)->header = array(__CLASS__, 'injectHeadAssets');

        try {
            Helper::addRoute(self::ROUTE_NAME, self::ROUTE_PATH, 'TypechoPaid_Action', 'dispatch');
        } catch (\Throwable $e) {
            // 路由注册失败不应阻止插件激活
        }

        try {
            $menuIndex = Helper::addMenu(self::MENU_NAME);
            Helper::addPanel($menuIndex, 'TypechoPaid/Orders.php', _t('订单管理'), _t('TypechoPaid 订单管理'), 'administrator');
            Helper::addPanel($menuIndex, 'TypechoPaid/Manage.php', _t('文章定价与计划'), _t('管理文章付费设置与订阅计划'), 'administrator');
            Helper::addPanel($menuIndex, 'TypechoPaid/Subscriptions.php', _t('订阅管理'), _t('查看已购买订阅及有效期'), 'administrator');
        } catch (Exception $e) {
            Helper::addPanel(3, 'TypechoPaid/Orders.php', _t('订单管理'), _t('TypechoPaid 订单管理'), 'administrator');
            Helper::addPanel(3, 'TypechoPaid/Manage.php', _t('文章定价与计划'), _t('管理文章付费设置与订阅计划'), 'administrator');
            Helper::addPanel(3, 'TypechoPaid/Subscriptions.php', _t('订阅管理'), _t('查看已购买订阅及有效期'), 'administrator');
        } catch (Throwable $e) {
            Helper::addPanel(3, 'TypechoPaid/Orders.php', _t('订单管理'), _t('TypechoPaid 订单管理'), 'administrator');
            Helper::addPanel(3, 'TypechoPaid/Manage.php', _t('文章定价与计划'), _t('管理文章付费设置与订阅计划'), 'administrator');
            Helper::addPanel(3, 'TypechoPaid/Subscriptions.php', _t('订阅管理'), _t('查看已购买订阅及有效期'), 'administrator');
        }

        return _t('TypechoPaid 插件启用成功');
    }

    public static function deactivate()
    {
        Helper::removeRoute(self::ROUTE_NAME);

        try {
            $menuIndex = Helper::removeMenu(self::MENU_NAME);
            if ($menuIndex !== null) {
                Helper::removePanel($menuIndex, 'TypechoPaid/Orders.php');
                Helper::removePanel($menuIndex, 'TypechoPaid/Manage.php');
                Helper::removePanel($menuIndex, 'TypechoPaid/Subscriptions.php');
            }
        } catch (Exception $e) {
        } catch (Throwable $e) {
        }

        Helper::removePanel(3, 'TypechoPaid/Orders.php');
        Helper::removePanel(3, 'TypechoPaid/Manage.php');
        Helper::removePanel(3, 'TypechoPaid/Subscriptions.php');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $isGet = empty($_POST);

        // 每次进入 config() 重置分组状态，防止重复调用时累积
        self::$configSections = array();

        // 用输出缓冲隔离所有视觉输出，防止干扰 Typecho 表单处理
        if ($isGet) {
            ob_start();
        }

        // 赞助链接完整性校验（仅 GET 显示，避免影响 POST 保存）
        if ($isGet) {
            $sponsorWarning = '';
            if (!self::isSponsorUrlIntact(self::SPONSOR_URL)) {
                $sponsorWarning = '<div style="padding:12px 16px;margin:0 0 16px;border-radius:12px;background:#dc2626;color:#fff;font-size:13px;font-weight:600;text-align:center;line-height:1.6;width:100%!important;max-width:100%!important;flex:0 0 100%!important;box-sizing:border-box!important;">⚠ 检测到安全隐患：赞助链接已被篡改，请停止使用并从官方渠道重新获取插件</div>';
            }
            echo $sponsorWarning;
        }

        // 置顶信息卡片（仅 GET 显示，避免影响 POST 保存）
        if ($isGet) {
            echo self::renderCardsWrapperOpen();
            echo self::renderInfoCard(false, true);
            echo self::renderDocPromo();
            echo self::renderABPromo();
            echo self::renderCardsWrapperClose();
        }

        // ======================== 💰 支付与订阅 ========================
        self::renderConfigCard(_t('支付与订阅'), _t('配置支付通道、订阅计划与购买行为'), array('payment_channels','subscription_plans','cookie_days','sandbox_auto_paid'), $isGet);

        $paymentChannels = new Typecho_Widget_Helper_Form_Element_Textarea(
            'payment_channels',
            null,
            '',
            _t('支付通道定义'),
            _t('每行格式：支付通道名称|支付驱动:配置1:配置2:配置3...；配置中的英文冒号请写为 \\:。支付通道配置请查看文档：<a href="https://blog.lhl.one/artical/1309.html#支付通道" target="_blank">https://blog.lhl.one/artical/1309.html</a>')
        );
        $form->addInput($paymentChannels);

        $plans = new Typecho_Widget_Helper_Form_Element_Textarea(
            'subscription_plans',
            null,
            '',
            _t('订阅计划'),
            _t('每行一个计划，格式：plan_key:名称:价格:有效天数。例如：monthly:月度订阅:29.90:30。空则不启用订阅。')
        );
        $form->addInput($plans);

        $cookieDays = new Typecho_Widget_Helper_Form_Element_Text(
            'cookie_days',
            null,
            '30',
            _t('购买状态 Cookie 有效天数'),
            _t('购买成功后自动写入 Cookie，默认 30 天。')
        );
        $form->addInput($cookieDays->addRule('isInteger', _t('必须是整数')));

        $sandboxAutoPaid = new Typecho_Widget_Helper_Form_Element_Radio(
            'sandbox_auto_paid',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '0',
            _t('沙箱自动支付'),
            _t('开启后创建订单立即标记为已支付，便于联调。生产环境请关闭并接入真实回调。')
        );
        $form->addInput($sandboxAutoPaid);

        // ======================== 🎫 优惠码 ========================
        self::renderConfigCard(_t('优惠码'), _t('配置折扣优惠码，一行一个'), array('coupon_codes'), $isGet);

        $couponCodes = new Typecho_Widget_Helper_Form_Element_Textarea(
            'coupon_codes',
            null,
            '',
            _t('优惠码定义'),
            _t('每行格式：优惠码名称|优惠码|优惠方式(直接数字则减该金额，带%则为折扣)|适用文章UID(用,分开)<br>'
                . '例如：<code>开业大吉|Welcome|30%|1,3</code> 表示对文章 1 和 3 打七折<br>'
                . '例如：<code>满减|SAVE10|10|2,5</code> 表示文章 2 和 5 减 10 元<br>'
                . '留空则不启用优惠码功能。优惠码验证受 Turnstile 保护。')
        );
        $form->addInput($couponCodes);

        self::renderConfigCard(_t('购买卡片外观'), _t('自定义前台付费卡片的主题、文案与亮暗模式'), array('default_theme','default_paid_desc','theme_advanced_options','paid_theme_mode','paid_theme_mode_switch'), $isGet);

        $themeOptions = array();
        $themes = self::listThemes();
        foreach ($themes as $id) {
            $themeOptions[$id] = $id;
        }
        if (empty($themeOptions)) {
            $themeOptions = array('default' => 'default');
        }

        $defaultTheme = new Typecho_Widget_Helper_Form_Element_Select(
            'default_theme',
            $themeOptions,
            isset($themeOptions['default']) ? 'default' : key($themeOptions),
            _t('前台默认主题'),
            _t('主题 ID 为 themes 目录下的文件夹名。文章可通过 paid_theme 覆盖。查看主题预览请见文档：<a href="https://blog.lhl.one/artical/1309.html#主题与设置" target="_blank">https://blog.lhl.one/artical/1309.html</a>')
        );
        $form->addInput($defaultTheme);

        $defaultDesc = new Typecho_Widget_Helper_Form_Element_Textarea(
            'default_paid_desc',
            null,
            '该文章为付费阅读内容，请先购买后查看全文。',
            _t('默认购买提示文案'),
            _t('当文章的 paid_desc 自定义字段留空时使用。')
        );
        $form->addInput($defaultDesc);

        $themeAdvanced = new Typecho_Widget_Helper_Form_Element_Textarea(
            'theme_advanced_options',
            null,
            '',
            _t('主题高级设置（全局）'),
            _t('格式：CSS变量:值，一行一个。文章可用 paid_theme_options 覆盖。支持的字段请查阅文档: <a href="https://blog.lhl.one/artical/1309.html#主题与设置" target="_blank">https://blog.lhl.one/artical/1309.html</a>')
        );
        $form->addInput($themeAdvanced);

        $themeMode = new Typecho_Widget_Helper_Form_Element_Select(
            'paid_theme_mode',
            array('auto' => _t('自动（跟随系统或主题）'), 'dark' => _t('强制暗色'), 'light' => _t('强制亮色')),
            'auto',
            _t('卡片亮暗模式'),
            _t('“自动”模式下，具体判定方式由下方“亮暗自动适配方式”决定。')
        );
        $form->addInput($themeMode);

        $themeModeSwitch = new Typecho_Widget_Helper_Form_Element_Textarea(
            'paid_theme_mode_switch',
            null,
            'system',
            _t('亮暗自动适配方式'),
            _t('填 system（默认）表示跟随系统 prefers-color-scheme；若主题是通过 body/html 添加 class 切换亮暗，请按“一行一个”填写，例如：dark:dark 、 light:light（即 dark: 后面写暗色模式的 class 名，light: 后面写亮色模式的 class 名）。具体请查看文档：<a href="https://blog.lhl.one/artical/1309.html#亮暗色适配" target="_blank">https://blog.lhl.one/artical/1309.html</a>')
        );
        $form->addInput($themeModeSwitch);

        // ======================== 📖 正文预览 ========================
        self::renderConfigCard(_t('正文预览'), _t('控制付费文章在前台的预览段落展示'), array('preview_enable','preview_length'), $isGet);

        $previewEnable = new Typecho_Widget_Helper_Form_Element_Radio(
            'preview_enable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '1',
            _t('正文预览（tp-paid-preview）'),
            _t('关闭后卡片不再显示模糊预览段落。')
        );
        $form->addInput($previewEnable);

        $previewLength = new Typecho_Widget_Helper_Form_Element_Text(
            'preview_length',
            null,
            '200',
            _t('预览字数'),
            _t('正文预览开启时，截取的纯文本字数上限。')
        );
        $form->addInput($previewLength->addRule('isInteger', _t('必须是整数')));

        // ======================== 🛡️ 安全验证 ========================
        self::renderConfigCard(_t('安全验证'), _t('Cloudflare Turnstile 人机验证防刷'), array('turnstile_enable','turnstile_site_key','turnstile_secret_key','turnstile_daily_threshold'), $isGet);

        $turnstileEnable = new Typecho_Widget_Helper_Form_Element_Radio(
            'turnstile_enable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '0',
            _t('Cloudflare Turnstile 验证'),
            _t('开启后，当同一 IP 当日下单次数达到阈值时，购买表单会要求完成 Turnstile 验证。')
        );
        $form->addInput($turnstileEnable);

        $turnstileSiteKey = new Typecho_Widget_Helper_Form_Element_Text(
            'turnstile_site_key',
            null,
            '',
            _t('Turnstile Site Key'),
            _t('Cloudflare Turnstile 控制台获取的站点密钥。')
        );
        $form->addInput($turnstileSiteKey);

        $turnstileSecretKey = new Typecho_Widget_Helper_Form_Element_Text(
            'turnstile_secret_key',
            null,
            '',
            _t('Turnstile Secret Key'),
            _t('Cloudflare Turnstile 控制台获取的私钥，仅服务端用于验证 Token。')
        );
        $form->addInput($turnstileSecretKey);

        $turnstileThreshold = new Typecho_Widget_Helper_Form_Element_Text(
            'turnstile_daily_threshold',
            null,
            '3',
            _t('同 IP 每日触发次数'),
            _t('同一 IP 当日下单次数达到或超过该值时，才要求完成 Turnstile 验证。')
        );
        $form->addInput($turnstileThreshold->addRule('isInteger', _t('必须是整数')));

        // ======================== 📧 邮件通知 ========================
        self::renderConfigCard(_t('邮件通知'), _t('SMTP 邮件发送配置，购买成功后通知用户'), array('smtp_enable','smtp_host','smtp_port','smtp_encryption','smtp_username','smtp_password','smtp_from_email','smtp_from_name'), $isGet);

        $smtpEnable = new Typecho_Widget_Helper_Form_Element_Radio(
            'smtp_enable',
            array('1' => _t('开启'), '0' => _t('关闭')),
            '0',
            _t('SMTP 购买通知'),
            _t('支付成功后向购买邮箱发送订单通知。关闭时不会发送邮件。')
        );
        $form->addInput($smtpEnable);

        $smtpHost = new Typecho_Widget_Helper_Form_Element_Text('smtp_host', null, '', _t('SMTP 主机'), _t('例如 smtp.example.com。'));
        $form->addInput($smtpHost);
        $smtpPort = new Typecho_Widget_Helper_Form_Element_Text('smtp_port', null, '465', _t('SMTP 端口'), _t('常用 SSL 为 465，STARTTLS 为 587。'));
        $form->addInput($smtpPort->addRule('isInteger', _t('必须是整数')));
        $smtpEncryption = new Typecho_Widget_Helper_Form_Element_Select('smtp_encryption', array('ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => _t('无加密')), 'ssl', _t('SMTP 加密方式'));
        $form->addInput($smtpEncryption);
        $smtpUser = new Typecho_Widget_Helper_Form_Element_Text('smtp_username', null, '', _t('SMTP 用户名'), _t('通常为完整邮箱地址。'));
        $form->addInput($smtpUser);
        $smtpPassword = new Typecho_Widget_Helper_Form_Element_Password('smtp_password', null, '', _t('SMTP 密码或授权码'), _t('建议使用服务商生成的 SMTP 授权码。'));
        $form->addInput($smtpPassword);
        $smtpFrom = new Typecho_Widget_Helper_Form_Element_Text('smtp_from_email', null, '', _t('发件人邮箱'), _t('留空时使用 SMTP 用户名。'));
        $form->addInput($smtpFrom);
        $smtpFromName = new Typecho_Widget_Helper_Form_Element_Text('smtp_from_name', null, 'TypechoPaid通知', _t('发件人名称'));
        $form->addInput($smtpFromName);

        if ($isGet) {
            self::renderConfigCardJS(true);
            // 赞赏与鸣谢卡片（放在最底部）
            echo self::renderSponsorCard();
        }

        // 输出缓冲：将卡片的视觉 HTML 在表单构建完毕后统一输出
        if ($isGet) {
            ob_end_flush();
        }
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    public static function injectHeadAssets()
    {
        // 全局 CSS/JS 通过外链加载，附带文件时间戳防止 CDN 缓存问题
        $options = Helper::options();
        $pluginUrl = Typecho_Common::url('TypechoPaid', $options->pluginUrl);

        $cssFile = __DIR__ . '/assets/style.css';
        $cssTs = is_file($cssFile) ? filemtime($cssFile) : time();
        echo '<link rel="stylesheet" href="' . htmlspecialchars($pluginUrl . '/assets/style.css?v=' . $cssTs) . '">' . "\n";

        $qrFile = __DIR__ . '/assets/vendor/qrcode.js';
        $qrTs = is_file($qrFile) ? filemtime($qrFile) : time();
        echo '<script src="' . htmlspecialchars($pluginUrl . '/assets/vendor/qrcode.js?v=' . $qrTs) . '" defer></script>' . "\n";

        $turnstileEnabled = intval(self::getOption('turnstile_enable', '0')) === 1;
        if ($turnstileEnabled) {
            echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=tpTurnstileReady&render=explicit" async defer></script>' . "\n";
        }

        echo '<script>'
            . '(function(){'
            . 'var _t0=typeof performance!=="undefined"?performance.now():Date.now();'
            . 'var _v="1.0.4";'
            . 'var _u="https://github.com/lhl77/TypechoPaid";'
            . 'var _done=false;'
            . 'function _tp(label){'
            . 'if(_done)return;_done=true;'
            . 'var n=typeof performance!=="undefined"?performance.now():Date.now();'
            . 'var t=Math.round(n-_t0);'
            . 'console.log('
            . '"%c Paid for Typecho %c v"+_v+"\\n%c"+_u+"\\n%c"+label+" in "+t+"ms",'
            . '"color:#fff;background:#6366f1;padding:2px 6px;border-radius:4px;font-weight:700;font-size:13px;",'
            . '"color:#10b981;font-weight:700;font-size:13px;",'
            . '"color:#6366f1;font-size:11px;",'
            . '"color:#9ca3af;font-size:11px;font-style:italic;"'
            . ');'
            . '}'
            . 'if(document.readyState==="complete"){_tp("page loaded");}'
            . 'else{window.addEventListener("load",function(){_tp("page loaded");},{once:true});}'
            . '})();'
            . '</script>' . "\n";

        echo <<<'SCRIPT'
<script>
(function(){
    function decodePayload(payload){
        var binary=atob(payload);
        if(window.TextDecoder){
            var bytes=new Uint8Array(binary.length);
            for(var i=0;i<binary.length;i++){bytes[i]=binary.charCodeAt(i);}
            return new TextDecoder('utf-8').decode(bytes);
        }
        return decodeURIComponent(Array.prototype.map.call(binary,function(ch){
            return '%' + ('00' + ch.charCodeAt(0).toString(16)).slice(-2);
        }).join(''));
    }
    function escapeHtml(str){
        var map={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
        return String(str==null?'':str).replace(/[&<>"']/g,function(ch){return map[ch];});
    }
    function reviveScripts(root){
        if(!root||root.nodeType!==1){return;}
        var scripts=root.tagName==='SCRIPT'?[root]:root.querySelectorAll('script');
        for(var i=0;i<scripts.length;i++){
            var old=scripts[i];
            var fresh=document.createElement('script');
            for(var j=0;j<old.attributes.length;j++){
                fresh.setAttribute(old.attributes[j].name,old.attributes[j].value);
            }
            fresh.text=old.textContent;
            if(old.parentNode){old.parentNode.replaceChild(fresh,old);}
        }
    }
    function applyThemeMode(){
        var boxes=document.querySelectorAll('.tp-paid-box[data-mode], .tp-paid-inline-placeholder[data-mode]');
        function readDataTheme(){
            var dt=(document.documentElement.getAttribute('data-theme')||'').toLowerCase();
            if(dt==='dark'){return'dark';}
            if(dt==='light'){return'light';}
            dt=(document.body&&document.body.getAttribute('data-theme')||'').toLowerCase();
            if(dt==='dark'){return'dark';}
            if(dt==='light'){return'light';}
            return'';
        }
        for(var i=0;i<boxes.length;i++){
            var box=boxes[i];
            var mode=box.getAttribute('data-mode')||'auto';
            var resolved='light';
            if(mode==='dark'||mode==='light'){
                resolved=mode;
            }else{
                // 自动模式下，允许主题通过 --tp-default-mode:dark 声明默认暗色
                var defaultMode='';
                try{defaultMode=(getComputedStyle(box).getPropertyValue('--tp-default-mode')||'').trim();}catch(e){}
                var switchCfg=(box.getAttribute('data-mode-switch')||'system').trim();
                if(!switchCfg||switchCfg.toLowerCase()==='system'){
                    // 无自定义配置：优先 data-theme 属性 → 其次系统配色 → 最后默认
                    var dt=readDataTheme();
                    resolved=dt||(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':(defaultMode==='dark'?'dark':'light'));
                }else{
                    var darkClass='',lightClass='';
                    var lines=switchCfg.split(/\r\n|\r|\n/);
                    for(var l=0;l<lines.length;l++){
                        var line=lines[l].trim();
                        if(!line){continue;}
                        var idx=line.indexOf(':');
                        if(idx<=0){continue;}
                        var key=line.substring(0,idx).trim().toLowerCase();
                        var val=line.substring(idx+1).trim();
                        if(key==='dark'){darkClass=val;}
                        if(key==='light'){lightClass=val;}
                    }
                    if(darkClass&&(document.body.classList.contains(darkClass)||document.documentElement.classList.contains(darkClass))){
                        resolved='dark';
                    }else if(lightClass&&(document.body.classList.contains(lightClass)||document.documentElement.classList.contains(lightClass))){
                        resolved='light';
                    }else{
                        // 类名都不匹配 → 再检查 data-theme → 系统配色 → 默认
                        var dt2=readDataTheme();
                        if(dt2){
                            resolved=dt2;
                        }else if(darkClass&&!lightClass){resolved='light';}
                        else if(lightClass&&!darkClass){resolved='dark';}
                        else{resolved=(window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches)?'dark':(defaultMode==='dark'?'dark':'light');}
                    }
                }
            }
            box.classList.remove('tp-paid-mode-dark','tp-paid-mode-light');
            box.classList.add(resolved==='dark'?'tp-paid-mode-dark':'tp-paid-mode-light');
        }
    }
    function boot(){
        var nodes=document.querySelectorAll('.tp-paid-placeholder[data-tp-paid]');
        for(var i=0;i<nodes.length;i++){
            var node=nodes[i];
            if(node.getAttribute('data-tp-ready')){continue;}
            node.setAttribute('data-tp-ready','1');
            try{
                var wrap=document.createElement('div');
                wrap.innerHTML=decodePayload(node.getAttribute('data-tp-paid'));
                var parent=node.parentNode;
                var inserted=[];
                while(wrap.firstChild){
                    var child=wrap.firstChild;
                    parent.insertBefore(child,node);
                    inserted.push(child);
                }
                parent.removeChild(node);
                for(var k=0;k<inserted.length;k++){reviveScripts(inserted[k]);}
            }catch(e){node.textContent='付费内容加载失败，请刷新后重试。';}
        }
        applyThemeMode();
        // 确保第一个付费卡片有 id="tp-paid-card" 锚点，供部分付费跳转使用
        (function(){
            var existing=document.getElementById("tp-paid-card");
            if(!existing){
                var firstCard=document.querySelector(".tp-paid-card");
                if(firstCard&&!firstCard.id){firstCard.id="tp-paid-card";}
            }
        })();
        // 确保 body observer 已注册（首次 IIFE 执行时 body 可能尚未就绪）
        if(window._tpNeedBodyObs&&document.body&&window.MutationObserver){
            var bodyMo=new MutationObserver(function(mutations){
                var rel=false;
                for(var i=0;i<mutations.length;i++){var an=mutations[i].attributeName;if(an==='class'||an==='data-theme'){rel=true;break;}}
                if(rel){applyThemeMode();}
            });
            bodyMo.observe(document.body,{attributes:true,attributeFilter:['class','data-theme']});
            window._tpNeedBodyObs=false;
        }
        if(window.TypechoPaid&&window.TypechoPaid.renderTurnstileWidgets){window.TypechoPaid.renderTurnstileWidgets();}
    }
    function setupThemeObservers(){
        if(window._tpThemeObserving){return;}
        window._tpThemeObserving=true;
        if(window.MutationObserver){
            var mo=new MutationObserver(function(mutations){
                var relevant=false;
                for(var i=0;i<mutations.length;i++){
                    var an=mutations[i].attributeName;
                    if(an==='class'||an==='data-theme'){relevant=true;break;}
                }
                if(relevant){applyThemeMode();}
            });
            mo.observe(document.documentElement,{attributes:true,attributeFilter:['class','data-theme']});
            // body 可能尚未就绪，在 boot() 中会重新确保注册
            if(document.body){mo.observe(document.body,{attributes:true,attributeFilter:['class','data-theme']});}else{window._tpNeedBodyObs=true;}
        }
        if(window.matchMedia){
            try{window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change',applyThemeMode);}catch(e){}
        }
    }
    setupThemeObservers();
    // PJAX / SPA 导航支持：发现新 .tp-paid-placeholder 时自动 re-boot
    function setupPjaxSupport(){
        // MutationObserver：监听整个文档的 childList 变化
        if(window.MutationObserver){
            var pjaxMo=new MutationObserver(function(mutations){
                var found=false;
                for(var i=0;i<mutations.length;i++){
                    var added=mutations[i].addedNodes;
                    for(var j=0;j<added.length;j++){
                        var node=added[j];
                        if(node.nodeType!==1){continue;}
                        if(node.matches&&node.matches('.tp-paid-placeholder[data-tp-paid]:not([data-tp-ready])')){
                            found=true;break;
                        }
                        if(node.querySelectorAll&&node.querySelectorAll('.tp-paid-placeholder[data-tp-paid]:not([data-tp-ready])').length){
                            found=true;break;
                        }
                    }
                    if(found){break;}
                }
                if(found){setTimeout(boot,0);}
            });
            pjaxMo.observe(document.documentElement,{childList:true,subtree:true});
        }
        // 同时监听常见的 PJAX / SPA 导航事件
        function onNavDone(){setTimeout(boot,0);}
        document.addEventListener('pjax:complete',onNavDone,false);
        document.addEventListener('pjax:end',onNavDone,false);
        document.addEventListener('page:load',onNavDone,false);
        document.addEventListener('page:loaded',onNavDone,false);
        document.addEventListener('turbolinks:load',onNavDone,false);
        document.addEventListener('barba:done',onNavDone,false);
        document.addEventListener('fds-pjax:success',onNavDone,false);
    }
    setupPjaxSupport();
    window.tpTurnstileReady=function(){
        if(window.TypechoPaid){
            window.TypechoPaid._turnstileReady=true;
            window.TypechoPaid.renderTurnstileWidgets();
        }
    };
    window.TypechoPaid=window.TypechoPaid||{
        post:function(form,cb){
            var fd=new FormData(form),channel=form.querySelector('input[name=tp_paid_channel]:checked');
            if(channel){fd.append('channel',channel.value);}
            fetch(form.action,{method:'POST',body:fd,credentials:'same-origin'}).then(function(r){return r.json();}).then(cb).catch(function(){cb({success:0,msg:'请求失败，请稍后重试'});});
        },
        show:function(form,msg,ok){
            var box=form.closest('.tp-paid-card');if(!box){return;}
            var el=box.querySelector('[data-role=msg]');if(!el){return;}
            el.innerHTML=msg||'';el.className='tp-paid-msg'+(ok?' is-ok':' is-err');
        },
        renderTurnstileWidgets:function(){
            if(!window.turnstile){
                this._turnstileRetries=this._turnstileRetries||0;
                if(this._turnstileRetries<60){
                    this._turnstileRetries++;
                    setTimeout(function(){if(window.TypechoPaid)window.TypechoPaid.renderTurnstileWidgets();},500);
                }
                return;
            }
            this._turnstileRetries=0;
            var nodes=document.querySelectorAll('.cf-turnstile:not([data-tp-rendered])');
            for(var i=0;i<nodes.length;i++){
                nodes[i].setAttribute('data-tp-rendered','1');
                try{window.turnstile.render(nodes[i]);}catch(e){}
            }
        },
        resetTurnstile:function(form){
            if(!window.turnstile){return;}
            var node=form.querySelector('.cf-turnstile');
            if(!node){return;}
            try{window.turnstile.reset(node);}catch(e){}
            // 重置后需要重新渲染
            node.removeAttribute('data-tp-rendered');
            try{window.turnstile.render(node);node.setAttribute('data-tp-rendered','1');}catch(e){}
        },
        toggleUnlock:function(link){
            var card=link.closest('.tp-paid-card');if(!card){return false;}
            card.classList.add('is-unlock-mode');
            return false;
        },
        backToBuy:function(link){
            var card=link.closest('.tp-paid-card');if(!card){return false;}
            card.classList.remove('is-unlock-mode');
            return false;
        },
        submitCreate:function(form){
            var card=form.closest('.tp-paid-card');
            var btn=form.querySelector('button[type=submit]');
            if(card&&card.getAttribute('data-tp-pending')==='1'){return false;}
            // 计划卡片位于表单外，需从当前付款卡读取选中项。
            var selectedPlan=card?card.querySelector('.tp-paid-plan-card.is-selected'):null;
            var planKey=selectedPlan?(selectedPlan.getAttribute('data-plan')||''):'';
            var planKeyInput=form.querySelector('input[name=plan_key]');
            var subscribeAction=form.querySelector('input[name=subscribe_action]');
            if(planKey!==''&&subscribeAction&&planKeyInput){
                planKeyInput.value=planKey;
                form.action=subscribeAction.value;
            }else{
                if(planKeyInput){planKeyInput.value='';}
                if(form.getAttribute('data-create-action')){form.action=form.getAttribute('data-create-action');}
            }
            if(btn){btn.disabled=true;}
            // 在等待服务器响应期间显示加载动画
            var paymentBox=card?card.querySelector('[data-role=payment-box]'):null;
            if(paymentBox){
                paymentBox.innerHTML='<div class="tp-paid-qr-loading"><div class="tp-paid-qr-spinner"></div><p class="tp-paid-qr-tip">正在创建订单…</p></div>';
            }
            this.post(form,function(res){
                if(res.success){
                    if(card){
                        card.setAttribute('data-tp-pending','1');
                        card.setAttribute('data-tp-trade-no',res.trade_no||'');
                    }
                    if(res.auto_paid){
                        TypechoPaid.show(form,'已自动完成支付，正在刷新页面...',1);
                        setTimeout(function(){location.reload();},700);
                        return;
                    }
                    // 优惠码验证成功后显示名称
                    if(res.coupon_name){
                        var couponInput=card?card.querySelector('input[name=coupon_code]'):null;
                        if(couponInput){couponInput.disabled=true;couponInput.readOnly=true;}
                        var couponResult=card?card.querySelector('[data-role=coupon-result]'):null;
                        if(couponResult){
                            couponResult.innerHTML='<span class="tp-paid-coupon-ok">已使用优惠码：'+escapeHtml(res.coupon_name)+'</span>';
                            couponResult.className='tp-paid-coupon-result is-ok';
                        }
                    }
                    TypechoPaid.renderPaymentBox(card,res);
                    if(res.trade_no){TypechoPaid.startPolling(card,res.trade_no);}
                    return;
                }
                if(btn){btn.disabled=false;}
                TypechoPaid.resetTurnstile(form);
                TypechoPaid.show(form,res.msg||'下单失败',0);
            });
            return false;
        },
        renderPaymentBox:function(card,res){
            if(!card){return;}
            var box=card.querySelector('[data-role=payment-box]');
            if(!box){return;}
            box.innerHTML='';

            function genQR(url){
                box.innerHTML='';
                var canvas=document.createElement('canvas');
                box.appendChild(canvas);
                var tip=document.createElement('p');
                tip.className='tp-paid-qr-tip';
                tip.textContent='请使用手机扫码完成支付';
                box.appendChild(tip);
                try{
                    if(typeof window.qrcode!=='function'){throw new Error('qrcode not loaded');}
                    var qr=window.qrcode(0,'L');
                    qr.addData(url);
                    qr.make();
                    var cellSize=4;
                    var count=qr.getModuleCount();
                    canvas.width=count*cellSize;
                    canvas.height=count*cellSize;
                    var ctx=canvas.getContext('2d');
                    qr.renderTo2dContext(ctx,cellSize);
                }catch(e){
                    box.innerHTML='<a class="tp-paid-btn" target="_blank" rel="noopener" href="'+escapeHtml(url)+'">前往支付</a>';
                }
            }

            function showLoading(){
                box.innerHTML='<div class="tp-paid-qr-loading"><div class="tp-paid-qr-spinner"></div><p class="tp-paid-qr-tip">正在生成支付二维码…</p></div>';
            }

            if(res.qr&&res.pay_url){
                if(res.qr_url){
                    // 先显示占位，后台预加载网关二维码图片
                    showLoading();
                    var preload=new Image();
                    preload.onload=function(){
                        box.innerHTML='';
                        var img=document.createElement('img');
                        img.src=res.qr_url;
                        img.alt='支付二维码';
                        img.style.maxWidth='220px';
                        img.style.display='block';
                        img.style.margin='0 auto';
                        box.appendChild(img);
                        var tip=document.createElement('p');
                        tip.className='tp-paid-qr-tip';
                        tip.textContent='请使用手机扫码完成支付';
                        box.appendChild(tip);
                    };
                    preload.onerror=function(){
                        genQR(res.pay_url);
                    };
                    preload.src=res.qr_url;
                }else{
                    genQR(res.pay_url);
                }
            }else if(res.pay_url){
                box.innerHTML='<a class="tp-paid-btn" target="_blank" rel="noopener" href="'+escapeHtml(res.pay_url)+'">前往支付</a>';
            }else{
                box.innerHTML='<p class="tp-paid-msg">订单已创建，请联系管理员完成支付确认。</p>';
            }
        },
        startPolling:function(card,tradeNo){
            if(!card){return;}
            var action=card.getAttribute('data-status-action');
            if(!action){return;}
            var attempts=0;
            var maxAttempts=720;
            var timer=setInterval(function(){
                attempts+=1;
                fetch(action+'?trade_no='+encodeURIComponent(tradeNo),{credentials:'same-origin'})
                    .then(function(r){return r.json();})
                    .then(function(res){
                        if(res.success&&res.status==='paid'){
                            clearInterval(timer);
                            location.reload();
                            return;
                        }
                        if(attempts>=maxAttempts){
                            clearInterval(timer);
                            card.removeAttribute('data-tp-pending');
                            var btn=card.querySelector('.tp-paid-form button[type=submit]');
                            if(btn){btn.disabled=false;}
                            var msgEl=card.querySelector('[data-role=msg]');
                            if(msgEl){msgEl.innerHTML='支付确认超时，如已支付请刷新页面，或重新发起购买。';msgEl.className='tp-paid-msg is-err';}
                        }
                    })
                    .catch(function(){});
            },5000);
        },
        submitUnlock:function(form){
            this.post(form,function(res){
                if(res.success){
                    TypechoPaid.show(form,'验证成功，正在刷新页面...',1);
                    setTimeout(function(){location.reload();},600);
                    return;
                }
                TypechoPaid.show(form,res.msg||'验证失败',0);
            });
            return false;
        },
        selectPlan:function(cardEl){
            if(!cardEl){return;}
            var all=cardEl.parentNode.querySelectorAll('.tp-paid-plan-card');
            for(var i=0;i<all.length;i++){all[i].classList.remove('is-selected');}
            cardEl.classList.add('is-selected');
            var radio=cardEl.querySelector('input[type=radio]');
            if(radio){radio.checked=true;}
            var box=cardEl.closest('.tp-paid-card');
            var form=box?box.querySelector('.tp-paid-form'):null;
            var planKeyInput=form?form.querySelector('input[name=plan_key]'):null;
            if(planKeyInput){planKeyInput.value=cardEl.getAttribute('data-plan')||'';}
            // 更新价格显示 — 兼容不同主题的金额 DOM 结构
            var isSubscription=(cardEl.getAttribute('data-plan')||'')!=='';
            var newPrice=cardEl.getAttribute('data-price');
            if(box){
                var priceEl=box.querySelector('[data-role="price-display"]');
                if(priceEl){
                    if(isSubscription){
                        priceEl.style.display='none';
                    }else{
                        priceEl.style.display='';
                        if(newPrice){
                            var amountEl=priceEl.querySelector('.tp-paid-price-value,.tp-paid-price-amount,strong');
                            if(amountEl){
                                amountEl.textContent='￥'+newPrice;
                            }else{
                                priceEl.innerHTML='价格：<strong>￥'+newPrice+'</strong>';
                            }
                        }
                    }
                }
            }
        }
    };
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',boot);}else{boot();}
})();
</script>
SCRIPT;
    }

    public static function injectEditorHelper()
    {
        $configuredMethods = implode(',', array_keys(self::getPaymentChannels()));
        $defaultTheme = self::getOption('default_theme', 'default');
        $defaultDesc = self::getOption('default_paid_desc', '该文章为付费阅读内容，请先购买后查看全文。');

        $abEnabled = false;
        try {
            $plugins = Typecho_Plugin::export();
            $abEnabled = isset($plugins['activated']['AdminBeautify']);
        } catch (\Exception $e) {}
        ?>
        <script>
        (function () {
        var _isABAdmin = <?php echo $abEnabled ? 'true' : 'false'; ?>;
            function addPaidField(name, placeholder, value) {
                var names = document.querySelectorAll('input[name="fieldNames[]"]');
                for (var i = 0; i < names.length; i++) {
                    if ((names[i].value || '').trim() === name) {
                        var trExist = names[i].closest('tr');
                        if (trExist) {
                            var taExist = trExist.querySelector('textarea[name="fieldValues[]"]');
                            if (taExist && placeholder) {
                                taExist.placeholder = placeholder;
                            }
                        }
                        return;
                    }
                }

                var table = document.querySelector('#custom-field table tbody');
                if (!table) {
                    return;
                }

                var tr = document.createElement('tr');
                tr.innerHTML = '' +
                    '<td><input type="text" name="fieldNames[]" class="text-s w-100"></td>' +
                    '<td><select name="fieldTypes[]"><option value="str">字符</option><option value="int">整数</option><option value="float">小数</option></select></td>' +
                    '<td><textarea name="fieldValues[]" class="text-s w-100" rows="2"></textarea></td>' +
                    '<td><button type="button" class="btn btn-xs">删除</button></td>';

                table.appendChild(tr);

                var nameInput = tr.querySelector('input[name="fieldNames[]"]');
                var typeSelect = tr.querySelector('select[name="fieldTypes[]"]');
                var valueInput = tr.querySelector('textarea[name="fieldValues[]"]');
                var delBtn = tr.querySelector('button.btn-xs');

                nameInput.value = name;
                nameInput.readOnly = true;
                typeSelect.value = 'str';
                valueInput.placeholder = placeholder || '';
                if (typeof value !== 'undefined') {
                    valueInput.value = value;
                }

                delBtn.addEventListener('click', function () {
                    if (confirm('确认要删除此字段吗?')) {
                        tr.parentNode.removeChild(tr);
                    }
                });
            }

            function ensureExpand() {
                var btn = document.querySelector('#custom-field-expand i');
                var wrap = document.querySelector('#custom-field-expand');
                if (!btn || !wrap) return;
                if (btn.classList.contains('i-caret-right')) {
                    btn.classList.remove('i-caret-right');
                    btn.classList.add('i-caret-down');
                    if (wrap.parentNode) {
                        wrap.parentNode.classList.remove('fold');
                    }
                }
            }

            // ======== [Paid] 短代码编辑器按钮 ========
            function insertPaidShortcode() {
                var textarea = document.getElementById('text');
                if (!textarea) return;

                var shortcode = '[Paid]\n\n[Paid /]';
                // 尝试现代 API
                try {
                    textarea.focus();
                    if (document.execCommand('insertText', false, shortcode)) {
                        return;
                    }
                } catch (e) {}

                // Fallback: 手动拼接
                var start = textarea.selectionStart;
                var end = textarea.selectionEnd;
                var before = textarea.value.substring(0, start);
                var selected = textarea.value.substring(start, end);
                var after = textarea.value.substring(end);

                if (selected) {
                    // 有选中文字 → 包裹在 [Paid] 内
                    textarea.value = before + '[Paid]\n' + selected + '\n[Paid /]' + after;
                    textarea.selectionStart = start + 7; // '[Paid]\n'.length
                    textarea.selectionEnd = start + 7 + selected.length;
                } else {
                    // 无选中 → 光标放在短代码中间
                    textarea.value = before + shortcode + after;
                    var cursor = start + 7; // '[Paid]\n'.length
                    textarea.selectionStart = cursor;
                    textarea.selectionEnd = cursor;
                }
                textarea.focus();
            }

            function mountPaidToolbarBtn() {
                if (document.getElementById('wmd-typechopaid-paid-button')) return;

                // 策略 1: Markdown 编辑器工具栏 (#wmd-button-row)
                var row = document.getElementById('wmd-button-row');
                if (row) {
                    var buttons = row.querySelectorAll('.wmd-button');
                    var maxLeft = 0;
                    for (var i = 0; i < buttons.length; i++) {
                        var left = parseFloat(buttons[i].style.left) || 0;
                        if (left > maxLeft) maxLeft = left;
                    }

                    var li = document.createElement('li');
                    li.className = 'wmd-button';
                    li.id = 'wmd-typechopaid-paid-button';
                    li.title = '插入 Paid 付费内容短代码';
                    li.setAttribute('aria-label', '插入 Paid 付费内容短代码');
                    li.style.left = (maxLeft + 25) + 'px';
                    if (_isABAdmin) {
                        li.innerHTML = '<i class="material-icons-round" style="font-size:20px;line-height:1;display:block;background:none;background-image:none;">lock</i>';
                    } else {
                        li.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:block;width:20px;height:20px;color:#6366f1;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
                    }
                    li.addEventListener('click', function (e) { e.preventDefault(); insertPaidShortcode(); });
                    row.appendChild(li);
                    return;
                }

                // 策略 2: 其他编辑器 — 在 .url-slug 之后插入按钮
                var slug = document.querySelector('.url-slug');
                if (slug && slug.parentNode) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.id = 'wmd-typechopaid-paid-button';
                    btn.className = 'btn btn-xs';
                    btn.style.cssText = 'margin-right:5px;color:#6366f1;font-weight:700;';
                    btn.textContent = '[Paid]';
                    btn.title = '插入 [Paid] 付费内容短代码';
                    btn.addEventListener('click', function (e) { e.preventDefault(); insertPaidShortcode(); });
                    slug.parentNode.insertBefore(btn, slug.nextSibling);
                }
            }

            // ======== 初始化 ========
            function boot() {
                ensureExpand();
                addPaidField('paid_enable', '1 开启付费阅读，0 关闭', '0');
                addPaidField('paid_price', '价格，例如 9.90', '9.90');
                addPaidField('paid_methods', '可用渠道：<?php echo htmlspecialchars($configuredMethods, ENT_QUOTES, 'UTF-8'); ?>；留空使用全部已配置渠道', '');
                addPaidField('paid_theme', '主题 ID（themes 文件夹名）；留空使用插件默认主题（当前：<?php echo htmlspecialchars($defaultTheme, ENT_QUOTES, 'UTF-8'); ?>）', '');
                addPaidField('paid_desc', '留空使用插件默认提示文案：<?php echo htmlspecialchars($defaultDesc, ENT_QUOTES, 'UTF-8'); ?>', '');
                addPaidField('paid_plan', '订阅计划标识（如 monthly）；留空则为单篇付费', '');
                addPaidField('paid_theme_options', '主题高级设置：字段:值（一行一个）', '');
                mountPaidToolbarBtn();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }
        })();
        </script>
        <?php
    }

    public static function filterContent($text, $widget, $lastResult)
    {
        $text = empty($lastResult) ? $text : $lastResult;

        if (!($widget instanceof Widget_Archive)) {
            return $text;
        }

        // 先判断是否为付费文章（非付费文章原样返回，不受 RSS 等影响）
        if (!isset($widget->fields) || !isset($widget->fields->paid_enable) || intval($widget->fields->paid_enable) !== 1) {
            return $text;
        }

        // 检测短代码 [Paid]…[Paid /] — 部分付费模式
        $hasPaidTag = (strpos($text, '[Paid]') !== false);
        if ($hasPaidTag) {
            return self::handlePartialPaidContent($text, $widget);
        }

        // 文章作者本人登录后免付费查看（全篇付费模式）
        if (self::isCurrentUserAuthor($widget)) {
            return $text;
        }

        // RSS / Atom / feed 中不输出完整的付费卡片，仅替换为提示文字
        $isFeed = method_exists($widget, 'is') && $widget->is('feed');
        if ($isFeed) {
            $tip = isset($widget->fields->paid_desc) && trim($widget->fields->paid_desc) !== ''
                ? trim($widget->fields->paid_desc)
                : self::getOption('default_paid_desc', '该文章为付费阅读内容，请先购买后查看全文。');
            return '<p><strong>[' . _t('付费阅读') . ']</strong> ' . htmlspecialchars($tip) . '</p>'
                . '<p><a href="' . htmlspecialchars($widget->permalink) . '">' . _t('点击访问原文') . '</a></p>';
        }

        if (method_exists($widget, 'is') && !$widget->is('single') && !$widget->is('page')) {
            // 非文章单页/独立页面（如首页、归档、分类等），劫持内容为购买提示
            $tip = isset($widget->fields->paid_desc) && trim($widget->fields->paid_desc) !== ''
                ? trim($widget->fields->paid_desc)
                : self::getOption('default_paid_desc', '该文章为付费阅读内容，请先购买后查看全文。');
            $permalink = htmlspecialchars($widget->permalink);
            return '<p><strong>[' . _t('付费阅读') . ']</strong> ' . htmlspecialchars($tip) . '</p>'
                . '<p><a href="' . $permalink . '">' . _t('点击访问原文') . '</a></p>';
        }

        $cid = intval($widget->cid);
        if ($cid <= 0) {
            return $text;
        }

        if (self::isUnlockedByCookie($cid, isset($widget->fields) ? $widget->fields : null)) {
            // 已解锁文章顶部显示购买详情横幅
            $banner = self::buildUnlockBanner($cid, isset($widget->fields) ? $widget->fields : null);
            return $banner . $text;
        }

        $price = self::normalizePrice(isset($widget->fields->paid_price) ? $widget->fields->paid_price : '0');
        $channels = self::getPaymentChannels();
        $methods = self::normalizeMethods(isset($widget->fields->paid_methods) ? $widget->fields->paid_methods : '');
        if (empty($methods)) {
            $methods = array_keys($channels);
        }
        // 展开简写驱动名到复合 key（如 epay → epay:alipay, epay:wxpay）
        $expanded = array();
        foreach ($methods as $method) {
            if (isset($channels[$method])) {
                $expanded[] = $method;
            } else {
                $prefix = $method . ':';
                foreach (array_keys($channels) as $key) {
                    if (strpos($key, $prefix) === 0) {
                        $expanded[] = $key;
                    }
                }
                // 如果简写名未匹配到任何复合 key，且也不是精确 key，则丢弃
            }
        }
        $methods = array_values(array_unique($expanded));

        $themeValue = isset($widget->fields->paid_theme) ? trim((string)$widget->fields->paid_theme) : '';
        $themeId = self::resolveThemeId($themeValue === '' ? self::getOption('default_theme', 'default') : $themeValue);

        $tip = isset($widget->fields->paid_desc) && trim($widget->fields->paid_desc) !== ''
            ? trim($widget->fields->paid_desc)
            : self::getOption('default_paid_desc', '该文章为付费阅读内容，请先购买后查看全文。');

        $globalThemeOptions = self::parseLineOptions(self::getOption('theme_advanced_options', ''));
        $contentThemeOptions = self::parseLineOptions(isset($widget->fields->paid_theme_options) ? $widget->fields->paid_theme_options : '');
        $themeOptions = array_merge($globalThemeOptions, $contentThemeOptions);

        $previewEnabled = intval(self::getOption('preview_enable', '1')) === 1;
        $previewLength = intval(self::getOption('preview_length', '200'));
        if ($previewLength <= 0) {
            $previewLength = 200;
        }
        $preview = $previewEnabled ? self::buildPreview($text, $previewLength) : '';
        $actionBase = Typecho_Common::url('typechopaid', Helper::options()->index);

        $methodHtml = '';
        foreach ($methods as $method) {
            $methodHtml .= '<label class="tp-paid-chip"><input type="radio" name="tp_paid_channel" value="' . htmlspecialchars($method) . '"' . ($methodHtml === '' ? ' checked' : '') . '> ' . htmlspecialchars($channels[$method]['name']) . '</label>';
        }

        $turnstileHtml = '';
        $turnstileEnabled = intval(self::getOption('turnstile_enable', '0')) === 1;
        $turnstileSiteKey = trim((string)self::getOption('turnstile_site_key', ''));
        if ($turnstileEnabled && $turnstileSiteKey !== '') {
            $turnstileThreshold = intval(self::getOption('turnstile_daily_threshold', '3'));
            if ($turnstileThreshold <= 0) {
                $turnstileThreshold = 3;
            }
            $ip = self::clientIp();
            if (self::dailyIpOrderCount($ip) >= $turnstileThreshold) {
                $turnstileHtml = '<div class="tp-paid-row"><div class="cf-turnstile" data-sitekey="' . htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8') . '"></div></div>';
            }
        }

        $themeMode = (string)self::getOption('paid_theme_mode', 'auto');
        if (!in_array($themeMode, array('dark', 'light', 'auto'), true)) {
            $themeMode = 'auto';
        }
        $themeModeSwitch = trim((string)self::getOption('paid_theme_mode_switch', 'system'));
        if ($themeModeSwitch === '') {
            $themeModeSwitch = 'system';
        }

        // 订阅计划 + 单独购买（统一样式的卡片列表）
        $articlePlanKeys = array();
        if (isset($widget->fields->paid_plan) && trim((string)$widget->fields->paid_plan) !== '') {
            $articlePlanKeys = array_map('trim', explode(',', (string)$widget->fields->paid_plan));
        }
        $allPlans = self::getSubscriptionPlans();
        $planHtml = '';
        $planLabel = '';
        $hasPlans = !empty($articlePlanKeys);
        $hasPrice = $price > 0;

        // 构建计划名称标签
        if ($hasPlans && !empty($allPlans)) {
            $planNamesDisplay = array();
            foreach ($articlePlanKeys as $pk) {
                if (isset($allPlans[$pk])) {
                    $planNamesDisplay[] = $allPlans[$pk]['name'];
                }
            }
            if (!empty($planNamesDisplay)) {
                $planLabel = '<div class="tp-paid-plan-label">本文包含于：<strong>' . htmlspecialchars(implode('、', $planNamesDisplay)) . '</strong></div>';
            }
        }

        // 构建卡片列表：有单篇价格时插入"单独购买"卡片，然后是各订阅计划卡片
        $planCards = array();
        $defaultSelected = null;

        if ($hasPrice) {
            // 单独购买文章卡片
            $planCards[] = array(
                'key' => '',  // 空 key 表示单独购买
                'name' => '单独购买文章',
                'duration' => '',
                'priceDisplay' => '￥' . number_format($price, 2),
                'priceRaw' => number_format($price, 2),
                'isSingle' => true,
            );
            $defaultSelected = '';  // 默认选中单独购买
        }

        if ($hasPlans && !empty($allPlans)) {
            foreach ($articlePlanKeys as $pk) {
                if (!isset($allPlans[$pk])) continue;
                $p = $allPlans[$pk];
                $planCards[] = array(
                    'key' => $p['key'],
                    'name' => $p['name'],
                    'duration' => intval($p['duration_days']) . '天',
                    'priceDisplay' => '￥' . number_format($p['price'], 2),
                    'priceRaw' => number_format($p['price'], 2),
                    'isSingle' => false,
                );
                if ($defaultSelected === null && !$hasPrice) {
                    $defaultSelected = $p['key'];  // 无价格时默认选第一个计划
                }
            }
        }

        if (!empty($planCards)) {
            $planHtml = '<div class="tp-paid-plans">';
            foreach ($planCards as $card) {
                $isSelected = ($card['key'] === $defaultSelected);
                $selectedClass = $isSelected ? ' is-selected' : '';
                $checked = $isSelected ? ' checked' : '';
                $planHtml .= '<label class="tp-paid-plan-card' . $selectedClass . '" data-plan="' . htmlspecialchars($card['key']) . '" data-price="' . htmlspecialchars($card['priceRaw']) . '" onclick="TypechoPaid.selectPlan(this)">'
                    . '<input type="radio" name="tp_paid_plan" value="' . htmlspecialchars($card['key']) . '"' . $checked . '>'
                    . '<span class="tp-paid-plan-name">' . htmlspecialchars($card['name']) . '</span>';
                if ($card['duration'] !== '') {
                    $planHtml .= '<span class="tp-paid-plan-duration">' . htmlspecialchars($card['duration']) . '</span>';
                }
                $planHtml .= '<span class="tp-paid-plan-price">' . $card['priceDisplay'] . '</span>'
                    . '</label>';
            }
            $planHtml .= '<input type="hidden" name="tp_paid_plan" value="">';
            $planHtml .= '</div>';
        }

        // 决定是否显示单篇价格行
        $showSingleBuy = $hasPrice;

        // 生成优惠码 HTML（仅当文章有适用优惠码时）
        $articleCoupons = self::getCouponsForArticle(intval($cid));
        $couponHtml = self::renderCouponHtml($articleCoupons);

        $themeAsset = self::themeAssetHtml($themeId);
        $themeVars = self::buildThemeStyleVars($themeOptions);

        $templateHtml = self::renderThemeHtml($themeId, array(
            'theme_id' => htmlspecialchars($themeId),
            'preview' => $preview,
            'title' => '付费阅读',
            'desc' => htmlspecialchars($tip),
            'price' => $showSingleBuy ? number_format($price, 2) : '',
            'show_price' => $showSingleBuy ? '1' : '0',
            'plan_label' => $planLabel,
            'cid' => intval($cid),
            'create_action' => htmlspecialchars($actionBase . '/create'),
            'unlock_action' => htmlspecialchars($actionBase . '/unlock'),
            'status_action' => htmlspecialchars($actionBase . '/status'),
            'subscribe_action' => htmlspecialchars($actionBase . '/subscribe'),
            'methods_html' => ($methodHtml === '' ? '<span class="tp-paid-msg is-err">管理员尚未配置可用支付渠道。</span>' : $methodHtml),
            'buy_disabled' => ($methodHtml === '' ? ' disabled' : ''),
            'turnstile_html' => $turnstileHtml,
            'plan_html' => $planHtml,
            'coupon_html' => $couponHtml,
            'theme_mode' => htmlspecialchars($themeMode, ENT_QUOTES, 'UTF-8'),
            'theme_mode_switch' => htmlspecialchars($themeModeSwitch, ENT_QUOTES, 'UTF-8'),
            'theme_style_vars' => htmlspecialchars($themeVars),
            'theme_options_json' => htmlspecialchars(json_encode($themeOptions), ENT_QUOTES, 'UTF-8')
        ));

        // 不直接输出卡片 HTML：部分主题会将正文再次交给 Markdown 或 JSON-LD 生成器，
        // 导致模板源码以纯文本形式泄漏到页面。使用 base64 占位后由浏览器恢复 DOM。
        $payload = base64_encode($themeAsset . $templateHtml);
        $html = '<span class="tp-paid-placeholder" data-tp-paid="' . htmlspecialchars($payload, ENT_QUOTES, 'UTF-8') . '"></span>';

        return $html;
    }

    /**
     * 判断当前登录用户是否为文章作者
     * @param Widget_Archive $widget
     * @return bool
     */
    public static function isCurrentUserAuthor($widget)
    {
        if (!isset($widget->authorId)) {
            return false;
        }
        try {
            $user = Typecho_Widget::widget('Widget_User');
            return $user->hasLogin() && intval($user->uid) === intval($widget->authorId);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 处理 [Paid] 短代码 — 部分付费模式
     * @param string $text
     * @param Widget_Archive $widget
     * @return string
     */
    public static function handlePartialPaidContent($text, $widget)
    {
        $cid = intval($widget->cid);
        $isFeed = method_exists($widget, 'is') && $widget->is('feed');
        $isSingle = method_exists($widget, 'is') && ($widget->is('single') || $widget->is('page'));

        // 解析短代码
        $blocks = self::parsePaidBlocks($text);

        // 作者或已解锁用户 → 显示全部内容（移除短代码标签）
        if (self::isCurrentUserAuthor($widget) || self::isUnlockedByCookie($cid, isset($widget->fields) ? $widget->fields : null)) {
            $banner = self::isUnlockedByCookie($cid, isset($widget->fields) ? $widget->fields : null)
                ? self::buildUnlockBanner($cid, isset($widget->fields) ? $widget->fields : null) : '';
            $out = '';
            foreach ($blocks as $block) {
                $out .= $block['content'];
            }
            return $banner . $out;
        }

        // RSS 模式
        if ($isFeed) {
            return self::renderPartialFeedContent($blocks, $widget);
        }

        // 列表/归档模式
        if (!$isSingle) {
            return self::renderPartialListContent($blocks, $widget);
        }

        // 单页模式 — 渲染部分付费
        return self::renderPartialSingleContent($blocks, $widget);
    }

    /**
     * 解析 [Paid]…[Paid /] 短代码块
     * @return array 每个元素包含 type ('free'|'paid') 和 content
     */
    private static function parsePaidBlocks($text)
    {
        $blocks = array();
        $pattern = '/\[Paid\s*\](.*?)\[Paid\s*\/\]/s';
        $lastPos = 0;

        if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $m) {
                $content = $m[1][0];
                $startPos = $m[0][1];
                $endPos = $startPos + strlen($m[0][0]);

                // 短代码之前的普通内容 — 免费
                if ($startPos > $lastPos) {
                    $before = substr($text, $lastPos, $startPos - $lastPos);
                    $before = trim($before);
                    if ($before !== '') {
                        $blocks[] = array('type' => 'free', 'content' => $before);
                    }
                }

                // 短代码内内容 — 付费
                $blocks[] = array('type' => 'paid', 'content' => $content);

                $lastPos = $endPos;
            }
        }

        // 最后的普通内容 — 免费
        if ($lastPos < strlen($text)) {
            $after = substr($text, $lastPos);
            $after = trim($after);
            if ($after !== '') {
                $blocks[] = array('type' => 'free', 'content' => $after);
            }
        }

        // 没有任何短代码匹配 → 整个内容为免费
        if (empty($blocks)) {
            $blocks[] = array('type' => 'free', 'content' => $text);
        }

        return $blocks;
    }

    /**
     * RSS 部分付费渲染
     */
    private static function renderPartialFeedContent($blocks, $widget)
    {
        $tip = isset($widget->fields->paid_desc) && trim($widget->fields->paid_desc) !== ''
            ? trim($widget->fields->paid_desc)
            : self::getOption('default_paid_desc', '付费内容，请购买后查看。');
        $permalink = htmlspecialchars($widget->permalink);
        $out = '';
        foreach ($blocks as $block) {
            if ($block['type'] === 'free') {
                $out .= $block['content'];
            } else {
                $out .= '<p><strong>[' . _t('付费阅读') . ']</strong> ' . htmlspecialchars($tip) . '</p>';
            }
        }
        $out .= '<p><a href="' . $permalink . '">' . _t('点击访问原文查看完整内容') . '</a></p>';
        return $out;
    }

    /**
     * 列表/归档模式部分付费渲染
     */
    private static function renderPartialListContent($blocks, $widget)
    {
        $tip = isset($widget->fields->paid_desc) && trim($widget->fields->paid_desc) !== ''
            ? trim($widget->fields->paid_desc)
            : self::getOption('default_paid_desc', '该文章为付费阅读内容，请先购买后查看全文。');
        $permalink = htmlspecialchars($widget->permalink);
        $out = '';
        $hasPaid = false;
        foreach ($blocks as $block) {
            if ($block['type'] === 'free') {
                $out .= $block['content'];
            } else {
                $hasPaid = true;
            }
        }
        if ($hasPaid) {
            $out .= '<p><strong>[' . _t('付费阅读') . ']</strong> ' . htmlspecialchars($tip) . '</p>'
                . '<p><a href="' . $permalink . '">' . _t('点击访问原文') . '</a></p>';
        }
        return $out;
    }

    /**
     * 单页部分付费渲染（占位符内联，购买卡片放在文章底部）
     */
    private static function renderPartialSingleContent($blocks, $widget)
    {
        $cid = intval($widget->cid);

        // 获取主题信息（与 buildPartialPaidCard 共享）
        $themeValue = isset($widget->fields->paid_theme) ? trim((string)$widget->fields->paid_theme) : '';
        $themeId = self::resolveThemeId($themeValue === '' ? self::getOption('default_theme', 'default') : $themeValue);
        $globalThemeOptions = self::parseLineOptions(self::getOption('theme_advanced_options', ''));
        $contentThemeOptions = self::parseLineOptions(isset($widget->fields->paid_theme_options) ? $widget->fields->paid_theme_options : '');
        $themeOptions = array_merge($globalThemeOptions, $contentThemeOptions);
        $themeVars = self::buildThemeStyleVars($themeOptions);
        $themeMode = (string)self::getOption('paid_theme_mode', 'auto');
        $themeModeSwitch = trim((string)self::getOption('paid_theme_mode_switch', 'system'));
        if ($themeModeSwitch === '') $themeModeSwitch = 'system';

        $contentHtml = '';
        $hasPaid = false;

        foreach ($blocks as $block) {
            if ($block['type'] === 'free') {
                $contentHtml .= $block['content'];
            } else {
                $hasPaid = true;
                $contentHtml .= self::renderPaidPlaceholder($themeId, $themeVars, $themeMode, $themeModeSwitch);
            }
        }

        if (!$hasPaid) {
            return $contentHtml;
        }

        // 文章末尾放购买卡片
        $paidCardHtml = self::buildPartialPaidCard($widget);
        return $contentHtml . $paidCardHtml;
    }

    /**
     * 内联付费内容占位符（适配主题亮暗色）
     */
    private static function renderPaidPlaceholder($themeId, $themeVars, $themeMode, $themeModeSwitch)
    {
        return '<div class="tp-paid-inline-placeholder tp-paid-theme-' . htmlspecialchars($themeId) . '"'
            . ' style="' . htmlspecialchars($themeVars) . '"'
            . ' data-mode="' . htmlspecialchars($themeMode) . '"'
            . ' data-mode-switch="' . htmlspecialchars($themeModeSwitch) . '"'
            . '>'
            . '<svg class="tp-paid-placeholder-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>'
            . '<path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
            . '</svg>'
            . '<span class="tp-paid-placeholder-text">' . _t('此处为付费内容，请购买后查看') . '</span>'
            . '</div>';
    }

    /**
     * 构建部分付费的购买卡片
     */
    private static function buildPartialPaidCard($widget)
    {
        $cid = intval($widget->cid);
        $price = self::normalizePrice(isset($widget->fields->paid_price) ? $widget->fields->paid_price : '0');
        $channels = self::getPaymentChannels();
        $methods = self::normalizeMethods(isset($widget->fields->paid_methods) ? $widget->fields->paid_methods : '');
        if (empty($methods)) {
            $methods = array_keys($channels);
        }
        $expanded = array();
        foreach ($methods as $method) {
            if (isset($channels[$method])) {
                $expanded[] = $method;
            } else {
                $prefix = $method . ':';
                foreach (array_keys($channels) as $key) {
                    if (strpos($key, $prefix) === 0) {
                        $expanded[] = $key;
                    }
                }
            }
        }
        $methods = array_values(array_unique($expanded));

        $themeValue = isset($widget->fields->paid_theme) ? trim((string)$widget->fields->paid_theme) : '';
        $themeId = self::resolveThemeId($themeValue === '' ? self::getOption('default_theme', 'default') : $themeValue);

        $tip = isset($widget->fields->paid_desc) && trim($widget->fields->paid_desc) !== ''
            ? trim($widget->fields->paid_desc)
            : self::getOption('default_paid_desc', '该文章为付费阅读内容，请先购买后查看全文。');

        $globalThemeOptions = self::parseLineOptions(self::getOption('theme_advanced_options', ''));
        $contentThemeOptions = self::parseLineOptions(isset($widget->fields->paid_theme_options) ? $widget->fields->paid_theme_options : '');
        $themeOptions = array_merge($globalThemeOptions, $contentThemeOptions);

        // 短代码模式：不显示正文预览，仅显示购买提示
        $preview = '';
        $actionBase = Typecho_Common::url('typechopaid', Helper::options()->index);

        $methodHtml = '';
        foreach ($methods as $method) {
            $methodHtml .= '<label class="tp-paid-chip"><input type="radio" name="tp_paid_channel" value="' . htmlspecialchars($method) . '"' . ($methodHtml === '' ? ' checked' : '') . '> ' . htmlspecialchars($channels[$method]['name']) . '</label>';
        }

        $turnstileHtml = '';
        $turnstileEnabled = intval(self::getOption('turnstile_enable', '0')) === 1;
        $turnstileSiteKey = trim((string)self::getOption('turnstile_site_key', ''));
        if ($turnstileEnabled && $turnstileSiteKey !== '') {
            $threshold = intval(self::getOption('turnstile_daily_threshold', '3'));
            if ($threshold <= 0) $threshold = 3;
            $ip = self::clientIp();
            if (self::dailyIpOrderCount($ip) >= $threshold) {
                $turnstileHtml = '<div class="tp-paid-row"><div class="cf-turnstile" data-sitekey="' . htmlspecialchars($turnstileSiteKey, ENT_QUOTES, 'UTF-8') . '"></div></div>';
            }
        }

        $themeMode = (string)self::getOption('paid_theme_mode', 'auto');
        $themeModeSwitch = trim((string)self::getOption('paid_theme_mode_switch', 'system'));
        if ($themeModeSwitch === '') $themeModeSwitch = 'system';

        // 订阅计划
        $articlePlanKeys = array();
        if (isset($widget->fields->paid_plan) && trim((string)$widget->fields->paid_plan) !== '') {
            $articlePlanKeys = array_map('trim', explode(',', (string)$widget->fields->paid_plan));
        }
        $allPlans = self::getSubscriptionPlans();
        $planLabel = '';
        $hasPlans = !empty($articlePlanKeys);
        $hasPrice = $price > 0;

        if ($hasPlans && !empty($allPlans)) {
            $planNamesDisplay = array();
            foreach ($articlePlanKeys as $pk) {
                if (isset($allPlans[$pk])) $planNamesDisplay[] = $allPlans[$pk]['name'];
            }
            if (!empty($planNamesDisplay)) {
                $planLabel = '<div class="tp-paid-plan-label">本文包含于：<strong>' . htmlspecialchars(implode('、', $planNamesDisplay)) . '</strong></div>';
            }
        }

        $planCards = array();
        $defaultSelected = null;
        if ($hasPrice) {
            $planCards[] = array('key' => '', 'name' => '单独购买文章', 'duration' => '', 'priceDisplay' => '￥' . number_format($price, 2), 'priceRaw' => number_format($price, 2), 'isSingle' => true);
            $defaultSelected = '';
        }
        if ($hasPlans && !empty($allPlans)) {
            foreach ($articlePlanKeys as $pk) {
                if (!isset($allPlans[$pk])) continue;
                $p = $allPlans[$pk];
                $planCards[] = array('key' => $p['key'], 'name' => $p['name'], 'duration' => intval($p['duration_days']) . '天', 'priceDisplay' => '￥' . number_format($p['price'], 2), 'priceRaw' => number_format($p['price'], 2), 'isSingle' => false);
                if ($defaultSelected === null && !$hasPrice) $defaultSelected = $p['key'];
            }
        }
        $planHtml = '';
        if (!empty($planCards)) {
            $planHtml = '<div class="tp-paid-plans">';
            foreach ($planCards as $card) {
                $isSelected = ($card['key'] === $defaultSelected);
                $planHtml .= '<label class="tp-paid-plan-card' . ($isSelected ? ' is-selected' : '') . '" data-plan="' . htmlspecialchars($card['key']) . '" data-price="' . htmlspecialchars($card['priceRaw']) . '" onclick="TypechoPaid.selectPlan(this)">'
                    . '<input type="radio" name="tp_paid_plan" value="' . htmlspecialchars($card['key']) . '"' . ($isSelected ? ' checked' : '') . '>'
                    . '<span class="tp-paid-plan-name">' . htmlspecialchars($card['name']) . '</span>';
                if ($card['duration'] !== '') $planHtml .= '<span class="tp-paid-plan-duration">' . htmlspecialchars($card['duration']) . '</span>';
                $planHtml .= '<span class="tp-paid-plan-price">' . $card['priceDisplay'] . '</span></label>';
            }
            $planHtml .= '<input type="hidden" name="tp_paid_plan" value=""></div>';
        }

        // 生成优惠码 HTML
        $articleCoupons = self::getCouponsForArticle(intval($cid));
        $couponHtml = self::renderCouponHtml($articleCoupons);

        $themeAsset = self::themeAssetHtml($themeId);
        $themeVars = self::buildThemeStyleVars($themeOptions);

        $templateHtml = self::renderThemeHtml($themeId, array(
            'theme_id' => htmlspecialchars($themeId),
            'preview' => $preview,
            'title' => '付费阅读',
            'desc' => htmlspecialchars($tip),
            'price' => $hasPrice ? number_format($price, 2) : '',
            'show_price' => $hasPrice ? '1' : '0',
            'plan_label' => $planLabel,
            'cid' => intval($cid),
            'create_action' => htmlspecialchars($actionBase . '/create'),
            'unlock_action' => htmlspecialchars($actionBase . '/unlock'),
            'status_action' => htmlspecialchars($actionBase . '/status'),
            'subscribe_action' => htmlspecialchars($actionBase . '/subscribe'),
            'methods_html' => ($methodHtml === '' ? '<span class="tp-paid-msg is-err">管理员尚未配置可用支付渠道。</span>' : $methodHtml),
            'buy_disabled' => ($methodHtml === '' ? ' disabled' : ''),
            'turnstile_html' => $turnstileHtml,
            'plan_html' => $planHtml,
            'coupon_html' => $couponHtml,
            'theme_mode' => htmlspecialchars($themeMode, ENT_QUOTES, 'UTF-8'),
            'theme_mode_switch' => htmlspecialchars($themeModeSwitch, ENT_QUOTES, 'UTF-8'),
            'theme_style_vars' => htmlspecialchars($themeVars),
            'theme_options_json' => htmlspecialchars(json_encode($themeOptions), ENT_QUOTES, 'UTF-8')
        ));

        $payload = base64_encode($themeAsset . $templateHtml);
        return '<span class="tp-paid-placeholder" data-tp-paid="' . htmlspecialchars($payload, ENT_QUOTES, 'UTF-8') . '"></span>';
    }

    public static function isUnlockedByCookie($cid, $fields = null)
    {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }

        $data = json_decode($_COOKIE[self::COOKIE_NAME], true);
        if (!is_array($data)) {
            return false;
        }

        // 先检查订阅：如果文章有所属计划（支持逗号分隔多个），任一有效即通过
        $paidPlan = self::fieldValue($fields, 'paid_plan');
        if ($paidPlan !== '') {
            $planKeys = array_map('trim', explode(',', $paidPlan));
            foreach ($planKeys as $planKey) {
                if ($planKey === '') continue;
                $entryKey = '_plan_' . $planKey;
                if (!empty($data[$entryKey]) && intval($data[$entryKey]) > time()) {
                    return true;
                }
            }
        }

        // 再检查按篇文章购买
        if (!empty($data[$cid]) && intval($data[$cid]) > time()) {
            return true;
        }

        return false;
    }

    public static function buildUnlockBanner($cid, $fields = null)
    {
        $banner = '<div class="tp-paid-banner" style="'
            . 'border-radius:var(--tp-radius,12px);'
            . 'font-size:0.92rem;'
            . 'display:flex;align-items:center;gap:0.5rem;'
            . '">';

        $paidPlan = self::fieldValue($fields, 'paid_plan');
        if ($paidPlan !== '') {
            $planKeys = array_map('trim', explode(',', $paidPlan));
            $plans = self::getSubscriptionPlans();
            $matchedPlanName = '';
            // 查找用户当前有效的订阅计划
            $data = !empty($_COOKIE[self::COOKIE_NAME]) ? json_decode($_COOKIE[self::COOKIE_NAME], true) : array();
            if (is_array($data)) {
                foreach ($planKeys as $pk) {
                    $entryKey = '_plan_' . $pk;
                    if (!empty($data[$entryKey]) && intval($data[$entryKey]) > time()) {
                        $matchedPlanName = isset($plans[$pk]) ? $plans[$pk]['name'] : $pk;
                        break;
                    }
                }
            }
            if ($matchedPlanName !== '') {
                $banner .= '您已通过订阅计划 <strong>' . htmlspecialchars($matchedPlanName) . '</strong> 解锁该文章';
            } else {
                $banner .= '您已购买该文章';
            }
        } else {
            $banner .= '您已购买该文章';
        }

        $banner .= '</div>';
        return $banner;
    }

    public static function setUnlockCookie($cid)
    {
        $cid = intval($cid);
        if ($cid <= 0) {
            return;
        }

        $days = intval(self::getOption('cookie_days', 30));
        if ($days <= 0) {
            $days = 30;
        }

        $expires = time() + ($days * 86400);
        $data = array();

        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $old = json_decode($_COOKIE[self::COOKIE_NAME], true);
            if (is_array($old)) {
                $data = $old;
            }
        }

        $data[$cid] = $expires;
        foreach ($data as $entryExpires) {
            $expires = max($expires, intval($entryExpires));
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, json_encode($data), $expires, '/', '', $secure, false);
        $_COOKIE[self::COOKIE_NAME] = json_encode($data);
    }

    public static function tableName()
    {
        return 'typechopaid_orders';
    }

    public static function installTable()
    {
        $db = Typecho_Db::get();
        $prefix = $db->getPrefix();
        $table = $prefix . self::tableName();

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` ("
            . "`id` INTEGER NOT NULL PRIMARY KEY AUTO_INCREMENT,"
            . "`cid` INTEGER NOT NULL DEFAULT 0,"
            . "`title` VARCHAR(255) NOT NULL DEFAULT '',"
            . "`email` VARCHAR(190) NOT NULL DEFAULT '',"
            . "`price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,"
            . "`channel` VARCHAR(64) NOT NULL DEFAULT '',"
            . "`trade_no` VARCHAR(64) NOT NULL DEFAULT '',"
            . "`visit_password` VARCHAR(128) NOT NULL DEFAULT '',"
            . "`status` VARCHAR(20) NOT NULL DEFAULT 'pending',"
            . "`pay_url` VARCHAR(500) NOT NULL DEFAULT '',"
            . "`notify_payload` TEXT NULL,"
            . "`ip` VARCHAR(64) NOT NULL DEFAULT '',"
            . "`ua` VARCHAR(255) NOT NULL DEFAULT '',"
            . "`plan_id` VARCHAR(64) NOT NULL DEFAULT '',"
            . "`expires_at` INTEGER NOT NULL DEFAULT 0,"
            . "`created` INTEGER NOT NULL DEFAULT 0,"
            . "`paid_at` INTEGER NOT NULL DEFAULT 0,"
            . "INDEX (`cid`),"
            . "INDEX (`email`),"
            . "INDEX (`plan_id`),"
            . "UNIQUE KEY `trade_no_unique` (`trade_no`)"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        try {
            $db->query($sql, Typecho_Db::WRITE);
            // 兼容旧表：尝试补充 plan_id / expires_at 列
            try {
                $db->query("ALTER TABLE `{$table}` ADD COLUMN `plan_id` VARCHAR(64) NOT NULL DEFAULT ''", Typecho_Db::WRITE);
            } catch (Exception $e) {}
            try {
                $db->query("ALTER TABLE `{$table}` ADD COLUMN `expires_at` INTEGER NOT NULL DEFAULT 0", Typecho_Db::WRITE);
            } catch (Exception $e) {}
            try {
                $db->query("ALTER TABLE `{$table}` ADD INDEX `idx_plan_id` (`plan_id`)", Typecho_Db::WRITE);
            } catch (Exception $e) {}
        } catch (Exception $e) {
            $sqlLite = "CREATE TABLE IF NOT EXISTS `{$table}` ("
                . "`id` INTEGER PRIMARY KEY AUTOINCREMENT,"
                . "`cid` INTEGER NOT NULL DEFAULT 0,"
                . "`title` TEXT NOT NULL DEFAULT '',"
                . "`email` TEXT NOT NULL DEFAULT '',"
                . "`price` REAL NOT NULL DEFAULT 0,"
                . "`channel` TEXT NOT NULL DEFAULT '',"
                . "`trade_no` TEXT NOT NULL DEFAULT '',"
                . "`visit_password` TEXT NOT NULL DEFAULT '',"
                . "`status` TEXT NOT NULL DEFAULT 'pending',"
                . "`pay_url` TEXT NOT NULL DEFAULT '',"
                . "`notify_payload` TEXT,"
                . "`ip` TEXT NOT NULL DEFAULT '',"
                . "`ua` TEXT NOT NULL DEFAULT '',"
                . "`plan_id` TEXT NOT NULL DEFAULT '',"
                . "`expires_at` INTEGER NOT NULL DEFAULT 0,"
                . "`created` INTEGER NOT NULL DEFAULT 0,"
                . "`paid_at` INTEGER NOT NULL DEFAULT 0"
                . ");";
            $db->query($sqlLite, Typecho_Db::WRITE);
        }
    }

    public static function normalizeMethods($methods)
    {
        $arr = array();
        $parts = preg_split('/[,\s]+/', strtolower(trim((string)$methods)));
        foreach ($parts as $it) {
            $it = trim($it);
            if ($it === '') {
                continue;
            }
            $arr[$it] = $it;
        }
        return array_values($arr);
    }

    public static function normalizePrice($price)
    {
        $price = floatval($price);
        if ($price < 0) {
            $price = 0;
        }
        return round($price, 2);
    }

    public static function methodLabel($method)
    {
        $map = array(
            'alipay_face' => '支付宝当面付',
            'wechat' => '微信支付',
            'epay' => '易支付'
        );

        return isset($map[$method]) ? $map[$method] : strtoupper($method);
    }

    /**
     * 设置面板分组数据（renderConfigCard 与 renderConfigCardJS 之间共享）
     * @var array
     */
    private static $configSections = array();

    /**
     * 渲染设置页可折叠分类面板头部
     * @param string $title      面板标题
     * @param string $desc       面板描述
     * @param array  $fieldNames 该分组的表单字段名列表（name 属性）
     */
    public static function renderConfigCard($title, $desc, $fieldNames = array(), $echo = true)
    {
        static $styleInjected = false;
        static $sectionIndex = 0;
        $sectionId = 'tp-config-section-' . (++$sectionIndex);

        self::$configSections[] = array('id' => $sectionId, 'fields' => $fieldNames);

        if (!$echo) {
            return;
        }

        if (!$styleInjected) {
            $styleInjected = true;
            echo '<style>'
                . '.tp-config-section{margin:20px 0 0;border-radius:12px;overflow:hidden;'
                . 'background:var(--md-surface-container-low,var(--md-surface,#fff));'
                . 'border:1px solid var(--md-outline-variant,#e0e0e0);'
                . 'box-shadow:0 1px 3px rgba(0,0,0,.05);}'
                . 'html[data-theme="dark"] .tp-config-section{'
                . 'background:var(--md-dark-surface-container-low,var(--md-surface,#1e1e2a));'
                . 'border-color:var(--md-dark-outline-variant,rgba(255,255,255,.08));}'
                . '.tp-config-section-header{'
                . 'display:flex;align-items:center;gap:12px;width:100%;padding:14px 20px;'
                . 'border:none;background:none;cursor:pointer;font:inherit;text-align:left;'
                . 'color:var(--md-on-surface,#1c1b1f);user-select:none;-webkit-user-select:none;'
                . 'transition:background .15s;}'
                . '.tp-config-section-header:hover{background:rgba(0,0,0,.03);}'
                . 'html[data-theme="dark"] .tp-config-section-header{color:var(--md-dark-on-surface,#e4e2ed);}'
                . 'html[data-theme="dark"] .tp-config-section-header:hover{background:rgba(255,255,255,.04);}'
                . '.tp-config-section-arrow{'
                . 'flex-shrink:0;width:20px;height:20px;display:flex;align-items:center;justify-content:center;'
                . 'transition:transform .25s cubic-bezier(.4,0,.2,1);}'
                . '.tp-config-section-arrow svg{width:20px;height:20px;fill:currentColor;opacity:.55;}'
                . '.tp-config-section.collapsed .tp-config-section-arrow{transform:rotate(-90deg);}'
                . '.tp-config-section-text{flex:1;min-width:0;}'
                . '.tp-config-section-text h3{margin:0;font-size:15px;font-weight:600;line-height:1.3;}'
                . '.tp-config-section-text p{margin:2px 0 0;font-size:12px;'
                . 'color:var(--md-on-surface-variant,#6b6b78);line-height:1.4;}'
                . 'html[data-theme="dark"] .tp-config-section-text p{color:var(--md-dark-on-surface-variant,#9d9caa);}'
                . '.tp-config-section-body{transition:max-height .35s cubic-bezier(.4,0,.2,1),opacity .25s;'
                . 'max-height:3000px;opacity:1;overflow:hidden;}'
                . '.tp-config-section.collapsed .tp-config-section-body{max-height:0;opacity:0;}'
                . '.tp-config-section-body .typecho-option,'
                . '.tp-config-section-body .typecho-option-title{'
                . 'margin:0;border:none;padding:8px 20px;}'
                . '.tp-config-section-body .typecho-option:last-child{padding-bottom:16px;}'
                . '</style>';
        }

        echo '<div class="tp-config-section collapsed" id="' . $sectionId . '">'
            . '<button type="button" class="tp-config-section-header" aria-expanded="false" onclick="TPConfigCard.toggle(this)">'
            . '<span class="tp-config-section-arrow">'
            . '<svg viewBox="0 0 24 24"><path d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>'
            . '</span>'
            . '<span class="tp-config-section-text">'
            . '<h3>' . htmlspecialchars($title) . '</h3>'
            . '<p>' . htmlspecialchars($desc) . '</p>'
            . '</span>'
            . '</button>'
            . '<div class="tp-config-section-body"></div>'
            . '</div>';

    }

    /**
     * 注入设置面板折叠 JS，按字段名将表单项移入对应面板
     * 应在 config() 末尾、所有 renderConfigCard 调用之后调用一次
     */
    public static function renderConfigCardJS($echo = true)
    {
        static $injected = false;
        if ($injected) return;
        if (empty(self::$configSections)) return;

        if (!$echo) return;

        $injected = true;
        $sectionsJson = json_encode(self::$configSections, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        echo '<script>'
            . '(function(){'
            . 'var SECTIONS=' . $sectionsJson . ';'
            . 'var TPConfigCard={'
            . 'init:function(){'
            . 'var form=document.querySelector(".typecho-page-main form");'
            . 'if(!form)form=document.querySelector("form");'
            . 'if(!form)return;'
            . 'if(!form.id){form.id="tp-config-form";}'
            . 'for(var i=0;i<SECTIONS.length;i++){'
            . 'var sec=SECTIONS[i];'
            . 'var wrapper=document.getElementById(sec.id);'
            . 'if(!wrapper)continue;'
            . 'var body=wrapper.querySelector(".tp-config-section-body");'
            . 'if(!body)continue;'
            . 'var fields=sec.fields;'
            . 'for(var j=0;j<fields.length;j++){'
            . 'var name=fields[j];'
            . 'var el=form.querySelector("[name=\'"+name+"\']");'
            . 'if(!el)continue;'
            . 'var opt=el.closest(".typecho-option,.typecho-option-title");'
            . 'if(opt){'
            . 'body.appendChild(opt);'
            . 'var controls=opt.querySelectorAll("input,select,textarea,button");'
            . 'for(var k=0;k<controls.length;k++){'
            . 'controls[k].setAttribute("form","tp-config-form");'
            . '}'
            . '}'
            . '}'
            . '}'
            . '},'
            . 'toggle:function(btn){'
            . 'var sec=btn.closest(".tp-config-section");'
            . 'if(!sec)return;'
            . 'sec.classList.toggle("collapsed");'
            . 'btn.setAttribute("aria-expanded",!sec.classList.contains("collapsed"));'
            . '}'
            . '};'
            . 'window.TPConfigCard=TPConfigCard;'
            . 'if(document.readyState==="loading"){'
            . 'document.addEventListener("DOMContentLoaded",function(){TPConfigCard.init();});'
            . '}else{TPConfigCard.init();}'
            . '})();'
            . '</script>';
    }

    /**
     * 输出卡片包裹容器（开标签）
     * 隔离 .container 的 flex 布局，确保内部卡片正常排列
     * @return string
     */
    public static function renderCardsWrapperOpen()
    {
        return '<style>
.tp-paid-cards-wrapper{display:block!important;width:100%!important;max-width:100%!important;flex:0 0 100%!important;box-sizing:border-box!important;margin:0!important;padding:0!important;}
</style>
<div class="tp-paid-cards-wrapper">';
    }

    /**
     * 输出卡片包裹容器（闭标签）
     * @return string
     */
    public static function renderCardsWrapperClose()
    {
        return '</div>';
    }

    /**
     * 生成 AB-Admin（AdminBeautify）推广卡片
     * @return string
     */
    public static function renderABPromo()
    {
        // 已启用 AB-Admin 则不显示推广
        try {
            $plugins = Typecho_Plugin::export();
            if (isset($plugins['activated']['AdminBeautify'])) {
                return '';
            }
        } catch (\Exception $e) {}

        $abUrl = 'https://github.com/lhl77/Typecho-Plugin-AdminBeautify';
        $abSite = 'https://see.lhl.one/Typecho-AB-Admin';

        return '
<style>
.tp-ab-promo{display:block;margin:0 0 16px!important;border-radius:12px!important;overflow:hidden!important;background:linear-gradient(135deg,#6750a4 0%,#7c5cb8 40%,#9a7fd4 100%)!important;color:#fff!important;box-shadow:0 2px 12px rgba(103,80,164,.25)!important;width:100%!important;max-width:100%!important;flex:0 0 100%!important;box-sizing:border-box!important;}
.tp-ab-promo-bar{display:flex!important;flex-direction:row!important;align-items:center!important;gap:12px!important;padding:14px 20px!important;width:100%!important;box-sizing:border-box!important;}
.tp-ab-promo-text{flex:1 1 auto!important;min-width:0!important;display:block!important;}
.tp-ab-promo-text h4{margin:0 0 3px!important;font-size:14px!important;font-weight:700!important;line-height:1.3!important;display:block!important;}
.tp-ab-promo-text p{margin:0 0 8px!important;font-size:12px!important;opacity:.82!important;line-height:1.5!important;display:block!important;}
.tp-ab-promo-actions{display:flex!important;flex-direction:row!important;align-items:center!important;gap:8px!important;flex-wrap:wrap!important;}
.tp-ab-promo-btn{display:inline-flex!important;align-items:center!important;gap:5px!important;padding:7px 16px!important;border-radius:20px!important;background:rgba(255,255,255,.18)!important;color:#fff!important;font-size:12px!important;font-weight:600!important;text-decoration:none!important;border:1px solid rgba(255,255,255,.3)!important;transition:background .2s;white-space:nowrap!important;cursor:pointer!important;}
.tp-ab-promo-btn:hover{background:rgba(255,255,255,.28)!important;color:#fff!important;}
.tp-ab-promo-detail{display:none;padding:0 20px 16px!important;border-top:1px solid rgba(255,255,255,.15)!important;}
.tp-ab-promo-detail.show{display:block!important;}
.tp-ab-promo-detail-title{font-size:13px!important;font-weight:700!important;margin:14px 0 6px!important;display:block!important;}
.tp-ab-promo-detail-desc{font-size:12px!important;opacity:.85!important;line-height:1.6!important;margin:0 0 14px!important;display:block!important;}
.tp-ab-promo-detail-desc a{color:#fff!important;text-decoration:underline!important;}
.tp-ab-promo-grid{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:10px!important;}
.tp-ab-promo-item{background:rgba(255,255,255,.1)!important;border-radius:10px!important;padding:8px!important;text-align:center!important;display:block!important;}
.tp-ab-promo-item h5{font-size:11px!important;font-weight:600!important;margin:0 0 3px!important;line-height:1.4!important;display:block!important;}
.tp-ab-promo-item p{font-size:10px!important;opacity:.75!important;margin:0 0 6px!important;line-height:1.4!important;display:block!important;}
.tp-ab-promo-item img{width:100%!important;border-radius:6px!important;cursor:pointer!important;transition:transform .2s;display:block!important;}
.tp-ab-promo-item img:hover{transform:scale(1.02);}
.tp-ab-promo-overlay{display:none;position:fixed!important;inset:0!important;z-index:99999!important;background:rgba(0,0,0,.85)!important;align-items:center!important;justify-content:center!important;cursor:pointer!important;}
.tp-ab-promo-overlay.show{display:flex!important;}
.tp-ab-promo-overlay img{max-width:92vw!important;max-height:92vh!important;border-radius:10px!important;box-shadow:0 8px 40px rgba(0,0,0,.5)!important;}
.tp-ab-dismiss-wrap{display:flex!important;justify-content:center!important;padding:4px 0 0!important;position:relative!important;min-height:32px!important;}
.tp-ab-dismiss-btn{display:inline-flex!important;align-items:center!important;gap:3px!important;padding:5px 14px!important;border-radius:16px!important;background:transparent!important;color:rgba(255,255,255,.55)!important;font-size:11px!important;cursor:pointer!important;border:1px solid rgba(255,255,255,.15)!important;white-space:nowrap!important;transition:all .35s cubic-bezier(.4,0,.2,1)!important;position:relative!important;}
.tp-ab-dismiss-btn:hover{color:rgba(255,255,255,.85)!important;border-color:rgba(255,255,255,.35)!important;}
.tp-ab-dismiss-btn.step-1{margin-left:auto!important;margin-right:0!important;color:rgba(255,255,255,.75)!important;border-color:rgba(255,255,255,.3)!important;}
.tp-ab-dismiss-btn.step-2{margin-left:0!important;margin-right:auto!important;color:#ffb74d!important;border-color:rgba(255,183,77,.5)!important;background:rgba(255,183,77,.12)!important;}
@media(max-width:768px){.tp-ab-promo-grid{grid-template-columns:repeat(2,1fr)!important;}.tp-ab-promo-bar{flex-direction:column!important;align-items:flex-start!important;}.tp-ab-promo-actions{width:100%!important;flex-direction:row!important;}.tp-ab-promo-btn{flex:1!important;justify-content:center!important;}}
@media(max-width:480px){.tp-ab-promo-grid{grid-template-columns:1fr!important;}}
</style>
<div class="tp-ab-promo" id="tp-ab-promo">
    <div class="tp-ab-promo-bar">
        <div class="tp-ab-promo-text">
            <h4>推荐安装 AB Admin</h4>
            <p>最美后台美化插件，基于 Material Design 3，让后台更美观更好用，内置 AB Store 可检测并一键更新本插件。</p>
            <div class="tp-ab-promo-actions">
                <a class="tp-ab-promo-btn" href="' . htmlspecialchars($abUrl) . '" target="_blank" rel="noopener">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z"/></svg>
                    GitHub
                </a>
                <button class="tp-ab-promo-btn" onclick="TPABPromo.toggleDetail()">查看详情 &gt;</button>
            </div>
        </div>
    </div>
    <div class="tp-ab-promo-detail" id="tp-ab-promo-detail">
        <div class="tp-ab-promo-detail-title">为什么推荐 AB Admin？</div>
        <div class="tp-ab-promo-detail-desc">
            AB Admin 是一款为 Typecho 打造的后台美化增强插件，基于 Material Design 3 风格设计，让后台更美观、更好用。<br>
            图文介绍：<a href="' . htmlspecialchars($abSite) . '" target="_blank">' . htmlspecialchars($abSite) . '</a> &nbsp;·&nbsp;
            <a href="' . htmlspecialchars($abUrl) . '" target="_blank">' . htmlspecialchars($abUrl) . '</a>
        </div>
        <div class="tp-ab-promo-grid">
            <div class="tp-ab-promo-item">
                <h5>更优雅的编辑器</h5>
                <p>支持 Markdown 所见即所得，编辑体验大幅提升。</p>
                <img src="https://i.see.you/2026/07/23/p8Ua/298856ee9254e688f1ded9a6633874fc.jpg" alt="更优雅的编辑器" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>更方便直观的图片附件插入</h5>
                <p>支持更直观的附件选择和插入操作，降低编辑成本。</p>
                <img src="https://i.see.you/2026/07/15/soX9/e051af1be8482e28383c019079cf352a.jpg" alt="更方便直观的图片附件插入" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>支持多文件同时选择和上传</h5>
                <p>可一次选择多个文件并批量上传，显著提升素材整理效率。</p>
                <img src="https://i.see.you/2026/07/15/xX6y/e6a720fedf3c91da079582c19b2b84e0.jpg" alt="支持多文件同时选择和上传" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>支持调用其他文章中的图片或附件</h5>
                <p>插入附件时可跨文章检索并复用已有文件，避免重复上传。</p>
                <img src="https://i.see.you/2026/07/15/Kdw9/0ad28c9743dd686c381b9fc7ce840776.jpg" alt="支持调用其他文章中的图片或附件" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>直观的文件管理界面</h5>
                <p>更清晰的文件卡片与操作入口，日常维护与归档管理更高效。</p>
                <img src="https://i.see.you/2026/07/15/c6kF/2ab6f637f6029d05199377aaa5d432b4.jpg" alt="直观的文件管理界面" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>内置插件仓库</h5>
                <p>一键搜索、安装、更新各类 Typecho 插件。</p>
                <img src="https://i.see.you/2026/07/23/hY1m/83a01ba0452661afd6807cf70b2478ed.jpg" alt="内置插件仓库" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>内置主题仓库</h5>
                <p>在线浏览、安装和切换 Typecho 主题。</p>
                <img src="https://i.see.you/2026/07/23/q1Pu/213eefcbc1cc5f49bea0552dce6f8d82.jpg" alt="内置主题仓库" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
            <div class="tp-ab-promo-item">
                <h5>支持亮暗双色主题切换</h5>
                <p>自动跟随系统或手动切换，随时适配护眼需求。</p>
                <img src="https://i.see.you/2026/07/23/Ies1/5f645ac7a4cdc424ac24287b7aca6db7.jpg" alt="支持亮暗双色主题切换" loading="lazy" onclick="TPABPromo.preview(this.src)">
            </div>
        </div>
        <div class="tp-ab-dismiss-wrap">
            <button class="tp-ab-dismiss-btn" id="tp-ab-dismiss-btn" onclick="TPABPromo.dismissStep()">不再显示</button>
        </div>
    </div>
</div>
<div class="tp-ab-promo-overlay" id="tp-ab-promo-overlay" onclick="this.classList.remove(\'show\')">
    <img id="tp-ab-promo-overlay-img" src="" alt="">
</div>
<script>
(function(){
    var _dismissStep=0;
    var TPABPromo={
        toggleDetail:function(){
            var d=document.getElementById("tp-ab-promo-detail");
            if(d){d.classList.toggle("show");_dismissStep=0;this._resetDismissBtn();}
        },
        _resetDismissBtn:function(){
            var btn=document.getElementById("tp-ab-dismiss-btn");
            if(btn){btn.textContent="不再显示";btn.className="tp-ab-dismiss-btn";}
            _dismissStep=0;
        },
        dismissStep:function(){
            var btn=document.getElementById("tp-ab-dismiss-btn");
            if(!btn)return;
            _dismissStep++;
            if(_dismissStep===1){
                btn.textContent="真的不试试了？";
                btn.className="tp-ab-dismiss-btn step-1";
            }else if(_dismissStep===2){
                btn.textContent="确定不再显示";
                btn.className="tp-ab-dismiss-btn step-2";
            }else{
                var card=document.getElementById("tp-ab-promo");
                if(card)card.style.display="none";
                try{localStorage.setItem("tp-ab-promo-dismissed","1");}catch(e){}
            }
        },
        preview:function(src){
            var ov=document.getElementById("tp-ab-promo-overlay");
            var img=document.getElementById("tp-ab-promo-overlay-img");
            if(ov&&img){img.src=src;ov.classList.add("show");}
        }
    };
    window.TPABPromo=TPABPromo;
    try{if(localStorage.getItem("tp-ab-promo-dismissed")){var d=document.getElementById("tp-ab-promo");if(d)d.style.display="none";}}catch(e){}
})();
</script>';
    }

    /**
     * 生成文档引导卡片（可关闭，localStorage 记忆）
     * @return string
     */
    public static function renderDocPromo()
    {
        $docUrl = 'https://blog.lhl.one/artical/1309.html';

        return '
<style>
.tp-doc-promo{display:flex;flex-direction:row!important;flex-wrap:nowrap!important;align-items:center!important;gap:10px!important;margin:0 0 16px!important;border-radius:12px!important;padding:11px 16px!important;background:var(--md-surface-container,#f5f4f7)!important;border:1px solid var(--md-outline-variant,#e0e0e0)!important;color:var(--md-on-surface,#1c1b1f)!important;width:100%!important;max-width:100%!important;flex:0 0 100%!important;box-sizing:border-box!important;}
html[data-theme="dark"] .tp-doc-promo{background:var(--md-dark-surface-container,#2b2930)!important;border-color:var(--md-dark-outline-variant,#49454f)!important;color:var(--md-dark-on-surface,#e4e2ed)!important;}
.tp-doc-promo-icon{font-size:16px!important;flex-shrink:0!important;line-height:1!important;display:inline-block!important;}
.tp-doc-promo-text{flex:1 1 auto!important;font-size:13px!important;font-weight:500!important;min-width:0!important;display:flex!important;align-items:center!important;gap:4px!important;flex-wrap:wrap!important;}
.tp-doc-promo-text a{color:var(--md-primary,#6366f1)!important;font-weight:600!important;text-decoration:none!important;white-space:nowrap!important;}
.tp-doc-promo-text a:hover{text-decoration:underline!important;}
.tp-doc-promo-close{flex-shrink:0!important;background:none!important;border:none!important;color:var(--md-on-surface-variant,#6b6b78)!important;cursor:pointer!important;font-size:12px!important;padding:3px 8px!important;border-radius:12px!important;white-space:nowrap!important;}
.tp-doc-promo-close:hover{background:rgba(0,0,0,.06)!important;}
html[data-theme="dark"] .tp-doc-promo-close:hover{background:rgba(255,255,255,.06)!important;}
@media(max-width:768px){.tp-doc-promo{flex-wrap:wrap!important;padding:10px 14px!important;flex-direction:row!important;}.tp-doc-promo-close{margin-left:auto!important;}}
</style>
<div class="tp-doc-promo" id="tp-doc-promo">
    <span class="tp-doc-promo-text">第一次使用？快来看看<a href="' . htmlspecialchars($docUrl) . '" target="_blank">使用文档</a>吧</span>
    <button class="tp-doc-promo-close" onclick="(function(el){el.style.display=\'none\';try{localStorage.setItem(\'tp-doc-promo-dismissed\',\'1\');}catch(e){}})(document.getElementById(\'tp-doc-promo\'))">不再显示</button>
</div>
<script>
(function(){try{if(localStorage.getItem(\'tp-doc-promo-dismissed\')){var el=document.getElementById(\'tp-doc-promo\');if(el)el.style.display=\'none\';}}catch(e){}})();
</script>';
    }

    /**
     * 生成赞赏与鸣谢卡片
     * 包含微信赞赏码占位、鸣谢列表、插件信息链接
     * @return string
     */
    public static function renderSponsorCard()
    {
        $github = 'https://github.com/lhl77/TypechoPaid';
        $issuesUrl = 'https://github.com/lhl77/TypechoPaid/issues';
        $sponsorUrl = self::SPONSOR_URL;
        return '
<style>
.tp-sponsor-card{border-radius:12px;overflow:hidden;background:var(--md-surface-container-low,var(--md-surface,#fff));border:1px solid var(--md-outline-variant,#e0e0e0);box-shadow:0 1px 3px rgba(0,0,0,.05);color:var(--md-on-surface,#1c1b1f);margin:20px 0 0!important;width:100%!important;max-width:100%!important;flex:0 0 100%!important;box-sizing:border-box!important;}
html[data-theme="dark"] .tp-sponsor-card{background:var(--md-dark-surface-container-low,#1e1e2a)!important;border-color:var(--md-dark-outline-variant,rgba(255,255,255,.08))!important;color:var(--md-dark-on-surface,#e4e2ed)!important;}
.tp-sponsor-header{display:flex!important;align-items:center!important;gap:10px!important;padding:14px 20px!important;border-bottom:1px solid var(--md-outline-variant,#e0e0e0)!important;font-size:15px!important;font-weight:600!important;line-height:1.3!important;}
html[data-theme="dark"] .tp-sponsor-header{border-bottom-color:var(--md-dark-outline-variant,rgba(255,255,255,.08))!important;}
.tp-sponsor-body{padding:18px 20px 20px!important;display:flex!important;flex-direction:column!important;gap:18px!important;}
.tp-sponsor-grid{display:grid!important;grid-template-columns:1fr 1fr!important;gap:20px!important;}
.tp-sponsor-col{display:flex!important;flex-direction:column!important;gap:10px!important;min-width:0!important;}
.tp-sponsor-col h4{margin:0!important;font-size:14px!important;font-weight:600!important;line-height:1.4!important;display:flex!important;align-items:center!important;gap:6px!important;}
.tp-sponsor-col h4 svg{flex-shrink:0!important;}
.tp-sponsor-qr-wrap{display:flex!important;flex-direction:column!important;align-items:center!important;gap:10px!important;padding:12px!important;border-radius:10px!important;background:var(--md-surface-container,#f5f4f7)!important;border:1px dashed var(--md-outline-variant,#d0d0d6)!important;}
.tp-sponsor-qr-img-wrap{position:relative!important;display:inline-block!important;border-radius:8px!important;overflow:hidden!important;line-height:0!important;}
.tp-sponsor-qr-watermark{position:absolute!important;inset:auto 0 0 0!important;background:rgba(99,102,241,.75)!important;color:#fff!important;font-size:9px!important;font-weight:700!important;text-align:center!important;padding:2px 0!important;letter-spacing:1px!important;pointer-events:none!important;backdrop-filter:blur(1px)!important;line-height:1.4!important;}
html[data-theme="dark"] .tp-sponsor-qr-wrap{background:var(--md-dark-surface-container,#2b2930)!important;border-color:var(--md-dark-outline-variant,#49454f)!important;}
.tp-sponsor-qr-placeholder{width:132px!important;height:132px!important;display:flex!important;align-items:center!important;justify-content:center!important;border-radius:8px!important;background:#fff!important;color:var(--md-on-surface-variant,#6b6b78)!important;font-size:11px!important;text-align:center!important;line-height:1.4!important;padding:8px!important;box-sizing:border-box!important;}
.tp-sponsor-qr-wrap .tp-sponsor-qr-tip{font-size:12px!important;color:var(--md-on-surface-variant,#6b6b78)!important;line-height:1.6!important;margin:0!important;text-align:center!important;}
html[data-theme="dark"] .tp-sponsor-qr-wrap .tp-sponsor-qr-tip{color:var(--md-dark-on-surface-variant,#9d9caa)!important;}
.tp-sponsor-note{font-size:12px!important;line-height:1.7!important;color:var(--md-on-surface-variant,#6b6b78)!important;margin:0!important;padding:8px 12px!important;border-radius:8px!important;background:var(--md-surface-container,#f5f4f7)!important;}
html[data-theme="dark"] .tp-sponsor-note{background:var(--md-dark-surface-container,#2b2930)!important;color:var(--md-dark-on-surface-variant,#9d9caa)!important;}
.tp-sponsor-note strong{color:var(--md-on-surface,#1c1b1f)!important;}
html[data-theme="dark"] .tp-sponsor-note strong{color:var(--md-dark-on-surface,#e4e2ed)!important;}
.tp-sponsor-note em{font-style:normal!important;color:#d97706!important;font-size:11px!important;}
.tp-sponsor-list{list-style:none!important;margin:0!important;padding:0!important;display:flex!important;flex-direction:column!important;gap:6px!important;}
.tp-sponsor-list li{display:flex!important;align-items:center!important;gap:8px!important;padding:7px 12px!important;border-radius:8px!important;font-size:13px!important;line-height:1.5!important;background:var(--md-surface-container,#f5f4f7)!important;transition:background .15s;}
html[data-theme="dark"] .tp-sponsor-list li{background:var(--md-dark-surface-container,#2b2930)!important;}
.tp-sponsor-list li .tp-sponsor-name{font-weight:600!important;color:var(--md-on-surface,#1c1b1f)!important;}
html[data-theme="dark"] .tp-sponsor-list li .tp-sponsor-name{color:var(--md-dark-on-surface,#e4e2ed)!important;}
.tp-sponsor-list li .tp-sponsor-desc{font-size:12px!important;color:var(--md-on-surface-variant,#6b6b78)!important;flex:1!important;min-width:0!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;}
html[data-theme="dark"] .tp-sponsor-list li .tp-sponsor-desc{color:var(--md-dark-on-surface-variant,#9d9caa)!important;}
.tp-sponsor-list li .tp-sponsor-site{font-size:11px!important;color:var(--md-primary,#6366f1)!important;text-decoration:none!important;flex-shrink:0!important;max-width:140px!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;}
.tp-sponsor-list li .tp-sponsor-site:hover{text-decoration:underline!important;}
.tp-sponsor-list li .tp-sponsor-amount{font-size:12px!important;color:var(--md-primary,#6366f1)!important;font-weight:600!important;flex-shrink:0!important;white-space:nowrap!important;}
.tp-sponsor-list-empty{padding:20px!important;text-align:center!important;font-size:13px!important;color:var(--md-on-surface-variant,#6b6b78)!important;line-height:1.6!important;}
html[data-theme="dark"] .tp-sponsor-list-empty{color:var(--md-dark-on-surface-variant,#9d9caa)!important;}
.tp-sponsor-info-bar{display:flex!important;flex-wrap:wrap!important;align-items:center!important;gap:10px 20px!important;padding:12px 16px!important;border-radius:10px!important;background:var(--md-surface-container,#f5f4f7)!important;font-size:13px!important;}
html[data-theme="dark"] .tp-sponsor-info-bar{background:var(--md-dark-surface-container,#2b2930)!important;}
.tp-sponsor-info-bar .tp-sponsor-info-label{color:var(--md-on-surface-variant,#6b6b78)!important;font-weight:500!important;flex-shrink:0!important;}
html[data-theme="dark"] .tp-sponsor-info-bar .tp-sponsor-info-label{color:var(--md-dark-on-surface-variant,#9d9caa)!important;}
.tp-sponsor-info-bar a{color:var(--md-primary,#6366f1)!important;text-decoration:none!important;font-weight:500!important;}
.tp-sponsor-info-bar a:hover{text-decoration:underline!important;}
@media(max-width:768px){.tp-sponsor-grid{grid-template-columns:1fr!important;gap:16px!important;}.tp-sponsor-body{padding:14px 16px 16px!important;}.tp-sponsor-header{padding:12px 16px!important;font-size:14px!important;}.tp-sponsor-info-bar{flex-direction:column!important;align-items:flex-start!important;gap:6px!important;}}
</style>
<div class="tp-sponsor-card">
    <div class="tp-sponsor-header">赞赏与鸣谢</div>
    <div class="tp-sponsor-body">
        <div class="tp-sponsor-grid">
            <!-- 左侧：微信赞赏 -->
            <div class="tp-sponsor-col">
                <h4>
                    微信赞赏
                </h4>
                <div class="tp-sponsor-qr-wrap">
                    <a href="' . htmlspecialchars($sponsorUrl) . '" target="_blank" rel="noopener" style="display:flex;flex-direction:column;align-items:center;gap:10px;text-decoration:none;color:inherit;cursor:pointer;">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--md-primary,#6366f1)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                        <span style="font-size:13px;font-weight:600;color:var(--md-primary,#6366f1);text-align:center;line-height:1.5;">点击前往赞助页<br><span style="font-size:11px;font-weight:400;color:var(--md-on-surface-variant,#6b6b78);">支持插件持续开发 ❤️</span></span>
                    </a>
                </div>
                <div class="tp-sponsor-note">
                    <strong>备注说明</strong><br>
                    赞赏时请备注：<br>
                    <strong>TypechoPaid</strong> + 您的昵称 + 网址<br>
                    <em>⚠ 我们会审核网址合法性，仅个人或非商业网站将展示链接</em>
                </div>
            </div>
            <!-- 右侧：鸣谢列表 -->
            <div class="tp-sponsor-col">
                <h4>
                    鸣谢列表
                </h4>
                <div class="tp-sponsor-list-empty">
                    暂无记录<br>
                    <span style="font-size:12px;">成为第一位赞赏者，您的名字将在此展示</span>
                </div>
                <div style="margin-top:auto;padding-top:8px;font-size:12px;color:var(--md-on-surface-variant,#6b6b78);line-height:1.6;border-top:1px solid var(--md-outline-variant,#e0e0e0);">
                    <a href="' . htmlspecialchars($sponsorUrl) . '" target="_blank" rel="noopener" style="color:var(--md-primary,#6366f1);text-decoration:none;font-weight:500;">前往赞助页 →</a>
                </div>
            </div>
        </div>
        <!-- 底部：插件信息 -->
        <div class="tp-sponsor-info-bar">
            <span>GitHub：<a href="' . htmlspecialchars($github) . '" target="_blank" rel="noopener">' . htmlspecialchars($github) . '</a></span>
            <span>Issues：<a href="' . htmlspecialchars($issuesUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($issuesUrl) . '</a></span>
        </div>
    </div>
</div>';
    }

    /**
     * 校验赞助页链接是否被篡改
     * @param string $url 待校验的 URL
     * @return bool
     */
    private static function isSponsorUrlIntact($url)
    {
        // 内嵌哈希值校验：二开者若修改 SPONSOR_URL 而未同步更新此处哈希值会触发警告
        return hash('sha256', $url) === '9964f3caea5f0520b37ae93bde8977070d1d20109756f63faca789e19fe2a1fc';
    }

    /**
     * 生成插件信息卡片（含版本、作者、GitHub、更新检查）
     * 风格与下方折叠面板统一
     * @param bool $showSettings 是否显示"插件设置"按钮（设置页面应传 false）
     * @param bool $showSponsor 是否显示"赞助作者"按钮（仅设置页面）
     * @return string
     */
    public static function renderInfoCard($showSettings = true, $showSponsor = false)
    {
        $version = '1.0.4';
        $authorUrl = 'https://lhl.one';
        $author = 'LHL';
        $github = 'https://github.com/lhl77/TypechoPaid';
        $docUrl = 'https://blog.lhl.one/artical/1309.html';
        $sponsorUrl = self::SPONSOR_URL;
        $settingsUrl = Typecho_Widget::widget('Widget_Options')->adminUrl('options-plugin.php?config=TypechoPaid', true);

        $abStoreEnabled = false;
        try {
            $plugins = Typecho_Plugin::export();
            $abStoreEnabled = isset($plugins['activated']['AdminBeautify']);
        } catch (\Exception $e) {}

        $sponsorBtnHtml = '';
        if ($showSponsor) {
            if (self::isSponsorUrlIntact($sponsorUrl)) {
                $sponsorBtnHtml = '<a class="tp-infocard-sponsorbtn" href="' . htmlspecialchars($sponsorUrl) . '" target="_blank" rel="noopener"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>赞助作者</a>';
            } else {
                $sponsorBtnHtml = '<span style="display:inline-flex;align-items:center;gap:5px;padding:7px 16px;border-radius:20px;background:#dc2626;color:#fff;font-size:12px;font-weight:600;white-space:nowrap;">⚠ 赞助链接已被篡改，请停止使用</span>';
            }
        }

        $settingsBtnHtml = $showSettings ? '
            <a class="tp-infocard-settingsbtn" href="' . htmlspecialchars($settingsUrl) . '">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                插件设置
            </a>' : '';

        return '
<style>
.tp-infocard{border-radius:12px;overflow:hidden;background:var(--md-surface-container-low,var(--md-surface,#fff));border:1px solid var(--md-outline-variant,#e0e0e0);box-shadow:0 1px 3px rgba(0,0,0,.05);color:var(--md-on-surface,#1c1b1f);--tp-primary:var(--md-primary,#6366f1);margin:0 0 16px!important;width:100%!important;max-width:100%!important;flex:0 0 100%!important;box-sizing:border-box!important;}
.tp-infocard-header{display:flex!important;flex-wrap:nowrap!important;flex-direction:row!important;align-items:center!important;justify-content:space-between!important;gap:14px!important;padding:14px 20px!important;width:100%!important;box-sizing:border-box!important;}
.tp-infocard-meta{min-width:0!important;flex:1 1 auto!important;display:flex!important;flex-direction:column!important;gap:3px!important;}
.tp-infocard-meta h3{margin:0!important;font-size:15px!important;font-weight:600!important;color:var(--md-on-surface,#1c1b1f)!important;line-height:1.3!important;}
.tp-infocard-meta p{margin:0!important;font-size:12px!important;color:var(--md-on-surface-variant,#6b6b78)!important;line-height:1.4!important;display:flex!important;align-items:center!important;gap:5px!important;flex-wrap:wrap!important;}
.tp-infocard-meta a{color:var(--tp-primary)!important;text-decoration:none!important;font-weight:500!important;}
.tp-infocard-meta a:hover{text-decoration:underline!important;}
.tp-infocard-meta-dot{color:#d0d0d6!important;}
.tp-infocard-actions{display:flex!important;flex-direction:row!important;align-items:center!important;gap:8px!important;flex-shrink:0!important;}
.tp-infocard-docbtn,.tp-infocard-updatebtn{display:inline-flex!important;align-items:center!important;gap:5px!important;padding:7px 16px!important;border-radius:20px!important;background:var(--tp-primary)!important;color:#fff!important;font-size:12px!important;font-weight:600!important;text-decoration:none!important;border:none!important;cursor:pointer!important;white-space:nowrap!important;transition:box-shadow .2s,transform .15s,filter .2s;line-height:1!important;box-shadow:0 1px 3px rgba(0,0,0,.12)!important;}
.tp-infocard-docbtn:hover,.tp-infocard-updatebtn:hover{box-shadow:0 4px 12px rgba(99,102,241,.35)!important;transform:translateY(-1px);filter:brightness(1.06);color:#fff!important;}
.tp-infocard-docbtn:active,.tp-infocard-updatebtn:active{transform:translateY(0)!important;}
.tp-infocard-updatebtn:disabled{opacity:.5!important;cursor:not-allowed!important;filter:none!important;box-shadow:none!important;transform:none!important;}
.tp-infocard-settingsbtn{display:inline-flex!important;align-items:center!important;gap:5px!important;padding:7px 16px!important;border-radius:20px!important;background:transparent!important;color:var(--tp-primary)!important;font-size:12px!important;font-weight:600!important;text-decoration:none!important;border:1.5px solid var(--tp-primary)!important;transition:background .2s;white-space:nowrap!important;}
.tp-infocard-settingsbtn:hover{background:rgba(99,102,241,.08)!important;background:color-mix(in srgb,var(--tp-primary) 8%,transparent)!important;color:var(--tp-primary)!important;}
.tp-infocard-sponsorbtn{display:inline-flex!important;align-items:center!important;gap:5px!important;padding:7px 16px!important;border-radius:20px!important;background:#ec4899!important;color:#fff!important;font-size:12px!important;font-weight:600!important;text-decoration:none!important;box-shadow:0 1px 3px rgba(236,72,153,.25)!important;transition:box-shadow .2s,transform .15s;white-space:nowrap!important;}
.tp-infocard-sponsorbtn:hover{box-shadow:0 4px 12px rgba(236,72,153,.35)!important;transform:translateY(-1px);color:#fff!important;}
.tp-infocard-update-result{display:none;border-top:1px solid var(--md-outline-variant,#e0e0e0)!important;padding:14px 20px!important;font-size:13px!important;line-height:1.65!important;animation:tp-infocard-fadein .25s ease;}
.tp-infocard-update-result.show{display:block!important;}
@keyframes tp-infocard-fadein{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.tp-infocard-update-result .tp-infocard-update-ok{color:#0a8f43!important;font-weight:600!important;}
.tp-infocard-update-result .tp-infocard-update-new{color:#d97706!important;font-weight:600!important;}
.tp-infocard-update-result .tp-infocard-update-err{color:#dc2626!important;font-weight:500!important;}
.tp-infocard-update-goto-btn{display:flex!important;justify-content:center!important;align-items:center!important;gap:6px!important;margin-top:10px!important;padding:10px 22px!important;border-radius:22px!important;background:#0a8f43!important;color:#fff!important;font-size:13px!important;font-weight:600!important;text-decoration:none!important;white-space:nowrap!important;width:fit-content!important;transition:box-shadow .2s,transform .15s,filter .2s;}
.tp-infocard-update-goto-btn:hover{box-shadow:0 4px 12px rgba(10,143,67,.35)!important;transform:translateY(-1px);filter:brightness(1.06);color:#fff!important;}
.tp-infocard-update-goto-btn.is-ab-store{background:var(--tp-primary)!important;}
.tp-infocard-update-goto-btn.is-ab-store:hover{box-shadow:0 4px 12px rgba(99,102,241,.35)!important;}
html[data-theme="dark"] .tp-infocard{background:var(--md-dark-surface-container-low,#1e1e2a)!important;border-color:var(--md-dark-outline-variant,rgba(255,255,255,.08))!important;color:var(--md-dark-on-surface,#e4e2ed)!important;box-shadow:0 1px 3px rgba(0,0,0,.2)!important;}
html[data-theme="dark"] .tp-infocard-meta h3{color:var(--md-dark-on-surface,#e4e2ed)!important;}
html[data-theme="dark"] .tp-infocard-meta p{color:var(--md-dark-on-surface-variant,#9d9caa)!important;}
html[data-theme="dark"] .tp-infocard-meta-dot{color:#555!important;}
html[data-theme="dark"] .tp-infocard-docbtn:hover,.tp-infocard-updatebtn:hover{filter:brightness(1.12);}
html[data-theme="dark"] .tp-infocard-settingsbtn:hover{background:rgba(165,164,246,.1)!important;background:color-mix(in srgb,var(--tp-primary) 12%,transparent)!important;}
html[data-theme="dark"] .tp-infocard-update-result{border-top-color:var(--md-dark-outline-variant,rgba(255,255,255,.08))!important;}
@media(max-width:768px){.tp-infocard-header{flex-direction:column!important;align-items:stretch!important;gap:12px!important;padding:14px 16px!important;}.tp-infocard-actions{flex-wrap:wrap!important;width:100%!important;}.tp-infocard-docbtn,.tp-infocard-updatebtn,.tp-infocard-settingsbtn,.tp-infocard-sponsorbtn{flex:1!important;justify-content:center!important;min-width:0!important;}.tp-infocard-meta h3{font-size:14px!important;}.tp-infocard-update-result{padding:12px 16px!important;}}
</style>
<div class="tp-infocard" id="tp-infocard">
    <div class="tp-infocard-header">
        <div class="tp-infocard-meta">
            <h3>Paid for Typecho</h3>
            <p>
                <span>v' . htmlspecialchars($version) . '</span>
                <span class="tp-infocard-meta-dot">&middot;</span>
                <span>by <a href="' . htmlspecialchars($authorUrl) . '" target="_blank" rel="noopener">' . htmlspecialchars($author) . '</a></span>
                <span class="tp-infocard-meta-dot">&middot;</span>
                <a href="' . htmlspecialchars($github) . '" target="_blank" rel="noopener">GitHub</a>
            </p>
        </div>
        <div class="tp-infocard-actions">
            ' . $sponsorBtnHtml . '
            ' . $settingsBtnHtml . '
            <a class="tp-infocard-docbtn" href="' . htmlspecialchars($docUrl) . '" target="_blank" rel="noopener">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>使用文档
            </a>
            <button class="tp-infocard-updatebtn" id="tp-infocard-update-btn" onclick="TPInfoCard.checkUpdate()">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                <span>检查更新</span>
            </button>
        </div>
    </div>
    <div class="tp-infocard-update-result" id="tp-infocard-update-result"></div>
</div>
<script>
(function(){
    var TPInfoCard={
        _currentVer:"' . htmlspecialchars($version) . '",
        _repo:"lhl77/TypechoPaid",
        _abStoreEnabled:' . ($abStoreEnabled ? 'true' : 'false') . ',
        _abStoreUrl:"' . htmlspecialchars(Typecho_Widget::widget("Widget_Options")->adminUrl . 'extending.php?panel=' . urlencode('AdminBeautifyStore/Panel.php')) . '",
        _checking:false,
        _silent:false,
        _safeTimer:0,
        _el:function(id){return document.getElementById(id);},
        _compareVer:function(a,b){
            var pa=String(a).split(".").map(Number),pb=String(b).split(".").map(Number);
            for(var i=0;i<Math.max(pa.length,pb.length);i++){var va=pa[i]||0,vb=pb[i]||0;if(va>vb)return 1;if(va<vb)return -1;}
            return 0;
        },
        _showUpdateResult:function(type,msg,btnLabel,btnHref,btnCls){
            var el=this._el("tp-infocard-update-result");if(!el)return;
            el.innerHTML="";el.className="tp-infocard-update-result show";
            var p=document.createElement("p");
            p.className=type==="ok"?"tp-infocard-update-ok":type==="err"?"tp-infocard-update-err":"tp-infocard-update-new";
            p.style.cssText="margin:0 0 8px;line-height:1.6;";
            p.textContent=msg;
            el.appendChild(p);
            if(btnLabel&&btnHref){
                var a=document.createElement("a");
                a.className=btnCls||"tp-infocard-update-goto-btn";
                a.textContent=btnLabel;
                a.href=btnHref;
                a.target=type==="ab"?"_self":"_blank";
                if(type!=="ab")a.rel="noopener";
                a.style.cssText="display:flex;justify-content:center;align-items:center;gap:6px;margin-top:4px;padding:8px 20px;border-radius:20px;background:"+(type==="ab"?"var(--md-primary,#6366f1)":"#0a8f43")+";color:#fff;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap;width:fit-content;";
                el.appendChild(a);
            }
        },
        _resetBtn:function(){
            var btn=this._el("tp-infocard-update-btn");
            if(btn){btn.disabled=false;var sp=btn.querySelector("span");if(sp)sp.textContent="检查更新";}
            this._checking=false;
        },
        checkUpdate:function(silent){
            if(this._checking)return;
            this._checking=true;this._silent=!!silent;
            var self=this,btn=this._el("tp-infocard-update-btn");
            if(btn){btn.disabled=true;var sp=btn.querySelector("span");if(sp)sp.textContent="检查中...";}
            clearTimeout(this._safeTimer);
            this._safeTimer=setTimeout(function(){
                if(self._checking){if(!self._silent)self._showUpdateResult("err","请求超时，请稍后重试");self._resetBtn();}
            },20000);
            try{
                var xhr=new XMLHttpRequest();
                xhr.open("GET","https://api.github.com/repos/"+this._repo+"/releases/latest",true);
                xhr.timeout=12000;
                xhr.onload=function(){
                    clearTimeout(self._safeTimer);
                    try{
                        if(xhr.status===200){
                            var data=JSON.parse(xhr.responseText),tag=data.tag_name||"";
                            if(!tag){if(!self._silent)self._showUpdateResult("err","未能获取版本信息");self._resetBtn();return;}
                            var latest=tag.replace(/^v/i,""),cmp=self._compareVer(latest,self._currentVer);
                            var releaseUrl=data.html_url||("https://github.com/"+self._repo+"/releases/tag/"+encodeURIComponent(tag));
                            if(cmp>0){
                                var msg="发现新版本："+tag+"，当前版本：v"+self._currentVer;
                                if(self._abStoreEnabled){
                                    self._showUpdateResult("ab",msg,"前往 AB-Store 更新",self._abStoreUrl,"tp-infocard-update-goto-btn is-ab-store");
                                }else{
                                    self._showUpdateResult("new",msg,"前往 Github 更新",releaseUrl,"tp-infocard-update-goto-btn");
                                }
                            }else if(!self._silent){
                                if(cmp===0)self._showUpdateResult("ok","已是最新版本（v"+self._currentVer+"）");
                                else self._showUpdateResult("ok","当前为预发布版本（v"+self._currentVer+"，最新 release："+tag+"）");
                            }
                        }else if(!self._silent){
                            self._showUpdateResult("err",xhr.status===403?"GitHub API 限流（403），请稍后重试":"网络错误（状态码 "+xhr.status+"），请稍后重试");
                        }
                    }catch(e){if(!self._silent)self._showUpdateResult("err","解析版本信息失败");}
                    self._resetBtn();
                };
                xhr.onerror=function(){clearTimeout(self._safeTimer);if(!self._silent)self._showUpdateResult("err","网络不可用，请检查连接后重试");self._resetBtn();};
                xhr.ontimeout=function(){clearTimeout(self._safeTimer);if(!self._silent)self._showUpdateResult("err","请求超时，请稍后重试");self._resetBtn();};
                xhr.send();
            }catch(e){clearTimeout(self._safeTimer);if(!self._silent)self._showUpdateResult("err","请求异常："+e.message);self._resetBtn();}
        }
    };
    window.TPInfoCard=TPInfoCard;
    if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){TPInfoCard.checkUpdate(true);});}
    else{TPInfoCard.checkUpdate(true);}
})();
</script>';
    }

    /**
     * 生成后台分页导航 HTML
     * @param int $currentPage 当前页码
     * @param int $pageCount 总页数
     * @param string $baseUrl 基础链接（不含 page 参数）
     * @return string
     */
    public static function renderPager($currentPage, $pageCount, $baseUrl)
    {
        if ($pageCount <= 1) {
            return '';
        }
        $sep = strpos($baseUrl, '?') === false ? '?' : '&';
        static $pagerStyleInjected = false;
        if (!$pagerStyleInjected) {
            $html = '<style>.typecho-pager{display:flex!important;align-items:center!important;justify-content:flex-end!important;list-style:none!important;padding:0!important;line-height:1!important;zoom:1;margin-top:12px}.typecho-pager li{display:inline-block!important;margin:0 3px!important;height:28px!important;line-height:28px!important}.typecho-pager li a{display:block;padding:0 10px;border-radius:2px}.typecho-pager li a:hover{text-decoration:none;background:#E9E9E6}.typecho-pager li.current a{background:#E9E9E6;color:#444}</style>';
            $pagerStyleInjected = true;
        } else {
            $html = '';
        }
        $html .= '<ul class="typecho-pager">';

        // Prev
        if ($currentPage > 1) {
            $html .= '<li class="prev"><a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($currentPage - 1)) . '">' . _t('上一页') . '</a></li>';
        } else {
            $html .= '<li class="prev"><span>' . _t('上一页') . '</span></li>';
        }

        // Page numbers with ellipsis
        $range = 2; // pages on each side of current
        for ($i = 1; $i <= $pageCount; $i++) {
            if ($i === 1 || $i === $pageCount || ($i >= $currentPage - $range && $i <= $currentPage + $range)) {
                if ($i === $currentPage) {
                    $html .= '<li class="current"><a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
                } else {
                    $html .= '<li><a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
                }
            } elseif ($i === $currentPage - $range - 1 || $i === $currentPage + $range + 1) {
                $html .= '<li><span>…</span></li>';
            }
        }

        // Next
        if ($currentPage < $pageCount) {
            $html .= '<li class="next"><a href="' . htmlspecialchars($baseUrl . $sep . 'page=' . ($currentPage + 1)) . '">' . _t('下一页') . '</a></li>';
        } else {
            $html .= '<li class="next"><span>' . _t('下一页') . '</span></li>';
        }

        $html .= '</ul>';
        return $html;
    }

    public static function getPaymentChannels()
    {
        $text = trim((string)self::getOption('payment_channels', ''));
        if ($text === '') {
            return array();
        }

        $channels = array();
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '|') === false) {
                continue;
            }

            list($name, $definition) = explode('|', $line, 2);
            $parts = self::splitEscapedChannelConfig(trim($definition));
            $driver = isset($parts[0]) ? strtolower(trim($parts[0])) : '';
            if ($driver === '') {
                continue;
            }

            // 生成唯一 key：epay 驱动按 type 参数区分（如 epay:alipay、epay:wxpay），其他驱动使用驱动名本身
            $key = $driver;
            if ($driver === 'epay' && isset($parts[4])) {
                $type = strtolower(trim($parts[4]));
                if ($type !== '') {
                    $key = $driver . ':' . $type;
                }
            }
            if (isset($channels[$key])) {
                continue;
            }

            $channels[$key] = array(
                'name' => trim($name) === '' ? self::methodLabel($driver) : trim($name),
                'driver' => $driver,
                'configs' => array_slice($parts, 1)
            );
        }

        return $channels;
    }

    public static function getSubscriptionPlans()
    {
        $text = trim((string)self::getOption('subscription_plans', ''));
        if ($text === '') {
            return array();
        }
        $plans = array();
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $parts = explode(':', $line, 4);
            if (count($parts) < 4) {
                continue;
            }
            $key = trim($parts[0]);
            $name = trim($parts[1]);
            $price = floatval(trim($parts[2]));
            $days = intval(trim($parts[3]));
            if ($key === '' || $name === '' || $price <= 0 || $days <= 0) {
                continue;
            }
            $plans[$key] = array(
                'key' => $key,
                'name' => $name,
                'price' => $price,
                'duration_days' => $days
            );
        }
        return $plans;
    }

    public static function getPlanByKey($planKey)
    {
        $plans = self::getSubscriptionPlans();
        return isset($plans[$planKey]) ? $plans[$planKey] : null;
    }

    public static function getArticlePlan($fields)
    {
        $planKey = self::fieldValue($fields, 'paid_plan');
        if ($planKey === '') {
            return null;
        }
        return self::getPlanByKey($planKey);
    }

    /**
     * 解析优惠码配置
     * @return array 优惠码数组，key 为优惠码代码（小写）
     */
    public static function getCoupons()
    {
        $text = trim((string)self::getOption('coupon_codes', ''));
        if ($text === '') {
            return array();
        }
        $coupons = array();
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '|') === false) {
                continue;
            }
            $parts = explode('|', $line, 4);
            if (count($parts) < 4) {
                continue;
            }
            $name = trim($parts[0]);
            $code = trim($parts[1]);
            $method = trim($parts[2]);
            $articleUids = trim($parts[3]);
            if ($name === '' || $code === '' || $method === '' || $articleUids === '') {
                continue;
            }
            // 解析适用文章 UID
            $uidArr = array();
            foreach (explode(',', $articleUids) as $uid) {
                $uid = intval(trim($uid));
                if ($uid > 0) {
                    $uidArr[$uid] = $uid;
                }
            }
            if (empty($uidArr)) {
                continue;
            }
            // 解析优惠方式
            $isPercent = strpos($method, '%') !== false;
            $value = floatval(str_replace('%', '', $method));
            if ($value <= 0) {
                continue;
            }
            $coupons[strtolower($code)] = array(
                'name' => $name,
                'code' => $code,
                'is_percent' => $isPercent,
                'value' => $value,
                'article_uids' => $uidArr
            );
        }
        return $coupons;
    }

    /**
     * 获取适用于指定文章的优惠码列表
     * @param int $cid 文章 CID
     * @return array
     */
    public static function getCouponsForArticle($cid)
    {
        $cid = intval($cid);
        if ($cid <= 0) {
            return array();
        }
        $all = self::getCoupons();
        if (empty($all)) {
            return array();
        }
        $result = array();
        foreach ($all as $code => $coupon) {
            if (isset($coupon['article_uids'][$cid])) {
                $result[$code] = $coupon;
            }
        }
        return $result;
    }

    /**
     * 验证优惠码是否有效
     * @param string $code 优惠码
     * @param int $cid 文章 CID
     * @return array|null 优惠码信息或 null（无效）
     */
    public static function validateCoupon($code, $cid)
    {
        $code = trim((string)$code);
        $cid = intval($cid);
        if ($code === '' || $cid <= 0) {
            return null;
        }
        $coupons = self::getCouponsForArticle($cid);
        $key = strtolower($code);
        if (isset($coupons[$key])) {
            return $coupons[$key];
        }
        return null;
    }

    /**
     * 应用优惠码折扣到价格
     * @param float $price 原价
     * @param array $coupon 优惠码信息
     * @return float 折扣后的价格
     */
    public static function applyCouponDiscount($price, $coupon)
    {
        if ($coupon['is_percent']) {
            // 百分比折扣：打 (100-value)% 折
            $discount = $price * ($coupon['value'] / 100);
            return round(max(0, $price - $discount), 2);
        } else {
            // 固定金额减免
            return round(max(0, $price - $coupon['value']), 2);
        }
    }

    /**
     * 生成优惠码前端 HTML（包含输入框和提示）
     * @param array $coupons 适用于当前文章的优惠码列表
     * @return string
     */
    public static function renderCouponHtml($coupons)
    {
        if (empty($coupons)) {
            return '';
        }
        $html = '<input class="tp-paid-input" type="text" name="coupon_code" placeholder="优惠码（选填）" autocomplete="off">'
            . '<div class="tp-paid-coupon-result" data-role="coupon-result"></div>'
            . '<input type="hidden" name="coupon_valid" value="">'
            . '<input type="hidden" name="coupon_discount" value="">';
        return $html;
    }

    /**
     * 同时兼容 Typecho_Config 字段对象和普通数组。
     * 不能将 Typecho_Config 直接转为数组，否则其私有配置会丢失 paid_plan 等字段。
     */
    public static function fieldValue($fields, $name, $default = '')
    {
        if (is_array($fields) && isset($fields[$name])) {
            return trim((string)$fields[$name]);
        }
        if (is_object($fields)) {
            if ($fields instanceof ArrayAccess && isset($fields[$name])) {
                return trim((string)$fields[$name]);
            }
            if (isset($fields->$name)) {
                return trim((string)$fields->$name);
            }
        }
        return trim((string)$default);
    }

    public static function hasActiveSubscription($planKey)
    {
        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return false;
        }
        $data = json_decode($_COOKIE[self::COOKIE_NAME], true);
        if (!is_array($data)) {
            return false;
        }
        $entryKey = '_plan_' . $planKey;
        return isset($data[$entryKey]) && intval($data[$entryKey]) > time();
    }

    public static function setSubscriptionCookie($planKey, $durationDays)
    {
        $planKey = trim((string)$planKey);
        if ($planKey === '') {
            return;
        }
        $days = intval($durationDays);
        if ($days <= 0) {
            $days = 30;
        }
        $expires = time() + ($days * 86400);
        $data = array();
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $old = json_decode($_COOKIE[self::COOKIE_NAME], true);
            if (is_array($old)) {
                $data = $old;
            }
        }
        $entryKey = '_plan_' . $planKey;
        $data[$entryKey] = $expires;
        foreach ($data as $entryExpires) {
            $expires = max($expires, intval($entryExpires));
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, json_encode($data), $expires, '/', '', $secure, false);
        $_COOKIE[self::COOKIE_NAME] = json_encode($data);
    }

    public static function setSubscriptionCookieUntil($planKey, $expires)
    {
        $planKey = trim((string)$planKey);
        $expires = intval($expires);
        if ($planKey === '' || $expires <= time()) {
            return;
        }
        $data = array();
        if (!empty($_COOKIE[self::COOKIE_NAME])) {
            $old = json_decode($_COOKIE[self::COOKIE_NAME], true);
            if (is_array($old)) {
                $data = $old;
            }
        }
        $data['_plan_' . $planKey] = $expires;
        foreach ($data as $entryExpires) {
            $expires = max($expires, intval($entryExpires));
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(self::COOKIE_NAME, json_encode($data), $expires, '/', '', $secure, false);
        $_COOKIE[self::COOKIE_NAME] = json_encode($data);
    }

    public static function sendPurchaseNotification(array $order)
    {
        if (intval(self::getOption('smtp_enable', '0')) !== 1 || empty($order['email'])) {
            return false;
        }
        $host = trim((string)self::getOption('smtp_host', ''));
        $username = trim((string)self::getOption('smtp_username', ''));
        $password = (string)self::getOption('smtp_password', '');
        if ($host === '' || $username === '' || $password === '') {
            return false;
        }
        require_once __DIR__ . '/Smtp.php';
        $options = Helper::options();
        $siteUrl = rtrim((string)$options->siteUrl, '/') . '/';
        $isSubscription = !empty($order['plan_id']);
        $type = $isSubscription ? '订阅' : '文章';
        $content = (string)$order['title'];
        $expires = !empty($order['expires_at']) ? date('Y-m-d H:i:s', intval($order['expires_at'])) : '';
        $siteName = htmlspecialchars((string)$options->title, ENT_QUOTES, 'UTF-8');
        $safeSiteUrl = htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8');
        $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
        $safeTradeNo = htmlspecialchars((string)$order['trade_no'], ENT_QUOTES, 'UTF-8');
        $amount = '￥' . number_format(floatval($order['price']), 2);
        $purchaseDate = date('Y-m-d H:i:s', intval($order['paid_at']));
        $detailRows = '<tr><td style="padding:13px 0;color:#748097;font-size:13px;border-bottom:1px solid #edf0f5;">购买类型</td><td style="padding:13px 0;text-align:right;color:#17233b;font-size:14px;font-weight:600;border-bottom:1px solid #edf0f5;">' . $type . '</td></tr>'
            . '<tr><td style="padding:13px 0;color:#748097;font-size:13px;border-bottom:1px solid #edf0f5;">购买内容</td><td style="padding:13px 0;text-align:right;color:#17233b;font-size:14px;font-weight:600;border-bottom:1px solid #edf0f5;word-break:break-word;">' . $safeContent . '</td></tr>'
            . '<tr><td style="padding:13px 0;color:#748097;font-size:13px;border-bottom:1px solid #edf0f5;">购买日期</td><td style="padding:13px 0;text-align:right;color:#17233b;font-size:14px;border-bottom:1px solid #edf0f5;">' . $purchaseDate . '</td></tr>';
        if ($expires !== '') {
            $detailRows .= '<tr><td style="padding:13px 0;color:#748097;font-size:13px;border-bottom:1px solid #edf0f5;">订阅到期</td><td style="padding:13px 0;text-align:right;color:#17233b;font-size:14px;font-weight:600;border-bottom:1px solid #edf0f5;">' . $expires . '</td></tr>';
        }
        $detailRows .= '<tr><td style="padding:13px 0 0;color:#748097;font-size:13px;">商户订单号</td><td style="padding:13px 0 0;text-align:right;color:#17233b;font-family:monospace;font-size:13px;word-break:break-all;">' . $safeTradeNo . '</td></tr>';
        $html = '<!doctype html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body style="margin:0;padding:0;background:#f3f6fb;color:#17233b;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',\'Microsoft YaHei\',Arial,sans-serif;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f6fb;"><tr><td align="center" style="padding:32px 14px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(24,47,86,.10);">'
            . '<tr><td style="padding:30px 34px;background:#256fe0;color:#ffffff;">'
            . '<div style="font-size:12px;font-weight:700;letter-spacing:1.6px;opacity:.8;">' . $siteName . '</div>'
            . '<div style="margin-top:9px;font-size:25px;line-height:1.3;font-weight:700;">购买成功</div>'
            . '<div style="margin-top:7px;font-size:14px;line-height:1.7;opacity:.88;">您的订单已完成支付，感谢您的支持。</div></td></tr>'
            . '<tr><td style="padding:28px 34px 14px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td style="color:#748097;font-size:13px;">实付金额</td><td align="right" style="color:#256fe0;font-size:28px;font-weight:750;letter-spacing:-.5px;">' . $amount . '</td>'
            . '</tr></table></td></tr><tr><td style="padding:0 34px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $detailRows . '</table></td></tr>'
            . '<tr><td align="center" style="padding:6px 34px 30px;"><a href="' . $safeSiteUrl . '" style="display:inline-block;padding:12px 25px;border-radius:9px;background:#256fe0;color:#ffffff;text-decoration:none;font-size:14px;font-weight:700;">访问网站</a></td></tr>'
            . '<tr><td style="padding:18px 34px;background:#f8faff;color:#8a96aa;font-size:12px;line-height:1.7;text-align:center;">此邮件由 ' . $siteName . ' 自动发送，请勿直接回复。<br>如需帮助，请保留商户订单号并联系网站管理员。</td></tr>'
            . '</table></td></tr></table></body></html>';
        try {
            $mailer = new TypechoPaid_Smtp($host, intval(self::getOption('smtp_port', '465')), self::getOption('smtp_encryption', 'ssl'), $username, $password);
            return $mailer->send(
                trim((string)self::getOption('smtp_from_email', '')) ?: $username,
                trim((string)self::getOption('smtp_from_name', 'TypechoPaid通知')),
                (string)$order['email'],
                '购买成功 - ' . $content,
                $html
            );
        } catch (Exception $e) {
            return false;
        }
    }

    public static function splitEscapedChannelConfig($value)
    {
        $parts = array();
        $buffer = '';
        $escaped = false;
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            if ($escaped) {
                $buffer .= $char;
                $escaped = false;
            } elseif ($char === '\\') {
                $escaped = true;
            } elseif ($char === ':') {
                $parts[] = trim($buffer);
                $buffer = '';
            } else {
                $buffer .= $char;
            }
        }
        if ($escaped) {
            $buffer .= '\\';
        }
        $parts[] = trim($buffer);

        return $parts;
    }

    public static function buildPreview($html, $limit)
    {
        $plain = trim(strip_tags($html));
        if ($plain === '') {
            return '<p>此文章需要购买后查看。</p>';
        }

        if (function_exists('mb_substr')) {
            $short = mb_substr($plain, 0, $limit, 'UTF-8');
            if (mb_strlen($plain, 'UTF-8') > $limit) {
                $short .= '...';
            }
        } else {
            $short = substr($plain, 0, $limit);
            if (strlen($plain) > $limit) {
                $short .= '...';
            }
        }

        return '<p>' . htmlspecialchars($short) . '</p>';
    }

    public static function getOption($key, $default = null)
    {
        $opts = Helper::options()->plugin('TypechoPaid');
        return isset($opts->{$key}) && $opts->{$key} !== '' ? $opts->{$key} : $default;
    }

    public static function clientIp()
    {
        // 出于防绕过考虑：REMOTE_ADDR 由 Web 服务器直接设置，客户端无法伪造；
        // HTTP_X_FORWARDED_FOR / HTTP_CF_CONNECTING_IP 等请求头可被客户端任意伪造，
        // 仅在 REMOTE_ADDR 缺失时才作为兜底使用，避免同 IP 频控 / Turnstile 触发条件被绕过。
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }

        $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP');
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $value = $_SERVER[$key];
                if (strpos($value, ',') !== false) {
                    $parts = explode(',', $value);
                    $value = trim($parts[0]);
                }
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    public static function dailyIpOrderCount($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return 0;
        }

        $db = Typecho_Db::get();
        $todayStart = strtotime(date('Y-m-d') . ' 00:00:00');

        try {
            $rows = $db->fetchAll($db->select('id')->from('table.' . self::tableName())
                ->where('ip = ?', $ip)
                ->where('created >= ?', $todayStart));
            return count($rows);
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function listThemes()
    {
        $base = __DIR__ . '/themes';
        if (!is_dir($base)) {
            return array();
        }

        $items = glob($base . '/*', GLOB_ONLYDIR);
        $themes = array();
        foreach ($items as $dir) {
            $id = basename($dir);
            if ($id === '.' || $id === '..') {
                continue;
            }
            if (is_file($dir . '/index.html') && is_file($dir . '/theme.css') && is_file($dir . '/main.js')) {
                $themes[] = $id;
            }
        }

        sort($themes);
        return $themes;
    }

    public static function resolveThemeId($themeId)
    {
        $themeId = trim((string)$themeId);
        $themes = self::listThemes();
        if (empty($themes)) {
            return 'default';
        }
        if (in_array($themeId, $themes, true)) {
            return $themeId;
        }
        if (in_array('default', $themes, true)) {
            return 'default';
        }
        return $themes[0];
    }

    public static function themeAssetHtml($themeId)
    {
        $options = Helper::options();
        $pluginUrl = Typecho_Common::url('TypechoPaid', $options->pluginUrl);
        $themeDir = __DIR__ . '/themes/' . $themeId;

        // 主题 CSS/JS 通过外链加载，附带文件修改时间戳防止 CDN 缓存问题
        $cssFile = $themeDir . '/theme.css';
        $cssTs = is_file($cssFile) ? filemtime($cssFile) : time();
        $cssUrl = $pluginUrl . '/themes/' . rawurlencode($themeId) . '/theme.css?v=' . $cssTs;

        $jsFile = $themeDir . '/main.js';
        $jsTs = is_file($jsFile) ? filemtime($jsFile) : time();
        $jsUrl = $pluginUrl . '/themes/' . rawurlencode($themeId) . '/main.js?v=' . $jsTs;

        return '<link rel="stylesheet" href="' . htmlspecialchars($cssUrl) . '">'
            . '<script src="' . htmlspecialchars($jsUrl) . '" defer></script>';
    }

    public static function renderThemeHtml($themeId, array $vars)
    {
        $themeFile = __DIR__ . '/themes/' . $themeId . '/index.html';
        if (!is_file($themeFile)) {
            $template = self::defaultThemeTemplate();
        } else {
            $template = file_get_contents($themeFile);
        }

        $replacements = array(
            '{{theme_id}}' => $vars['theme_id'],
            '{{preview}}' => $vars['preview'],
            '{{title}}' => $vars['title'],
            '{{desc}}' => $vars['desc'],
            '{{price}}' => $vars['price'],
            '{{cid}}' => $vars['cid'],
            '{{create_action}}' => $vars['create_action'],
            '{{unlock_action}}' => $vars['unlock_action'],
            '{{status_action}}' => $vars['status_action'],
            '{{subscribe_action}}' => $vars['subscribe_action'],
            '{{methods_html}}' => $vars['methods_html'],
            '{{buy_disabled}}' => $vars['buy_disabled'],
            '{{turnstile_html}}' => $vars['turnstile_html'],
            '{{plan_html}}' => $vars['plan_html'],
            '{{plan_label}}' => $vars['plan_label'],
            '{{coupon_html}}' => $vars['coupon_html'],
            '{{show_price}}' => $vars['show_price'],
            '{{theme_mode}}' => $vars['theme_mode'],
            '{{theme_mode_switch}}' => $vars['theme_mode_switch'],
            '{{theme_style_vars}}' => $vars['theme_style_vars'],
            '{{theme_options_json}}' => $vars['theme_options_json']
        );

        return strtr($template, $replacements);
    }

    public static function parseLineOptions($text)
    {
        $out = array();
        foreach (preg_split('/\r\n|\r|\n/', (string)$text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, ':') === false) {
                continue;
            }
            list($key, $value) = explode(':', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    public static function buildThemeStyleVars(array $themeOptions)
    {
        $vars = '';
        foreach ($themeOptions as $key => $value) {
            $name = trim((string)$key);
            $val = trim((string)$value);
            if ($name === '' || $val === '') {
                continue;
            }
            if (strpos($name, '--') !== 0) {
                $name = '--tp-' . strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $name));
            }
            if (!preg_match('/^--[a-zA-Z0-9_-]+$/', $name)) {
                continue;
            }
            $safeVal = str_replace(array(';', '{', '}'), '', $val);
            $vars .= $name . ':' . $safeVal . ';';
        }
        return $vars;
    }

    private static function defaultThemeTemplate()
    {
        return '<div class="tp-paid-box tp-paid-theme-{{theme_id}}" style="{{theme_style_vars}}" data-theme-options="{{theme_options_json}}" data-mode="{{theme_mode}}" data-mode-switch="{{theme_mode_switch}}">'
            . '<div class="tp-paid-preview">{{preview}}</div>'
            . '<div class="tp-paid-card" id="tp-paid-card" data-status-action="{{status_action}}">'
            . '<div class="tp-paid-buy-view">'
            . '<h3 class="tp-paid-title">{{title}}</h3>'
            . '<p class="tp-paid-desc">{{desc}}</p>'
            . '{{plan_label}}'
            . '<p class="tp-paid-price" data-show-price="{{show_price}}" data-role="price-display">价格：<strong>￥{{price}}</strong></p>'
            . '{{plan_html}}'
            . '<form class="tp-paid-form" method="post" action="{{create_action}}" data-create-action="{{create_action}}" onsubmit="return TypechoPaid.submitCreate(this);">'
            . '<input type="hidden" name="cid" value="{{cid}}">'
            . '<input type="hidden" name="subscribe_action" value="{{subscribe_action}}">'
            . '<input type="hidden" name="plan_key" value="">'
            . '<div class="tp-paid-row"><input class="tp-paid-input" type="email" name="email" placeholder="邮箱" required></div>'
            . '<div class="tp-paid-row"><label class="tp-paid-label">设置访问密码（可留空）</label><input class="tp-paid-input" type="text" name="visit_password" placeholder="访问密码（每个订单独立）"></div>'
            . '{{coupon_html}}'
            . '<div class="tp-paid-methods">{{methods_html}}</div>'
            . '{{turnstile_html}}'
            . '<div class="tp-paid-row"><button class="tp-paid-btn" type="submit"{{buy_disabled}}>立即购买</button></div>'
            . '</form>'
            . '<div class="tp-paid-payment-box" data-role="payment-box"></div>'
            . '<div class="tp-paid-row tp-paid-switch-row"><a href="javascript:;" class="tp-paid-link" onclick="return TypechoPaid.toggleUnlock(this);">已经购买？</a></div>'
            . '</div>'
            . '<div class="tp-paid-unlock-view">'
            . '<div class="tp-paid-row"><a href="javascript:;" class="tp-paid-back" onclick="return TypechoPaid.backToBuy(this);">← 返回</a></div>'
            . '<form class="tp-paid-form" method="post" action="{{unlock_action}}" onsubmit="return TypechoPaid.submitUnlock(this);">'
            . '<input type="hidden" name="cid" value="{{cid}}">'
            . '<div class="tp-paid-row"><input class="tp-paid-input" type="email" name="email" placeholder="邮箱" required></div>'
            . '<div class="tp-paid-row"><input class="tp-paid-input" type="text" name="credential" placeholder="访问密码/商户订单号（填一个即可）"></div>'
            . '<div class="tp-paid-row"><button class="tp-paid-btn tp-paid-btn-verify" type="submit">验证并解锁</button></div>'
            . '</form>'
            . '</div>'
            . '<div class="tp-paid-msg" data-role="msg"></div>'
            . '</div></div>';
    }
}
