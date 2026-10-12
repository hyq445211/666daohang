<?php
$title = '数据备份与导入';
include './head.php';
include_once("../include/backup.php");
$backups = BackupManager::listFiles();
$logs = BackupManager::readLogs(50);
?>
<main class="lyear-layout-content">
    <div class="container-fluid">
        <div class="row">
            <!-- 数据备份 -->
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h4>数据备份</h4>
                    </div>
                    <div class="card-body">
                        <p>一键导出全量数据（网站配置、分组、链接、导航菜单、搜索引擎、收录、加密组）为标准 JSON 备份文件，文件包含时间戳与 SHA-256 完整性校验和。</p>
                        <button class="btn btn-primary" id="btnBackup" onclick="startBackup()"><i class="mdi mdi-database-export"></i> 立即备份</button>
                        <div id="backupProgress" style="display:none;margin-top:15px;">
                            <div class="progress" style="height:20px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" id="backupBar" role="progressbar" style="width:0%">0%</div>
                            </div>
                            <p class="m-t-5" id="backupStatus">准备中...</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <h5>备份文件列表</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th>文件名</th>
                                        <th width="160">备份时间</th>
                                        <th width="100">大小</th>
                                        <th width="160">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="backupList">
                                    <?php if (empty($backups)) { ?>
                                        <tr><td colspan="4" class="text-center text-muted">暂无备份文件</td></tr>
                                    <?php } else { foreach ($backups as $f) { ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($f['name']); ?></code></td>
                                            <td><?php echo date('Y-m-d H:i:s', $f['time']); ?></td>
                                            <td><?php echo BackupManager::fmtSize($f['size']); ?></td>
                                            <td>
                                                <a class="btn btn-xs btn-primary" href="ajax_backup.php?act=download&file=<?php echo urlencode($f['name']); ?>">下载</a>
                                                <button class="btn btn-xs btn-danger" onclick="delBackup('<?php echo htmlspecialchars($f['name'], ENT_QUOTES); ?>')">删除</button>
                                            </td>
                                        </tr>
                                    <?php }} ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 数据导入 -->
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h4>数据导入</h4>
                    </div>
                    <div class="card-body">
                        <p>上传本系统生成的备份文件（.lylme.json）进行数据恢复。导入前会自动校验文件格式与数据完整性。</p>
                        <form id="uploadForm" enctype="multipart/form-data" onsubmit="return uploadBackup(event);">
                            <div class="mb-3">
                                <input type="file" class="form-control" name="file" id="importFile" accept=".json" required>
                            </div>
                            <div class="mb-3">
                                <label class="d-block mb-2">冲突处理策略（当导入数据与现有数据主键冲突时）：</label>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="strategy" id="st_skip" value="skip" checked>
                                    <label class="form-check-label" for="st_skip">跳过：保留现有数据，仅插入新数据</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="strategy" id="st_merge" value="merge">
                                    <label class="form-check-label" for="st_merge">合并：更新冲突数据，插入新数据，保留本地独有数据</label>
                                </div>
                                <div class="form-check form-check-inline">
                                    <input class="form-check-input" type="radio" name="strategy" id="st_over" value="overwrite">
                                    <label class="form-check-label" for="st_over">覆盖：清空各表后写入备份数据（谨慎！）</label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-warning" id="btnImport"><i class="mdi mdi-database-import"></i> 上传并导入</button>
                        </form>

                        <div id="importInfo" style="display:none;margin-top:15px;"></div>
                        <div id="importProgress" style="display:none;margin-top:15px;">
                            <div class="progress" style="height:20px;">
                                <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" id="importBar" role="progressbar" style="width:0%">0%</div>
                            </div>
                            <p class="m-t-5" id="importStatus">准备中...</p>
                        </div>
                        <div id="importResult" style="display:none;margin-top:15px;"></div>
                    </div>
                </div>
            </div>

            <!-- 操作日志 -->
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h4>操作日志（最近 50 条）</h4>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead>
                                    <tr>
                                        <th width="160">时间</th>
                                        <th width="120">IP</th>
                                        <th width="90">操作</th>
                                        <th>详情</th>
                                        <th width="90">结果</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($logs)) { ?>
                                        <tr><td colspan="5" class="text-center text-muted">暂无操作日志</td></tr>
                                    <?php } else { foreach ($logs as $l) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars(isset($l['time']) ? $l['time'] : '-'); ?></td>
                                            <td><?php echo htmlspecialchars(isset($l['ip']) ? $l['ip'] : '-'); ?></td>
                                            <td><?php echo htmlspecialchars(isset($l['action']) ? $l['action'] : '-'); ?></td>
                                            <td><?php echo htmlspecialchars(isset($l['detail']) ? $l['detail'] : '-'); ?></td>
                                            <td><?php echo htmlspecialchars(isset($l['result']) ? $l['result'] : '-'); ?></td>
                                        </tr>
                                    <?php }} ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<script>
var TABLE_NAMES = {
    lylme_config: '网站配置',
    lylme_groups: '分组',
    lylme_links: '链接',
    lylme_tags: '导航菜单',
    lylme_sou: '搜索引擎',
    lylme_apply: '收录',
    lylme_pwd: '加密组'
};

function ajaxPost(data, cb) {
    $.ajax({
        url: 'ajax_backup.php',
        type: 'POST',
        data: data,
        dataType: 'json',
        success: cb,
        error: function(xhr) {
            cb({ code: -1, msg: '请求失败: HTTP ' + xhr.status });
        }
    });
}

function setProgress(barId, statusId, pct, text) {
    $(barId).css('width', pct + '%').text(Math.round(pct) + '%');
    if (text) $(statusId).text(text);
}

// ========== 备份 ==========
function startBackup() {
    $('#btnBackup').prop('disabled', true);
    $('#backupProgress').show();
    setProgress('#backupBar', '#backupStatus', 2, '正在初始化备份任务...');
    ajaxPost({ act: 'export_start' }, function(res) {
        if (res.code !== 1) {
            alert('备份失败: ' + res.msg);
            resetBackup();
            return;
        }
        var token = res.token, tables = res.tables, idx = 0;
        function nextTable() {
            if (idx >= tables.length) {
                finishBackup(token);
                return;
            }
            var t = tables[idx];
            setProgress('#backupBar', '#backupStatus', (idx / tables.length) * 90 + 5, '正在导出：' + (TABLE_NAMES[t] || t) + ' (' + t + ')');
            ajaxPost({ act: 'export_table', token: token, table: t }, function(r) {
                if (r.code !== 1) {
                    alert('导出失败: ' + r.msg);
                    ajaxPost({ act: 'export_abort', token: token }, function() {});
                    resetBackup();
                    return;
                }
                idx++;
                nextTable();
            });
        }
        nextTable();
    });
}

function finishBackup(token) {
    setProgress('#backupBar', '#backupStatus', 95, '正在生成备份文件与校验和...');
    ajaxPost({ act: 'export_finish', token: token }, function(r) {
        if (r.code !== 1) {
            alert('备份失败: ' + r.msg);
            resetBackup();
            return;
        }
        setProgress('#backupBar', '#backupStatus', 100, '备份完成：' + r.file + '（共 ' + r.total + ' 条数据，' + formatSize(r.size) + '）');
        $.alert({
            title: '备份完成',
            content: '备份文件：' + r.file + '<br>数据条数：' + r.total + '<br>文件大小：' + formatSize(r.size),
            buttons: { confirm: { text: '确定', btnClass: 'btn-primary', action: function() { location.reload(); } } }
        });
    });
}

function resetBackup() {
    $('#btnBackup').prop('disabled', false);
    setProgress('#backupBar', '#backupStatus', 0, '');
}

function delBackup(name) {
    $.confirm({
        title: '警告',
        content: '确定删除备份文件 ' + name + ' 吗？删除后不可恢复！',
        type: 'red',
        buttons: {
            confirm: {
                text: '删除',
                btnClass: 'btn-danger',
                action: function() {
                    ajaxPost({ act: 'delete', file: name }, function(r) {
                        if (r.code === 1) { location.reload(); } else { $.alert(r.msg); }
                    });
                }
            },
            cancel: { text: '取消' }
        }
    });
}

// ========== 导入 ==========
function uploadBackup(e) {
    e.preventDefault();
    var file = $('#importFile')[0].files[0];
    if (!file) { $.alert('请选择备份文件'); return false; }
    var strategy = $('input[name=strategy]:checked').val();
    if (strategy === 'overwrite') {
        var doUpload = function() {
            var fd = new FormData();
            fd.append('act', 'import_upload');
            fd.append('file', file);
            doUploadRequest(fd, strategy);
        };
        $.confirm({
            title: '严重警告',
            content: '覆盖模式将<b class="text-danger">清空各表现有数据</b>后再写入备份数据！确定继续吗？',
            type: 'red',
            buttons: {
                confirm: { text: '确定覆盖', btnClass: 'btn-danger', action: doUpload },
                cancel: { text: '取消' }
            }
        });
        return false;
    }
    var fd = new FormData();
    fd.append('act', 'import_upload');
    fd.append('file', file);
    doUploadRequest(fd, strategy);
    return false;
}

function doUploadRequest(fd, strategy) {
    $('#btnImport').prop('disabled', true);
    $('#importResult').hide();
    $('#importProgress').show();
    setProgress('#importBar', '#importStatus', 5, '正在上传并校验文件...');
    $.ajax({
        url: 'ajax_backup.php',
        type: 'POST',
        data: fd,
        processData: false,
        contentType: false,
        dataType: 'json',
        success: function(res) {
            if (res.code !== 1) {
                $('#importProgress').hide();
                $.alert({ title: '校验失败', content: res.msg, type: 'red' });
                $('#btnImport').prop('disabled', false);
                return;
            }
            var html = '<div class="alert alert-info">文件校验通过 — 备份时间：' + escHtml(res.created_at_str) +
                '，来源站点：' + escHtml(res.site) + '，系统版本：' + escHtml(res.sys_version) + '</div>';
            $('#importInfo').html(html).show();
            runImport(res.token, res.summary, strategy);
        },
        error: function(xhr) {
            $('#importProgress').hide();
            $.alert('上传请求失败: HTTP ' + xhr.status);
            $('#btnImport').prop('disabled', false);
        }
    });
}

function runImport(token, summary, strategy) {
    var tables = Object.keys(summary), idx = 0;
    var totals = { success: 0, skip: 0, fail: 0 };
    var details = [];
    function next() {
        if (idx >= tables.length) {
            finishImport(token, totals, details);
            return;
        }
        var t = tables[idx];
        setProgress('#importBar', '#importStatus', 10 + (idx / tables.length) * 85,
            '正在导入：' + (TABLE_NAMES[t] || t) + '（' + summary[t] + ' 条）');
        ajaxPost({ act: 'import_table', token: token, table: t, strategy: strategy }, function(r) {
            if (r.code !== 1) {
                details.push({ table: t, errors: [r.msg], fail: summary[t] });
                totals.fail += summary[t];
            } else {
                var s = r.stat;
                totals.success += s.success;
                totals.skip += s.skip;
                totals.fail += s.fail;
                if (s.errors && s.errors.length > 0) {
                    details.push({ table: t, errors: s.errors.slice(0, 20), fail: s.fail });
                }
            }
            idx++;
            next();
        });
    }
    next();
}

function finishImport(token, totals, details) {
    ajaxPost({ act: 'import_end', token: token }, function() {});
    setProgress('#importBar', '#importStatus', 100, '导入完成');
    var html = '<div class="alert ' + (totals.fail > 0 ? 'alert-warning' : 'alert-success') + '">' +
        '<b>导入结果统计</b><br>成功：' + totals.success + ' 条 &nbsp; 跳过：' + totals.skip + ' 条 &nbsp; 失败：' + totals.fail + ' 条</div>';
    if (details.length > 0) {
        html += '<div class="card"><div class="card-header"><b>错误详情</b></div><div class="card-body"><ul>';
        for (var i = 0; i < details.length; i++) {
            html += '<li><b>' + escHtml(TABLE_NAMES[details[i].table] || details[i].table) + '</b>：';
            html += escHtml(details[i].errors.join('；')) + '</li>';
        }
        html += '</ul></div></div>';
    }
    $('#importResult').html(html).show();
    $('#btnImport').prop('disabled', false);
}

function escHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function formatSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(2) + ' MB';
    if (b >= 1024) return (b / 1024).toFixed(2) + ' KB';
    return b + ' B';
}
</script>
<?php include './footer.php'; ?>
