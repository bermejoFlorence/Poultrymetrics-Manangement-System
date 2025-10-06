<?php
/**
 * Common Layout Foot | PoultryMetrics (Worker)
 * Include at the bottom of every worker page BEFORE </body>.
 */
?>
    <footer class="mt-2">
      <p class="mb-0 small text-muted">&copy; <?php echo date('Y'); ?> PoultryMetrics</p>
    </footer>
  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script>
    // ==========================================================
    // Worker Foot — Utilities only (no sidebar toggle here)
    //   (Sidebar open/close + memory is handled in the HEAD script)
    // ==========================================================

    // -----------------------------
    // SweetAlert theme wrapper
    // -----------------------------
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

    // -----------------------------
    // Logout confirmation (matches head)
    // -----------------------------
    function confirmLogout(){
      themedSwal({
        title:'Logout?',
        text:'End your session securely.',
        icon:'question',
        showCancelButton:true,
        confirmButtonText:'Yes, logout',
        cancelButtonText:'Cancel',
        reverseButtons:true
      }).then(res=>{
        if(res.isConfirmed){
          themedSwal({
            title:'Logging out…',
            text:'Please wait',
            allowOutsideClick:false,
            didOpen:()=>Swal.showLoading()
          });
          setTimeout(()=>{ window.location.href='../logout.php'; }, 600);
        }
      });
      return false;
    }
    window.confirmLogout = confirmLogout;

    // -----------------------------
    // Small helpers (reusable)
    // -----------------------------
    // Convert "HH:MM[:SS]" to 12h "h:MM AM/PM"
    function formatTime12(t){
      if(!t) return '';
      const m = String(t).match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
      if(!m) return '';
      let hh = parseInt(m[1],10), mm = m[2];
      const ampm = hh>=12 ? 'PM' : 'AM';
      hh = hh%12; if(hh===0) hh = 12;
      return `${hh}:${mm} ${ampm}`;
    }
    // Minutes -> "H:MM" (clip negatives to 0)
    function minsToHM(mins){
      let m = Math.max(0, parseInt(mins||0,10));
      const h = Math.floor(m/60), mm = m%60;
      return `${h}:${mm<10?'0':''}${mm}`;
    }

    // -----------------------------
    // Flash -> SweetAlert (optional)
    // Render a hidden element like:
    // <div id="flash" data-type="success" data-msg="Saved!"></div>
    // -----------------------------
    (function(){
      const el = document.getElementById('flash');
      if(!el) return;
      const type = (el.dataset.type||'info').toLowerCase();
      const msg  = el.dataset.msg || '';
      themedSwal({
        title: type === 'success' ? 'Success' : (type==='error'?'Error':'Notice'),
        html: msg,
        icon: (['success','error','warning','info','question'].includes(type) ? type : 'info'),
        confirmButtonText:'OK'
      });
    })();

    // -----------------------------
    // Backdrop click close (mobile)
    // (Head manages open/close; we only make sure backdrop click always works)
    // -----------------------------
    (function(){
      const bd = document.getElementById('backdrop');
      if(!bd) return;
      bd.addEventListener('click', ()=> document.body.classList.remove('sidebar-open'));
    })();
  </script>
</body>
</html>
