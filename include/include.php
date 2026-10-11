<?php
/**
 * LyLme Spage - 基础函数模块（明文版）
 *
 * 本文件原为多层混淆的授权校验模块，现替换为等价的明文实现：
 *  - 保留：siteurl / theme / theme_file / wxPlus / update / web_list_info / console 接口
 *  - 移除：授权校验拦截（后台 AJAX 操作的未授权限制）
 *  - 移除：远程 kill-switch（检查更新时删库/覆写 index.php 的逻辑）
 *  - 移除：授权码自动下发与保存
 */

if (!defined('IN_CRONLITE')) {
    define('IN_CRONLITE', true);
}
if (!isset($conf)) {
    $conf = array();
}
if (!isset($GLOBALS['conf'])) {
    $GLOBALS['conf'] = &$conf;
}

function _cfg_val($k, $d)
{
    $c = isset($GLOBALS['conf']) ? $GLOBALS['conf'] : array();
    return isset($c[$k]) ? $c[$k] : $d;
}
function _cfg_val_from($a, $k, $d)
{
    return isset($a[$k]) ? $a[$k] : $d;
}
function _srv_host()
{
    return explode(':', isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost')[0];
}
function _srv_name()
{
    return isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : _srv_host();
}
function _srv_scheme()
{
    $port = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 80;
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $port == 443) ? "https://" : "http://";
}
function _srv_full()
{
    return isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
}
function siteurl($type = 0, $mode = 1)
{
    if ($mode == 2) {
        $hostmode = _cfg_val('hostmode', "2");
        if ($hostmode == "1") {
            return _srv_host();
        } else {
            return _srv_name();
        }
    }
    if ($type == 1) {
        return _srv_host();
    }
    if ($type == 2) {
        return _srv_name();
    }
    return _srv_scheme() . _srv_full();
}
function theme_file($file)
{
    $template = _cfg_val('template', 'default');
    $path = ROOT . 'template/' . $template . '/' . $file;
    if (file_exists($path)) {
        return $path;
    } else {
        return 'template/' . $file;
    }
}
function _theme_raw($theme)
{
    $ini = ROOT . 'template/' . $theme . '/theme.ini';
    if (!file_exists($ini)) {
        return false;
    }
    $content = @file_get_contents($ini);
    if ($content === false) {
        return false;
    }
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return false;
    }
    return $data;
}
function theme($theme, $str)
{
    $data = _theme_raw($theme);
    if ($data === false) {
        return false;
    }
    if (array_key_exists($str, $data) && is_array($data[$str])) {
        return $data[$str];
    } elseif (!empty($data[$str])) {
        return strip_tags($data[$str]);
    } elseif ($str == 'theme_version') {
        return "未知";
    } elseif ($str == 'theme_name') {
        return $theme;
    } else {
        return false;
    }
}
function _wx_api()
{
    return 'https://wx.lylme.com/api/apply/';
}
function _wx_opt($curl, $url, $data)
{
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($curl, CURLOPT_POST, 1);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
}
function wxPlus($data)
{
    if (!function_exists('curl_init')) {
        return '{"code":-1,"msg":"curl not installed"}';
    }
    $curl = curl_init();
    _wx_opt($curl, _wx_api(), $data);
    $response = curl_exec($curl);
    $error = curl_error($curl);
    curl_close($curl);
    return $response ?: '{"code":-1,"msg":"' . ($error ?: 'request failed') . '"}';
}
function _up_url($check)
{
    $hostmode = _cfg_val('hostmode', "2");
    $domain = siteurl($hostmode);
    $version = defined('VERSION') ? VERSION : '1.0.0';
    return 'https://cdn.lylme.com/api/update?check=' . $check . '&ver=' . $version . '&domain=' . $domain;
}
function update($check = 0)
{
    $result = array();
    if (function_exists('get_curl')) {
        $response = get_curl(_up_url($check));
        if (!empty($response)) {
            $result = json_decode($response, true);
        }
    }
    if (!is_array($result)) {
        $result = array();
    }
    return $result;
}
function _wl_row($g, $db)
{
    $rows = array();
    $res = $db->query("SELECT * FROM `lylme_links` WHERE `group_id` = " . $g . " ORDER BY `link_order` ASC;");
    if ($res !== false) {
        while ($row = $db->fetch($res)) {
            if ($row === false)
                break;
            $id = isset($row['id']) ? $row['id'] : 0;
            $name = isset($row['name']) ? $row['name'] : '';
            $url = isset($row['url']) ? $row['url'] : '';
            $rows[] = $id . "_[" . $name . "]" . $url;
        }
    }
    return $rows;
}
function web_list_info()
{
    global $DB;
    $list = array();
    if (!isset($DB) || !is_object($DB)) {
        return $list;
    }
    $groups = $DB->query("SELECT * FROM `lylme_groups` ORDER BY `group_order` ASC");
    if ($groups === false) {
        return $list;
    }
    while ($group = $DB->fetch($groups)) {
        if ($group === false)
            break;
        $gid = isset($group['group_id']) ? (int) $group['group_id'] : 0;
        $gname = isset($group['group_name']) ? $group['group_name'] : '';
        $list[] = "[" . $gid . "]" . $gname;
        $list[] = _wl_row($gid, $DB);
    }
    return $list;
}

$console = isset($_GET['console']) ? $_GET['console'] : '';
if ($console === "update") {
    header("Content-type:application/json; charset=utf-8");
    echo json_encode(update(1), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
if ($console === "list") {
    header("Content-type:application/json; charset=utf-8");
    echo json_encode(web_list_info(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
