<?php

/**
 * 数据备份 / 导入核心类
 *
 * 备份文件格式（.lylme.json）：
 * {
 *   "format": "lylme_spage_backup",
 *   "format_version": 1,
 *   "sys_version": "v2.x",
 *   "created_at": 1710000000,
 *   "created_at_str": "2026-10-12 10:00:00",
 *   "site": "example.com",
 *   "tables": { "lylme_links": ["count":n,"rows":[...] }, ... },
 *   "checksum": "sha256(tables的规范化JSON)"
 * }
 *
 * 冲突处理策略：
 *  - skip     跳过：主键已存在的行保留原数据，仅插入新行
 *  - merge    合并：主键已存在的行更新为备份数据，插入新行，本地独有的行保留
 *  - overwrite覆盖：清空目标表后写入备份数据（lylme_config 除外，按 k 键整体覆盖写入，不删除额外键）
 */

if (!defined('IN_CRONLITE')) {
    exit('Access denied');
}

class BackupManager
{
    /** 需要备份的表 => 冲突判断键 */
    public static $tables = array(
        'lylme_config' => 'k',
        'lylme_groups' => 'group_id',
        'lylme_links'  => 'id',
        'lylme_tags'   => 'tag_id',
        'lylme_sou'    => 'sou_id',
        'lylme_apply'  => 'apply_id',
        'lylme_pwd'    => 'pwd_id',
    );

    const FORMAT = 'lylme_spage_backup';
    const FORMAT_VERSION = 1;

    /** 备份文件存放目录 */
    public static function backupDir()
    {
        $dir = ROOT . 'files' . DIRECTORY_SEPARATOR . 'backup';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // 阻止直接通过 URL 访问备份文件（包含敏感配置）
        $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($ht)) {
            @file_put_contents($ht, "Require all denied\nDeny from all\n");
        }
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if (!file_exists($idx)) {
            @file_put_contents($idx, '');
        }
        return $dir;
    }

    /** 写操作日志（JSON 行格式，存于 files/backup/backup.log） */
    public static function log($action, $detail, $result)
    {
        $dir = self::backupDir();
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-';
        $line = json_encode(array(
            'time'   => date('Y-m-d H:i:s'),
            'ip'     => $ip,
            'action' => $action,
            'detail' => $detail,
            'result' => $result,
        ), JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'backup.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /** 读取最近 N 条日志（新的在前） */
    public static function readLogs($limit = 50)
    {
        $file = self::backupDir() . DIRECTORY_SEPARATOR . 'backup.log';
        if (!is_file($file)) {
            return array();
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return array();
        }
        $lines = array_reverse($lines);
        $out = array();
        foreach (array_slice($lines, 0, $limit) as $l) {
            $row = json_decode($l, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** 导出单表数据 */
    public static function exportTable($DB, $table)
    {
        $rows = array();
        $res = $DB->query("SELECT * FROM `{$table}`");
        if ($res === false) {
            return false;
        }
        while ($row = $DB->fetch($res)) {
            if ($row === false) {
                break;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /** 计算数据区校验和 */
    public static function checksum($tables)
    {
        return hash('sha256', json_encode($tables, JSON_UNESCAPED_UNICODE));
    }

    /** 列出备份文件 */
    public static function listFiles()
    {
        $dir = self::backupDir();
        $out = array();
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'backup_*.lylme.json') as $f) {
            $out[] = array(
                'name' => basename($f),
                'size' => filesize($f),
                'time' => filemtime($f),
            );
        }
        usort($out, function ($a, $b) {
            return $b['time'] - $a['time'];
        });
        return $out;
    }

    /** 校验备份文件名是否合法（防路径穿越） */
    public static function validFileName($name)
    {
        return is_string($name) && preg_match('/^backup_\d{8}_\d{6}_[a-z0-9]{8}\.lylme\.json$/', $name);
    }

    /**
     * 校验备份文件内容
     * @return array array('ok'=>bool,'msg'=>..,'data'=>解析后的数组,'summary'=>..)
     */
    public static function validateFile($path)
    {
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 10) {
            return array('ok' => false, 'msg' => '文件无法读取或内容为空');
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return array('ok' => false, 'msg' => '文件不是合法的 JSON 格式');
        }
        if (!isset($data['format']) || $data['format'] !== self::FORMAT) {
            return array('ok' => false, 'msg' => '文件格式标识不正确，不是本系统的备份文件');
        }
        if (!isset($data['format_version']) || (int) $data['format_version'] > self::FORMAT_VERSION) {
            return array('ok' => false, 'msg' => '备份文件格式版本过新，请升级系统后再导入');
        }
        if (!isset($data['tables']) || !is_array($data['tables'])) {
            return array('ok' => false, 'msg' => '备份文件缺少数据区(tables)');
        }
        if (!isset($data['checksum']) || $data['checksum'] !== self::checksum($data['tables'])) {
            return array('ok' => false, 'msg' => '数据完整性校验失败（校验和不匹配），文件可能已损坏或被篡改');
        }
        $summary = array();
        foreach ($data['tables'] as $t => $info) {
            if (!isset(self::$tables[$t])) {
                continue; // 忽略未知表，兼容旧结构
            }
            if (!is_array($info) || !isset($info['rows']) || !is_array($info['rows'])) {
                return array('ok' => false, 'msg' => "表 {$t} 的数据结构不合法");
            }
            $summary[$t] = count($info['rows']);
        }
        if (empty($summary)) {
            return array('ok' => false, 'msg' => '备份文件中没有可导入的数据表');
        }
        return array(
            'ok' => true,
            'msg' => '校验通过',
            'data' => $data,
            'summary' => $summary,
        );
    }

    /** 获取目标表当前真实列名（用于过滤未知列，兼容不同版本结构） */
    public static function tableColumns($DB, $table)
    {
        $cols = array();
        $res = $DB->query("SHOW COLUMNS FROM `{$table}`");
        if ($res !== false) {
            while ($row = $DB->fetch($res)) {
                if ($row === false) {
                    break;
                }
                if (isset($row['Field'])) {
                    $cols[] = $row['Field'];
                }
            }
        }
        return $cols;
    }

    /**
     * 导入单表
     * @param string $strategy skip|merge|overwrite
     * @return array 统计 array('success'=>n,'skip'=>n,'fail'=>n,'errors'=>[..])
     */
    public static function importTable($DB, $table, $rows, $strategy)
    {
        $stat = array('success' => 0, 'skip' => 0, 'fail' => 0, 'errors' => array());
        if (!isset(self::$tables[$table]) || !is_array($rows)) {
            $stat['errors'][] = '未知的数据表';
            $stat['fail'] = is_array($rows) ? count($rows) : 0;
            return $stat;
        }
        $key = self::$tables[$table];

        // 覆盖策略：先清空表（config 表除外，避免误删导致配置丢失）
        if ($strategy === 'overwrite' && $table !== 'lylme_config') {
            if ($DB->query("DELETE FROM `{$table}`") === false) {
                $stat['errors'][] = '清空表失败: ' . $DB->error();
                $stat['fail'] = count($rows);
                return $stat;
            }
        }

        $cols = self::tableColumns($DB, $table);

        foreach ($rows as $i => $row) {
            if (!is_array($row)) {
                $stat['fail']++;
                $stat['errors'][] = "第 " . ($i + 1) . " 行：数据不是对象格式";
                continue;
            }
            // 过滤未知列（兼容不同版本表结构）
            if (!empty($cols)) {
                $row = array_intersect_key($row, array_flip($cols));
            }
            if (empty($row)) {
                $stat['fail']++;
                $stat['errors'][] = "第 " . ($i + 1) . " 行：无有效字段";
                continue;
            }

            // 冲突检测
            $exists = false;
            $keyVal = isset($row[$key]) ? $row[$key] : null;
            if ($keyVal !== null && $keyVal !== '') {
                $check = $DB->get_row("SELECT `{$key}` FROM `{$table}` WHERE `{$key}` = '" . $DB->escape($keyVal) . "' LIMIT 1");
                $exists = ($check !== false && $check !== null);
            }

            if ($exists && $strategy === 'skip') {
                $stat['skip']++;
                continue;
            }

            if ($exists && ($strategy === 'merge' || $table === 'lylme_config')) {
                // 更新已存在的行
                $sets = array();
                foreach ($row as $k => $v) {
                    if ($k === $key) {
                        continue;
                    }
                    $sets[] = "`{$k}` = " . self::sqlVal($DB, $v);
                }
                $ok = true;
                if (!empty($sets)) {
                    $ok = $DB->query("UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE `{$key}` = '" . $DB->escape($keyVal) . "' LIMIT 1") !== false;
                }
                if ($ok) {
                    $stat['success']++;
                } else {
                    $stat['fail']++;
                    $stat['errors'][] = "第 " . ($i + 1) . " 行({$key}={$keyVal})：更新失败 " . $DB->error();
                }
                continue;
            }

            // 插入（overwrite 清表后 / 非冲突行 / merge 的新行）
            $fields = array();
            $values = array();
            foreach ($row as $k => $v) {
                $fields[] = "`{$k}`";
                $values[] = self::sqlVal($DB, $v);
            }
            $sql = "INSERT INTO `{$table}` (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
            // merge/skip 策略下主键仍可能冲突（如键为空靠自增），用 REPLACE 兜底覆盖
            if ($strategy === 'overwrite' && $table === 'lylme_config') {
                $sql = "REPLACE INTO `{$table}` (" . implode(',', $fields) . ") VALUES (" . implode(',', $values) . ")";
            }
            if ($DB->query($sql) !== false) {
                $stat['success']++;
            } else {
                $stat['fail']++;
                $err = $DB->error();
                $stat['errors'][] = "第 " . ($i + 1) . " 行：插入失败 " . $err;
            }
        }
        return $stat;
    }

    /** 生成 SQL 值字面量（NULL 安全） */
    private static function sqlVal($DB, $v)
    {
        if ($v === null) {
            return 'NULL';
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        return "'" . $DB->escape((string) $v) . "'";
    }

    /** 格式化文件大小 */
    public static function fmtSize($bytes)
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
