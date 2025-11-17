<?php
if (!defined('GLPI_ROOT')) { die('Direct access not allowed'); }

/** pdf jog ellenőrzés */
function plugin_html2pdf_user_can_use_pdf() : bool {
   static $cache = null;
   if ($cache !== null) { return $cache; }

   if (!Plugin::isPluginActive('pdf')) {
      return $cache = false;
   }
   // 1) Jogkulcsok a pdf pluginhoz
   $candidates = ['plugin_pdf', 'pdf', 'plugin_pdf_export', 'plugin_pdf_view', 'plugin_pdf_config'];
   foreach ($candidates as $key) {
      try {
         if (Session::haveRight($key, READ) || Session::haveRight($key, CREATE) || Session::haveRight($key, UPDATE)) {
            return $cache = true;
         }
      } catch (Throwable $e) {
         // ha a kulcs nincs definiálva, egyszerűen lépjünk tovább
      }
   }
   // 2) Megnézzük a profiljogok táblát
   global $DB;
   try {
      $profile_id = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
      if ($profile_id > 0) {
         // csak a pdf plugin jogait vizsgáljuk
         foreach ($DB->request([
            'SELECT' => ['name','rights'],
            'FROM'   => 'glpi_profilerights',
            'WHERE'  => [
               'profiles_id' => $profile_id,
               ['RAW' => "name LIKE 'plugin_pdf%'"]
            ],
         ]) as $row) {
            $rights = (int)($row['rights'] ?? 0);
            if (($rights & READ) || ($rights & CREATE) || ($rights & UPDATE)) {
               return $cache = true;
            }
         }
      }
   } catch (Throwable $e) {
      // ha valamiért nem tudtuk ellenőrizni, inkább NE mutassuk
   }
   return $cache = false;
}

/** Sablon-választós modal + 4 checkbox */
function plugin_html2pdf_print_modal(string $base_url, array $templates, string $button_id) {
   $rand    = mt_rand(100000, 999999);
   $modalId = 'html2pdf_tpl_modal_'.$rand;
   $selId   = 'html2pdf_tpl_select_'.$rand;
   $printId = 'html2pdf_tpl_print_'.$rand;
   $cbH = 'html2pdf_cb_h_'.$rand; $cbF = 'html2pdf_cb_f_'.$rand;
   $cbT = 'html2pdf_cb_t_'.$rand; $cbP = 'html2pdf_cb_p_'.$rand;

   echo "
<div id='{$modalId}' style='display:none; position:fixed; inset:0; z-index:99999; background:rgba(0,0,0,.35);'>
  <div style='position:absolute; left:50%; top:50%; transform:translate(-50%,-50%); width:min(92vw,640px); background:#fff; border-radius:12px; box-shadow:0 10px 40px rgba(0,0,0,.25);'>
    <div style='display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #e5e7eb; background:#f9fafb;'>
      <div style='font-weight:600'>".__('Munkalap nyomtatása', 'html2pdf')."</div>
      <button type='button' class='vsubmit' onclick=\"document.getElementById('{$modalId}').style.display='none'\">×</button>
    </div>
    <div style='padding:16px;'>
      <label for='{$selId}' style='display:block; font-weight:600; margin-bottom:6px;'>".__('Sablon', 'html2pdf')."</label>
      <select id='{$selId}' style='width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;'>";
      foreach ($templates as $file => $label) {
         echo "<option value='".Html::cleanInputText($file)."'>".Html::entities_deep($label)."</option>";
      }
   echo " </select>
      <div style='display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; margin-top:14px;'>
        <label style='display:flex; align-items:center; gap:8px;'><input type='checkbox' id='{$cbH}' checked/><span>Fejléc</span></label>
        <label style='display:flex; align-items:center; gap:8px;'><input type='checkbox' id='{$cbF}' checked/><span>Lábléc</span></label>
        <label style='display:flex; align-items:center; gap:8px;'><input type='checkbox' id='{$cbT}' checked/><span>Címsor</span></label>
        <label style='display:flex; align-items:center; gap:8px;'><input type='checkbox' id='{$cbP}' /><span>Privát reakciók</span></label>
      </div>
      <div style='margin-top:16px; display:flex; justify-content:flex-end; gap:8px;'>
        <button id='{$printId}' class='vsubmit'>".__('Nyomtatás', 'html2pdf')."</button>
      </div>
    </div>
  </div>
</div>";
echo Html::scriptBlock("
(function(){
  var modalId = ".json_encode($modalId).";
  var baseUrl = ".json_encode($base_url).";
  var selId   = ".json_encode($selId).";
  var printId = ".json_encode($printId).";
  var cbH     = ".json_encode($cbH).";
  var cbF     = ".json_encode($cbF).";
  var cbT     = ".json_encode($cbT).";
  var cbP     = ".json_encode($cbP).";
  var openBtnId = ".json_encode($button_id).";

  var modal = document.getElementById(modalId);

  // Globális nyitó, akkor is működik, ha nincs létező DOM gomb (User-es injektált gomb is hívhatja)
  window.__html2pdfOpeners = window.__html2pdfOpeners || {};
  window.__html2pdfOpeners[openBtnId] = function(){ if (modal) modal.style.display='block'; };

  // Ha létezik a gomb, kössük rá az eseményt
  var open = document.getElementById(openBtnId);
  if (open) {
     open.addEventListener('click', function(e){
        if (e) e.preventDefault();
        window.__html2pdfOpeners[openBtnId]();
        return false;
     });
  }

  // Print
  var print = document.getElementById(printId);
  if (print) {
     print.addEventListener('click', function(){
       var sel = document.getElementById(selId);
       var h = document.getElementById(cbH), f = document.getElementById(cbF);
       var t = document.getElementById(cbT), p = document.getElementById(cbP);
       var url = baseUrl
         + (sel && sel.value ? '&tpl=' + encodeURIComponent(sel.value) : '')
         + '&header=' + (h && h.checked ? 1:0)
         + '&footer=' + (f && f.checked ? 1:0)
         + '&title='  + (t && t.checked ? 1:0)
         + '&private='+ (p && p.checked ? 1:0);
       window.open(url, '_blank');
       if (modal) modal.style.display='none';
     });
  }
})();
");

}
/** Gombok képzése */
function plugin_html2pdf_pre_item_form(array $params) {
   if (!isset($params['item']) || !($params['item'] instanceof CommonDBTM)) return;

   // TICKET
   if ($params['item'] instanceof Ticket) {
      if (!plugin_html2pdf_user_can_use_pdf()) return;
      $id = (int)$params['item']->getID(); if ($id<=0) return;

      $base = Plugin::getWebDir('html2pdf')."/front/generate_report.php?id=".$id;
      $templates = ['ticket_munkalap.html.twig'=>'Alap munkalap'];
      $openId = 'html2pdf_open_ticket_'.mt_rand(100000,999999);

      echo "<tr class='tab_bg_1 center'><td colspan='4' style='padding:10px 0;'>
        <button type='button' class='vsubmit' id='{$openId}'>".__('Munkalap nyomtatása (html2pdf)', 'html2pdf')."</button>
      </td></tr>";
      plugin_html2pdf_print_modal($base, $templates, $openId);
      return;
   }
}