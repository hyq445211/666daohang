<?php
/**
 * 数据备份 / 导入 AJAX 接口
 * 权限：仅登录管理员可用（沿用现有 admin_token 校验）
 */
header('Content-Type:application/json; charset=utf-8');
include_once("../include/common.php");
include_once("../include/backup.php");

if (!isset($islogin) || $islogin !== 1) {
    exit(json_encode(array('code' => -1, 'msg' => '未登录或无权限'), JSON_UNESCAPED_UNICODE));
}

function bak_resp($code, $msg, $extra = array())
{
    $res = array_merge(array('code' => $code, 'msg' => $msg), $extra);
    exit(json_encode($res, JSON_UNESCAPED_UNICODE));
}

$act = isset($_REQUEST['act']) ? $_REQUEST['act'] : '';
$dir = BackupManager::backupDir();

// 临时文件 token 校验（防路径穿越）
function bak_valid_token($t)
{
    return is_string($t) && preg_match('/^[a-z0-9]{16}$/', $t);
}

switch ($act) {

    // ========== 备份：初始化导出任务 ==========
    case 'export_start':
        $token = substr(md5(uniqid(mt_rand(), true)), 0, 16);
        $tmp = $dir . '/tmp_export_' . $token . '.json';
        $meta = array(
            'format' => BackupManager::FORMAT,
            'format_version' => BackupManager::FORMAT_VERSION,
            'sys_version' => defined('VERSION') ? VERSION : 'unknown',
            'created_at' => time(),
            'created_at_str' => date('Y-m-d H:i:s'),
            'site' => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost',
        );
        if (@file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_UNICODE)) === false) {
            BackupManager::log('backup', '创建临时文件失败', '失败');
            bak_resp(-1, '备份目录不可写，请检查 files/backup 目录权限');
        }
        BackupManager::log('backup', '开始备份任务 token=' . $token, '进行中');
        bak_resp(1, 'ok', array(
            'token' => $token,
            'meta' => $meta,
            'tables' => array_keys(BackupManager::$tables),
        ));
        break;

    // ========== 备份：导出单表（前端逐表调用以显示进度） ==========
    case 'export_table':
        $token = isset($_POST['token']) ? $_POST['token'] : '';
        $table = isset($_POST['table']) ? $_POST['table'] : '';
        if (!bak_valid_token($token) || !isset(BackupManager::$tables[$table])) {
            bak_resp(-1, '参数不合法');
        }
        $tmp = $dir . '/tmp_export_' . $token . '.json';
        $dataFile = $dir . '/tmp_export_' . $token . '.data';
        if (!is_file($tmp)) {
            bak_resp(-1, '导出任务不存在或已过期');
        }
        $rows = BackupManager::exportTable($DB, $table);
        if ($rows === false) {
            bak_resp(-1, '读取表 ' . $table . ' 失败: ' . $DB->error());
        }
        // 每表数据单独落盘，避免大表占用内存
        $part = array($table => array('count' => count($rows), 'rows' => $rows));
        if (@file_put_contents($dataFile . '.' . $table, json_encode($part, JSON_UNESCAPED_UNICODE)) === false) {
            bak_resp(-1, '写入临时数据失败');
        }
        bak_resp(1, 'ok', array('table' => $table, 'count' => count($rows)));
        break;

    // ========== 备份：合并生成最终文件（含完整性校验和） ==========
    case 'export_finish':
        $token = isset($_POST['token']) ? $_POST['token'] : '';
        if (!bak_valid_token($token)) {
            bak_resp(-1, '参数不合法');
        }
        $tmp = $dir . '/tmp_export_' . $token . '.json';
        if (!is_file($tmp)) {
            bak_resp(-1, '导出任务不存在或已过期');
        }
        $meta = json_decode(file_get_contents($tmp), true);
        $tables = array();
        foreach (array_keys(BackupManager::$tables) as $t) {
            $partFile = $dir . '/tmp_export_' . $token . '.data.' . $t;
            if (is_file($partFile)) {
                $part = json_decode(file_get_contents($partFile), true);
                if (is_array($part) && isset($part[$t])) {
                    $tables[$t] = $part[$t];
                }
                @unlink($partFile);
            }
        }
        if (empty($tables)) {
            @unlink($tmp);
            bak_resp(-1, '没有已导出的数据');
        }
        $meta['tables'] = $tables;
        $meta['checksum'] = BackupManager::checksum($tables);
        $fname = 'backup_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8) . '.lylme.json';
        $fpath = $dir . '/' . $fname;
        if (@file_put_contents($fpath, json_encode($meta, JSON_UNESCAPED_UNICODE)) === false) {
            @unlink($tmp);
            BackupManager::log('backup', '写入备份文件失败', '失败');
            bak_resp(-1, '备份文件写入失败');
        }
        @unlink($tmp);
        $total = 0;
        foreach ($tables as $info) {
            $total += $info['count'];
        }
        BackupManager::log('backup', '备份完成 ' . $fname . '，共 ' . $total . ' 条数据', '成功');
        bak_resp(1, '备份完成', array(
            'file' => $fname,
            'size' => filesize($fpath),
            'total' => $total,
        ));
        break;

    // ========== 备份：取消/清理临时任务 ==========
    case 'export_abort':
        $token = isset($_POST['token']) ? $_POST['token'] : '';
        if (bak_valid_token($token)) {
            @unlink($dir . '/tmp_export_' . $token . '.json');
            foreach (array_keys(BackupManager::$tables) as $t) {
                @unlink($dir . '/tmp_export_' . $token . '.data.' . $t);
            }
        }
        bak_resp(1, 'ok');
        break;

    // ========== 备份列表 ==========
    case 'list':
        $files = BackupManager::listFiles();
        foreach ($files as &$f) {
            $f['size_str'] = BackupManager::fmtSize($f['size']);
            $f['time_str'] = date('Y-m-d H:i:s', $f['time']);
        }
        bak_resp(1, 'ok', array('files' => $files));
        break;

    // ========== 下载备份 ==========
    case 'download':
        $name = isset($_GET['file']) ? $_GET['file'] : '';
        if (!BackupManager::validFileName($name)) {
            bak_resp(-1, '文件名不合法');
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            bak_resp(-1, '文件不存在');
        }
        BackupManager::log('download', '下载备份 ' . $name, '成功');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;

    // ========== 删除备份 ==========
    case 'delete':
        $name = isset($_POST['file']) ? $_POST['file'] : '';
        if (!BackupManager::validFileName($name)) {
            bak_resp(-1, '文件名不合法');
        }
        $path = $dir . '/' . $name;
        if (!is_file($path)) {
            bak_resp(-1, '文件不存在');
        }
        if (@unlink($path)) {
            BackupManager::log('delete', '删除备份 ' . $name, '成功');
            bak_resp(1, '已删除');
        }
        BackupManager::log('delete', '删除备份 ' . $name, '失败');
        bak_resp(-1, '删除失败');
        break;

    // ========== 导入：上传并校验 ==========
    case 'import_upload':
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $err = isset($_FILES['file']) ? $_FILES['file']['error'] : 'no file';
            bak_resp(-1, '文件上传失败(错误码:' . $err . ')');
        }
        if ($_FILES['file']['size'] > 50 * 1024 * 1024) {
            bak_resp(-1, '文件超过 50MB 限制');
        }
        $token = substr(md5(uniqid(mt_rand(), true)), 0, 16);
        $tmp = $dir . '/tmp_import_' . $token . '.json';
        if (!@move_uploaded_file($_FILES['file']['tmp_name'], $tmp)) {
            if (!@copy($_FILES['file']['tmp_name'], $tmp)) {
                bak_resp(-1, '临时文件保存失败');
            }
        }
        $check = BackupManager::validateFile($tmp);
        if (!$check['ok']) {
            @unlink($tmp);
            BackupManager::log('import', '上传文件校验失败：' . $check['msg'], '失败');
            bak_resp(-1, $check['msg']);
        }
        $d = $check['data'];
        BackupManager::log('import', '上传备份文件并通过校验 token=' . $token, '成功');
        bak_resp(1, '文件校验通过', array(
            'token' => $token,
            'created_at_str' => isset($d['created_at_str']) ? $d['created_at_str'] : '-',
            'site' => isset($d['site']) ? $d['site'] : '-',
            'sys_version' => isset($d['sys_version']) ? $d['sys_version'] : '-',
            'summary' => $check['summary'],
        ));
        break;

    // ========== 导入：执行单表导入（前端逐表调用以显示进度） ==========
    case 'import_table':
        $token = isset($_POST['token']) ? $_POST['token'] : '';
        $table = isset($_POST['table']) ? $_POST['table'] : '';
        $strategy = isset($_POST['strategy']) ? $_POST['strategy'] : 'skip';
        if (!bak_valid_token($token) || !isset(BackupManager::$tables[$table])) {
            bak_resp(-1, '参数不合法');
        }
        if (!in_array($strategy, array('skip', 'merge', 'overwrite'), true)) {
            bak_resp(-1, '未知的冲突处理策略');
        }
        $tmp = $dir . '/tmp_import_' . $token . '.json';
        if (!is_file($tmp)) {
            bak_resp(-1, '导入任务不存在或已过期，请重新上传');
        }
        $check = BackupManager::validateFile($tmp);
        if (!$check['ok']) {
            @unlink($tmp);
            bak_resp(-1, '文件校验失败：' . $check['msg']);
        }
        $rows = isset($check['data']['tables'][$table]['rows']) ? $check['data']['tables'][$table]['rows'] : array();
        $stat = BackupManager::importTable($DB, $table, $rows, $strategy);
        BackupManager::log(
            'import',
            "导入表 {$table}（策略:{$strategy}）成功 {$stat['success']} 跳过 {$stat['skip']} 失败 {$stat['fail']}",
            $stat['fail'] > 0 ? '部分失败' : '成功'
        );
        bak_resp(1, 'ok', array('table' => $table, 'stat' => $stat));
        break;

    // ========== 导入：结束（清理临时文件） ==========
    case 'import_end':
        $token = isset($_POST['token']) ? $_POST['token'] : '';
        if (bak_valid_token($token)) {
            @unlink($dir . '/tmp_import_' . $token . '.json');
        }
        bak_resp(1, 'ok');
        break;

    // ========== 操作日志 ==========
    case 'logs':
        bak_resp(1, 'ok', array('logs' => BackupManager::readLogs(50)));
        break;

    default:
        bak_resp(-1, '未知操作');
}
