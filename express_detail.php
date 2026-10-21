<?php
// 物流与订单进度（买家/商家均可看）。轨迹 = lib/logistics.php 演示物流引擎（provider=simulated）
require_once __DIR__.'/lib/shop.php';
require_once __DIR__.'/lib/logistics.php';
$userId=uid(); $order=one('SELECT * FROM orders WHERE id=?',[(int)($_GET['order_id'] ?? 0)]);
if(!$order || ((int)$order['user_id']!==$userId && !can_manage_order($order,$userId))) { http_response_code(404); page_start('订单不存在或无权查看'); page_end(); exit; }
page_start('物流与订单进度');
$lgShop=one('SELECT ship_from FROM shops WHERE id=?',[(int)($order['shop_id'] ?? 0)]);
$shipFrom=(string)($lgShop['ship_from'] ?? '');
?>
<section class="card"><h2><?=h(status_name($order['status']))?></h2><p>订单 <?=h($order['order_no'])?></p><p><?=h($order['receiver_name'])?> · <?=h($order['receiver_phone'])?><br><?=h($order['receiver_address'])?></p><hr class="divider"><p>创建订单：<?=h($order['created_at'])?></p><?php if($order['paid_at']): ?><p>付款记录：<?=h($order['paid_at'])?> <?=$order['payment_method']==='demo'?'（演示支付）':''?></p><?php endif; ?><?php if($order['completed_at']): ?><p>确认收货：<?=h($order['completed_at'])?></p><?php endif; ?></section>
<?php if($order['shipped_at']):
$lg=logistics_track($order,$shipFrom);
$lgMap=logistics_map_url($order,$shipFrom);
// 交互地图：AMAP_JS_KEY 已配置时，服务端把起终点地址解析成坐标交给前端 JS API 画真实驾车路线
$lgFromAddr=$shipFrom!==''?$shipFrom:'杭州市';
$lgFrom=defined('AMAP_JS_KEY')&&AMAP_JS_KEY!==''?logistics_geocode($lgFromAddr):null;
$lgTo=defined('AMAP_JS_KEY')&&AMAP_JS_KEY!==''?logistics_geocode((string)$order['receiver_address']):null;
$lgInteractive=$lgFrom&&$lgTo;
?>
<section class="card">
<h2>物流轨迹 <span class="badge"><?=h($lg['stage'])?></span></h2>
<p>快递公司：<?=h($order['express_company'])?> · 运单号：<?=h($order['express_no'])?></p>
<p>路线：<strong><?=h($lg['from'])?></strong> → <strong><?=h($lg['to'])?></strong> · 预计 <strong><?=h($lg['eta'])?></strong> 送达</p>
<div class="lg-progress"><div class="lg-progress-bar" style="width:<?=$lg['percent']?>%"></div></div>
<?php if($lgInteractive): ?>
<div id="lg-amap" class="lg-map" style="height:380px"></div>
<script type="text/javascript">window._AMapSecurityConfig={securityJsCode:<?=json_encode((string)(defined('AMAP_JS_SECURITY')?AMAP_JS_SECURITY:''))?>};</script>
<script src="https://webapi.amap.com/maps?v=2.0&key=<?=h(AMAP_JS_KEY)?>&plugin=AMap.Driving,AMap.MoveAnimation"></script>
<script>
(function(){
  var map=new AMap.Map('lg-amap',{zoom:6,viewMode:'2D'});
  var from=[<?=json_encode($lgFrom['lng'])?>,<?=json_encode($lgFrom['lat'])?>];
  var to=[<?=json_encode($lgTo['lng'])?>,<?=json_encode($lgTo['lat'])?>];
  var driving=new AMap.Driving({map:map,autoFitView:true});
  driving.search(new AMap.LngLat(from[0],from[1]),new AMap.LngLat(to[0],to[1]),function(status,res){
    if(status!=='complete'||!res.routes||!res.routes.length){return;}
    var path=[];
    res.routes[0].steps.forEach(function(s){s.path.forEach(function(p,i){if(path.length===0||i%3===0){path.push([p.lng,p.lat]);}});});
    if(path.length<2){return;}
    // 车辆初始位置与演示引擎的真实进度对齐（percent 按发货时间起 48h 线性推进）：
    // 已走过路段不重播，每次打开页面车辆都落在当前进度点上、只动画剩余段；签收后停在终点
    var prog=<?= (int)$lg['percent'] ?>;
    var startIdx=Math.min(path.length-1,Math.floor(path.length*prog/100));
    var truck=new AMap.Marker({map:map,position:path[startIdx],anchor:'bottom-center',content:'<div style="font-size:24px;filter:drop-shadow(0 2px 3px rgba(0,0,0,.35))">🚚</div>'});
    if(startIdx<path.length-1){truck.moveAlong(path.slice(startIdx),{duration:400,autoRotation:true});}
  });
})();
</script>
<?php elseif($lgMap): ?><img class="lg-map" src="<?=h($lgMap)?>" alt="物流路线图" onerror="this.style.display='none';document.getElementById('lg-map-err').style.display='block'"><p id="lg-map-err" style="display:none" class="muted">地图图片加载失败（多为高德 Key 类型不含"Web 服务"或静态地图配额用尽），文字轨迹仍可正常使用。</p>
<?php else: ?><p class="muted">未配置高德 Key 或地址解析失败，仅展示文字轨迹。</p><?php endif; ?>
<ul class="lg-timeline">
<?php foreach($lg['events'] as $lgE): ?><li class="<?=$lgE['done']?'lg-on':'lg-plan'?>"><span class="lg-time"><?=$lgE['done']?h($lgE['time']):'预计 '.h($lgE['time'])?></span><?=h($lgE['text'])?></li><?php endforeach; ?>
</ul>
<p class="muted">演示物流说明：本站为演示环境，以上轨迹由系统按发货时间自动模拟生成（<?=h(logistics_provider())?> 引擎），与真实快递包裹无关；运单号仅供流程体验。</p>
</section>
<?php else: ?>
<section class="card"><p>暂无发货记录，商家发货后此页将展示物流轨迹。</p></section>
<?php endif; ?>
<?php page_end(); ?>
