<?php
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/image_search.php';
$error=''; $keywords=[]; $results=[]; $unavailable=false;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $file = $_FILES['image'] ?? null;
    try {
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new DomainException('请选择要搜索的图片');
        if ((int)$file['size'] > 5 * 1024 * 1024) throw new DomainException('图片不能超过 5MB');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($allowed[$mime]) || !getimagesize($file['tmp_name'])) throw new DomainException('仅支持 JPG、PNG 或 WEBP 图片');
        // 复用 save_image_upload 的安全校验口径，但搜索图片不落盘：读取为 base64 后临时文件用后即删
        $dataUrl = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($file['tmp_name']));
        @unlink($file['tmp_name']);
        $keywords = image_search_keywords($dataUrl);
        if ($keywords === null) {
            $unavailable = true;
        } elseif (!$keywords) {
            $error = '未识别出该图片的商品关键词，请换一张更清晰的图片试试。';
        } else {
            $results = image_search_results($keywords);
        }
    } catch (DomainException $e) {
        $error = $e->getMessage();
    }
}
page_start('拍照搜同款');
?>
<section class="card">
<h2>拍照搜同款</h2>
<p class="muted">上传一张商品照片，系统识别后生成关键词，为你找到站内同款好物（≤5MB，JPG / PNG / WEBP）。</p>
<?php if ($error) echo '<p class="notice error" role="alert">'.h($error).'</p>'; ?>
<?php if ($unavailable) echo '<p class="notice" role="status">图片搜索暂不可用，请稍后再试，或直接使用关键词搜索。</p>'; ?>
<form method="post" enctype="multipart/form-data"><?=csrf_field()?>
<label>选择图片 <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
<button type="submit">搜同款</button>
</form>
</section>
<?php if ($keywords): ?>
<section class="card">
<h2>识别出的关键词</h2>
<p><?php foreach ($keywords as $i => $kw): ?><a class="chip" href="index.php?keyword=<?=urlencode($kw)?>"><?=h($kw)?></a><?php if ($i < count($keywords)-1) echo ' '; endforeach; ?></p>
<p class="muted">点击关键词可进入完整搜索（含筛选与排序）。</p>
</section>
<?php foreach ($results as $kw => $items): ?>
<section class="card">
<h3>“<?=h($kw)?>” 的搜索结果（<?=count($items)?> 件）</h3>
<?php if (!$items): ?><p class="muted">暂未找到匹配商品，<a href="index.php?keyword=<?=urlencode($kw)?>">查看完整搜索</a>。</p>
<?php else: ?><div class="grid"><?php foreach ($items as $item): ?>
<article class="card">
<a href="detail.php?id=<?=(int)$item['id']?>">
<?php if (!empty($item['image_url']) && file_exists($item['image_url'])): ?><img class="thumb" style="width:100%;height:150px;" src="<?=h($item['image_url'])?>" alt="<?=h($item['title'])?>"><?php else: ?><span class="thumb" style="display:inline-flex;width:100%;height:150px;align-items:center;justify-content:center;font-size:2.5rem;">📦</span><?php endif; ?>
</a>
<h3 style="margin-top:10px;"><a href="detail.php?id=<?=(int)$item['id']?>"><?=h($item['title'])?></a></h3>
<?php if (!empty($item['shop_name'])): ?><div style="font-size:.8rem;color:#64748b;">🏪 <?=h($item['shop_name'])?></div><?php endif; ?>
<div class="price">￥<?=h(number_format((float)$item['price'], 2))?></div>
</article>
<?php endforeach; ?></div><?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; ?>
<?php page_end(); ?>
