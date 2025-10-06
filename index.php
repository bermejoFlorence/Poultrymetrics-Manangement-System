<?php
// index.php — PoultryMetrics Homepage (schema-aligned)
// Requires config.php (sets $conn and DB to 'poultrymetrics')
include __DIR__.'/config.php';

// -------- Fetch latest/featured products (limit 6) --------
$products = [];
$sql = "SELECT product_id, name, price, unit, image_path
        FROM products
        WHERE status='active'
        ORDER BY product_id DESC
        LIMIT 6";
if ($result = $conn->query($sql)) {
  while ($row = $result->fetch_assoc()) { $products[] = $row; }
  $result->free();
}

// Helper: pick best image path (supports absolute/relative stored paths)
function pick_product_img(?string $image_path): string {
  $placeholder = 'assets/images/placeholder.png';
  if (!$image_path) return $placeholder;

  // If it's already a readable path (absolute or relative), use it
  if (is_file($image_path) && filesize($image_path) > 0) return $image_path;

  // Try common folders with just the basename stored
  $base = basename($image_path);
  foreach (['uploads/products/', 'assets/uploads/products/'] as $dir) {
    $p = $dir.$base;
    if (is_file($p) && filesize($p) > 0) return $p;
  }
  return $placeholder;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>PoultryMetrics | Bocago Poultry Farm Online Ordering</title>

  <link href="https://fonts.googleapis.com/css?family=Poppins:100,200,300,400,500,600,700,800,900&display=swap" rel="stylesheet">
  <link rel="stylesheet" type="text/css" href="assets/css/bootstrap.min.css">
  <link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">
  <link rel="stylesheet" href="assets/css/style.css">

  <!-- Layout-only tweaks (no button styles, keep your style.css as-is) -->
  <style>
    /* --- Cards (equal height, subtle hover) --- */
    .product-card.card{
      border-radius: 12px;
      overflow: hidden;
      transition: transform .25s ease, box-shadow .25s ease;
      border: 1px solid #e9ecef;
    }
    .product-card.card:hover{
      transform: translateY(-4px);
      box-shadow: 0 10px 24px rgba(0,0,0,.12);
    }
    .product-card .product-img{
      width: 100%;
      height: 220px;
      object-fit: cover;
      display: block;
      transition: transform .3s ease;
    }
    .product-card.card:hover .product-img{ transform: scale(1.03); }
    .product-card .card-body{
      display: flex;
      flex-direction: column;
      padding: 15px;
    }
    .product-price{
      font-weight: 700;
      color: #f5a425;
      margin-bottom: .35rem;
      font-size: 1rem;
    }
    .product-title{
      font-size: 1.05rem;
      line-height: 1.25;
      margin-bottom: .3rem;
    }
    .product-desc{
      margin-bottom: .75rem;
      color: #5f6b6f;
    }
    .products-grid > [class*="col-"]{ display: flex; }

    /* Hero caption centering + responsiveness */
    .video-overlay .caption{
      max-width: 900px;
      margin-inline: auto;
      padding: 0 16px;
      text-align: center;
    }
    .video-overlay .caption h6{
      font-weight: 600; letter-spacing:.08em; text-transform:uppercase;
      margin: 0 0 .35rem; font-size: clamp(.85rem, 1.8vw, 1rem); line-height:1.25;
    }
    .video-overlay .caption h2{
      margin: .25rem 0 1rem; line-height: 1.15;
      font-size: clamp(1.4rem, 5vw, 3rem); font-weight: 800;
    }
    .video-overlay .caption h2 em{ font-style: normal; }

    /* Responsive image heights */
    @media (max-width: 1199.98px){ .product-card .product-img{ height:210px; } }
    @media (max-width: 991.98px) { .product-card .product-img{ height:200px; } }
    @media (max-width: 767.98px) { .product-card .product-img{ height:170px; } }

    /* General safety */
    html, body{ max-width:100%; overflow-x:hidden; }
    img{ max-width:100%; height:auto; }
  </style>
</head>

<body>
  <!-- Preloader -->
  <div id="js-preloader" class="js-preloader">
    <div class="preloader-inner">
      <span class="dot"></span>
      <div class="dots"><span></span><span></span><span></span></div>
    </div>
  </div>

  <!-- Header -->
  <header class="header-area header-sticky">
    <div class="container">
      <div class="row">
        <div class="col-12">
          <nav class="main-nav">
            <a href="index.php" class="logo">Poultry<em>Metrics</em></a>
            <ul class="nav">
              <li><a href="index.php" class="active">Home</a></li>
              <li><a href="shop.php">Shop</a></li>
              <li><a href="about.html">About</a></li>
              <li><a href="contact.php">Contact</a></li>
              <li><a href="register.php">Register</a></li>
              <li><a href="login.php">Login</a></li>
            </ul>
            <a class='menu-trigger'><span>Menu</span></a>
          </nav>
        </div>
      </div>
    </div>
  </header>

  <!-- Banner -->
  <div class="main-banner" id="top">
    <video autoplay muted loop playsinline id="bg-video">
      <source src="assets/images/poultrymetrix.mp4" type="video/mp4" />
    </video>
    <div class="video-overlay header-text">
      <div class="caption">
        <h6>Bocago Poultry Farm</h6>
        <h2>Fresh <em>Eggs & Poultry</em> Straight From Our Farm</h2>
        <div class="main-button">
          <a href="shop.php">Shop Now</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Featured Products -->
  <section class="section" id="products">
    <div class="container">
      <div class="row mb-3">
        <div class="col-lg-8 mx-auto">
          <div class="section-heading text-center">
            <h2>Featured <em>Products</em></h2>
            <img src="assets/images/line-dec.png" alt="">
            <p>Order fresh eggs and poultry products directly from Bocago Poultry Farm.</p>
          </div>
        </div>
      </div>

      <div class="row products-grid">
        <?php if (!empty($products)): ?>
          <?php foreach ($products as $row): ?>
            <?php
              $pid   = (int)$row['product_id'];
              $name  = htmlspecialchars((string)$row['name'], ENT_QUOTES, 'UTF-8');
              $price = number_format((float)$row['price'], 2);
              $unit  = htmlspecialchars((string)($row['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
              $img   = pick_product_img($row['image_path'] ?? null);
            ?>
            <div class="col-6 col-md-6 col-lg-4 mb-4 d-flex">
              <article class="product-card card h-100 w-100 shadow-sm">
                <img
                  src="<?= $img ?>"
                  alt="<?= $name ?>"
                  class="product-img"
                  loading="lazy"
                  onerror="this.onerror=null;this.src='assets/images/placeholder.png';"
                />
                <div class="card-body">
                  <div class="product-price">₱<?= $price ?><?= $unit ? " / $unit" : "" ?></div>
                  <h3 class="product-title h6 mb-1"><?= $name ?></h3>
                  <p class="product-desc small"><?= $unit ? "Sold per {$unit}." : "&nbsp;" ?></p>
                  <div class="mt-auto text-center main-button">
                    <a href="shop-details.php?id=<?= $pid ?>">+ View More</a>
                  </div>
                </div>
              </article>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div class="col-12 text-center">
            <p>No products available yet.</p>
          </div>
        <?php endif; ?>
      </div>

      <div class="text-center mt-2 main-button">
        <a href="shop.php">View All Products</a>
      </div>
    </div>
  </section>

  <!-- About -->
  <section class="section section-bg" id="about" style="background-image: url('assets/images/about-fullscreen-1-1920x700.jpg'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    <div class="container">
      <div class="row">
        <div class="col-lg-6 offset-lg-3">
          <div class="section-heading dark-bg text-center">
            <h2>About <em>Bocago Poultry Farm</em></h2>
            <img src="assets/images/line-dec.png" alt="">
            <p>We supply fresh eggs, poultry, and chicks directly from our farm to your table. Trusted by households and resellers across the region.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Ordering Guide -->
  <section class="section" id="ordering-guide">
    <div class="container">
      <div class="row">
        <div class="col-lg-6 offset-lg-3">
          <div class="section-heading text-center">
            <h2>How to <em>Order</em></h2>
            <img src="assets/images/line-dec.png" alt="">
            <p>Choose your products from the shop, add them to your cart, and place your order for delivery or pickup.</p>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- CTA -->
  <section class="section section-bg" id="call-to-action" style="background-image: url('assets/images/banner-image-1-1920x500.jpg'); background-size: cover; background-position: center; background-repeat: no-repeat;">
    <div class="container">
      <div class="row">
        <div class="col-lg-10 offset-lg-1">
          <div class="cta-content text-center">
            <h2>Need <em>Bulk Orders?</em></h2>
            <p>We cater to restaurants, resellers, and wholesalers. Contact us for bulk pricing and regular supply.</p>
            <div class="main-button">
              <a href="contact.php">Contact Us</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- Testimonials -->
  <section class="section" id="features">
    <div class="container">
      <div class="row">
        <div class="col-lg-6 offset-lg-3">
          <div class="section-heading text-center">
            <h2>What Our <em>Customers Say</em></h2>
            <img src="assets/images/line-dec.png" alt="waves">
            <p>Hear from our satisfied customers who enjoy fresh eggs and quality poultry from Bocago Farm.</p>
          </div>
        </div>
      </div>
      <!-- (You can add testimonial cards here later) -->
    </div>
  </section>

  <!-- Footer -->
  <footer>
    <div class="container">
      <div class="row">
        <div class="col-lg-12 text-center">
          <p>&copy; 2025 PoultryMetrics - Powered by Bocago Poultry Farm</p>
        </div>
      </div>
    </div>
  </footer>

  <!-- Scripts -->
  <script src="assets/js/jquery-2.1.0.min.js"></script>
  <script src="assets/js/popper.js"></script>
  <script src="assets/js/bootstrap.min.js"></script>
  <script src="assets/js/scrollreveal.min.js"></script>
  <script src="assets/js/waypoints.min.js"></script>
  <script src="assets/js/jquery.counterup.min.js"></script>
  <script src="assets/js/imgfix.min.js"></script>
  <script src="assets/js/mixitup.js"></script>
  <script src="assets/js/accordions.js"></script>
  <script src="assets/js/custom.js"></script>

  <!-- Preloader navigation (keeps Ctrl/Cmd/middle click & external links intact) -->
  <script>
  document.addEventListener("DOMContentLoaded", function () {
    const preloader = document.getElementById("js-preloader");

    function shouldIntercept(a){
      const href = a.getAttribute("href");
      if (!href || href.startsWith("#") || href.startsWith("javascript:")) return false;
      try {
        const url = new URL(href, window.location.href);
        if (url.origin !== window.location.origin) return false; // skip external
      } catch(e){ return false; }
      return true;
    }

    document.body.addEventListener("click", function(e){
      const a = e.target.closest("a");
      if (!a) return;

      // new tab/window or middle click or modifier keys → don't intercept
      if (a.target === "_blank" || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;
      if (!shouldIntercept(a)) return;

      e.preventDefault();
      preloader.classList.remove("loaded");
      setTimeout(() => { window.location = a.href; }, 250);
    }, true);

    window.addEventListener("load", function () {
      setTimeout(() => preloader.classList.add("loaded"), 200);
    });
  });
  </script>
</body>
</html>
