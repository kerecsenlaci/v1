<?php
use Glpi\Application\View\TemplateRenderer;

class PluginHtml2pdfReportGenerator {

   public static function generateTicketWorksheet(int $ticket_id, ?string $tpl = null, array $opts = []) {
   if (!Plugin::isPluginActive('pdf')) {
      throw new \RuntimeException('PDF plugin is not active');
   }

   $t = new \Ticket();
   if (!$t->getFromDB($ticket_id)) {
      throw new \RuntimeException("Nem található jegy #{$ticket_id}");
   }

   // opciók
   $show_header     = (bool)($opts['show_header']      ?? true);
   $show_footer     = (bool)($opts['show_footer']      ?? true);
   $show_title      = (bool)($opts['show_title']       ?? true);
   $include_private = (bool)($opts['include_private']  ?? \Session::haveRight('ticket', UPDATE));

   // alap ticket mezők
   $data = $t->fields;

   // entitás neve (ahogy eddig)
   $data['entity_name'] = self::fetchName('glpi_entities', 'id', $data['entities_id'] ?? 0);

   // *** ITIL enum -> szöveg ***
   $data['type_label']     = \Ticket::getTicketTypeName((int)$data['type']);                // Típus
   $data['status_label'] = \Ticket::getSpecificValueToDisplay('status', (int)$data['status']);// Státusz
   $data['urgency_label']  = \CommonITILObject::getUrgencyName((int)$data['urgency']);     // Fontosság
   $data['impact_label']   = \CommonITILObject::getImpactName((int)$data['impact']);       // Terhelés
   $data['priority_label'] = \CommonITILObject::getPriorityName((int)$data['priority']);   // Prioritás
   // feladatok, dokumentumok, megoldás
   $tasks      = self::loadTasks($ticket_id);
   $documents  = self::loadDocuments($ticket_id);
   $solution   = self::loadSolution($ticket_id);
   // Kategória név
   $data['category_name'] = '';
   if (!empty($data['itilcategories_id'])) {
      $cat = new \ITILCategory();
      if ($cat->getFromDB((int)$data['itilcategories_id'])) {
         $data['category_name'] = $cat->fields['completename'] ?? $cat->fields['name'];
      }
   }

   // Hely (location) név
   $data['location_name'] = '';
   if (!empty($data['locations_id'])) {
      $loc = new \Location();
      if ($loc->getFromDB((int)$data['locations_id'])) {
         $data['location_name'] = $loc->fields['completename'] ?? $loc->fields['name'];
      }
   }

   // Kérelmező (első requester neve)
   $data['requester_name'] = '';
   $requesters = $t->getUsers(\CommonITILActor::REQUESTER);
   if (!empty($requesters)) {
      $first = reset($requesters);
      if (!empty($first['users_id'])) {
         $user = new \User();
         if ($user->getFromDB((int)$first['users_id'])) {
            $data['requester_name'] = $user->getFriendlyName();
         }
      }
   }

   // követségek
   $followups = self::loadFollowups($ticket_id, $include_private);

   // logo data-uri
   $logo_data_uri = self::logoDataUri();

   // template fájl
   $tplfile = self::resolveTemplate($tpl ?: 'ticket_munkalap.html.twig');

   // Twig render
   $renderer = TemplateRenderer::getInstance();
   $html = $renderer->render('@html2pdf/'.$tplfile, [
   'ticket'       => $data,
   'followups'    => $followups,
   'tasks'        => $tasks,
   'documents'    => $documents,
   'solution'     => $solution,
   'options'      => [
      'show_header' => $show_header,
      'show_footer' => $show_footer,
      'show_title'  => $show_title,
   ],
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

   // PDF generálás (PDF plugin API-ján keresztül)
   \PluginPdfApi::generateFromHtml($html, "munkalap_{$ticket_id}.pdf", '', '', 'I');
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

   // Ticket feladatok (glpi_tickettasks)
   protected static function loadTasks(int $ticket_id): array {
      global $DB;

      $tasks = [];

      $iterator = $DB->request([
         'FROM'  => 'glpi_tickettasks',
         'WHERE' => [
            'tickets_id' => $ticket_id
         ],
         'ORDER' => 'date ASC'   // vagy 'id' => 'ASC', ha biztosan van 'date'
      ]);

      foreach ($iterator as $row) {
         $task = $row;

         // kategória neve
         $task['category_name'] = '';
         if (!empty($row['taskcategories_id'])) {
            $cat = new \TaskCategory();
            if ($cat->getFromDB((int)$row['taskcategories_id'])) {
               $task['category_name'] = $cat->fields['completename'] ?? $cat->fields['name'];
            }
         }

         // technikus neve
         $task['technician_name'] = '';
         if (!empty($row['users_id_tech'])) {
            $user = new \User();
            if ($user->getFromDB((int)$row['users_id_tech'])) {
               $task['technician_name'] = $user->getFriendlyName();
            }
         }

         $tasks[] = $task;
      }

      return $tasks;
   }


   // Ticket dokumentumok
   protected static function loadDocuments(int $ticket_id): array {
      global $DB;

      $docs = [];

      $links = $DB->request([
         'FROM'  => 'glpi_documents_items',
         'WHERE' => [
            'itemtype' => 'Ticket',
            'items_id' => $ticket_id
         ]
      ]);

      foreach ($links as $link) {
         $doc = new \Document();
         if ($doc->getFromDB((int)$link['documents_id'])) {
            $docs[] = [
               'name'     => $doc->fields['name']     ?? '',
               'filename' => $doc->fields['filename'] ?? '',
               'comment'  => $doc->fields['comment']  ?? '',
            ];
         }
      }

      return $docs;
   }

   // Ticket megoldás (ITILSolution)
   protected static function loadSolution(int $ticket_id): ?array {
      global $DB;

      $rows = [];

      $iterator = $DB->request([
         'FROM'  => 'glpi_itilsolutions',
         'WHERE' => [
            'itemtype' => 'Ticket',
            'items_id' => $ticket_id
         ]
      ]);

      foreach ($iterator as $row) {
         $rows[] = $row;
      }

      if (empty($rows)) {
         return null;
      }

      // ha több megoldás van, vegyük a legutolsót
      usort($rows, function ($a, $b) {
         return strcmp($a['date_creation'] ?? '', $b['date_creation'] ?? '');
      });
      $row = array_pop($rows);

      $solution = $row;

      // ki készítette
      $solution['user_name'] = '';
      if (!empty($row['users_id'])) {
         $user = new \User();
         if ($user->getFromDB((int)$row['users_id'])) {
            $solution['user_name'] = $user->getFriendlyName();
         }
      }

      return $solution;
   }
}
