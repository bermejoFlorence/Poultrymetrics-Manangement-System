<?php
// contact.php — static contact info (no message form, no DB writes)
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
<meta name="description" content="Contact PoultryMetrix - Bocago Poultry Farm Management System">
<title>PoultryMetrix | Contact</title>

<link href="https://fonts.googleapis.com/css?family=Poppins:100,200,300,400,500,600,700,800,900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/css/font-awesome.css">
<link rel="stylesheet" href="assets/css/style.css">

<style>
  /* Responsive-only tweaks (no theme change) */
  html, body { max-width:100%; overflow-x:hidden; }
  img { max-width:100%; height:auto; display:block; }

  /* HERO text scales nicely */
  .section.section-bg .cta-content{
    text-align:center; color:#fff; padding:24px 16px; max-width:960px; margin:0 auto;
  }
  .section.section-bg .cta-content h2{
    font-weight:800; line-height:1.15; font-size:clamp(1.5rem, 5vw, 2.5rem); margin-bottom:.5rem;
  }
  .section.section-bg .cta-content p{
    font-size:clamp(.95rem, 2.6vw, 1.05rem); margin-bottom:1rem;
  }

  /* Contact info icons grid */
  #features .icon{ font-size:1.6rem; color:#f5a425; margin-bottom:.35rem; }
  #features h5 a, #features h5 { word-break: break-word; }

  /* Map height scaling */
  #map-section iframe{ width:100%; height:600px; border:0; }

  /* Phones */
  @media (max-width: 575.98px){
    .header-area .nav li a{ padding:14px 10px; }
    .section{ padding:48px 0; }
    #features .col-md-4{ margin-bottom:16px; }
    #map-section iframe{ height:420px; }
  }

  /* Tablets */
  @media (max-width: 991.98px){
    #map-section iframe{ height:520px; }
  }
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
    <div class="row"><div class="col-12">
      <nav class="main-nav">
        <a href="index.php" class="logo">Poultry<em>Metrix</em></a>
        <ul class="nav">
          <li><a href="index.php">Home</a></li>
          <li><a href="shop.php">Shop</a></li>
          <li><a href="about.html">About</a></li>
          <li><a href="contact.php" class="active">Contact</a></li>
          <li><a href="register.php">Register</a></li>
          <li><a href="login.php">Login</a></li>
        </ul>
        <a class='menu-trigger'><span>Menu</span></a>
      </nav>
    </div></div>
  </div>
</header>

<!-- Banner -->
<section class="section section-bg" id="call-to-action" style="background-image: url(assets/images/banner-image-1-1920x500.jpg)">
  <div class="container"><div class="row"><div class="col-lg-10 offset-lg-1">
    <div class="cta-content text-center">
      <br><br>
      <h2>Get in <em>Touch</em></h2>
      <p>We’d love to hear from you regarding PoultryMetrix and Bocago Poultry Farm.</p>
    </div>
  </div></div></div>
</section>

<!-- Contact Info -->
<section class="section" id="features">
  <div class="container">
    <div class="row text-center">
      <div class="col-lg-6 offset-lg-3">
        <div class="section-heading">
          <h2>Contact <em>Info</em></h2>
          <img src="assets/images/line-dec.png" alt="waves">
          <p class="mt-2">For inquiries and orders, reach us via phone or email.</p>
        </div>
      </div>

      <div class="col-md-4">
        <div class="icon"><i class="fa fa-phone"></i></div>
        <h5><a href="tel:+639123456789">+63 912 345 6789</a></h5>
        <p class="text-muted small mb-0">Mon–Sat, 8:00 AM–5:00 PM</p>
      </div>
      <div class="col-md-4">
        <div class="icon"><i class="fa fa-envelope"></i></div>
        <h5><a href="mailto:support@poultrymetrics.com">support@poultrymetrics.com</a></h5>
        <p class="text-muted small mb-0">We reply within 1–2 business days</p>
      </div>
      <div class="col-md-4">
        <div class="icon"><i class="fa fa-map-marker"></i></div>
        <h5>Malubago, Sipocot, Camarines Sur</h5>
        <p class="text-muted small mb-0">Pickup by arrangement</p>
      </div>
    </div>
  </div>
</section>

<!-- Map -->
<section class="section" id="map-section">
  <div class="container-fluid">
    <div class="row">
      <div class="col-12">
        <iframe
          src="https://maps.google.com/maps?q=Malubago+Sipocot+Camarines+Sur&t=&z=13&ie=UTF8&iwloc=&output=embed"
          allowfullscreen loading="lazy">
        </iframe>
      </div>
    </div>
  </div>
</section>

<!-- Footer -->
<footer>
  <div class="container">
    <div class="row"><div class="col-lg-12 text-center">
      <p>Copyright © <?php echo date('Y'); ?> PoultryMetrix | Powered by Bocago Poultry Farm</p>
    </div></div>
  </div>
</footer>

<!-- JS -->
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

<!-- Preloader Navigation Logic (keeps new-tab/external links intact) -->
<script>
document.addEventListener("DOMContentLoaded", function () {
  const preloader = document.getElementById("js-preloader");

  function shouldIntercept(a){
    const href = a.getAttribute("href");
    if (!href || href.startsWith("#") || href.startsWith("javascript:")) return false;
    try {
      const url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return false; // external, skip
    } catch(e){ return false; }
    return true;
  }

  document.body.addEventListener("click", function(e){
    const a = e.target.closest("a");
    if (!a) return;
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
