<?php
include ('../../../inc/includes.php');
Session::checkRight('ticket', READ);

$ticket_id = (int)($_GET['id'] ?? 0);
if ($ticket_id <= 0) Html::redirect($CFG_GLPI['root_doc']);

$tpl = $_REQUEST['tpl'] ?? null;
$opts = [
   'show_header'     => isset($_REQUEST['header']) ? (bool)$_REQUEST['header'] : true,
   'show_footer'     => isset($_REQUEST['footer']) ? (bool)$_REQUEST['footer'] : true,
   'show_title'      => isset($_REQUEST['title'])  ? (bool)$_REQUEST['title']  : true,
   'include_private' => isset($_REQUEST['private'])? (bool)$_REQUEST['private']: Session::haveRight('ticket', UPDATE),
];

PluginHtml2pdfReportGenerator::generateTicketWorksheet($ticket_id, $tpl, $opts);
