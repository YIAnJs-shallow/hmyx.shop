<?php
require_once __DIR__.'/lib/shop.php';
$userId=uid(); $id=(int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
$append= (($_POST['append'] ?? $_GET['append'] ?? 0) === 1) || (($_POST['append'] ?? $_GET['append'] ?? 0) === '1');
$item=one("SELECT i.* FROM order_items i JOIN orders o ON o.id=i.order_id WHERE i.id=? AND o.user_id=? AND o.status='completed'",[$id,$userId]);
if(!$item || !$item['product_id']) { http_response_code(403); exit('仅可评价本人已完成订单中的商品'); }
$error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if($append) { append_review($userId,$id,$_POST['content'] ?? ''); redirect_to('detail.php?id='.(int)$item['product_id'],'追评已发布'); }
        $rating=(int)($_POST['rating'] ?? 0); $content=trim($_POST['content'] ?? '');
        if($rating<1 || $rating>5 || mb_strlen($content)<2 || mb_strlen($content)>1000) throw new DomainException('请选择 1 至 5 星，评价内容为 2 至 1000 字');
        // 晒图：任一张上传失败则删除已传图片、整条不落库
        $images=[];
        try { foreach(validate_review_images($_FILES['review_images'] ?? []) as $file) $images[]=save_image_upload($file,'reviews'); }
        catch(DomainException $e) { foreach($images as $path) @unlink(__DIR__.'/../'.$path); throw $e; }
        try { $reviewId=create_review($userId,$id,$rating,$content); }
        catch(DomainException $e) { foreach($images as $path) @unlink(__DIR__.'/../'.$path); throw $e; }
        foreach($images as $path) query('INSERT INTO review_images(review_id,image_url) VALUES(?,?)',[$reviewId,$path]);
        redirect_to('detail.php?id='.(int)$item['product_id'],'评价已发布');
    } catch(DomainException $e) { $error=$e->getMessage(); }
}
page_start($append ? '追加评价' : '评价商品'); ?><section class="card"><h2><?=h($item['product_title'])?></h2><?php if($error): ?><p class="notice error"><?=h($error)?></p><?php endif; ?>
<?php if($append): ?><p class="muted">确认收货 30 天内可对已评价商品追加一条评价。</p><?php endif; ?>
<form method="post"<?=$append ? '' : ' enctype="multipart/form-data"'?>><?=csrf_field()?><input type="hidden" name="item_id" value="<?=$id?>"><?php if($append): ?><input type="hidden" name="append" value="1"><?php else: ?><label>评分 <select name="rating"><?php for($i=5;$i>=1;$i--): ?><option value="<?=$i?>"><?=$i?> 星</option><?php endfor; ?></select></label><?php endif; ?><label><?= $append ? '追评内容' : '评价内容' ?><textarea name="content" minlength="2" maxlength="1000" required><?=h($_POST['content'] ?? '')?></textarea></label><?php if(!$append): ?><label>晒图（最多 3 张，可选）<input type="file" name="review_images[]" accept="image/jpeg,image/png,image/gif,image/webp" multiple></label><?php endif; ?><button><?= $append ? '提交追评' : '提交评价' ?></button></form></section><?php page_end(); ?>
