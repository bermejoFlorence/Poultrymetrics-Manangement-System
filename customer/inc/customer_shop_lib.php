<?php
// /customer/inc/customer_shop_lib.php
// Helpers for customer shop that mirror admin/products.php data model.

if (!isset($conn) || !($conn instanceof mysqli)) {
  require_once __DIR__ . '/common.php';
}

if (!function_exists('shop_table_exists')) {
  function shop_table_exists(mysqli $c, string $t): bool {
    $t = $c->real_escape_string($t);
    $res = @$c->query("SHOW TABLES LIKE '{$t}'");
    if (!$res) return false; $ok = $res->num_rows>0; $res->free(); return $ok;
  }
}

if (!function_exists('fetch_shop_products')) {
  /**
   * Get visible products with joined stock & current price.
   * Returns: [ [size_id, code, label, display_name, short_desc, img_url, trays_on_hand, price_per_tray, max_per_order], ... ]
   */
  function fetch_shop_products(mysqli $conn): array {
    $TBL_SIZES  = 'egg_sizes';
    $TBL_STOCK  = 'egg_stock';
    $TBL_SHOP   = 'products';
    $VIEW_PRICE = 'v_current_prices';
    $TBL_PRICE  = 'pos_prices';

    $rows=[];

    $base = "SELECT z.size_id, z.code, z.label, z.image_path,
                    p.display_name, p.short_desc, p.img_url, p.max_per_order,
                    k.trays_on_hand";
    $join = " FROM `{$TBL_SIZES}` z
              LEFT JOIN `{$TBL_SHOP}` p ON p.size_id=z.size_id
              LEFT JOIN `{$TBL_STOCK}` k ON k.size_id=z.size_id
              WHERE COALESCE(p.visible,1)=1";
    $order = " ORDER BY COALESCE(p.sort_order,z.sort_order,z.size_id), z.size_id";

    // Prefer view if present
    if (shop_table_exists($conn,$VIEW_PRICE)) {
      $sql = $base.", cp.price_per_tray
              $join
              LEFT JOIN `{$VIEW_PRICE}` cp ON cp.size_id=z.size_id
              $order";
      if ($res=@$conn->query($sql)) {
        while($r=$res->fetch_assoc()){
          $r['price_per_tray'] = is_null($r['price_per_tray']) ? null : (float)$r['price_per_tray'];
          $rows[]=$r;
        }
        $res->free();
        return $rows;
      }
    }

    // Fallback to latest unexpired pos_prices
    $sql = $base.",
              (
                SELECT pr.price_per_tray
                FROM `{$TBL_PRICE}` pr
                WHERE pr.size_id=z.size_id
                  AND (pr.effective_to IS NULL OR pr.effective_to > NOW())
                ORDER BY pr.effective_from DESC
                LIMIT 1
              ) AS price_per_tray
            $join
            $order";
    if ($res=@$conn->query($sql)) {
      while($r=$res->fetch_assoc()){
        $r['price_per_tray'] = is_null($r['price_per_tray']) ? null : (float)$r['price_per_tray'];
        $rows[]=$r;
      }
      $res->free();
    }
    return $rows;
  }
}

if (!function_exists('cart_get_items')) {
  /**
   * Resolve session cart -> enriched items with name, price, stock, subtotals.
   * Returns [items=>[...], totals=>['qty'=>int,'amount'=>float]]
   */
  function cart_get_items(mysqli $conn): array {
    $items=[]; $qtyTotal=0; $amtTotal=0.0;
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
      return ['items'=>[], 'totals'=>['qty'=>0,'amount'=>0.0]];
    }
    $ids=[];
    foreach($_SESSION['cart'] as $sid=>$q){
      $sid=(int)$sid; $q=(int)$q;
      if ($sid>0 && $q>0) $ids[$sid]=$q;
    }
    if (!$ids) return ['items'=>[], 'totals'=>['qty'=>0,'amount'=>0.0]];

    $in = implode(',', array_map('intval', array_keys($ids)));
    $sql = "SELECT z.size_id, z.code, z.label,
                   COALESCE(p.display_name, z.label) AS display_name,
                   COALESCE(p.img_url, z.image_path) AS img_url,
                   k.trays_on_hand,
                   (
                     SELECT pr.price_per_tray
                     FROM pos_prices pr
                     WHERE pr.size_id=z.size_id
                       AND (pr.effective_to IS NULL OR pr.effective_to > NOW())
                     ORDER BY pr.effective_from DESC
                     LIMIT 1
                   ) AS price_per_tray,
                   COALESCE(p.max_per_order,0) AS max_per_order
            FROM egg_sizes z
            LEFT JOIN products p ON p.size_id=z.size_id
            LEFT JOIN egg_stock k ON k.size_id=z.size_id
            WHERE z.size_id IN ($in)";
    if ($res=@$conn->query($sql)){
      while($r=$res->fetch_assoc()){
        $sid=(int)$r['size_id']; $q=$ids[$sid]??0; if ($q<=0) continue;
        $price = is_null($r['price_per_tray']) ? 0.0 : (float)$r['price_per_tray'];
        $sub   = $price * $q;
        $items[] = [
          'size_id'=>$sid,
          'name'=> (string)$r['display_name'],
          'code'=> (string)($r['code'] ?? ''),
          'img_url'=> (string)($r['img_url'] ?? ''),
          'stock'=> (int)($r['trays_on_hand'] ?? 0),
          'price'=> $price,
          'qty'=> $q,
          'subtotal'=> $sub,
          'max_per_order'=> (int)$r['max_per_order'],
        ];
        $qtyTotal += $q; $amtTotal += $sub;
      }
      $res->free();
    }
    return ['items'=>$items, 'totals'=>['qty'=>$qtyTotal,'amount'=>$amtTotal]];
  }
}

if (!function_exists('cart_add')) {
  /** Add quantity to cart with optional max limit. */
  function cart_add(int $size_id, int $qty, ?int $max_per_order=null): void {
    if ($size_id<=0 || $qty<=0) return;
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) $_SESSION['cart']=[];
    $cur = (int)($_SESSION['cart'][$size_id] ?? 0);
    $new = $cur + $qty;
    if ($max_per_order !== null && $max_per_order>0) $new = min($new, $max_per_order);
    $_SESSION['cart'][$size_id] = $new;
  }
}
