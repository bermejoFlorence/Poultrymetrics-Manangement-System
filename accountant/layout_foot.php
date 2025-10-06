<?php
/* Footer shell for Accountant pages */
?>
  <footer class="mt-2"><p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p></footer>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function(){
  const body=document.body,
        mq=window.matchMedia('(max-width: 992px)'),
        menuTrigger=document.getElementById('menuTrigger'),
        backdrop=document.getElementById('backdrop'),
        preloader=document.getElementById('preloader');

  function hidePreloader(){
    if(preloader && !preloader.classList.contains('hidden')){
      preloader.classList.add('hidden');
      body.classList.remove('content-hidden');
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function(){ setTimeout(hidePreloader, 150); }, {once:true});
  } else { setTimeout(hidePreloader, 150); }
  setTimeout(hidePreloader, 2200);

  function toggleSidebar(){
    if (mq.matches){
      body.classList.toggle('sidebar-open');
    } else {
      body.classList.toggle('sidebar-collapsed');
    }
  }
  if (menuTrigger){
    menuTrigger.addEventListener('click', toggleSidebar);
    menuTrigger.addEventListener('keydown', (e)=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); toggleSidebar(); }});
  }
  if (backdrop) backdrop.addEventListener('click', ()=> body.classList.remove('sidebar-open'));
  document.addEventListener('keydown', (e)=>{ if (e.key==='Escape' && mq.matches && body.classList.contains('sidebar-open')) body.classList.remove('sidebar-open'); });

  window.themedSwal = function(opts){
    return Swal.fire({
      ...opts,
      iconColor:'#10b981',
      customClass:{
        popup:'swal-theme-popup',
        title:'swal-theme-title',
        htmlContainer:'swal-theme-html',
        confirmButton:'swal-theme-confirm',
        cancelButton:'swal-theme-cancel'
      },
      buttonsStyling:false
    });
  };
})();
</script>

<?php if (!empty($PAGE_FOOT_SCRIPTS)) echo $PAGE_FOOT_SCRIPTS; ?>
</body>
</html>
