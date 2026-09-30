"""Run after integration.php and a test-only development server on port 8099."""
import http.cookiejar
import json
import re
import urllib.error
import urllib.parse
import urllib.request
from html.parser import HTMLParser
from pathlib import Path

BASE = 'http://127.0.0.1:8099/'
state = json.loads(Path(__file__).with_name('state.json').read_text())
checks = 0


def check(ok, label):
    global checks
    assert ok, label
    checks += 1
    print('PASS:', label)


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__()
        self.forms = []
        self.current = None
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == 'form':
            assert self.current is None, 'nested form'
            self.current = {'attrs': attrs, 'fields': {}}
        if tag == 'input' and self.current is not None and attrs.get('name'):
            self.current['fields'][attrs['name']] = attrs.get('value', '')

    def handle_endtag(self, tag):
        if tag == 'form' and self.current is not None:
            self.forms.append(self.current)
            self.current = None


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))

    def request(self, path, data=None, expected=200):
        payload = None if data is None else urllib.parse.urlencode(data).encode()
        try:
            response = self.opener.open(BASE + path, payload, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        html = response.read().decode('utf-8')
        assert response.code == expected, (path, response.code, html[:300])
        assert not re.search(r'(Fatal error|Warning:|Parse error|Notice:|SQLSTATE)', html), (path, html[:500])
        if expected == 200:
            for form in Forms(html).forms:
                if form['attrs'].get('method', 'get').lower() == 'post':
                    assert form['fields'].get('csrf_token'), ('missing CSRF', path, form)
        return html, response.url

    def ajax(self, path, data, expected=200):
        request = urllib.request.Request(BASE + path, urllib.parse.urlencode(data).encode(), headers={'X-Requested-With': 'XMLHttpRequest'})
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode('utf-8')
        assert response.code == expected, (path, response.code, body[:300])
        assert not re.search(r'(Fatal error|Warning:|Parse error|Notice:|SQLSTATE)', body), (path, body[:500])
        return json.loads(body)

    def token(self, path='user_login.php'):
        html, _ = self.request(path)
        return re.search(r'name="csrf_token" value="([^"]+)"', html).group(1)

    def login(self, username, merchant=False):
        html, url = self.request('user_login.php', {'csrf_token': self.token(), 'username': username,
                                  'password': 'TestShop2026!', 'login_type': 'merchant' if merchant else 'customer'})
        check('user_center.php' in url, 'login ' + username)


guest = Client()
guest.request('index.php')
guest.request('detail.php?id=' + str(state['platform_product']))
guest.request('user_login.php', {'username': 'qa_admin', 'password': '123456'}, expected=403)
check(True, 'missing CSRF rejected')
html, url = guest.request('user_login.php', {'csrf_token': guest.token(), 'username': 'qa_admin', 'password': '123456', 'login_type': 'merchant'})
check('user_login.php' in url and '错误' in html, 'universal admin password fails over HTTP')
for path in ['tests/state.json', 'config/db.php', 'config/database.local.php', 'config/secrets.php', '.env', '.env.local', 'db_backup_20260918.sql', 'backup_20260918/index.php']:
    guest.request(path, expected=404)
check(True, 'local development server blocks sensitive paths')

buyer = Client(); buyer.login('qa_buyer')
other = Client(); other.login('qa_other')
merchant = Client(); merchant.login('qa_merchant', True)
admin = Client(); admin.login('qa_admin', True)
for path in ['index.php?sort=price_asc', 'cart.php', 'favorites.php', 'my_orders.php', 'addresses.php', 'shop_manage.php', 'user_center.php']:
    buyer.request(path)
check(True, 'buyer navigation renders and POST forms contain CSRF')
invalid_geo, _ = buyer.request('addresses.php?action=parse_geo&lat=91&lng=0')
invalid_geo = json.loads(invalid_geo)
check(invalid_geo['status'] == 0 and invalid_geo['msg'] == '经纬度无效', 'invalid geolocation is rejected without service details')
for path in ['admin.php', 'admin_dashboard.php', 'admin_users.php', 'admin_audit.php?tab=approved', 'category_add.php', 'product_add.php', 'product_edit.php?id=' + str(state['platform_product']), 'admin_orders.php']:
    admin.request(path)
check(True, 'admin pages render and forms remain valid')
for path in ['product_list.php', 'product_add.php', 'product_edit.php?id=' + str(state['shop_product']), 'admin_orders.php']:
    merchant.request(path)
check(True, 'merchant pages render')

# Purchase price from browser is intentionally fraudulent; server must ignore it.
product = str(state['shop_product'])
token = buyer.token('detail.php?id=' + product)
buyer.request('cart.php', {'csrf_token': token, 'action': 'add', 'product_id': product, 'quantity': '2'})
html, _ = buyer.request('checkout.php')
form = Forms(html).forms[0]['fields']; form['address_id'] = str(state['address']); form['submit_order'] = '1'; form['price'] = '0.01'
html, url = buyer.request('checkout.php', form)
check('pay.php?order_id=' in url and '60.20' in html, 'cart checkout uses server price and selected address')
order_id = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['order_id'][0]
_, retry_url = buyer.request('checkout.php', form)
check(retry_url == url, 'browser resubmit redirects to same order')
other.request('pay.php?order_id=' + order_id, expected=404)
other.request('express_detail.php?order_id=' + order_id, expected=404)
check(True, 'other buyers cannot view payment or delivery details')
buyer.request('order_action.php', {'csrf_token': buyer.token('pay.php?order_id=' + order_id), 'action': 'pay', 'order_id': order_id})
html, _ = merchant.request('admin_orders.php')
check(order_id in html and '60.20' in html, 'paid order reaches correct merchant')
merchant.request('order_action.php', {'csrf_token': merchant.token('admin_orders.php'), 'action': 'ship', 'order_id': order_id, 'express_company': '测试物流', 'express_no': 'QA123456789'})
html, _ = buyer.request('express_detail.php?order_id=' + order_id)
check('QA123456789' in html and '尚未接入' in html, 'shipping details reach buyer without fabricated tracking')
buyer.request('order_action.php', {'csrf_token': buyer.token('my_orders.php'), 'action': 'receive', 'order_id': order_id})
html, _ = buyer.request('my_orders.php?status=completed')
item_id = re.search(r'review.php\?item_id=(\d+)', html).group(1)
other.request('review.php?item_id=' + item_id, expected=403)
token = buyer.token('review.php?item_id=' + item_id)
html, _ = buyer.request('review.php', {'csrf_token': token, 'item_id': item_id, 'rating': '5', 'content': '测试评价 <script>alert(1)</script>'})
check('&lt;script&gt;' in html and '<script>alert(1)</script>' not in html, 'verified-purchase review is published with escaped HTML')
buyer.request('product_delete.php?id=' + product, expected=403)
buyer.request('admin_orders.php', expected=403)
check(True, 'buyer cannot retire products or access merchant orders')

html, _ = other.request('addresses.php', {'csrf_token': other.token('addresses.php'), 'edit_id': str(state['address']),
                       'receiver_name': '越权修改', 'receiver_phone': '13800000000', 'receiver_address': '不应保存的测试地址', 'is_default': '1'})
check('无权修改' in html, 'foreign address edits fail without resetting defaults')
html, _ = other.request('addresses.php', {'csrf_token': other.token('addresses.php'), 'edit_id': '0',
                       'receiver_name': '测试收货人', 'receiver_phone': '13800000001', 'receiver_address': '测试省测试市测试区测试街道1号', 'is_default': '1'})
check('收货地址已保存' in html, 'address creation succeeds')

title = 'HTTP审核商品'
html, _ = merchant.request('product_add.php', {'csrf_token': merchant.token('product_add.php'), 'title': title,
                          'price': '12.34', 'stock': '5', 'detail': '商家发布后审核再购买', 'submit_action': 'publish'})
html, _ = merchant.request('product_list.php')
new_product = str(max(int(x) for x in re.findall(r'product_edit.php\?id=(\d+)', html)))
buyer.request('detail.php?id=' + new_product, expected=404)
check(True, 'merchant product requires approval before public access')
rejected_title = 'HTTP驳回商品'
merchant.request('product_add.php', {'csrf_token': merchant.token('product_add.php'), 'title': rejected_title,
                 'price': '8.88', 'stock': '3', 'detail': '驳回后不得直接上架', 'submit_action': 'publish'})
html, _ = merchant.request('product_list.php')
rejected_product = str(max(int(x) for x in re.findall(r'product_edit.php\?id=(\d+)', html) if int(x) != int(new_product)))
admin.request('admin_audit.php', {'csrf_token': admin.token('admin_audit.php?tab=pending'),
               'product_id': rejected_product, 'audit_action': 'reject', 'comment': '请补充商品说明'})
response = merchant.ajax('product_list.php', {'csrf_token': merchant.token('product_list.php'), 'action': 'toggle_status',
                                               'is_ajax': '1', 'product_id': rejected_product})
check(response['status'] == 0 and '重新提交审核' in response['msg'], 'rejected product cannot be relisted directly')
html, _ = merchant.request('product_list.php')
check(rejected_title in html and '审核未通过' in html and '请编辑后重新提交审核' in html, 'rejected product remains off shelf in merchant list')
buyer.request('detail.php?id=' + rejected_product, expected=404)
check(True, 'rejected product remains hidden from storefront')
html, _ = admin.request('admin_audit.php', {'csrf_token': admin.token('admin_audit.php?tab=pending'),
                         'product_id': new_product, 'audit_action': 'approve', 'comment': '测试通过'})
html, _ = admin.request('admin_audit.php', {'csrf_token': admin.token('admin_audit.php?tab=pending'),
                         'product_id': new_product, 'audit_action': 'approve', 'comment': '重复通过'})
check('已审核或状态已变化' in html and '商品已通过审核' not in html, 'repeated approval is rejected')
html, _ = admin.request('admin_audit.php', {'csrf_token': admin.token('admin_audit.php?tab=pending'),
                         'product_id': new_product, 'audit_action': 'reject', 'comment': '重复驳回'})
check('已审核或状态已变化' in html and '商品已驳回并自动下架' not in html, 'repeated rejection is rejected')
history, _ = admin.request('api_audit_history.php?product_id=' + new_product)
history = json.loads(history)
check(history['success'] is True and len(history['records']) == 1 and history['records'][0]['result'] == 'approved', 'repeated audit creates no additional record')
html, _ = buyer.request('detail.php?id=' + new_product)
check(title in html and '12.34' in html, 'approved merchant product appears in storefront')
html, _ = buyer.request('checkout.php?product_id=' + new_product + '&quantity=1&price=0.01')
form = Forms(html).forms[0]['fields']; form.update(address_id=str(state['address']), submit_order='1')
html, url = buyer.request('checkout.php', form)
check('12.34' in html, 'buy-now ignores client-supplied price')
new_order = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['order_id'][0]
buyer.request('order_action.php', {'csrf_token': buyer.token('pay.php?order_id=' + new_order), 'action': 'cancel', 'order_id': new_order})
html, _ = buyer.request('detail.php?id=' + new_product)
check('库存 5 件' in html, 'cancelled buy-now returns inventory to storefront')

html, url = buyer.request('checkout.php?product_id=' + new_product + '&quantity=1&price=0.01')
form = Forms(html).forms[0]['fields']; form.update(address_id=str(state['address']), submit_order='1')
_, url = buyer.request('checkout.php', form)
after_sale_order = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['order_id'][0]
buyer.request('order_action.php', {'csrf_token': buyer.token('pay.php?order_id=' + after_sale_order), 'action': 'pay', 'order_id': after_sale_order})
merchant.request('order_action.php', {'csrf_token': merchant.token('admin_orders.php'), 'action': 'ship', 'order_id': after_sale_order, 'express_company': '测试物流', 'express_no': 'QA7654321'})
buyer.request('order_action.php', {'csrf_token': buyer.token('my_orders.php'), 'action': 'receive', 'order_id': after_sale_order})
html, _ = buyer.request('my_orders.php?status=completed')
check('申请退货退款' in html, 'completed order shows after-sale entry')
buyer.request('order_action.php', {'csrf_token': buyer.token('my_orders.php?status=completed'), 'action': 'after_sale', 'order_id': after_sale_order, 'reason': '测试退货退款'})
html, _ = merchant.request('admin_orders.php?status=completed')
check('退货退款申请' in html and '同意退货' in html, 'merchant sees pending after-sale request')
merchant.request('order_action.php', {'csrf_token': merchant.token('admin_orders.php?status=completed'), 'action': 'after_sale_approve', 'order_id': after_sale_order})
merchant.request('order_action.php', {'csrf_token': merchant.token('admin_orders.php?status=completed'), 'action': 'after_sale_receive', 'order_id': after_sale_order})
html, _ = buyer.request('my_orders.php?status=completed')
check('退货退款已完成' in html and '测试退货退款' in html, 'buyer sees completed after-sale progress')
html, _ = buyer.request('detail.php?id=' + new_product)
check('库存 5 件' in html, 'after-sale return restores inventory once')

edit_html, _ = merchant.request('product_edit.php?id=' + new_product)
stale_token = re.search(r'name="csrf_token" value="([^"]+)"', edit_html).group(1)
stale_stock = re.search(r'name="expected_stock" value="(\d+)"', edit_html).group(1)
html, url = buyer.request('checkout.php?product_id=' + new_product + '&quantity=1&price=0.01')
form = Forms(html).forms[0]['fields']; form.update(address_id=str(state['address']), submit_order='1')
_, url = buyer.request('checkout.php', form)
stale_order = urllib.parse.parse_qs(urllib.parse.urlparse(url).query)['order_id'][0]
html, _ = merchant.request('product_edit.php?id=' + new_product, {
    'csrf_token': stale_token, 'id': new_product, 'expected_stock': stale_stock,
    'title': '陈旧库存编辑', 'price': '12.34', 'stock': stale_stock,
    'detail': '不应写入', 'submit_action': 'publish'
})
check('库存已被订单操作更新' in html, 'stale product edit is rejected after order reserves stock')
html, _ = buyer.request('detail.php?id=' + new_product)
check(title in html and '陈旧库存编辑' not in html and '库存 4 件' in html, 'stale edit leaves product and reserved inventory unchanged')
buyer.request('order_action.php', {'csrf_token': buyer.token('pay.php?order_id=' + stale_order), 'action': 'cancel', 'order_id': stale_order})
edit_html, _ = merchant.request('product_edit.php?id=' + new_product)
fresh_token = re.search(r'name="csrf_token" value="([^"]+)"', edit_html).group(1)
fresh_stock = re.search(r'name="expected_stock" value="(\d+)"', edit_html).group(1)
html, _ = merchant.request('product_edit.php?id=' + new_product, {
    'csrf_token': fresh_token, 'id': new_product, 'expected_stock': fresh_stock,
    'title': '库存同步编辑', 'price': '12.34', 'stock': fresh_stock,
    'detail': '库存恢复后保存', 'submit_action': 'publish'
})
check('库存同步编辑' in html and '待审核' in html, 'refreshed stock snapshot permits product edit')
print(str(checks) + ' HTTP flow assertions passed.')
