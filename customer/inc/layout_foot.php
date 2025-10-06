<?php
/**
 * Customer Layout Foot
 * Include at the BOTTOM of every CUSTOMER page BEFORE </body>.
 * Closes the <main> container opened in layout_head.php.
 */
?>
    <footer class="mt-2"><p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p></footer>
  </main>

  <!-- Core JS -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    (function(){
      const body=document.body,
            mq=window.matchMedia('(max-width: 992px)'),
            menuTrigger=document.getElementById('menuTrigger'),
            backdrop=document.getElementById('backdrop'),
            LS_KEY='pm.sidebar.customer',
            preloader=document.getElementById('preloader');

      // Preloader hide
      function hidePreloader(){
        if(preloader && !preloader.classList.contains('hidden')){
          preloader.classList.add('hidden');
          body.classList.remove('content-hidden');
        }
      }
      if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function(){ setTimeout(hidePreloader, 150); }, {once:true});
      } else { setTimeout(hidePreloader, 150); }
      setTimeout(hidePreloader, 3000);

      // Apply saved sidebar state (desktop only)
      try{
        const s=localStorage.getItem(LS_KEY);
        if(!mq.matches && s==='collapsed') body.classList.add('sidebar-collapsed');
      }catch(e){}

      // Toggle + persist
      function saveSidebarState(){
        try{ localStorage.setItem(LS_KEY, body.classList.contains('sidebar-collapsed')?'collapsed':'open'); }catch(e){}
      }
      function toggleSidebar(){
        if (mq.matches){
          body.classList.toggle('sidebar-open');
        } else {
          body.classList.toggle('sidebar-collapsed');
          saveSidebarState();
        }
      }

      // Bindings
      if (menuTrigger){
        menuTrigger.addEventListener('click', toggleSidebar);
        menuTrigger.addEventListener('keydown', (e)=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); toggleSidebar(); }});
      }
      if (backdrop) backdrop.addEventListener('click', ()=> body.classList.remove('sidebar-open'));
      document.addEventListener('keydown', (e)=>{
        if (e.key === 'Escape' && mq.matches && body.classList.contains('sidebar-open')) body.classList.remove('sidebar-open');
      });
      window.addEventListener('resize', ()=>{ if (!mq.matches) body.classList.remove('sidebar-open'); }, {passive:true});

      // Themed SweetAlert helper
      function themedSwal(opts){
        return Swal.fire({
          ...opts,
          iconColor:'#f5a425',
          customClass:{
            popup:'swal-theme-popup',
            title:'swal-theme-title',
            htmlContainer:'swal-theme-html',
            confirmButton:'swal-theme-confirm',
            cancelButton:'swal-theme-cancel'
          },
          buttonsStyling:false
        });
      }
      window.themedSwal = themedSwal;

      // Logout confirm
      function confirmLogout(){
        themedSwal({
          title:'Logout?',
          text:'End your session securely.',
          icon:'question',
          showCancelButton:true,
          confirmButtonText:'Yes, logout',
          cancelButtonText:'Cancel'
        }).then(res=>{
          if(res.isConfirmed){
            themedSwal({ title:'Logging out...', text:'Please wait', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
            setTimeout(()=>{ window.location.href = <?php echo json_encode((defined('BASE_URI')?BASE_URI:'').'logout.php', JSON_UNESCAPED_SLASHES); ?>; }, 600);
          }
        });
      }
      window.confirmLogout = confirmLogout;

      // Generic SweetAlert confirm for small POST forms
      document.querySelectorAll('form.js-confirm').forEach(form=>{
        if (form.dataset.bound==='1') return;
        form.dataset.bound='1';
        form.addEventListener('submit',(e)=>{
          e.preventDefault();
          themedSwal({
            title:'Please confirm',
            text: form.dataset.message || 'Are you sure?',
            icon:'question',
            showCancelButton:true,
            confirmButtonText:'Yes, proceed',
            cancelButtonText:'Cancel'
          }).then(r=>{ if(r.isConfirmed) form.submit(); });
        });
      });

      // Optional: Flash -> SweetAlert (if a hidden #flash element exists)
      (function(){
        const el=document.getElementById('flash'); if(!el) return;
        themedSwal({
          title: el.dataset.type==='success' ? 'Success' : (el.dataset.type==='error' ? 'Error' : 'Notice'),
          html: el.dataset.msg || '',
          icon: el.dataset.type==='success' ? 'success' : (el.dataset.type==='error' ? 'error' : 'info'),
          confirmButtonText:'OK'
        });
      })();
    })();
  </script>

  <?php if (!empty($PAGE_FOOT_SCRIPTS)) echo $PAGE_FOOT_SCRIPTS; ?>
</body>
</html>
