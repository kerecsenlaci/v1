<?php
if (!defined('GLPI_ROOT')) { die('Direct access not allowed'); }

class PluginPdfApi {

   protected static function loadVendors() : void {
      if (class_exists('Plugin')) {
         $pdfRoot = Plugin::getPhpDir('pdf');
         $candidates = [
            $pdfRoot . '/vendor/autoload.php',
            GLPI_ROOT . '/vendor/autoload.php',
            GLPI_ROOT . '/plugins/pdf/vendor/autoload.php',
            GLPI_ROOT . '/marketplace/pdf/vendor/autoload.php',
         ];
         foreach ($candidates as $file) {
            if (is_file($file)) {
               require_once $file;
            }
         }
         // Direct include for TCPDF if composer autoload not present
         if (!class_exists('TCPDF')) {
            $tcpdfCandidates = [
               $pdfRoot . '/vendor/tecnickcom/tcpdf/tcpdf.php',
               GLPI_ROOT . '/plugins/pdf/vendor/tecnickcom/tcpdf/tcpdf.php',
               GLPI_ROOT . '/marketplace/pdf/vendor/tecnickcom/tcpdf/tcpdf.php',
            ];
            foreach ($tcpdfCandidates as $file) {
               if (is_file($file)) {
                  require_once $file;
                  break;
               }
            }
         }
      }
   }

   public static function generateFromHtml($html_content, $filename = 'document.pdf', $header_html = '', $footer_html = '', $output_method = 'I') {
      self::loadVendors();

      // 1) Spipu Html2Pdf
      if (class_exists('\Spipu\Html2Pdf\Html2Pdf')) {
         $content = $html_content;
         if (!empty($header_html) || !empty($footer_html)) {
            $content = '<page>'
                     . (!empty($header_html) ? '<page_header>'.$header_html.'</page_header>' : '')
                     . $html_content
                     . (!empty($footer_html) ? '<page_footer>'.$footer_html.'</page_footer>' : '')
                     . '</page>';
         }
         $pdf = new \Spipu\Html2Pdf\Html2Pdf('P', 'A4', 'en');
         $pdf->writeHTML($content);
         $pdf->output($filename, $output_method);
         exit;
      }

      // 2) mPDF
      if (class_exists('\Mpdf\Mpdf')) {
         $mpdf = new \Mpdf\Mpdf(['tempDir' => GLPI_TMP_DIR . '/mpdf']);
         if (!empty($header_html)) $mpdf->SetHTMLHeader($header_html);
         if (!empty($footer_html)) $mpdf->SetHTMLFooter($footer_html);
         $mpdf->WriteHTML($html_content);
         $mpdf->Output($filename, $output_method);
         exit;
      }

      // 3) Dompdf
      if (class_exists('\Dompdf\Dompdf')) {
         $dompdf = new \Dompdf\Dompdf();
         $dompdf->setPaper('A4', 'portrait');
         if (!empty($header_html) || !empty($footer_html)) {
            $hf = '<style>@page { margin: 60px 40px; } .pdf-header { position: fixed; top: -40px; left: 0; right: 0; } .pdf-footer { position: fixed; bottom: -40px; left: 0; right: 0; font-size: 10px; }</style>';
            $html_content = $hf
              . (!empty($header_html) ? '<div class="pdf-header">'.$header_html.'</div>' : '')
              . $html_content
              . (!empty($footer_html) ? '<div class="pdf-footer">'.$footer_html.'</div>' : '');
         }
         $dompdf->loadHtml($html_content);
         $dompdf->render();
         $dompdf->stream($filename, ['Attachment' => ($output_method === 'D') ? 1 : 0]);
         exit;
      }

      // 4) TCPDF fallback
      if (class_exists('TCPDF')) {
         // Create TCPDF and configure
         $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
         $pdf->SetCreator('GLPI html2pdf');
         $pdf->SetAuthor('GLPI');
         $pdf->SetTitle($filename);
         $pdf->setPrintHeader(false);
         $pdf->setPrintFooter(false);
         $pdf->SetMargins(15, 15, 15); // leave space for header/footer if any
         $pdf->SetAutoPageBreak(true, 20);
         $pdf->SetHeaderMargin(10);
         $pdf->SetFooterMargin(15);
         // Font
         if (method_exists($pdf, 'SetFont')) {
            // DejaVuSans for UTF-8 (if available), otherwise helvetica
            try { $pdf->SetFont('dejavusans', '', 10, '', true); }
            catch (\Throwable $e) { $pdf->SetFont('helvetica', '', 10); }
         }
         // Header callback (HTML)
         if (!empty($header_html) && method_exists($pdf, 'setHeaderCallback')) {
            $header = $header_html;
            $pdf->setHeaderCallback(function($pdf) use ($header) {
               $pdf->SetY(10);
               $pdf->writeHTML($header, true, false, true, false, '');
            });
         }
         // Footer callback (HTML)
         if (!empty($footer_html) && method_exists($pdf, 'setFooterCallback')) {
            $footer = $footer_html;
            $pdf->setFooterCallback(function($pdf) use ($footer) {
               $pdf->SetY(-15);
               $pdf->writeHTML($footer, true, false, true, false, '');
            });
         }
         $pdf->AddPage();
         $pdf->writeHTML($html_content, true, false, true, false, '');
         $pdf->Output($filename, $output_method);
         exit;
      }

      throw new \RuntimeException('Nem található PDF motor (Html2Pdf/mPDF/Dompdf/TCPDF).');
   }
}
