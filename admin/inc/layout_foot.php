<?php
// /admin/inc/layout_foot.php — Admin-only footer (pairs with admin layout_head)
?>
    <footer class="mt-2">
      <p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p>
    </footer>
  </main>

  <!-- Bootstrap Bundle (Popper included) -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
  <!-- SweetAlert2 (optional) -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
  (function(){
    // Prevent duplicate init if this file is accidentally included twice
    window.PM = window.PM || {};
    if (window.PM._adminFootInit) return;
    window.PM._adminFootInit = true;

    const body     = document.body;
    const mq       = window.matchMedia('(max-width: 992px)');
    const burger   = document.getElementById('menuTrigger');   // from header
    const backdrop = document.getElementById('backdrop');      // from layout
    const LS_KEY   = 'pm.sidebar.admin';

    // ---------- Helpers (defined once) ----------
    if (!window.PM.themedSwal) {
      window.PM.themedSwal = function(opts){
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
      };
    }

    if (!window.PM.confirmLogout) {
      window.PM.confirmLogout = function(){
        const go = (typeof LOGOUT_URL !== 'undefined' && LOGOUT_URL) ? LOGOUT_URL : 'logout.php';
        window.PM.themedSwal({
          title:'Logout?',
          text:'End your session securely.',
          icon:'question',
          showCancelButton:true,
          confirmButtonText:'Yes, logout',
          cancelButtonText:'Cancel'
        }).then(res=>{
          if(res.isConfirmed){
            window.PM.themedSwal({ title:'Logging out...', text:'Please wait', allowOutsideClick:false, didOpen:()=>Swal.showLoading() });
            setTimeout(()=>{ window.location.href = go; }, 600);
          }
        });
      };
      // expose for onclicks in templates
      window.confirmLogout = window.PM.confirmLogout;
    }

    function setBurgerExpanded(expanded){
      if (!burger) return;
      burger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function applySavedSidebar(){
      try{
        const s = localStorage.getItem(LS_KEY);
        if(!mq.matches && s === 'collapsed'){
          body.classList.add('sidebar-collapsed');
          setBurgerExpanded(false);
        } else {
          setBurgerExpanded(true);
        }
      }catch(e){}
    }

    function saveSidebarState(){
      try{
        localStorage.setItem(
          LS_KEY,
          body.classList.contains('sidebar-collapsed') ? 'collapsed' : 'open'
        );
      }catch(e){}
    }

    function closeMobileSidebar(){
      body.classList.remove('sidebar-open');
      setBurgerExpanded(false);
    }

    function toggleSidebar(e){
      if (e) e.preventDefault();
      if (mq.matches){
        const opening = !body.classList.contains('sidebar-open');
        body.classList.toggle('sidebar-open');
        setBurgerExpanded(opening);
      } else {
        body.classList.toggle('sidebar-collapsed');
        saveSidebarState();
        setBurgerExpanded(!body.classList.contains('sidebar-collapsed'));
      }
    }

    // ---------- Init once ----------
    applySavedSidebar();

    // Burger / backdrop
    if (burger && !burger.dataset.bound){
      burger.dataset.bound = '1';
      burger.addEventListener('click', toggleSidebar, false);
      burger.addEventListener('keydown', function(e){
        if(e.key === 'Enter' || e.key === ' '){
          e.preventDefault();
          toggleSidebar(e);
        }
      }, false);
    }
    if (backdrop && !backdrop.dataset.bound){
      backdrop.dataset.bound = '1';
      backdrop.addEventListener('click', closeMobileSidebar, false);
    }

    // ESC closes mobile sidebar
    if (!document.documentElement.dataset.pmAdminEscBound){
      document.documentElement.dataset.pmAdminEscBound = '1';
      document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && mq.matches && body.classList.contains('sidebar-open')){
          closeMobileSidebar();
        }
      }, false);
    }

    // On resize, ensure mobile-open state is cleared when going desktop
    if (!window._pmAdminResizeBound){
      window._pmAdminResizeBound = true;
      window.addEventListener('resize', function(){
        if (!mq.matches){
          closeMobileSidebar();
        }
      }, {passive:true});
    }

    // ---------- Flash -> SweetAlert (expects hidden #flash element on the page) ----------
    (function(){
      const el = document.getElementById('flash'); if(!el) return;
      window.PM.themedSwal({
        title: el.dataset.type==='success' ? 'Success' : (el.dataset.type==='error' ? 'Error' : 'Notice'),
        html: el.dataset.msg || '',
        icon: el.dataset.type==='success' ? 'success' : (el.dataset.type==='error' ? 'error' : 'info'),
        confirmButtonText:'OK'
      });
    })();

    // ---------- Generic SweetAlert confirm for small POST forms ----------
    (function(){
      document.querySelectorAll('form.js-confirm').forEach(form=>{
        if (form.dataset.bound === '1') return;
        form.dataset.bound = '1';
        form.addEventListener('submit',(e)=>{
          e.preventDefault();
          const msg=form.dataset.message||'Are you sure?';
          window.PM.themedSwal({
            title:'Please confirm',
            text:msg,
            icon:'question',
            showCancelButton:true,
            confirmButtonText:'Yes, proceed',
            cancelButtonText:'Cancel'
          }).then(r=>{ if(r.isConfirmed) form.submit(); });
        });
      });
    })();

  })();
  </script>

  <?php if (!empty($PAGE_FOOT_SCRIPTS)) echo $PAGE_FOOT_SCRIPTS; ?>
</body>
</html>
