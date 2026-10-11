<?php
function admin_form($action, $id, $label, $extra = '') {
    return '<form method="post" class="inline">'.csrf_field().'<input type="hidden" name="id" value="'.(int)$id.'">'.$extra.'<button name="action" value="'.h($action).'">'.h($label).'</button></form>';
}
function admin_transaction($fn) {
    global $conn;
    $conn->begin_transaction();
    try { $fn(); $conn->commit(); $_SESSION['admin_notice']='操作成功'; }
    catch (Throwable $e) { $conn->rollback(); audit_log('operation.failed', '', '', ['reason'=>$e instanceof DomainException ? $e->getMessage() : 'database_error'], 'failure'); if (!($e instanceof DomainException)) error_log($e); $_SESSION['admin_notice']=$e instanceof DomainException ? $e->getMessage() : '操作未完成，请重试'; }
}
function admin_pagination($page, $count) {
    echo '<nav class="tabs">';
    foreach (['上一页'=>$page-1,'下一页'=>$page+1] as $label=>$number) if ($number>0 && ($number-1)*20<$count) echo '<a href="?'.h(http_build_query(array_merge($_GET,['page'=>$number]))).'">'.$label.'</a> ';
    echo '第 '.(int)$page.' 页 · 共 '.(int)$count.' 条</nav>';
}
