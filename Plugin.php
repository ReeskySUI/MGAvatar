<?php
/**
 * 用 URL 图片替换 Gravatar 头像。
 *
 * @package MGAvatar
 * @author 明关
 * @version 1.1.4
 * @link https://blog.mghub.top
 * @license MIT
 */

namespace TypechoPlugin\MGAvatar;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Layout;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * MGAvatar 插件主类。
 */
class Plugin implements PluginInterface
{
    /**
     * @var array uid => 头像 URL 的缓存
     */
    private static $userAvatarCache = array();

    public static function activate()
    {
        \Typecho\Plugin::factory('Widget\Base\Comments')->gravatar = array(__CLASS__, 'renderCommentAvatar');
        \Typecho\Plugin::factory('admin/profile.php')->bottom = array(__CLASS__, 'renderProfileScript');
        return _t('MGAvatar 已启用：请在“个人设置”中填写头像 URL，全站不再请求 Gravatar。');
    }

    public static function deactivate()
    {
    }

    public static function config(Form $form)
    {
        // 详细说明在插件设置页展示（插件列表仅保留一行描述）。
        $note = new Layout('div', array('class' => 'message notice'));
        $note->html(
            '<p><strong>使用方法</strong></p>'
            . '<ol>'
            . '<li>每位用户进入后台「个人设置」页，在表单顶部的 <strong>MGAvatar</strong> 区填写「头像 URL」并保存。</li>'
            . '<li>评论头像（前台评论列表、后台评论管理）与个人设置页头像均使用该地址，全站不再请求 gravatar.com。<strong>注意：「设置 → 评论」中的「启用 Gravatar 头像服务」必须保持开启</strong>——它是头像显示的总开关；插件接管该输出后只显示 URL 图片，不会真正请求 Gravatar。</li>'
            . '<li>未填写个人头像的用户，使用下方「全局默认头像地址」；该地址也留空时，使用插件自带的默认头像。</li>'
            . '<li>插件自包含默认头像，不依赖任何主题，可单独安装使用。</li>'
            . '</ol>'
        );
        $form->addItem($note);

        $defaultAvatar = new Text(
            'defaultAvatar',
            null,
            null,
            _t('全局默认头像地址'),
            _t('未填写个人头像的用户评论时显示此头像；留空时使用插件自带的默认头像（assets/default-avatar.png）。')
        );
        $form->addInput($defaultAvatar);

        $replaceProfile = new Radio(
            'replaceProfile',
            array(
                '1' => _t('替换'),
                '0' => _t('不替换')
            ),
            '1',
            _t('替换个人设置页头像'),
            _t('在后台“个人设置”页用个人头像或默认头像替换 Gravatar，并移除指向 gravatar.com 的链接。')
        );
        $form->addInput($replaceProfile);
    }

    public static function personalConfig(Form $form)
    {
        $avatarUrl = new Text(
            'avatarUrl',
            null,
            null,
            _t('头像 URL'),
            _t('填写图片完整地址，你的评论头像与“个人设置”页头像将使用此图片；留空则使用全局默认头像。')
        );
        $form->addInput($avatarUrl);
    }

    /**
     * 评论头像钩子（替代 Widget\Base\Comments::gravatar 的默认输出）。
     */
    public static function renderCommentAvatar($size, $rating, $default, $comment)
    {
        $options = \Helper::options();
        $url = '';

        if ((int) ($comment->authorId ?? 0) > 0) {
            $url = self::userAvatarUrl((int) $comment->authorId);
        }
        if ($url === '') {
            $url = self::globalDefaultAvatar($options);
        }

        $alt = (string) $comment->author;
        echo '<img class="avatar" loading="lazy" src="' . self::h($url) . '" alt="'
            . self::h($alt) . '" width="' . (int) $size . '" height="' . (int) $size . '" />';
    }

    /**
     * 个人设置页底部注入替换脚本。
     */
    public static function renderProfileScript()
    {
        // 将 MGAvatar 设置区移动到表单区最上方（始终执行，与头像替换开关无关）。
        echo '<script>(function(){';
        echo <<<'JS'
var personal = document.getElementById('personal-MGAvatar');
if (personal) {
    var panel = personal.closest('.typecho-content-panel');
    if (panel) {
        panel.insertBefore(personal, panel.firstElementChild);
        personal.style.marginBottom = '18px';
    }
}
JS;
        echo '}());</script>';

        $options = \Helper::options();
        $user = \Widget\User::alloc();

        if ((string) (self::globalConfig($options)->replaceProfile) !== '1') {
            return;
        }

        $avatar = self::userAvatarUrl((int) $user->uid);
        if ($avatar === '') {
            $avatar = self::globalDefaultAvatar($options);
        }

        echo '<script>(function(){var url=' . json_encode(
            $avatar,
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) . ';';
        echo <<<'JS'
var img = document.querySelector('.profile-avatar');
if (img && url) {
    img.src = url;
    var link = img.closest('a');
    if (link && /gravatar\.com/.test(link.href)) {
        var holder = document.createElement('span');
        link.parentNode.insertBefore(holder, link);
        holder.appendChild(link.removeChild(img));
        link.parentNode.removeChild(link);
    }
}
JS;
        echo '}());</script>';
    }

    /**
     * 读取指定用户的个人头像 URL（存于 options 表，name=_plugin:MGAvatar, user=uid）。
     */
    private static function userAvatarUrl($uid)
    {
        if (array_key_exists($uid, self::$userAvatarCache)) {
            return self::$userAvatarCache[$uid];
        }

        $url = '';
        try {
            $db = \Typecho\Db::get();
            $row = $db->fetchRow(
                $db->select()->from('table.options')
                    ->where('name = ? AND user = ?', '_plugin:MGAvatar', (int) $uid)
            );
            if (!empty($row['value'])) {
                $settings = self::decodeOptions($row['value']);
                $url = self::sanitizeUrl($settings['avatarUrl'] ?? '');
            }
        } catch (\Throwable $e) {
            $url = '';
        }

        self::$userAvatarCache[$uid] = $url;
        return $url;
    }

    /**
     * 解析 options 行值：不同 Typecho 版本的插件配置存储格式不同
     * （部分版本用 JSON，部分版本用 PHP serialize），两种都支持。
     *
     * @param string $raw
     * @return array
     */
    private static function decodeOptions($raw)
    {
        if (!is_string($raw) || $raw === '') {
            return array();
        }

        // JSON 格式（容器内 Typecho 的存储格式）
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // PHP serialize 格式（配置值为纯数组，禁止反序列化任何对象）
        $decoded = @unserialize($raw, ['allowed_classes' => false]);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array();
    }

    /**
     * 全局默认头像：全局配置优先，否则使用插件自带图片。
     */
    private static function globalDefaultAvatar($options)
    {
        $configured = self::sanitizeUrl(self::globalConfig($options)->defaultAvatar);
        if ($configured !== '') {
            return $configured;
        }

        return rtrim((string) $options->pluginUrl, '/') . '/MGAvatar/assets/default-avatar.png';
    }

    /**
     * 读取插件全局配置；配置行缺失时返回带默认值的对象。
     */
    private static function globalConfig($options)
    {
        $config = new \stdClass();
        $config->defaultAvatar = '';
        $config->replaceProfile = '1';

        try {
            $saved = $options->plugin('MGAvatar');
            $config->defaultAvatar = (string) ($saved->defaultAvatar ?? '');
            $config->replaceProfile = (string) ($saved->replaceProfile ?? '1');
        } catch (\Throwable $e) {
            // 配置行未写入时使用默认值。
        }

        return $config;
    }

    /**
     * 仅允许 http/https 协议的头像地址，非法值或空值返回空串。
     *
     * @param mixed $url
     * @return string
     */
    private static function sanitizeUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    private static function h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
