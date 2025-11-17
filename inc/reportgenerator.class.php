<?php
use Glpi\Application\View\TemplateRenderer;

class PluginHtml2pdfReportGenerator {

   public static function generateTicketWorksheet(int $ticket_id, ?string $tpl=null, array $opts=[]) {
      if (!Plugin::isPluginActive('pdf')) throw new \RuntimeException('PDF plugin is not active');

      $t = new Ticket();
      if (!$t->getFromDB($ticket_id)) throw new \RuntimeException("Nem található jegy #{$ticket_id}");

      // opciók
      $show_header     = (bool)($opts['show_header'] ?? true);
      $show_footer     = (bool)($opts['show_footer'] ?? true);
      $show_title      = (bool)($opts['show_title']  ?? true);
      $include_private = (bool)($opts['include_private'] ?? Session::haveRight('ticket', UPDATE));

      $data = $t->fields;
      $data['entity_name'] = self::fetchName('glpi_entities','id', $data['entities_id'] ?? 0);

      $followups = self::loadFollowups($ticket_id, $include_private);

      // logo data-uri
      $logo_data_uri = self::logoDataUri();

      $tplfile = self::resolveTemplate($tpl ?: 'ticket_munkalap.html.twig');

      $renderer = TemplateRenderer::getInstance();
      $html = $renderer->render('@html2pdf/'.$tplfile, [
         'ticket'    => $data,
         'followups' => $followups,
         'options'   => ['show_header'=>$show_header,'show_footer'=>$show_footer,'show_title'=>$show_title],
         'logo_data_uri' => $logo_data_uri
      ]);
       // >>> DEBUG BLOKK  <<<
      if (!empty($_GET['debughtml'])) {
         header('Content-Type: text/html; charset=UTF-8');
         echo '<!-- '.'----->>> DEBUG BLOKK  <<<-----'.' -->'."\r\n";
         echo '<!-- followups_count: ' . count($followups) . ' -->'."\r\n";
         echo '<!-- '.json_encode($followups).' -->'."\r\n";
         echo '<!-- '.'----->>> DEBUG BLOKK END <<<-----'.' -->'."\r\n";
         echo $html;
         exit;
      }
      // header/footer a templatesből include-olódik → itt üres
      PluginPdfApi::generateFromHtml($html, "munkalap_{$ticket_id}.pdf", '', '', 'I');
   }

   protected static function resolveTemplate(?string $tpl) : string {
      $default='ticket_munkalap.html.twig';
      if ($tpl) {
         $path = GLPI_ROOT.'/plugins/html2pdf/templates/'.basename($tpl);
         if (is_file($path)) return basename($tpl);
      }
      return $default;
   }

   protected static function loadFollowups(int $tickets_id, bool $include_private) : array {
      global $DB;
      $out=[]; $where=['items_id'=>$tickets_id];
      if (!$include_private) $where['is_private']=0;

      $rows = $DB->request([
         'SELECT'=>['id','content','date','is_private','users_id','requesttypes_id'],
         'FROM'=>'glpi_itilfollowups',
         'WHERE'=>$where,
         'ORDER'=>'date ASC, id ASC'
      ]);
      foreach ($rows as $row) {
         $author = self::userName((int)($row['users_id']??0));
         $src = self::fetchName('glpi_requesttypes','id',(int)($row['requesttypes_id']??0));
         if ($src==='') $src = ((int)($row['is_private']??0)===1) ? 'Privát' : 'Nyilvános';
         $out[] = [
            'id'=>(int)$row['id'], 'date'=>(string)($row['date']??''),
            'author'=>$author, 'source'=>$src, 'content'=>(string)($row['content']??'')
         ];
      }
      return $out;
   }

   protected static function userName(int $users_id) : string {
      if ($users_id<=0) return '';
      global $DB;
      foreach ($DB->request(['SELECT'=>['realname','firstname','name'],'FROM'=>'glpi_users','WHERE'=>['id'=>$users_id],'LIMIT'=>1]) as $r) {
         $full=trim(($r['firstname']??'').' '.($r['realname']??''));
         return $full!=='' ? $full : (string)($r['name']??'');
      }
      return '';
   }
   protected static function fetchName(string $table,string $idcol,int $id,string $namecol='name') : string {
      if ($id<=0) return '';
      global $DB;
      foreach ($DB->request(['SELECT'=>[$namecol],'FROM'=>$table,'WHERE'=>[$idcol=>$id],'LIMIT'=>1]) as $r) {
         return (string)($r[$namecol]??'');
      }
      return '';
   }

   protected static function logoDataUri() : string {
      $logo = GLPI_ROOT.'/plugins/html2pdf/templates/img/okfo-logo.png';
      if (is_file($logo) && is_readable($logo)) {
         $mime = 'image/png';
         return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($logo));
      }
      return '';
   }
}
